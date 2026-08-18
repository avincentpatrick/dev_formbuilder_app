<?php

declare(strict_types=1);

use App\Enums\SettingKey;
use App\Models\Audit;
use App\Models\Setting;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The tenant App Settings surface (Increment I5, PRD Feature #10) — Access / Maintenance / Modules.
|
| THE LOAD-BEARING ASSERTION IN THIS FILE is the audit key: `auditable_type = 'settings'` with
| `auditable_id = tenants.id`, NEVER the settings row's own uuid. I2 pinned that rule while this table was
| still hypothetical (audit spec §1, data-dictionary §13), precisely so I5 would not re-key and split one
| tenant's settings history across a dozen ids — unqueryable by `audits_tenant_auditable_idx`, which is the
| index the viewer's "this resource's history" filter rides on, and orphaning every row I2 already wrote.
| The test asserts the id positively AND asserts it is not the row uuid, the AuditDomainSitesTest device:
| re-key the service and this reddens with a concrete diff rather than a soft "some audit exists".
|
| TRAPS PAID FOR HERE: `withoutVite()` on the /settings GET; the tenant GUC dies with every HTTP request,
| so every post-request DB assertion re-`enterTenant()`s first (an RLS-invisible row reads as ABSENT, so
| forgetting it turns a passing assertion into a false negative and vice versa).
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

function appSettingsUrl(string $path = ''): string
{
    return 'http://acme.meridian.test/settings'.$path;
}

it('renders the four App Settings panels with resolved values', function (): void {
    $this->withoutVite();

    $this->actingAs($this->owner)->get(appSettingsUrl())->assertOk()->assertInertia(fn ($page) => $page
        ->component('Settings/Index', false)
        ->where('appSettings.can_manage', true)
        // No rows exist yet: the sparse table's default has to reach the wire, not a null.
        ->where('appSettings.access.invite_only', true)
        ->where('appSettings.access.signup_host', 'acme.meridian.test')
        ->where('appSettings.maintenance.enabled', false)
        ->where('appSettings.maintenance.message', null)
        // 11 → 12 in K1a: `gamification` joins ToggleableModules::KEYS. The count is pinned deliberately
        // (a module silently appearing or vanishing from this card is a capability nobody decided to
        // offer), so moving it is part of adding a key — this assertion is what caught the addition.
        ->has('appSettings.modules', 12)
        ->where('appSettings.modules.0.key', 'ocr_single')
        ->where('appSettings.modules.0.enabled', true)
        ->has('appSettings.about.version')
    );
});

it('hides the three controls from a member without tenant.settings.manage, but not About', function (): void {
    $viewer = apiMember('viewer');
    $this->withoutVite();

    $this->actingAs($viewer)->get(appSettingsUrl())->assertOk()->assertInertia(fn ($page) => $page
        ->component('Settings/Index', false)
        ->where('appSettings.can_manage', false)
        // About is deliberately outside the gate — the person reporting a bug is rarely the Owner.
        ->has('appSettings.about.version')
    );
});

it('writes the access setting and audits it on the TENANT id, never the settings row id', function (): void {
    $this->actingAs($this->owner)
        ->patch(appSettingsUrl('/access'), ['invite_only' => false])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->owner->id);

    $row = Setting::query()->where('key', SettingKey::RegistrationInviteOnly->value)->firstOrFail();
    expect(json_decode((string) $row->value, true))->toBeFalse();
    expect($row->updated_by)->toBe($this->owner->id);

    $audit = Audit::query()->where('auditable_type', 'settings')->latest('id')->firstOrFail();
    expect($audit->auditable_id)->toBe($this->tenant->id);
    // The pinned rule, stated as a refusal as well as as an expectation — this is what reddens if a later
    // session "tidies" the key onto the row it describes.
    expect($audit->auditable_id)->not->toBe($row->id);
    expect($audit->user_id)->toBe($this->owner->id);
    // Resolved before/after, so the ledger reads "true → false" rather than "(absent) → false".
    expect($audit->old_values)->toBe(['registration.invite_only' => true]);
    expect($audit->new_values)->toBe(['registration.invite_only' => false]);
});

it('writes maintenance mode to the TENANT COLUMNS, not to a settings row', function (): void {
    $this->actingAs($this->owner)
        ->patch(appSettingsUrl('/maintenance'), [
            'maintenance_mode' => true,
            'maintenance_message' => 'Back at 09:00.',
        ])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->owner->id);

    $this->tenant->refresh();
    expect($this->tenant->isUnderMaintenance())->toBeTrue();
    expect($this->tenant->maintenanceNotice())->toBe('Back at 09:00.');
    // The column IS the store for this one — a settings row would mean an extra query on every guest render.
    expect(Setting::query()->where('key', 'maintenance.enabled')->exists())->toBeFalse();

    $audit = Audit::query()->where('auditable_type', 'settings')->latest('id')->firstOrFail();
    expect($audit->auditable_id)->toBe($this->tenant->id);
});

it('falls back to the product notice when the message is cleared', function (): void {
    $this->actingAs($this->owner)
        ->patch(appSettingsUrl('/maintenance'), ['maintenance_mode' => true, 'maintenance_message' => '  '])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->owner->id);

    $this->tenant->refresh();
    expect($this->tenant->maintenance_message)->toBeNull();
    expect($this->tenant->maintenanceNotice())->toContain('temporarily unavailable');
});

it('rejects a maintenance write that omits either half of the one decision', function (): void {
    $this->actingAs($this->owner)
        ->patch(appSettingsUrl('/maintenance'), ['maintenance_mode' => true])
        ->assertSessionHasErrors('maintenance_message');

    $this->actingAs($this->owner)
        ->patch(appSettingsUrl('/maintenance'), ['maintenance_message' => 'x'])
        ->assertSessionHasErrors('maintenance_mode');
});

it('rejects a module key the surface does not offer', function (): void {
    $this->actingAs($this->owner)
        ->patch(appSettingsUrl('/modules'), ['module' => 'sso_saml', 'enabled' => false])
        ->assertSessionHasErrors('module');

    $this->actingAs($this->owner)
        ->patch(appSettingsUrl('/modules'), ['module' => 'not_a_module', 'enabled' => false])
        ->assertSessionHasErrors('module');
});

it('refuses all three writes for a member without tenant.settings.manage', function (): void {
    $viewer = apiMember('viewer');

    $this->actingAs($viewer)->patch(appSettingsUrl('/access'), ['invite_only' => false])->assertForbidden();
    $this->actingAs($viewer)->patch(appSettingsUrl('/maintenance'), [
        'maintenance_mode' => true,
        'maintenance_message' => '',
    ])->assertForbidden();
    $this->actingAs($viewer)->patch(appSettingsUrl('/modules'), [
        'module' => 'webhooks',
        'enabled' => false,
    ])->assertForbidden();

    enterTenant($this->tenant->id, $viewer->id);
    expect(Setting::query()->count())->toBe(0);
});
