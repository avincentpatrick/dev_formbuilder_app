<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\QueueName;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Auth\QueuedResetPassword;
use App\Notifications\Auth\QueuedVerifyEmail;
use App\Notifications\TenantInvitationNotification;
use App\Services\Branding\TenantBrandingService;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Branding\BrandPalette;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment H23a4 — the brand reaches the WORKER (ADR-0014 §D8).
|--------------------------------------------------------------------------
| `BrandedMailRenderTest` proves the template turns a palette into inlined colour. This file proves the
| harder half: that a real dispatch site puts the right palette into the payload, and that the payload
| survives the queue.
|
| THE FAILURE THIS GUARDS IS SILENT. A queued Notification is delivered by SendQueuedNotifications, not by
| TenantAwareJob, so `TenantContext::currentTenantId()` is NULL on the worker and every branding read
| there fails closed. Resolve the brand in `toMail()` and every tenant gets an unbranded email while the
| whole suite stays green — so the assertions below are on the SERIALIZED PAYLOAD, which is the only place
| the difference is visible.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    config(['app.url' => 'https://meridian.test']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** @return array{0: Tenant, 1: User} */
function brandedMailTenant(PlanTier $tier = PlanTier::Starter, string $hex = '#C0392B'): array
{
    $tenant = inboxTenant('acme');
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    assignPlanTier($tier);
    app(TenantBrandingService::class)->setBrandColor($tenant, $hex);

    // A logo too, so `logo_url` is a real URL rather than the empty string. Without one these tests would
    // still pass while proving nothing about the half of the palette that is hardest to get right.
    $tenant->forceFill(['logo_attachment_id' => brandingLogoAttachment($tenant->id)->id])->save();

    return [$tenant->refresh(), $admin];
}

it('resolves the tenant palette at the dispatch site, not on the worker', function (): void {
    Notification::fake();

    [$tenant, $admin] = brandedMailTenant();
    $expected = $tenant->brandRamp()?->token('light', 'bg');

    app(TenantMembershipService::class)->invite($tenant, 'newbie@membertest.local', 'form_editor', $admin);

    Notification::assertSentOnDemand(
        TenantInvitationNotification::class,
        function (TenantInvitationNotification $notification) use ($expected): bool {
            expect($notification->brand['bg'])->toBe($expected)
                ->and($notification->brand['name'])->toBe('Acme')
                // The APP arm, never the public one: ADR-0009 §D2 and ADR-0012 scope a custom host to the
                // guest runtime, so an authenticated link or an image on one would not resolve.
                ->and($notification->brand['url'])->toStartWith('https://acme.meridian.test/');

            return true;
        }
    );
});

it('sends the product palette for a tenant whose plan does not include branding', function (): void {
    Notification::fake();

    // Stored but not active. Checking `hasBrandRamp()` instead of `isActive()` anywhere in the chain
    // would ship a Starter+ feature to Free tenants, and mail is the surface where nobody would notice.
    [$tenant, $admin] = brandedMailTenant(tier: PlanTier::Starter);
    enterTenant($tenant->id, $admin->id);
    assignPlanTier(PlanTier::Free);

    app(TenantMembershipService::class)->invite($tenant->refresh(), 'newbie@membertest.local', 'form_editor', $admin);

    Notification::assertSentOnDemand(
        TenantInvitationNotification::class,
        function (TenantInvitationNotification $notification): bool {
            // ⛔ AMENDED BY M99 (R-62fb2e05), AND THE OLD FORM IS WHY THE DEFECT SURVIVED. It read
            //    `->toBe(BrandPalette::product())` — a function compared against its own output, which
            //    is green whatever that function returns and could not have failed if `product()` had
            //    started returning an empty array. What is asserted now is the CONTRACT: no tenant
            //    colours, no tenant logo, but the workspace's OWN host as the header link, because the
            //    tenant is known here and only its branding is missing. Before this, an unbranded
            //    workspace's invitation linked at `config('app.url')` — the agency's public website
            //    under D46 — and so did the other six non-auth dispatch sites.
            expect($notification->brand['bg'])->toBe(BrandPalette::PRODUCT['bg'])
                ->and($notification->brand['name'])->toBe((string) config('app.name'))
                ->and($notification->brand['logo_url'])->toBe('')
                ->and($notification->brand['url'])->toStartWith('https://acme.meridian.test')
                ->and($notification->brand['url'])->not->toBe((string) config('app.url'));

            return true;
        }
    );
});

it('carries the palette through the real queue payload with no serialized model', function (): void {
    config()->set('queue.default', 'database');
    config()->set('mail.default', 'array'); // no external transport when the worker sends

    [$tenant, $admin] = brandedMailTenant();
    $hex = (string) $tenant->brandRamp()?->token('light', 'bg');

    app(TenantMembershipService::class)->invite($tenant, 'recipient@membertest.local', 'form_editor', $admin);

    $row = DB::table('jobs')->where('queue', QueueName::Mail->value)->first();

    expect($row)->not->toBeNull();

    $payload = json_decode((string) $row->payload, true);

    // The hex is IN the serialized command — which is the whole point: this is what a worker with no
    // tenant context will read, and the only thing standing between it and an unbranded email.
    expect($payload['data']['command'])->toContain($hex)
        ->and($payload['data']['command'])->toContain('acme.meridian.test/branding/logo')
        // §D5 still holds: an array of hexes is not an escape hatch for a model.
        ->and($payload['data']['command'])->not->toContain('ModelIdentifier')
        ->and($payload['data']['command'])->not->toContain('App\Models');

    Exceptions::fake();

    workOneJob(QueueName::Mail->value);

    Exceptions::assertNothingReported();
    expect(DB::table('jobs')->count())->toBe(0); // reserved AND deleted, not released
});

it('renders the branded message end to end through a real worker', function (): void {
    config()->set('queue.default', 'database');
    config()->set('mail.default', 'array');

    [$tenant, $admin] = brandedMailTenant();
    $hex = (string) $tenant->brandRamp()?->token('light', 'bg');

    app(TenantMembershipService::class)->invite($tenant, 'recipient@membertest.local', 'form_editor', $admin);

    // Tear the context down exactly as the request boundary would, so the worker below runs in the
    // SAME state production gives it — null GUC, no bound tenant. Without this the test would prove
    // nothing about the case it exists for.
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    workOneJob(QueueName::Mail->value);

    $sent = app('mailer')->getSymfonyTransport()->messages();

    expect($sent)->toHaveCount(1);

    // getOriginalMessage(), not getMessage(): the latter returns the RawMessage the transport actually
    // pushed, which has no body accessors.
    $html = (string) $sent[0]->getOriginalMessage()->getHtmlBody();

    expect($html)->toContain('background-color: '.$hex)
        ->and($html)->toContain('Acme')
        ->and($html)->toContain('acme.meridian.test/branding/logo')
        // The product default must be absent, or the assertion above could be satisfied by a message
        // that happened to contain both.
        ->and($html)->not->toContain(BrandPalette::PRODUCT['bg']);

    // ── The TEXT arm, which is the entire reason `vendor/mail/html/header.blade.php` exists ─────────
    // The same view renders through both arms. An <img> written into the layout's header slot is
    // strip_tags()'d out of the plaintext (blank first line); one passed through the STOCK header's
    // slot is escaped by `text/header.blade.php`'s {{ $slot }} and printed to the reader as a literal
    // `<img src=…>`. Overriding only the HTML component is what avoids both — and nothing else in the
    // suite would notice if a later edit moved the logo up into the layout.
    $text = (string) $sent[0]->getOriginalMessage()->getTextBody();

    expect($text)->not->toContain('<img')
        ->and($text)->not->toContain('branding/logo')
        // The framework's `text/header.blade.php` is deliberately NOT published, so the plaintext header
        // is its own `{{ $slot }}: {{ $url }}` — the tenant's name and its app host, as words.
        ->and($text)->toContain('Acme')
        ->and($text)->toContain('acme.meridian.test');
});

/*
|--------------------------------------------------------------------------
| The two Fortify mails, and where their header points (M99, R-62fb2e05).
|--------------------------------------------------------------------------
| ⛔ THESE ARE THE ONES A TESTER ACTUALLY RECEIVES. `config/fortify.php` sets `domain => null` and its
| own comment records that tenant users legitimately sign in at a workspace address, so "Forgot
| password" on `acme.meridian.test` sends a reset mail whose header linked at `config('app.url')` — the
| agency's own public website under D46. The origin is taken from the message's OWN action URL, which
| both dispatch sites already build against the current host, so the link and the button agree by
| construction rather than by two readings of the same config.
|
| ⚠️ AND IT CANNOT BE DONE AT RENDER. `CarriesTenantBrand` substitutes the palette on the queue worker,
| where `request()` answers from the console kernel — a request-reading palette would stamp a local
| hostname into live mail, non-deterministically, while every in-process test here stayed green.
*/

it('links the password-reset header at the host the reset URL itself points to', function (): void {
    Notification::fake();

    // ⚠️ DRIVEN THROUGH THE URL GENERATOR RATHER THAN THROUGH `POST /forgot-password`, AND THE REASON IS
    //    STATED BECAUSE IT IS A REAL LIMIT. A guest POST on the workspace host is the true path, but the
    //    broker's lookup cannot find a factory user in this fixture — with the tenant GUC set or flushed
    //    it answers "We can't find a user with that email address" — so that form would assert the
    //    fixture rather than the code. `forceRootUrl()` is exactly what a request on that host does to
    //    the generator, which is the one thing the dispatch site reads.
    //
    // ⛔ WHAT THIS DOES NOT PROVE, said rather than papered over: that the broker reaches this dispatch
    //    site on a workspace host. The VERIFICATION case below runs the full request and does prove it,
    //    and both dispatch sites take their origin from the same helper — so the untested half is the
    //    routing, not the linking. The sibling case is what makes that argument available.
    config(['app.url' => 'https://central.example']);
    URL::forceRootUrl('https://acme.meridian.test');

    User::factory()->create()->sendPasswordResetNotification('a-token');

    Notification::assertSentOnDemand(
        QueuedResetPassword::class,
        function (QueuedResetPassword $notification): bool {
            // ⚠️ THE HOST, NOT THE WHOLE URL. `forceRootUrl()` sets the root but not the scheme, so the
            //    generator answers http here while a real request would answer https — an artifact of the
            //    harness, not of the code. Asserting the host states what this case is about; asserting
            //    the string would pin a detail of the test double and fail on the day it changed.
            expect(parse_url((string) $notification->brand['url'], PHP_URL_HOST))->toBe('acme.meridian.test')
                ->and($notification->brand['url'])->not->toContain('central.example');

            return true;
        }
    );
});

it('links the verification header the same way', function (): void {
    Notification::fake();

    config(['app.url' => 'https://central.example']);

    [$tenant, $admin] = brandedMailTenant();
    $admin->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($admin)->post('https://acme.meridian.test/email/verification-notification');

    Notification::assertSentOnDemand(
        QueuedVerifyEmail::class,
        function (QueuedVerifyEmail $notification): bool {
            expect($notification->brand['url'])->toBe('https://acme.meridian.test');

            return true;
        }
    );
});

it('gives an account with no honest destination no link rather than the wrong one', function (): void {
    // `BrandPalette::product('')` is what the welcome listener passes for an account that belongs to no
    // workspace. Asserting the EMPTY STRING rather than "not the central url" is deliberate: the two
    // differ exactly when someone reintroduces a fallback, which is the shape being prevented.
    expect(BrandPalette::product('')['url'])->toBe('');
});
