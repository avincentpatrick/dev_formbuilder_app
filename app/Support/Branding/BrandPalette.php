<?php

declare(strict_types=1);

namespace App\Support\Branding;

use App\Jobs\TenantAwareJob;
use App\Models\Tenant;
use App\Services\Branding\TenantBrandingService;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantUrl;

/**
 * The brand as MAIL AND PDF need it: a flat, string-only, light-theme-only array (H23a4, ADR-0014 §D8).
 *
 * ── WHY THIS EXISTS AT ALL, AND WHY IT IS NOT `TenantBrandingService::sharedRamp()` ─────────────
 * §D8 stores the ramp because three of the five consuming surfaces cannot resolve a CSS custom property:
 * mail clients strip `<style>` and ignore `var()`, and dompdf supports neither custom properties nor
 * `oklch()`. Those surfaces need literal hexes — but they ALSO need them in a place a QUEUE WORKER can
 * reach, and that is the harder half.
 *
 * **A queued Notification is delivered by the framework's `SendQueuedNotifications`, NOT by
 * {@see TenantAwareJob}.** So on the worker `TenantContext::currentTenantId()` is null, and
 * {@see TenantBrandingService::isActive()} — whose entitlement half reads that static rather than its
 * `$tenant` argument — fails closed to false. Branding resolved inside `toMail()` would therefore render
 * EVERY tenant unbranded, silently, with a green suite. `sharedRamp()` is worse still: it resolves
 * `app(TenantContract::class)`, which no worker binds, so it returns null unconditionally.
 *
 * Hence: **the palette is resolved at DISPATCH, where tenant context is live, and rides the payload as a
 * scalar array** — the same discipline every `TenantUrl`-built link in this application already follows,
 * and the shape `scripts/job-payload-lint.php` R3 permits (`array` is a legal payload type; a DTO is not).
 *
 * ── LIGHT THEME ONLY, AND THAT IS A DECISION WITH FOUR REASONS ──────────────────────────────────
 * The stored ramp carries both themes; this array carries one. Dark-mode mail is not attempted because:
 *   1. `CssToInlineStyles` DELETES `@media` blocks from the theme before inlining, so a dark rule written
 *      in the theme never reaches the client at all.
 *   2. Written instead into a `<style>` tag, it would lose anyway: after inlining, every element carries an
 *      inline `background-color`/`color`, and an inline declaration beats any stylesheet rule without
 *      `!important` — you would be writing `!important` on every line to defeat your own inliner.
 *   3. The framework's mail layout hard-codes `<meta name="color-scheme" content="light">`.
 *   4. The dark ramp was measured against MERIDIAN's dark grounds (`#123350` surface, `#0C2337` canvas —
 *      {@see BrandRampGenerator}), not against whatever ground Gmail or Outlook invents when it inverts a
 *      message. Shipping it would assert a contrast guarantee the engine never made for that ground.
 *
 * The PDF is light-only for the duller reason that paper is white.
 */
final class BrandPalette
{
    /**
     * The product default — Blueprint, the same six values `packages/design-system` resolves for an
     * unbranded tenant. Read off the design system's COMMITTED token sources, not off `dist/tokens.css`,
     * which is gitignored and absent in CI.
     *
     * **These literals have a drift test, not just a comment** —
     * `tests/Unit/Branding/BrandPaletteTokenParityTest.php`, named here as a PATH rather than as an
     * `{@see}` class reference, because Pint's `fully_qualified_strict_types` rule would turn the latter
     * into a real `use Tests\…` import and this is production code. It parses
     * `packages/design-system/tokens/{primitive,semantic}.json`, resolves the `{primary.600}` aliases and
     * asserts every value below. A second copy of a design-system value guarded only by a comment is how
     * `https://acme/invitations/` shipped in every invitation email for two increments.
     *
     * @var array<string, string>
     */
    public const array PRODUCT = [
        'bg' => '#1C4B72',        // color.action.primary.bg        → primary.600
        'bg_hover' => '#123350',  // color.action.primary.bg-hover  → primary.700
        'bg_active' => '#0D2740', // color.action.primary.bg-active → primary.800
        'fg' => '#1C4B72',        // color.action.primary.fg        → primary.600
        'tint' => '#EAF1F6',      // color.action.primary.tint      → primary.50
        'ring' => '#123350',      // color.focus.ring               → primary.700
    ];

