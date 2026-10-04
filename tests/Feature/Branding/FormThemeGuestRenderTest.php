<?php

declare(strict_types=1);

use App\Enums\FormThemePreset;
use App\Enums\PlanTier;
use App\Enums\RequiredMode;
use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Branding\TenantBrandingService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Guest\GuestShareTokenService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| A form's preset theme on the PUBLIC GUEST RUNTIME (M131, `R-6017d6d8`, `D65` = A, `D81`).
|--------------------------------------------------------------------------
| `GuestBrandingPresenter` stays the single reader: the shell's style block, its theme-color meta and the
| per-form manifest all read one answer, so a form's preset paints all three or none. A preset REPLACES the
| workspace block on its own form (`form-theme`, not `tenant-brand`), adds only its documented lines, and is
| offered on every plan (`D81`) — a Free workspace's own brand stays withheld while its presets render.
|
| ⚠️ THE DEVICE-WIDE FINGERPRINT STAYS THE WORKSPACE'S. `data-brand-version` keys ONE IndexedDB entry per device
| (`lib/brand-cache.ts`), so folding a per-form preset into it would make a respondent switching between two
| forms re-sweep every cached shell on every visit. The manifest link's `?b=` is per form instead.
*/

beforeEach(function (): void {
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
 * A published guest form at /f/{slug}, optionally themed, in the `acme` workspace.
 *
 * @return array{0: Tenant, 1: User, 2: Form}
 */
function themedGuestForm(string $slug, ?FormThemePreset $preset, ?Tenant $tenant = null, ?User $owner = null): array
{
    if ($tenant === null) {
        $tenant = inboxTenant();
        $owner = User::factory()->create();
        enterTenant($tenant->id, $owner->id);
        makeActiveMember($owner, 'admin');
        assignPlanTier(PlanTier::Starter);
    }

    /** @var User $owner */
    $form = app(FormService::class)->create($tenant, $owner, ucfirst($slug));
    addFormField($form->draftVersion, $owner, 'full_name', sequence: 0, extra: ['is_required' => RequiredMode::Required]);
    app(PublishService::class)->publish($form->refresh(), $owner);
    $form->update(['public_slug' => $slug, 'allow_guest_submissions' => true]);

    if ($preset !== null) {
        app(FormService::class)->setTheme($form->refresh(), $preset, $owner);
    }

    return [$tenant, $owner, $form->refresh()];
}

/** The `<style id="…">` body, or '' when none was emitted. */
function themeStyleBlock(string $html, string $id): string
{
    preg_match('/<style id="'.preg_quote($id, '/').'">(.*?)<\/style>/s', $html, $matches);

    return $matches[1] ?? '';
}

function themeMeta(string $html): ?string
{
    preg_match('/<meta name="theme-color" content="([^"]*)">/', $html, $matches);

    return $matches[1] ?? null;
}

function themeManifestParam(string $html): ?string
{
    preg_match('/<link rel="manifest" href="[^"]*\?b=([^"]*)">/', $html, $matches);

    return $matches[1] ?? null;
}

function themeMountVersion(string $html): ?string
{
    preg_match('/data-brand-version="([^"]*)"/', $html, $matches);

    return $matches[1] ?? null;
}

it('paints the shell with the preset: the six colour roles and the documented lines, in place of the workspace block', function (): void {
    [$tenant] = themedGuestForm('intake', FormThemePreset::Graphite);
    app(TenantBrandingService::class)->setBrandColor($tenant, '#B3261E');

    $html = $this->get('http://acme.meridian.test/f/intake')->assertOk()->getContent();
    $block = themeStyleBlock($html, 'form-theme');
    $tokens = FormThemePreset::Graphite->tokens();

    expect($html)->not->toContain('id="tenant-brand"')
        ->and($block)->toContain('--mds-color-action-primary-bg: '.$tokens['light']['bg'].';')
        ->and($block)->toContain('--mds-color-action-primary-bg: '.$tokens['dark']['bg'].';')
        // The font stack arrives verbatim: an escaped quote inside <style> would be a different font name.
        ->and($block)->toContain('--mds-font-family-display: Charter, "Bitstream Charter", "Sitka Text", Cambria, serif;')
        ->and($block)->toContain('--mds-radius-xl: 12px;');

    // Exactly the six plus the preset's own documented lines, and nothing else.
    preg_match_all('/(--mds-[a-z0-9-]+):/', $block, $props);
    $declared = array_values(array_unique($props[1]));
    sort($declared);

    $expected = [
        '--mds-color-action-primary-bg', '--mds-color-action-primary-bg-active', '--mds-color-action-primary-bg-hover',
        '--mds-color-action-primary-fg', '--mds-color-action-primary-tint', '--mds-color-focus-ring',
        ...array_keys(FormThemePreset::Graphite->lines()),
    ];
    sort($expected);

    expect($declared)->toBe($expected);
});

it('takes theme-color from the preset, and the manifest agrees with the shell', function (): void {
    themedGuestForm('intake', FormThemePreset::Forest);
    $expected = FormThemePreset::Forest->tokens()['light']['bg'];

    $html = $this->get('http://acme.meridian.test/f/intake')->assertOk()->getContent();

    expect(themeMeta($html))->toBe($expected);

    $this->get('http://acme.meridian.test/f/intake/manifest.webmanifest')
        ->assertOk()
        ->assertJsonPath('theme_color', $expected);
});

it('carries the preset through the save-and-resume shell', function (): void {
    [$tenant, , $form] = themedGuestForm('intake', FormThemePreset::Plum);

    $resume = app(GuestShareTokenService::class)->mintResume(
        $tenant->id,
        $form->id,
        (string) $form->current_published_version_id,
        Uuid::uuid7()->toString(),
    )->token;

    $html = $this->get('http://acme.meridian.test/f/resume/'.$resume)->assertOk()->getContent();

    expect(themeStyleBlock($html, 'form-theme'))->toContain(FormThemePreset::Plum->tokens()['light']['bg'])
        ->and(themeMeta($html))->toBe(FormThemePreset::Plum->tokens()['light']['bg']);
});

it('keeps the device-wide fingerprint the workspace\'s, while the manifest link moves per form', function (): void {
    [$tenant, $owner] = themedGuestForm('plain', null);
    app(TenantBrandingService::class)->setBrandColor($tenant, '#B3261E');
    themedGuestForm('themed', FormThemePreset::Lagoon, $tenant, $owner);
    themedGuestForm('other', FormThemePreset::Rosewood, $tenant, $owner);

    $plain = $this->get('http://acme.meridian.test/f/plain')->assertOk()->getContent();
    $themed = $this->get('http://acme.meridian.test/f/themed')->assertOk()->getContent();
    $other = $this->get('http://acme.meridian.test/f/other')->assertOk()->getContent();

    // One fingerprint for every shell of the workspace, or switching forms re-sweeps the device's cache.
    expect(themeMountVersion($themed))->toBe(themeMountVersion($plain))
        ->and(themeMountVersion($other))->toBe(themeMountVersion($plain))
        // …while each form's manifest is cache-busted by what that form actually renders.
        ->and(themeManifestParam($plain))->toBe(themeMountVersion($plain))
        ->and(themeManifestParam($themed))->not->toBe(themeManifestParam($plain))
        ->and(themeManifestParam($themed))->not->toBe(themeManifestParam($other))
        ->and(themeManifestParam($themed))->toMatch('/^[0-9a-f]{12}$/');
});

it('renders a preset on a Free workspace, whose own brand stays withheld', function (): void {
    [$tenant, $owner] = themedGuestForm('themed', FormThemePreset::Forest);
    themedGuestForm('plain', null, $tenant, $owner);
    app(TenantBrandingService::class)->setBrandColor($tenant, '#B3261E');

    enterTenant($tenant->id);
    assignPlanTier(PlanTier::Free);

    $themed = $this->get('http://acme.meridian.test/f/themed')->assertOk()->getContent();
    $plain = $this->get('http://acme.meridian.test/f/plain')->assertOk()->getContent();

    expect(themeStyleBlock($themed, 'form-theme'))->toContain(FormThemePreset::Forest->tokens()['light']['bg'])
        ->and($plain)->not->toContain('id="tenant-brand"')
        ->and(themeMountVersion($plain))->toBe('none');
});
