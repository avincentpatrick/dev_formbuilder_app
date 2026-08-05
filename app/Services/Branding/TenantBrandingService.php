<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Enums\PlanTier;
use App\Exceptions\Branding\BrandRampException;
use App\Models\Attachment;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Attachments\AttachmentStorageService;
use App\Services\Entitlements\EntitlementService;
use App\Support\Branding\BrandRamp;
use App\Support\Branding\BrandRampGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The tenant-branding write path (H23a2, ADR-0014) — the ONLY writer of `tenants.primary_color`,
 * `tenants.brand_ramp` and `tenants.logo_attachment_id`.
 *
 * Being the only writer is the point. `primary_color` and `brand_ramp` are two columns describing one
 * fact, and a row carrying one without the other is a bug with no honest reading — so they are always
 * written together, in a transaction, from here.
 *
 * **THE STORED/ACTIVE DISTINCTION IS THE SUBTLE PART OF THIS CLASS.** `branding` is a Starter+ plan
 * feature (ADR-0008 §D7). A tenant that sets a brand on Starter and then downgrades to Free keeps its
 * stored ramp and stops being branded — so "does this tenant have a ramp" and "should this tenant render
 * branded" are two different questions, answered by {@see Tenant::hasBrandRamp()} and
 * {@see self::isActive()} respectively. Getting them confused in either direction is a real defect: treat
 * stored as active and the paid feature is free; treat active as stored and a downgraded tenant loses the
 * ability to see or remove what they configured.
 *
 * That second half is why **clearing is never plan-gated while setting always is** — the ADR-0012 §D9
 * precedent, where a tenant downgraded off Business keeps a live custom hostname and must retain a path
 * to remove it. The routes enforce the asymmetry; this class assumes it.
 */
final class TenantBrandingService
{
    /** The plan feature key gating branding (ADR-0008 §D7, `PlanCatalog::FEATURE_KEYS`). */
    public const string FEATURE = 'branding';

    public function __construct(
        private readonly BrandRampGenerator $generator,
        private readonly AttachmentStorageService $attachments,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * Whether this tenant's branding should actually RENDER — stored AND entitled.
     *
     * Read by every consuming surface (H23a3's blades, H23a4's mail and PDF, H23b's guest runtime), so
     * the plan check lives here once rather than being re-derived per surface. A surface that forgets it
     * ships the paid feature for free, and nothing in the build would notice.
     */
    public function isActive(Tenant $tenant): bool
    {
        return $tenant->hasBrandRamp() && $this->entitlements->feature(self::FEATURE);
    }

    /**
     * Derive and store a ramp from the tenant's chosen hex.
     *
     * The generator is deny-by-default over all seventeen §4.1 pairings, so a stored ramp is a verified
     * ramp by construction — there is no path from here to a row that was never measured.
     *
     * @throws BrandRampException on a malformed hex (defence-in-depth behind the FormRequest's regex)
     */
    public function setBrandColor(Tenant $tenant, string $hex): BrandRamp
    {
        $ramp = $this->generator->generate($hex);

        DB::transaction(function () use ($tenant, $ramp): void {
            // forceFill mirrors FormService's guarded-column writes, and the two columns move together —
            // see the class docblock on why a row with one and not the other has no honest reading.
            $tenant->forceFill([
                'primary_color' => $ramp->input,
                'brand_ramp' => json_encode($ramp->toArray(), JSON_THROW_ON_ERROR),
            ])->save();
        });

        return $ramp;
    }

    /**
     * Remove the brand colour, leaving any logo in place.
     *
     * Deliberately NOT also removing the logo: they are two independent choices a tenant made, and a
     * "reset my colour" action that silently deleted an uploaded file would be the kind of destructive
     * surprise this codebase avoids elsewhere. {@see self::removeLogo()} is its own verb.
     */
    public function clearBrandColor(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant): void {
            $tenant->forceFill(['primary_color' => null, 'brand_ramp' => null])->save();
        });
    }

    /**
     * Store a new brand logo and point the tenant at it, returning the attachment.
     *
     * The previous logo's ROW and OBJECT are deliberately left alone. Deleting the old object here would
     * make replacement destructive and irreversible mid-request, and the `attachments` table is already
     * the tenant's storage-accounting ledger — an orphan is visible and reclaimable, a deleted file is
     * not. Reclamation of superseded logos belongs to a sweep, not to this write.
     */
    public function replaceLogo(Tenant $tenant, UploadedFile $file, ?string $uploadedBy): Attachment
    {
        $attachment = $this->attachments->storeBrandingLogo($file, (string) $tenant->id, $uploadedBy);

        DB::transaction(function () use ($tenant, $attachment): void {
            $tenant->forceFill(['logo_attachment_id' => $attachment->id])->save();
        });

        return $attachment;
    }

    /**
     * Unpoint the tenant from its logo.
     *
     * Clears the POINTER only, for the same reason replacement does not delete: the object stays in the
     * storage ledger where it can be accounted for and reclaimed deliberately. The tenant's experience is
     * identical either way — the logo stops rendering immediately.
     */
    public function removeLogo(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant): void {
            $tenant->forceFill(['logo_attachment_id' => null])->save();
        });
    }

    /**
     * The lowest plan tier whose catalog entry enables branding — surfaced by the settings UI so a
     * downgraded tenant is told WHY their stored brand is inactive rather than left to guess.
     *
     * **Read from the plan catalog rather than hard-coded to `Starter`.** `branding` is Starter+ today
     * (ADR-0008 §D7), but every number and flag in `PlanCatalog` is documented as an unpinned planning
     * default to be re-verified before a pricing page ships — so a hard-coded "Upgrade to Starter" string
     * would quietly start lying the moment the catalog moved, which is precisely the kind of drift the
     * repo's "§-numbered docs describe intent; code describes reality" rule exists to prevent.
     *
     * Ordered by `sort_order` (Free 0 → Enterprise 4) and NOT filtered on `is_active`: Business and
     * Enterprise are seeded inactive because they are held from sale, and a tenant that a super-admin has
     * placed on one still needs the honest answer.
     */
    public function requiredTier(): ?PlanTier
    {
        $plan = Plan::query()
            ->orderBy('sort_order')
            ->get()
            ->first(fn (Plan $plan): bool => $plan->featureEnabled(self::FEATURE));

        return $plan?->code;
    }
}