    /**
     * White on a brand fill — {@see BrandRampGenerator::ON_PRIMARY}, the ground every `bg`/`bg_hover`/
     * `bg_active` pairing was measured against at ≥4.5:1.
     *
     * Exposed as a constant so no template is tempted to reach for `fg` for button text: in the light
     * ramp `fg` IS `bg` (the generator aliases them), so using it there would put the fill colour on the
     * fill — a contrast inversion the engine never measured.
     */
    public const string ON_BRAND = '#FFFFFF';

    /**
     * The palette for a tenant, or the product default when it is not branded.
     *
     * **THE CONTEXT GUARD IS LOAD-BEARING, NOT DEFENSIVE PADDING.** `TenantBrandingService::isActive()`
     * answers `hasBrandRamp()` from the `$tenant` argument but `feature('branding')` from
     * `TenantContext::currentTenantId()`. Those are the same tenant everywhere it is called today — but
     * "everywhere it is called today" is not a guarantee, and if they ever diverge the method answers the
     * PLAN question for one tenant and the STORAGE question for another, with no error. Refusing to answer
     * at all unless they agree turns an invisible cross-tenant entitlement read into defined, testable,
     * fail-closed behaviour.
     *
     * @return array<string, string>
     */
    public static function forTenant(?Tenant $tenant): array
    {
        if ($tenant === null || (string) $tenant->getKey() !== (string) TenantContext::currentTenantId()) {
            return self::product();
        }

        if (! app(TenantBrandingService::class)->isActive($tenant)) {
            return self::product();
        }

        $ramp = $tenant->brandRamp();

        if ($ramp === null) {
            return self::product();
        }

        return self::identity($tenant) + [
            'bg' => $ramp->token('light', 'bg'),
            'bg_hover' => $ramp->token('light', 'bg_hover'),
            'bg_active' => $ramp->token('light', 'bg_active'),
            'fg' => $ramp->token('light', 'fg'),
            'tint' => $ramp->token('light', 'tint'),
            'ring' => $ramp->token('light', 'ring'),
        ];
    }

    /**
     * The palette for a tenant id — the shape a {@see TenantAwareJob} wants, since the base class
     * loads the tenant into a local and never hands it to `handleForTenant()`.
     *
     * @return array<string, string>
     */
    public static function forTenantId(?string $tenantId): array
    {
        if ($tenantId === null || $tenantId === '') {
            return self::product();
        }

        // `tenants` is RLS-exempt, so this reads correctly under the job's own GUC — the same query
        // GeneratePdfJob::downloadUrl() already makes for the same reason.
        return self::forTenant(Tenant::query()->whereKey($tenantId)->first());
    }

    /**
     * The palette for the ambient tenant of an HTTP request, or the product default off-tenant.
     *
     * @return array<string, string>
     */
    public static function current(): array
    {
        return self::forTenantId(TenantContext::currentTenantId());
    }

    /**
     * The unbranded palette: Blueprint, the platform's own name, and no logo.
     *
     * @return array<string, string>
     */
    public static function product(): array
    {
        return [
            'name' => (string) config('app.name'),
            'url' => (string) config('app.url'),
            'logo_url' => '',
        ] + self::PRODUCT;
    }

    /**
     * Name, home link and logo URL for a branded tenant.
     *
     * **`TenantUrl::to()` — the APP arm, never `toPublic()`.** ADR-0009 §D2 and ADR-0012 scope a custom
     * host to the public guest runtime only, so a link or an image built on a custom domain would 404 (the
     * route is not in that group) and an authenticated link would 302 the recipient to the central app.
     * ADR-0014's related-ADRs note pins this for the mail logo specifically.
     *
     * **`logo_url` is `''`, never null, when there is no servable logo.** Templates then branch on a plain
     * string compare, and — more importantly — an unscanned or quarantined logo resolves to "no logo" at
     * SEND time rather than becoming a permanently broken image in a message already sitting in an inbox.
     *
     * @return array<string, string>
     */
    private static function identity(Tenant $tenant): array
    {
        $logo = $tenant->logoAttachment()->first();
        $servable = $logo !== null && $logo->virus_scan_status->servable();

        return [
            'name' => (string) $tenant->name,
            'url' => TenantUrl::to($tenant, 'dashboard'),
            'logo_url' => $servable ? TenantUrl::to($tenant, 'branding/logo') : '',
        ];
    }
}
