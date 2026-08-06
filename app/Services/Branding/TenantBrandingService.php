<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Enums\AuditEvent;
use App\Enums\PlanTier;
use App\Exceptions\Branding\BrandRampException;
use App\Models\Attachment;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Attachments\AttachmentStorageService;
use App\Services\Entitlements\EntitlementService;
use App\Services\Tenancy\TenantSettingsService;
use App\Support\Audit\AuditLogger;
use App\Support\Branding\BrandRamp;
use App\Support\Branding\BrandRampGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

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
        private readonly AuditLogger $audit,
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
     * The ramp for the shared `ui.brand` Inertia prop, or null when nothing should render branded.
     *
     * **FAIL-CLOSED OFF-TENANT.** This runs on every Inertia render, including central-host and guest
     * routes where there is no resolved tenant — so an unresolved tenant returns null rather than
     * throwing, mirroring `EntitlementService::snapshot()`'s posture on the same middleware.
     *
     * Returns only the twelve tokens: the measurements and the input hex are the settings card's
     * business, and shipping them on every page would put ~2KB of contrast tables into every render.
     *
     * @return array<string, array<string, string>>|null
     */
    public function sharedRamp(): ?array
    {
        if (! app()->bound(TenantContract::class)) {
            return null;
        }

        $tenant = app(TenantContract::class);

        if (! $tenant instanceof Tenant || ! $this->isActive($tenant)) {
            return null;
        }

        return $tenant->brandRamp()?->tokens;
    }

    /**
     * Derive and store a ramp from the tenant's chosen hex.
     *
     * The generator is deny-by-default over all seventeen §4.1 pairings, so a stored ramp is a verified
     * ramp by construction — there is no path from here to a row that was never measured.
     *
     * @throws BrandRampException on a malformed hex (defence-in-depth behind the FormRequest's regex)
     */
    public function setBrandColor(Tenant $tenant, string $hex, ?User $actor = null): BrandRamp
    {
        // Outside the transaction on purpose: generate() throws on a malformed hex, and a refusal is not
        // an act — there is nothing to record and nothing to roll back.
        $ramp = $this->generator->generate($hex);

        DB::transaction(function () use ($tenant, $ramp, $actor): void {
            $old = ['primary_color' => $tenant->primary_color];

            // forceFill mirrors FormService's guarded-column writes, and the two columns move together —
            // see the class docblock on why a row with one and not the other has no honest reading.
            $tenant->forceFill([
                'primary_color' => $ramp->input,
                'brand_ramp' => json_encode($ramp->toArray(), JSON_THROW_ON_ERROR),
            ])->save();

            $this->recordSettingsChange($tenant, $old, ['primary_color' => $ramp->input], $actor);
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
    public function clearBrandColor(Tenant $tenant, ?User $actor = null): void
    {
        DB::transaction(function () use ($tenant, $actor): void {
            $old = ['primary_color' => $tenant->primary_color];

            $tenant->forceFill(['primary_color' => null, 'brand_ramp' => null])->save();

            // `updated`, not `deleted`: nothing is removed from the system, a tenant column is nulled — and
            // spec §1 gives the `settings` alias only `created`/`updated`, so this is also the one
            // spec-legal choice.
            $this->recordSettingsChange($tenant, $old, ['primary_color' => null], $actor);
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
    public function replaceLogo(Tenant $tenant, UploadedFile $file, ?string $uploadedBy, ?User $actor = null): Attachment
    {
        $attachment = $this->attachments->storeBrandingLogo($file, (string) $tenant->id, $uploadedBy);

        DB::transaction(function () use ($tenant, $attachment, $actor): void {
            $old = ['logo_attachment_id' => $tenant->logo_attachment_id];

            $tenant->forceFill(['logo_attachment_id' => $attachment->id])->save();

            // The SUPERSEDED attachment id is the useful half of this row: replacement deliberately leaves
            // the old object in place (see the docblock), so the ledger is what tells an operator which
            // orphan belonged to which tenant era when the reclamation sweep eventually lands.
            $this->recordSettingsChange($tenant, $old, ['logo_attachment_id' => (string) $attachment->id], $actor);
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
    public function removeLogo(Tenant $tenant, ?User $actor = null): void
    {
        DB::transaction(function () use ($tenant, $actor): void {
            $old = ['logo_attachment_id' => $tenant->logo_attachment_id];

            $tenant->forceFill(['logo_attachment_id' => null])->save();

            $this->recordSettingsChange($tenant, $old, ['logo_attachment_id' => null], $actor);
        });
    }

    /**
     * The one emission site the four branding writes share (Increment I2).
     *
     * **`auditable_type = 'settings'`, not a new `branding` alias.** Branding IS tenant settings: it renders
     * inside `/settings`, it gates on `tenant.settings.manage`, and spec §1's `settings` row exists for
     * exactly PRD Feature #12's "every settings change". A separate alias would fragment one user-visible
     * surface across two filter options in the audit viewer. `auditable_id` is the tenant id, the same key
     * {@see TenantSettingsService} uses and for the reason pinned there.
     *
     * ⚠️ **`brand_ramp` NEVER enters the payload.** It is ~2 KB of derived contrast tables, and it is a
     * PURE FUNCTION of `primary_color` — so it carries no information the diff does not already have, and
     * the two columns move together by construction (see the class docblock: a row with one and not the
     * other has no honest reading). Recording the colour records both. `audits` is append-only and never
     * pruned; one such blob per brand tweak is how a compliance ledger becomes unqueryable.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function recordSettingsChange(Tenant $tenant, array $old, array $new, ?User $actor): void
    {
        $this->audit->record(
            AuditEvent::Updated,
            'settings',
            (string) $tenant->getKey(),
            old: $old,
            new: $new,
            actorId: $actor?->getKey() === null ? null : (string) $actor->getKey(),
        );
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
