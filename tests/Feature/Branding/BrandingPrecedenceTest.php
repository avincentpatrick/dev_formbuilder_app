<?php

declare(strict_types=1);

use App\Enums\AccentToken;
use App\Enums\PlanTier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserUiPreference;
use App\Services\Branding\TenantBrandingService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment H23a3 — tenant branding reaches the admin app, and personalization still wins (ADR-0014).
|--------------------------------------------------------------------------
| PRECEDENCE IS RESOLVED ON THE SERVER, NOT IN CSS, and this file is what pins that.
|
| The CSS route was designed first and rejected: the tenant ramp needs a `:root` block, a
| `[data-theme-mode='dark']` block and a prefers-color-scheme twin — (0,1,0), (0,2,0), (0,3,0). The
| user's own `:root[data-accent='teal']` rule is (0,2,0), so it would TIE with the tenant's dark block
| and the winner would fall to source order. G11 already shipped that exact bug once.
|
| So the rule is: EMIT THE TENANT RAMP ONLY WHEN THE USER HAS NO ACCENT OPINION. Personalization cannot
| lose, because when it is present the thing it would have to beat was never emitted. Every branch of
| that rule is asserted below.
*/

beforeEach(function (): void {
    // A GET that renders the root view runs @vite, and the CI tests job builds no assets. Without this
    // these pass locally (public/build survives a dev build, gitignored) and fail in CI with a 500.
    $this->withoutVite();

    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * A signed-in admin on a tenant that is branded unless `$brand` is null.
 *
 * @return array{0: Tenant, 1: User}
 */
function precedenceActor(?string $brand = '#C0392B', PlanTier $tier = PlanTier::Starter): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'admin');
    assignPlanTier($tier);

    if ($brand !== null) {
        app(TenantBrandingService::class)->setBrandColor($tenant, $brand);
    }

    return [$tenant->refresh(), $user];
}

/** Set (or clear) the acting user's stored accent. NULL is a real value — "no opinion". */
function setAccent(User $user, ?AccentToken $accent): void
{
    UserUiPreference::updateOrCreate(
        ['user_id' => $user->id],
        ['accent_token' => $accent?->toColumn()],
    );
}

const DASHBOARD = 'http://acme.meridian.test/dashboard';

it('emits the tenant ramp when the member has expressed no accent opinion', function (): void {
    [, $admin] = precedenceActor();

    $html = $this->actingAs($admin)->get(DASHBOARD)->assertOk()->getContent();

    expect($html)->toContain('id="tenant-brand"')
        ->and($html)->toContain('--mds-color-action-primary-bg:')
        // No data-accent attribute: "no opinion" is still the absence of the attribute (§2.9's
        // convention survives), and the brand arrives through the style block instead.
        ->and($html)->not->toContain('data-accent=');
});

it('WITHHOLDS the tenant ramp when the member explicitly chooses Blueprint', function (): void {
    // ⚠️ THE ESCAPE HATCH. This is the whole reason `accent_token` widened from {NULL,'teal'} to
    // {NULL,'blueprint','teal'} in H23a3. Before it, Blueprint was STORED as NULL and so was "no
    // opinion" — leaving a member of a branded tenant with no way to ask for the product blue back.
    //
    // Note there is deliberately NO `:root[data-accent='blueprint']` rule anywhere: withholding the
    // tenant block is sufficient, because the base tokens ARE Blueprint.
    [, $admin] = precedenceActor();
    setAccent($admin, AccentToken::Blueprint);

    $html = $this->actingAs($admin)->get(DASHBOARD)->assertOk()->getContent();

    expect($html)->not->toContain('id="tenant-brand"')
        ->and($html)->not->toContain('data-accent=');
});

it('WITHHOLDS the tenant ramp when the member chooses Teal, and emits the attribute instead', function (): void {
    // Personalization wins, and it wins WITHOUT a specificity contest: teal's own (0,2,0) rules apply
    // over the base tokens exactly as they did before this increment, because the block they would have
    // had to outrank was never rendered.
    [, $admin] = precedenceActor();
    setAccent($admin, AccentToken::Teal);

    $html = $this->actingAs($admin)->get(DASHBOARD)->assertOk()->getContent();

    expect($html)->not->toContain('id="tenant-brand"')
        ->and($html)->toContain('data-accent="teal"');
});

it('emits nothing on a tenant that has never set a brand', function (): void {
    [, $admin] = precedenceActor(brand: null);

    $html = $this->actingAs($admin)->get(DASHBOARD)->assertOk()->getContent();

    expect($html)->not->toContain('id="tenant-brand"');
});

