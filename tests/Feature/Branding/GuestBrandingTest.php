<?php

declare(strict_types=1);

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
| Increment H23b — tenant branding reaches the PUBLIC GUEST RUNTIME (ADR-0014).
|--------------------------------------------------------------------------
| The respondent-facing half of the branding vertical, and the layer boundary is what this file is
| really about. H23a3 proved that a MEMBER's personalization beats the tenant's brand; here there is no
| personalization layer at all (a respondent has no preferences), so the tenant ramp is emitted
| unconditionally whenever it is ACTIVE — and "active" is the word doing the work: STORED is a different
| question, answered differently the moment a tenant downgrades.
|
| Three surfaces read one presenter, and they must agree: the shell's <style> block, the shell's
| <meta name="theme-color"> and the per-form web manifest's `theme_color`. A shell painted in one brand
| linking a manifest cache-busted for another is a state with no honest reading, so the fingerprint that
| ties them together is asserted directly.
*/

beforeEach(function (): void {
    // Every case here renders the guest SHELL, and the CI Pest job builds no assets — without this,
    // @vite throws "Vite manifest not found" and the GET 500s. Passes locally either way, which is
    // exactly what makes it a CI-only trap.
    $this->withoutVite();

    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** The brand hue every case here uses; nothing depends on the value beyond it being non-grey. */
const GUEST_BRAND_HEX = '#C0392B';

/** `--mds-primary-600` — the light `--mds-color-action-primary-bg` an UNBRANDED guest runtime paints. */
const UNBRANDED_THEME_COLOR = '#1C4B72';

/**
 * A guest-reachable published form on a tenant that is branded unless `$brand` is null.
 *
 * Helper names are prefixed rather than shared with GuestRuntimeTest / PwaManifestTest: a top-level
 * function in a test file is declared globally, and two files declaring one name is a fatal on a
 * full-suite run. (The H22a lesson in tests/Pest.php's header is the other half of the same rule.)
 *
 * @return array{0: Tenant, 1: User, 2: Form}
 */
function brandedGuestForm(?string $brand = GUEST_BRAND_HEX, PlanTier $tier = PlanTier::Starter): array
{
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'admin');
    assignPlanTier($tier);

    $form = app(FormService::class)->create($tenant, $owner, 'Intake');
    addFormField($form->draftVersion, $owner, 'full_name', sequence: 0, extra: ['is_required' => RequiredMode::Required]);
    app(PublishService::class)->publish($form->refresh(), $owner);
    $form->update(['public_slug' => 'intake', 'allow_guest_submissions' => true]);

    if ($brand !== null) {
        app(TenantBrandingService::class)->setBrandColor($tenant, $brand);
    }

    return [$tenant->refresh(), $owner, $form->refresh()];
}

/** The tenant's stored ramp, read the way the shell reads it. */
function guestRampTokens(Tenant $tenant): array
{
    return $tenant->brandRamp()?->tokens ?? [];
}

/** The guest shell HTML at /f/intake. */
function guestShell(): string
{
    return test()->get('http://acme.meridian.test/f/intake')->assertOk()->getContent();
}

/** The `<style id="tenant-brand">` body, or '' when none was emitted. */
function brandStyleBlock(string $html): string
{
    preg_match('/<style id="tenant-brand">(.*?)<\/style>/s', $html, $matches);

    return $matches[1] ?? '';
}

/** The value of a `<meta name="…">` tag. */
function metaContent(string $html, string $name): ?string
{
    preg_match('/<meta name="'.preg_quote($name, '/').'" content="([^"]*)">/', $html, $matches);

    return $matches[1] ?? null;
}

/** The `?b=` fingerprint off the manifest link. */
function manifestBrandParam(string $html): ?string
{
    preg_match('/<link rel="manifest" href="[^"]*\?b=([^"]*)">/', $html, $matches);

    return $matches[1] ?? null;
}

// ── The branded path ─────────────────────────────────────────────────────────────────────────

it('paints the guest shell with the tenant ramp', function (): void {
    [$tenant] = brandedGuestForm();
    $tokens = guestRampTokens($tenant);

    $html = guestShell();

    expect(brandStyleBlock($html))->toContain('--mds-color-action-primary-bg: '.$tokens['light']['bg'].';')
        ->and(brandStyleBlock($html))->toContain('--mds-color-focus-ring: '.$tokens['light']['ring'].';')
        ->and(brandStyleBlock($html))->toContain('--mds-color-action-primary-bg: '.$tokens['dark']['bg'].';');
});

it('takes theme-color from the ramp, and the manifest agrees with the shell', function (): void {
    // The three surfaces read ONE presenter precisely so this assertion can be made. If they each
    // derived their own answer, the tab strip, the installed splash screen and the page could drift
    // apart and every one of them would look individually correct.
    [$tenant] = brandedGuestForm();
    $expected = guestRampTokens($tenant)['light']['bg'];

    $html = guestShell();

    expect(metaContent($html, 'theme-color'))->toBe($expected);

    $this->get('http://acme.meridian.test/f/intake/manifest.webmanifest')
        ->assertOk()
        ->assertJsonPath('theme_color', $expected);
});

it('emits a 12-hex brand fingerprint on the mount node and the manifest link', function (): void {
    brandedGuestForm();

    $html = guestShell();
    $param = manifestBrandParam($html);

    expect($param)->toMatch('/^[0-9a-f]{12}$/')
        ->and($html)->toContain('data-brand-version="'.$param.'"');
});

it('derives the fingerprint from the rendered ramp, and holds it steady across renders', function (): void {
    // The whole offline-invalidation mechanism rests on this: a fingerprint that moved on every render
    // would re-prime every device's caches on every page load, and one that never moved would invalidate
    // nothing. Both halves are pinned here.
    //
    // The change half is asserted as the DERIVATION rather than by re-branding mid-test and re-rendering,
    // and that is not a shortcut. Within one Pest test the container keeps the Tenant instance bound by
    // the FIRST request — stancl's `Tenancy::initialize()` early-returns when the tenant key is unchanged
    // (Tenancy.php:43), so nothing rebinds — and a second render would therefore report the OLD ramp. A
    // test written the obvious way would fail while the product was correct. Pinning the formula is the
    // stronger assertion anyway: "a different ramp yields a different fingerprint" then follows from
    // sha256 rather than from one sampled pair.
    [$tenant] = brandedGuestForm();
    $expected = substr(hash('sha256', json_encode(guestRampTokens($tenant), JSON_THROW_ON_ERROR)), 0, 12);

    expect(manifestBrandParam(guestShell()))->toBe($expected)
        ->and(manifestBrandParam(guestShell()))->toBe($expected);
});

it('repaints only the six action tokens on the guest surface too', function (): void {
    // ADR-0014 §D7 on a surface H23a3's own test cannot see. A neutral or chart token leaking into the
    // guest ramp would be invisible to every other test in the suite.
    brandedGuestForm();

    preg_match_all('/(--mds-[a-z0-9-]+):/', brandStyleBlock(guestShell()), $props);
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

it('carries the same branding through the save-and-resume shell', function (): void {
    // The SECOND shell-rendering call site, and therefore the one that drifts. H9b's resume entry
    // renders the same view from a different action; a brand wired into only one of them would look
    // completely correct until a respondent followed an emailed link.
    [$tenant, $owner, $form] = brandedGuestForm();
    $expected = guestRampTokens($tenant)['light']['bg'];

    $resume = app(GuestShareTokenService::class)->mintResume(
        $tenant->id,
        $form->id,
        (string) $form->current_published_version_id,
        Uuid::uuid7()->toString(),
    )->token;

    $html = $this->get('http://acme.meridian.test/f/resume/'.$resume)->assertOk()->getContent();

    expect(brandStyleBlock($html))->toContain($expected)
        ->and(metaContent($html, 'theme-color'))->toBe($expected)
        ->and(manifestBrandParam($html))->toMatch('/^[0-9a-f]{12}$/');
});

// ── The unbranded paths ──────────────────────────────────────────────────────────────────────

it('WITHHOLDS the ramp when the plan no longer includes branding, even though it is stored', function (): void {
    // STORED is not ACTIVE, and this is the regression nothing else in the build would catch: a surface
    // reading Tenant::hasBrandRamp() instead of TenantBrandingService::isActive() ships a Starter+
    // feature to every Free tenant, with a green suite and a correct-looking page.
    [$tenant] = brandedGuestForm();

    enterTenant($tenant->id);
    assignPlanTier(PlanTier::Free);

    $html = guestShell();

    expect($tenant->refresh()->hasBrandRamp())->toBeTrue()          // still stored …
        ->and($html)->not->toContain('id="tenant-brand"')            // … and deliberately not rendered
        ->and(metaContent($html, 'theme-color'))->toBe(UNBRANDED_THEME_COLOR)
        ->and(manifestBrandParam($html))->toBe('none');
});

it('emits nothing branded on a tenant that has never set a brand', function (): void {
    brandedGuestForm(brand: null);

    $html = guestShell();

    expect($html)->not->toContain('id="tenant-brand"')
        ->and(metaContent($html, 'theme-color'))->toBe(UNBRANDED_THEME_COLOR)
        ->and(manifestBrandParam($html))->toBe('none')
        ->and($html)->toContain('data-brand-version="none"');
});

it('serves the product default theme colour on an unbranded manifest', function (): void {
    // The H23b correction, pinned. This was --mds-accent-teal-600 (#1B5E5E) from G8a until now, on a
    // surface that has never emitted data-accent and therefore never rendered teal — the installed app's
    // title bar and splash were a colour appearing nowhere in the form. `theme-color` now means one
    // thing in both cases: the light --mds-color-action-primary-bg.
    brandedGuestForm(brand: null);

    $this->get('http://acme.meridian.test/f/intake/manifest.webmanifest')
        ->assertOk()
        ->assertJsonPath('theme_color', UNBRANDED_THEME_COLOR);
});
