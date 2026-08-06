<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Exceptions\Attachments\AttachmentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreBrandingLogoRequest;
use App\Http\Requests\Tenant\UpdateBrandingRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Branding\TenantBrandingService;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

/**
 * The tenant-branding admin surface (Increment H23a2, ADR-0014) — a thin adapter over
 * {@see TenantBrandingService}, which owns every decision. Rendering lives on `/settings`
 * ({@see PreferencesController::show()}) rather than on its own page,
 * because branding is three controls and a preview; H22b earned its own page by carrying a per-row
 * lifecycle, and this does not.
 *
 * The write targets the SUBDOMAIN-RESOLVED current tenant only, never a tenant id from the request body
 * — `tenants` is RLS-exempt, so this controller-side scoping is the only thing preventing a cross-tenant
 * write. That is the {@see TenantSettingsController} convention, not a new one.
 *
 * ⚠️ **THE ROUTE GATING IS ASYMMETRIC AND THAT IS THE DESIGN, NOT AN OVERSIGHT** (routes/tenant.php).
 * `update` and `storeLogo` carry `feature:branding`; **`destroy` and `destroyLogo` deliberately do not.**
 * This is the ADR-0012 §D9 precedent applied: a tenant that sets a brand on Starter and downgrades to
 * Free keeps its stored ramp, stops rendering branded, and **must retain a path to remove what a paid
 * tier let them create**. Gating the removal would strand them — the settings card would show a brand
 * they can neither use nor delete. `BrandingRoutesTest` asserts the asymmetry in both directions,
 * because a later "tidy-up" that made all four gates match would look like a consistency fix.
 *
 * Gating mirrors the H14/H22b web convention: `can:tenant.settings.manage` and never `ability:`, which is
 * Sanctum token-scope middleware — a session carries no token.
 */
final class BrandingController extends Controller
{
    public function __construct(private readonly TenantBrandingService $branding) {}

    /** Set (or change) the tenant's brand colour; the ramp is derived and stored server-side. */
    public function update(UpdateBrandingRequest $request): RedirectResponse
    {
        $hex = $request->brandColor();

        if ($hex === null) {
            return back();
        }

        $ramp = $this->branding->setBrandColor($this->tenant(), $hex, $this->actor($request));

        // The message names the DERIVED fill, not the submitted hex, because they are frequently
        // different and the difference is the whole point of B-snap (ADR-0014 §D3). The card's snap
        // disclosure shows the full comparison; this line makes sure a tenant who dismisses the toast
        // still saw that something was adjusted.
        $adjusted = $ramp->token('light', 'bg') !== $ramp->input;

        return back()->with('toast', [
            'type' => 'success',
            'message' => $adjusted
                ? "Brand colour saved. {$ramp->input} was adjusted to {$ramp->token('light', 'bg')} so button text stays readable — see the contrast report below."
                : 'Brand colour saved.',
        ]);
    }

    /** Remove the brand colour. NOT plan-gated — see the class docblock. */
    public function destroy(Request $request): RedirectResponse
    {
        $this->branding->clearBrandColor($this->tenant(), $this->actor($request));

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Brand colour removed. Your forms use the default theme again.',
        ]);
    }

    /** Upload or replace the brand logo. */
    public function storeLogo(StoreBrandingLogoRequest $request): RedirectResponse
    {
        try {
            // `getAuthIdentifier()` rather than `->id`, matching AttachmentController: the User model's
            // `$id` is inferred as int by static analysis while the column is a UUID, so the cast is what
            // keeps the attachment service's `?string` contract honest.
            $this->branding->replaceLogo(
                $this->tenant(),
                $request->logo(),
                (string) $request->user()?->getAuthIdentifier(),
                $this->actor($request),
            );
        } catch (AttachmentException $e) {
            // The service layer's refusals are already written for a human (a rejected MIME type, an
            // over-size file). Surfacing the message keeps a web route's failure a redirect + toast
            // rather than the 422 the API twin would return.
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Logo uploaded.']);
    }

    /** Remove the brand logo. NOT plan-gated — see the class docblock. */
    public function destroyLogo(Request $request): RedirectResponse
    {
        $this->branding->removeLogo($this->tenant(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Logo removed.']);
    }

    /** The subdomain-resolved current tenant — stancl binds it under the Tenant contract. */
    private function tenant(): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = app(TenantContract::class);

        return $tenant;
    }

    /**
     * The audit actor for a branding write (Increment I2). Threaded explicitly rather than left to
     * {@see AuditLogger}'s `Auth::id()` fallback, matching the FormService convention:
     * the two `destroy*` verbs gained a `Request` parameter solely for this, which is cheaper than a
     * service that silently records a different actor when called from anywhere but a session.
     */
    private function actor(Request $request): ?User
    {
        /** @var ?User $user */
        $user = $request->user();

        return $user;
    }
}
