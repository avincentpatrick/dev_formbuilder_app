<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\Attachment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment H23a2 — the tenant-branding web surface (ADR-0014).
|--------------------------------------------------------------------------
| Four routes on /settings/branding, gated `can:tenant.settings.manage`, writing the SUBDOMAIN-resolved
| tenant only — `tenants` is RLS-exempt, so the controller is what scopes the write.
|
| THE HEADLINE ASSERTION IN THIS FILE IS THE GATING ASYMMETRY. The two WRITES carry `feature:branding`;
| the two REMOVALS deliberately do not, so a tenant that brands on Starter and downgrades to Free can
| still delete what it created (ADR-0012 §D9's precedent). Making all four gates match would look like a
| tidy-up and would strand that tenant, so both directions are asserted.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** @return array{0: Tenant, 1: User} */
function brandingActor(string $role = 'admin', PlanTier $tier = PlanTier::Starter): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, $role);
    // RequireFeature FAILS OPEN when currentPlan() is null, so a 402 test that skips this would pass
    // vacuously — the standing trap this repo's tracker names explicitly.
    assignPlanTier($tier);

    return [$tenant, $user];
}

/**
 * A tiny (~70-byte) real 1×1 PNG.
 *
 * `UploadedFile::fake()->image()` is NOT usable here: it needs the GD extension, which this container
 * does not have, and the failure is a LogicException rather than a skip. Real bytes also mean
 * `getMimeType()` content-sniffs `image/png` for free — which is what the service actually checks, so
 * this fixture exercises the real gate rather than a declared header. Same device as
 * `h5bTinyImage()` in StorageQuotaEnforcementTest.
 */
function brandingLogoFile(string $name = 'logo.png'): UploadedFile
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

    return UploadedFile::fake()->createWithContent($name, $png);
}

const BRANDING_URL = 'http://acme.meridian.test/settings/branding';

it('lets an admin set a brand colour and stores the derived ramp', function (): void {
    [$tenant, $admin] = brandingActor();

    $this->actingAs($admin)->patch(BRANDING_URL, ['primary_color' => '#c0392b'])->assertRedirect();

    // Read through the query builder: the stancl custom-column store would otherwise mask a mis-store.
    $row = DB::table('tenants')->where('id', $tenant->id)->first();

    expect($row->primary_color)->toBe('#C0392B') // normalised to upper case by the generator
        ->and($row->brand_ramp)->not->toBeNull();

    $ramp = Tenant::findOrFail($tenant->id)->brandRamp();

    expect($ramp?->tokens['light'])->toHaveKeys(['bg', 'bg_hover', 'bg_active', 'fg', 'tint', 'ring'])
        ->and($ramp?->measurements)->toHaveCount(17);
});

it('refuses a malformed hex before anything is derived', function (string $bad): void {
    [$tenant, $admin] = brandingActor();

    $this->actingAs($admin)
        ->patch(BRANDING_URL, ['primary_color' => $bad])
        ->assertSessionHasErrors('primary_color');

    expect(DB::table('tenants')->where('id', $tenant->id)->value('brand_ramp'))->toBeNull();
})->with([
    'shorthand' => '#FFF',
    'no hash' => 'C0392B',
    'named colour' => 'rebeccapurple',
    'rgb function' => 'rgb(1,2,3)',
    // Eight-digit hex is refused rather than truncated: the engine derives twelve OPAQUE tokens, so an
    // alpha channel it would silently discard is better refused than accepted.
    'with alpha' => '#C0392B80',
]);

it('refuses a member without tenant.settings.manage', function (): void {
    [$tenant, $editor] = brandingActor(role: 'form_editor');

    $this->actingAs($editor)->patch(BRANDING_URL, ['primary_color' => '#C0392B'])->assertForbidden();

    expect(DB::table('tenants')->where('id', $tenant->id)->value('brand_ramp'))->toBeNull();
});

it('refuses the WRITE on a plan without the branding feature', function (): void {
    // A redirect + error toast, NOT the 402 the `/api/v1` twin returns. `bootstrap/app.php` renders a
    // FeatureGateException as `back()` on the web arm — the H14/H22b convention — because a session-authed
    // form post has somewhere to go back to and a JSON error envelope would render as a blank page.
    [$tenant, $admin] = brandingActor(tier: PlanTier::Free);

    $this->actingAs($admin)
        ->from('http://acme.meridian.test/settings')
        ->patch(BRANDING_URL, ['primary_color' => '#C0392B'])
        ->assertRedirect('http://acme.meridian.test/settings')
        ->assertSessionHas('toast.type', 'error');

    expect(DB::table('tenants')->where('id', $tenant->id)->value('brand_ramp'))->toBeNull();
});

