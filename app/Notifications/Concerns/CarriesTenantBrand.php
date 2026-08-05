<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Jobs\TenantAwareJob;
use App\Support\Branding\BrandPalette;
use App\Support\Tenancy\TenantUrl;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Carries a tenant's brand palette from DISPATCH, where tenant context is live, to the WORKER, where it is
 * not — and renders every outbound email through the Meridian mail theme (H23a4, ADR-0014 §D8).
 *
 * ── THE PROBLEM THIS TRAIT IS THE ANSWER TO ─────────────────────────────────────────────────────
 * A queued notification is delivered by the framework's `SendQueuedNotifications`, **not** by
 * {@see TenantAwareJob}, so `toMail()` runs with `TenantContext::currentTenantId()` NULL. Every
 * branding read fails closed there: `TenantBrandingService::isActive()` answers its entitlement half from
 * that static, and `sharedRamp()` resolves a container binding no worker makes. Resolving the brand inside
 * `toMail()` would therefore send every tenant an unbranded email while the whole suite stayed green —
 * the exact failure mode this codebase keeps calling "passing vacuously".
 *
 * So the palette is resolved where the tenant is known and RIDES THE PAYLOAD, which is the same discipline
 * every outbound URL in this application already follows ({@see TenantUrl}). `array`
 * is a legal payload type under `scripts/job-payload-lint.php` R3; a DTO would not be.
 *
 * ── WHY A SETTER AND NOT A CONSTRUCTOR PARAMETER ────────────────────────────────────────────────
 * Eight notifications and nine dispatch sites already exist, with tests asserting on their constructor
 * properties. A trailing constructor parameter would churn all of it for no gain — and
 * `QueuedMailContractTest` asserts specifically that every CONSTRUCTOR PARAMETER is a builtin scalar, a
 * rule a setter neither weakens nor triggers.
 *
 * ⚠️ **`scripts/job-payload-lint.php` CANNOT SEE `$brand`.** Its R3 pass reads `Class_::getProperties()`,
 * which returns only properties declared in the class BODY — a trait-flattened property is invisible to
 * it. The type below is legal R3 either way, but the gate you would assume is guarding it is not; that is
 * why `QueuedMailContractTest` asserts the property's presence, type and default explicitly.
 *
 * ── WHY THE FALLBACK RESOLVES AT RENDER, NOT AT DISPATCH ────────────────────────────────────────
 * `$brand` defaults to `[]` and {@see self::branded()} substitutes {@see BrandPalette::product()} at render
 * time. A dispatch site added later that forgets `withBrand()` therefore sends a correct, unbranded,
 * Meridian-themed email — instead of throwing "undefined variable $brand" on a queue worker at 3am, which
 * is the one place in this system nobody is watching.
 *
 * **Never `View::share()`.** The view factory is a singleton and a queue worker is long-lived, so a shared
 * brand would leak from one tenant's job into the next one's.
 */
trait CarriesTenantBrand
{
    /**
     * The sending tenant's light-theme palette, or `[]` for the product default.
     *
     * Public and serialized with the notification — this IS the payload.
     *
     * @var array<string, string>
     */
    public array $brand = [];

    /**
     * @param  array<string, string>  $brand
     */
    public function withBrand(array $brand): static
    {
        $this->brand = $brand;

        return $this;
    }

    /**
     * Route a `MailMessage` through the Meridian template and theme.
     *
     * `MailMessage::markdown()` is the documented public API that sets the view, its data and clears any
     * plain view in one call; `MailMessage::data()` then merges `viewData` LAST, so it makes no difference
     * whether `->subject()`, `->line()` and `->action()` were called before or after this wrap.
     *
     * The theme is named PER MESSAGE rather than through `config('mail.markdown.theme')` on purpose: a test
     * environment that had not set it would silently render the framework's `default` theme, and every
     * brand assertion in the suite would then be asserting against the wrong stylesheet.
     */
    protected function branded(MailMessage $mail): MailMessage
    {
        return $mail
            ->markdown('mail.notification', [
                'brand' => $this->brand === [] ? BrandPalette::product() : $this->brand,
            ])
            ->theme('meridian');
    }
}