it('WITHHOLDS the ramp when the plan no longer includes branding, even though it is stored', function (): void {
    // STORED is not ACTIVE. A surface that read Tenant::hasBrandRamp() instead of
    // TenantBrandingService::isActive() would ship a Starter+ feature to a Free tenant, and nothing else
    // in the build would notice. This is the test that notices.
    [$tenant, $admin] = precedenceActor();

    enterTenant($tenant->id, $admin->id); // the GUC is torn down after any request; re-enter before writing
    assignPlanTier(PlanTier::Free);

    $html = $this->actingAs($admin)->get(DASHBOARD)->assertOk()->getContent();

    expect($tenant->refresh()->hasBrandRamp())->toBeTrue()  // still stored …
        ->and($html)->not->toContain('id="tenant-brand"');  // … and deliberately not rendered
});

it('repaints only the six action tokens, never a neutral, semantic or chart one', function (): void {
    // ADR-0014 §D7, inheriting §D11's rule that a data series must look the same to two colleagues
    // reading one screenshot. A regression here would be invisible to every other test in the suite.
    [, $admin] = precedenceActor();

    $html = $this->actingAs($admin)->get(DASHBOARD)->assertOk()->getContent();

    preg_match('/<style id="tenant-brand">(.*?)<\/style>/s', $html, $matches);
    $block = $matches[1] ?? '';

    expect($block)->not->toBe('');

    preg_match_all('/(--mds-[a-z0-9-]+):/', $block, $props);
    $declared = array_values(array_unique($props[1]));
    sort($declared);

    expect($declared)->toBe([
        '--mds-color-action-primary-bg',
        '--mds-color-action-primary-bg-active',
        '--mds-color-action-primary-bg-hover',
        '--mds-color-action-primary-fg',
        '--mds-color-action-primary-tint',
        '--mds-color-focus-ring',
    ]);
});

it('covers both themes and the prefers-color-scheme twin', function (): void {
    // Three blocks, not one: a selector cannot carry a media condition, so "system dark" needs its own
    // copy — the same duplication theme-overrides.css carries, and for the same reason.
    [, $admin] = precedenceActor();

    $html = $this->actingAs($admin)->get(DASHBOARD)->assertOk()->getContent();

    preg_match('/<style id="tenant-brand">(.*?)<\/style>/s', $html, $matches);
    $block = $matches[1];

    expect($block)->toContain(':root {')
        ->and($block)->toContain(":root[data-theme-mode='dark']")
        ->and($block)->toContain('@media (prefers-color-scheme: dark)')
        ->and($block)->toContain(":root:not([data-theme-mode='light']):not([data-theme-mode='dark'])");
});

it('stores Blueprint explicitly rather than as NULL', function (): void {
    // The column-semantics change itself. `toColumn()` used to map Blueprint to NULL, which is what made
    // "no opinion" and "the product default" the same row.
    expect(AccentToken::Blueprint->toColumn())->toBe('blueprint')
        ->and(AccentToken::Teal->toColumn())->toBe('teal');
});

it('accepts an explicit null on the appearance write and stores it', function (): void {
    [$tenant, $admin] = precedenceActor();
    setAccent($admin, AccentToken::Teal);

    $this->actingAs($admin)
        ->patch('http://acme.meridian.test/settings/appearance', ['accent_token' => null])
        ->assertRedirect();

    // RE-ENTER BEFORE READING, and this one is not optional bookkeeping: `user_ui_preferences` is
    // RLS-scoped to `app.current_user_id`, which the request tore down. Without this the row is
    // INVISIBLE rather than absent — and an earlier draft of this test passed VACUOUSLY for exactly that
    // reason, asserting toBeNull() against a row it simply could not see. The sibling test below is what
    // exposed it, by needing the row to still hold its other axes.
    enterTenant($tenant->id, $admin->id);

    $row = UserUiPreference::where('user_id', $admin->id)->firstOrFail();

    expect($row->accent_token)->toBeNull();
});

it('leaves the other appearance axes untouched when only the accent is sent', function (): void {
    // The partial-write guarantee UpdateAppearanceRequest exists for. Widening the accent rule to
    // `nullable` must not have widened it into a full-payload write.
    [$tenant, $admin] = precedenceActor();
    UserUiPreference::updateOrCreate(
        ['user_id' => $admin->id],
        ['accent_token' => 'teal', 'font_size_scale' => 'large', 'use_dyslexia_friendly_font' => true],
    );

    $this->actingAs($admin)
        ->patch('http://acme.meridian.test/settings/appearance', ['accent_token' => null])
        ->assertRedirect();

    enterTenant($tenant->id, $admin->id); // see the note above — the row is RLS-invisible without this

    $row = UserUiPreference::where('user_id', $admin->id)->firstOrFail();

    expect($row->accent_token)->toBeNull()
        ->and($row->font_size_scale->value)->toBe('large')
        ->and($row->use_dyslexia_friendly_font)->toBeTrue();
});