it('ALLOWS the removal on a plan without the branding feature', function (): void {
    // ⚠️ THE ASYMMETRY, AND THE REASON THIS FILE EXISTS. A tenant brands on Starter, downgrades to Free,
    // and must still be able to delete what it created — ADR-0012 §D9's precedent, where a tenant
    // downgraded off Business keeps a resolving hostname and must retain a path to remove it. If a future
    // change "tidies" the four routes into matching gates, THIS is the test that goes red.
    [$tenant, $admin] = brandingActor();

    $this->actingAs($admin)->patch(BRANDING_URL, ['primary_color' => '#C0392B'])->assertRedirect();
    expect(DB::table('tenants')->where('id', $tenant->id)->value('brand_ramp'))->not->toBeNull();

    // AFTER AN HTTP REQUEST THE TENANT GUC IS TORN DOWN — re-enter before any tenant-scoped write, or
    // the `subscriptions` insert fails RLS with 42501. A standing trap in this repo, and it fires exactly
    // here: every downgrade-mid-test in this file needs this line.
    enterTenant($tenant->id, $admin->id);
    assignPlanTier(PlanTier::Free);

    $this->actingAs($admin)->delete(BRANDING_URL)->assertRedirect();

    $row = DB::table('tenants')->where('id', $tenant->id)->first();

    expect($row->brand_ramp)->toBeNull()->and($row->primary_color)->toBeNull();
});

it('stores a logo, points the tenant at it, and keeps it out of the data json store', function (): void {
    Storage::fake('local');
    [$tenant, $admin] = brandingActor();

    $this->actingAs($admin)
        ->post(BRANDING_URL.'/logo', ['logo' => brandingLogoFile()])
        ->assertRedirect();

    $logoId = DB::table('tenants')->where('id', $tenant->id)->value('logo_attachment_id');

    expect($logoId)->not->toBeNull();

    enterTenant($tenant->id, $admin->id);
    $attachment = Attachment::findOrFail($logoId);

    expect($attachment->kind->value)->toBe('branding_logo')
        ->and($attachment->attachable_type)->toBe('tenant')
        ->and($attachment->attachable_id)->toBe($tenant->id)
        ->and($attachment->is_pii)->toBeFalse()
        ->and($attachment->path)->toContain("tenants/{$tenant->id}/branding_logo/");
});

it('refuses an SVG logo', function (): void {
    // Not a limitation — a security boundary. An SVG is XML that can carry <script>, and a logo is served
    // same-origin to every respondent of every branded form: stored XSS (threat-model §6). The request
    // rule and the service's content-sniffed allowlist both refuse it; this asserts the outer one.
    Storage::fake('local');
    [$tenant, $admin] = brandingActor();

    $svg = UploadedFile::fake()->createWithContent(
        'logo.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    );

    $this->actingAs($admin)
        ->post(BRANDING_URL.'/logo', ['logo' => $svg])
        ->assertSessionHasErrors('logo');

    expect(DB::table('tenants')->where('id', $tenant->id)->value('logo_attachment_id'))->toBeNull();
});

it('ALLOWS the logo removal on a plan without the branding feature', function (): void {
    Storage::fake('local');
    [$tenant, $admin] = brandingActor();

    $this->actingAs($admin)
        ->post(BRANDING_URL.'/logo', ['logo' => brandingLogoFile()])
        ->assertRedirect();

    enterTenant($tenant->id, $admin->id); // the GUC is gone after the POST above — see the note earlier
    assignPlanTier(PlanTier::Free);

    $this->actingAs($admin)->delete(BRANDING_URL.'/logo')->assertRedirect();

    expect(DB::table('tenants')->where('id', $tenant->id)->value('logo_attachment_id'))->toBeNull();
});

it('renders the settings page with the branding prop, including the snap disclosure', function (): void {
    [, $admin] = brandingActor();

    $this->actingAs($admin)->patch(BRANDING_URL, ['primary_color' => '#FFE14D'])->assertRedirect();

    $this->actingAs($admin)
        ->get('http://acme.meridian.test/settings')
        ->assertInertia(function ($page): void {
            $page->has('branding')
                ->where('branding.can_manage', true)
                ->where('branding.has_brand_color', true)
                ->where('branding.is_active', true)
                ->where('branding.input_color', '#FFE14D')
                // A saturated yellow cannot carry white button text at its own lightness, so the engine
                // snaps it — and the card must be able to SAY so. If `adjusted` were ever computed
                // against the wrong token this would be the test that noticed.
                ->where('branding.snap.adjusted', true)
                ->where('branding.snap.input', '#FFE14D')
                ->has('branding.contrast.measurements', 17);
        });
});

it('reports a stored brand as INACTIVE once the plan no longer allows branding', function (): void {
    [$tenant, $admin] = brandingActor();

    $this->actingAs($admin)->patch(BRANDING_URL, ['primary_color' => '#C0392B'])->assertRedirect();

    enterTenant($tenant->id, $admin->id); // the GUC is gone after the PATCH — see the note earlier
    assignPlanTier(PlanTier::Free);

    $this->actingAs($admin)
        ->get('http://acme.meridian.test/settings')
        ->assertInertia(function ($page): void {
            // STORED and ACTIVE are different questions, and this is the state where they disagree. The
            // card reads both: it explains why the brand is dormant and still offers removal.
            $page->where('branding.has_brand_color', true)
                ->where('branding.is_active', false)
                ->where('branding.required_tier', PlanTier::Starter->value);
        });
});

it('hides the card from a member who cannot manage tenant settings', function (): void {
    [, $editor] = brandingActor(role: 'form_editor');

    $this->actingAs($editor)
        ->get('http://acme.meridian.test/settings')
        ->assertInertia(fn ($page) => $page->where('branding.can_manage', false));
});
