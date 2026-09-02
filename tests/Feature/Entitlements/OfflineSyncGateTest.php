<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\Form;
use App\Models\LegacyOverride;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// offline_sync gating (H5c) — the "installable PWA / offline collection" feature. Gated at the offline ENTRY
// points: the guest PWA manifest (installability) and the authenticated sync/manifest (schema fetch). The
// online guest form + submission stay never-block, and so does the authenticated sync/submissions replay —
// already-collected data is always accepted (ADR-0008 §D4).

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function offlineGateTenant(): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'admin');

    return [$tenant, $owner];
}

/** A published, guest-enabled form at /f/intake. Requires enterTenant already called. */
function offlineGatePublishedForm(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Intake');
    addFormField($form->draftVersion, $owner, 'full_name');
    app(PublishService::class)->publish($form->refresh(), $owner);
    $form->update(['public_slug' => 'intake', 'allow_guest_submissions' => true]);

    return $form->refresh();
}

it('404s the guest PWA manifest for a plan without offline_sync', function (): void {
    [$tenant, $owner] = offlineGateTenant();
    offlineGatePublishedForm($tenant, $owner);
    assignPlanTier(PlanTier::Free); // offline_sync = false

    $this->get('http://acme.meridian.test/f/intake/manifest.webmanifest')->assertNotFound();
});

it('serves the guest PWA manifest for a Starter tenant', function (): void {
    [$tenant, $owner] = offlineGateTenant();
    offlineGatePublishedForm($tenant, $owner);
    assignPlanTier(PlanTier::Starter);

    $this->get('http://acme.meridian.test/f/intake/manifest.webmanifest')
        ->assertOk()
        ->assertJsonPath('name', 'Intake');
});

it('grandfathers the guest PWA manifest for a Free tenant via a legacy override', function (): void {
    [$tenant, $owner] = offlineGateTenant();
    offlineGatePublishedForm($tenant, $owner);
    assignPlanTier(PlanTier::Free);
    LegacyOverride::create(['feature_flags' => ['offline_sync' => true]]);
    app(EntitlementService::class)->forget();

    $this->get('http://acme.meridian.test/f/intake/manifest.webmanifest')->assertOk();
});

it('402s the authenticated sync/manifest when the plan lacks offline_sync', function (): void {
    [, $owner] = offlineGateTenant();
    // api_access present so the request clears the Group-B gate, but offline_sync absent.
    $plan = Plan::factory()->tier(PlanTier::Starter)->withFeatures(['api_access' => true])->create();
    Subscription::factory()->forPlan($plan)->create();
    app(EntitlementService::class)->forget();
    $token = $owner->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/sync/manifest?form_version_id='.Uuid::uuid7()->toString())
        ->assertStatus(402)
        ->assertJsonPath('error.details.feature', 'offline_sync');
});

it('does not offline_sync-gate the sync/submissions replay (never-block)', function (): void {
    [, $owner] = offlineGateTenant();
    $plan = Plan::factory()->tier(PlanTier::Starter)->withFeatures(['api_access' => true])->create();
    Subscription::factory()->forPlan($plan)->create();
    app(EntitlementService::class)->forget();
    // A write token so ability isn't the blocker; the point is offline_sync must NOT 402 the replay.
    $token = $owner->createToken('ci', [ApiAbilities::WRITE_SUBMISSIONS])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/sync/submissions', ['submissions' => []]);

    // Whatever the controller makes of an empty batch, it must not be a 402 offline_sync refusal.
    expect($response->status())->not->toBe(402);
});

// ── M61: the entitlement gate is the fourth 404 on this route, so it needs the same oracle guard ──
//
// The other three gates are guarded in PwaManifestTest. This one lives here because this file owns the
// plan-catalog fixture, and duplicating that fixture is exactly how two files come to disagree about what
// a Free tenant is entitled to.

it('404s — never 301s — a mixed-case manifest URL for a plan without offline_sync', function (): void {
    [$tenant, $owner] = offlineGateTenant();
    offlineGatePublishedForm($tenant, $owner);
    assignPlanTier(PlanTier::Free); // offline_sync = false

    $this->get('http://acme.meridian.test/f/InTaKe/manifest.webmanifest')
        ->assertNotFound()
        ->assertHeaderMissing('Location');
});

it('serves a mixed-case manifest URL for a Starter tenant, entitlement satisfied', function (): void {
    [$tenant, $owner] = offlineGateTenant();
    offlineGatePublishedForm($tenant, $owner);
    assignPlanTier(PlanTier::Starter);

    // The positive half. Without it the case above passes just as well under a lookup that resolves
    // nothing at all, because "mis-cased and unentitled" and "mis-cased and unfindable" are both 404.
    $this->get('http://acme.meridian.test/f/InTaKe/manifest.webmanifest')
        ->assertOk()
        ->assertJsonPath('scope', '/f/intake');
});
