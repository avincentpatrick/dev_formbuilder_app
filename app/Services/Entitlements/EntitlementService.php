<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Enums\PlanTier;
use App\Enums\UsageMetric;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Services\Authorization\ResourceGrantResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * The single interpreter of a tenant's entitlements (ADR-0008 §D3) — the one place `plans`/`subscriptions`/
 * `usage_counters` are read to answer "what can this tenant do". Following the {@see ResourceGrantResolver}
 * precedent, one resolver so UI gating and (H5b) server enforcement cannot drift. H5a ships the READ side
 * only; nothing here blocks anything.
 *
 * A tenant's current plan is the plan of its ACTIVE subscription (ADR-0008 §D2, {@see Subscription::scopeActive});
 * an unsubscribed tenant resolves to the seeded `free` plan. Feature access is a `plans.feature_flags`
 * boolean map; quota limits are a `plans.quotas` metric→number map (null ⇒ unlimited).
 *
 * ── Memoization ──────────────────────────────────────────────────────────────────────────────────────
 * Bound with `scoped()` (AppServiceProvider), not `singleton()`: the resolved plan + current-period usage
 * are memoized per TENANT for one request, and an Octane singleton would carry that memo across requests.
 * The memo key is the tenant id — Pest's `enterTenant()` runs repeatedly in one process, and a keyless memo
 * would bleed one tenant's plan into another's request.
 */
final class EntitlementService
{
    /** @var array<string, ?Plan> tenant id => resolved current plan */
    private array $plans = [];

    /** @var array<string, array<string, int>> tenant id => (metric value => current-period usage) */
    private array $usage = [];

    /**
     * The plan governing the current tenant: the plan of its active subscription, else the seeded `free`
     * tier. Null off-tenant (guest/central) or if the catalog is unseeded — callers degrade fail-closed.
     */
    public function currentPlan(): ?Plan
    {
        $tenantId = TenantContext::currentTenantId();

        if ($tenantId === null) {
            return null;
        }

        // array_key_exists, not ??=: a null result (unseeded catalog) must be memoized too, or every call
        // re-runs the resolution query.
        if (! array_key_exists($tenantId, $this->plans)) {
            $this->plans[$tenantId] = $this->resolvePlan();
        }

        return $this->plans[$tenantId];
    }

    /** The current tier, defaulting to `free` when no plan resolves (never null, so gating code stays simple). */
    public function tier(): PlanTier
    {
        $plan = $this->currentPlan();

        if ($plan === null) {
            return PlanTier::Free;
        }

        return $plan->code;
    }

    /**
     * Whether a feature-gate key is enabled for the current tenant. A per-tenant legacy override (the
     * grandfather seam, ADR-0008 §D5) wins over the plan flags; it is empty until H5c ships the storage.
     */
    public function feature(string $key): bool
    {
        $overrides = $this->legacyOverrides();

        if (array_key_exists($key, $overrides)) {
            return $overrides[$key];
        }

        return $this->currentPlan()?->featureEnabled($key) ?? false;
    }

    /** The current tenant's quota for a metric, or null for unlimited (also null off-tenant). */
    public function quota(UsageMetric $metric): ?int
    {
        return $this->currentPlan()?->quotaFor($metric);
    }

    /**
     * The current tenant's metered usage for a metric in the current period. Always 0 in H5a — metering
     * (increments + the cross-tenant rollup) lands with H5b; the read shape is here so the UI contract is
     * stable now.
     */
    public function usage(UsageMetric $metric): int
    {
        return $this->currentUsage()[$metric->value] ?? 0;
    }

    /**
     * The read-only entitlement model shared to the frontend (the `ui.theme` precedent). Null off-tenant
     * (guest/central) so the shared prop is fail-closed; on a tenant route it is the plan tier, the feature
     * flags (override-aware), and every metric's limit-vs-usage.
     *
     * @return array{
     *     plan: array{code: string, name: string},
     *     features: array<string, bool>,
     *     quotas: array<string, array{limit: int|null, used: int}>,
     * }|null
     */
    public function snapshot(): ?array
    {
        $plan = $this->currentPlan();

        if ($plan === null) {
            return null;
        }

        $features = [];

        foreach (array_keys($plan->feature_flags) as $key) {
            $features[(string) $key] = $this->feature((string) $key);
        }

        $quotas = [];

        foreach (UsageMetric::cases() as $metric) {
            $quotas[$metric->value] = [
                'limit' => $this->quota($metric),
                'used' => $this->usage($metric),
            ];
        }

        return [
            'plan' => ['code' => $plan->code->value, 'name' => $plan->name],
            'features' => $features,
            'quotas' => $quotas,
        ];
    }

    /** Drop the memoized plan + usage (after an assign, or for test isolation). Null clears every tenant. */
    public function forget(?string $tenantId = null): void
    {
        if ($tenantId === null) {
            $this->plans = [];
            $this->usage = [];

            return;
        }

        unset($this->plans[$tenantId], $this->usage[$tenantId]);
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────────────

    /** The active subscription's plan (RLS scopes to the current tenant), else the seeded `free` plan. */
    private function resolvePlan(): ?Plan
    {
        $plan = Subscription::query()
            ->active()
            ->with('plan')
            ->latest('created_at')
            ->first()
            ?->plan;

        return $plan ?? Plan::query()->where('code', PlanTier::Free->value)->first();
    }

    /**
     * The current tenant's current-period usage in one query, memoized. Empty in H5a (no counters written).
     *
     * @return array<string, int>
     */
    private function currentUsage(): array
    {
        $tenantId = TenantContext::currentTenantId();

        if ($tenantId === null) {
            return [];
        }

        if (isset($this->usage[$tenantId])) {
            return $this->usage[$tenantId];
        }

        $today = Carbon::now()->toDateString();

        /** @var array<string, int> $rows */
        $rows = UsageCounter::query()
            ->whereDate('period_start', '<=', $today)
            ->whereDate('period_end', '>=', $today)
            ->pluck('value', 'metric')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        return $this->usage[$tenantId] = $rows;
    }

    /**
     * Per-tenant feature overrides — the grandfather seam (ADR-0008 §D5). Returns nothing in H5a; H5c backs
     * this with storage and the merge-day backfill that grandfathers every then-existing tenant.
     *
     * @return array<string, bool>
     */
    private function legacyOverrides(): array
    {
        return [];
    }
}
