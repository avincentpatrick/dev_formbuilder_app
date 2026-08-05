<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Http\Controllers\Public\PwaManifestController;
use App\Support\Branding\BrandRampGenerator;

/**
 * Shapes the tenant's branding for the PUBLIC GUEST RUNTIME (Increment H23b, ADR-0014).
 *
 * Sibling of {@see BrandingPresenter}, which serves the `/settings` card. Two presenters rather than
 * one because they answer for two audiences: the card must DISCLOSE what the engine changed (the snap,
 * the seventeen measured ratios, the logo's scan status), and a respondent must be told none of that —
 * they get colours and nothing else.
 *
 * **Being the single reader is the point.** Three guest surfaces consume this — the shell's `<style>`
 * block, the shell's `<meta name="theme-color">` and {@see PwaManifestController}'s
 * `theme_color` — and two of them additionally carry {@see self::forGuest()}'s `version`. If any of
 * them derived its own answer they could disagree: a shell painted in one brand linking a manifest
 * cache-busted for another is a state with no honest reading.
 *
 * **This surface CAN resolve `var()`**, unlike the mail and PDF surfaces H23a4 serves — it is a real
 * browser. It still reads the STORED ramp rather than re-deriving one (ADR-0014 §D8): a ramp that was
 * compliant when written must stay the ramp that renders.
 */
final class GuestBrandingPresenter
{
    /**
     * What `theme-color` is when the tenant has no active brand.
     *
     * `--mds-primary-600`, which is what `--mds-color-action-primary-bg` resolves to in light mode with
     * no accent attribute set — i.e. exactly what the unbranded guest runtime actually paints.
     *
     * **This CORRECTS a live defect (H23b).** It was `#1B5E5E` = `--mds-accent-teal-600` from G8a until
     * now, while the guest shell has never emitted `data-accent` — so the browser chrome and the
     * installed app's splash screen were tinted a teal that appears nowhere in the form. The rule is now
     * one rule for both cases: **theme-color IS the light primary fill**, tenant-derived when there is a
     * brand and the product default when there is not.
     *
     * A literal rather than a token read: PHP has no access to the CSS custom properties, and the file
     * that does ({@see BrandRampGenerator}) hard-codes its grounds the same way.
     */
    private const string DEFAULT_THEME_COLOR = '#1C4B72';

    /** What {@see self::forGuest()} reports as `version` when nothing renders branded. */
    private const string UNBRANDED_VERSION = 'none';

    public function __construct(private readonly TenantBrandingService $branding) {}

    /**
     * The guest runtime's branding: the twelve tokens (or null), a change fingerprint, and the one hex
     * the browser chrome takes.
     *
     * `tokens` comes from {@see TenantBrandingService::sharedRamp()}, which is already gated on
     * `isActive()` — STORED and ACTIVE are different questions, and reading `Tenant::hasBrandRamp()`
     * here would ship a Starter+ feature to Free tenants with nothing in the build to notice. It is also
     * fail-closed off-tenant, which costs nothing here (the guest group runs
     * `InitializeTenancyByPublicHost`, so a tenant is always bound) but keeps this method total.
     *
     * @return array{tokens: ?array<string, array<string, string>>, version: string, theme_color: string}
     */
    public function forGuest(): array
    {
        $tokens = $this->branding->sharedRamp();

        return [
            'tokens' => $tokens,
            'version' => $this->version($tokens),
            'theme_color' => $tokens['light']['bg'] ?? self::DEFAULT_THEME_COLOR,
        ];
    }

    /**
     * A short, stable fingerprint of what the guest runtime is about to render.
     *
     * **This is the cache-invalidation key, and every property of it is load-bearing.** It rides the
     * shell's mount node and the manifest link's query string, and the SPA compares it against the value
     * it persisted in IndexedDB to decide whether previously-cached shells are showing a superseded
     * brand (`lib/brand-cache.ts`).
     *
     *  - **Derived only from the rendered hexes** — no clock, no random, no row id. The same brand must
     *    produce the same string on every render and on every web node, or the SPA re-primes its caches
     *    on every single page load.
     *  - **Computed from `$tokens`, not from `primary_color`** — the input hex is not what renders, and
     *    §D2 discards lightness, so two different inputs can legitimately produce one identical ramp.
     *    Fingerprinting the input would invalidate caches over a change nobody can see.
     *  - **Truncated to 12 hex chars.** It is a change detector, not a security token: nothing trusts
     *    it, and it sits in a URL a respondent can read.
     *  - **A literal `'none'` when unbranded**, never an empty string, so "this tenant has no brand" is
     *    a value the SPA can store and compare like any other rather than a falsy special case.
     *
     * @param  ?array<string, array<string, string>>  $tokens
     */
    private function version(?array $tokens): string
    {
        if ($tokens === null) {
            return self::UNBRANDED_VERSION;
        }

        return substr(hash('sha256', json_encode($tokens, JSON_THROW_ON_ERROR)), 0, 12);
    }
}
