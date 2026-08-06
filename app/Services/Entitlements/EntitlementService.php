<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Enums\FormStatus;
use App\Enums\PlanTier;
use App\Enums\TenantUserStatus;
use App\Enums\UsageMetric;
use App\Jobs\TenantAwareJob;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\LegacyOverride;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TenantUser;
use App\Models\UsageCounter;
use App\Models\WebhookEndpoint;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Settings\TenantSettingRegistry;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Entitlements\ToggleableModules;
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

    /** @var array<string, int> "{tenant id}:{gauge metric value}" => memoized live COUNT/SUM for the request */
    private array $gauges = [];

    /** @var array<string, array<string, bool>> tenant id => memoized legacy (grandfather) override map */
    private array $overrides = [];

    /**
     * I5's third {@see feature()} layer. Injected rather than resolved inline so the module read shares the
     * ONE per-request `settings` query `/settings` already makes, instead of adding a second.
     */
    public function __construct(private readonly TenantSettingRegistry $settings) {}

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
     * Whether a feature-gate key is enabled for the current tenant.
     *
     * THREE layers, and the third composes differently from the first two — deliberately:
     *   1. a per-tenant legacy override (the grandfather seam, ADR-0008 §D5) wins over the plan flags;
     *   2. otherwise the plan's own `feature_flags` map decides;
     *   3. and then the tenant's own module toggle (I5, PRD Feature #10) can SUBTRACT from the answer.
     *
     * ── Why the third layer ANDs rather than overrides ─────────────────────────────────────────────────
     * PRD #10 requires the module panel to reuse "the same capability-flag mechanism… not a second flagging
     * system", which is why the toggle lands here rather than beside the surfaces it governs — every
     * existing `feature:<key>` route gate, every policy and the shared `entitlements` Inertia prop
     * ({@see snapshot()} calls this once per flag) honour it with no further change. But the toggle is
     * TENANT-WRITABLE, and layers 1–2 are not: if it could win outright, an Owner could grant their own
     * workspace a capability their plan denies simply by writing their own `settings` row. One-directional
     * by construction — a tenant may silence what it pays for, never conjure what it does not.
     *
     * Only {@see ToggleableModules::KEYS} are consultable, so a key nobody offers cannot be switched off by
     * a hand-crafted row, and the read is shared with `/settings` rather than issuing its own query.
     */
    public function feature(string $key): bool
    {
        $overrides = $this->legacyOverrides();

        $granted = array_key_exists($key, $overrides)
            ? $overrides[$key]
            : ($this->currentPlan()?->featureEnabled($key) ?? false);

        if (! $granted || ! ToggleableModules::isToggleable($key)) {
            return $granted;
        }

        return $this->settings->moduleEnabled($key);
    }

    /** The current tenant's quota for a metric, or null for unlimited (also null off-tenant). */
    public function quota(UsageMetric $metric): ?int
    {
        return $this->currentPlan()?->quotaFor($metric);
    }

    /**
     * The current tenant's usage for a metric (H5b). A GAUGE metric (forms/storage/seats) is computed
     * LIVE (a COUNT/SUM under RLS) so the read-model reflects a just-created form immediately and can never
     * drift from what the guard enforces; a FLOW metric (submissions/api/exports) is read from the metered
     * `usage_counters` current-period row. Both are 0 off-tenant / unmetered.
     */
    public function usage(UsageMetric $metric): int
    {
        if ($metric->isGauge()) {
            return $this->liveGauge($metric);
        }

        return $this->currentUsage()[$metric->value] ?? 0;
    }

    /**
     * The authoritative, NON-memoized live level of a gauge metric — the number {@see QuotaGuard} checks
     * immediately before a create, so a check and the write it guards see the same value with no
     * intra-request memo staleness in between. Never reads `usage_counters`: a maintained running aggregate
     * drifts (gauges move DOWN on archive/delete/remove; future in-worker writers would bypass it), whereas
     * a COUNT/SUM under RLS is always correct (the H12a `max_responses` precedent — a transactional count,
     * not a running total). Callable in-request and inside a {@see TenantAwareJob} transaction —
     * both establish tenant context; it must NEVER be called from a MaintenanceJob (no context ⇒ zero rows).
     */
    public function countGauge(UsageMetric $metric): int
    {
        return $this->computeGauge($metric);
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

    /** Drop the memoized plan + usage + gauges + overrides (after an assign, or for test isolation). Null clears all. */
    public function forget(?string $tenantId = null): void
    {
        if ($tenantId === null) {
            $this->plans = [];
            $this->usage = [];
            $this->gauges = [];
            $this->overrides = [];

            return;
        }

        unset($this->plans[$tenantId], $this->usage[$tenantId], $this->overrides[$tenantId]);

        foreach (array_keys($this->gauges) as $key) {
            if (str_starts_with($key, $tenantId.':')) {
                unset($this->gauges[$key]);
            }
        }
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

    /** The memoized live gauge for the read-model — one COUNT/SUM per (tenant, gauge metric) per request. */
    private function liveGauge(UsageMetric $metric): int
    {
        $tenantId = TenantContext::currentTenantId();

        if ($tenantId === null) {
            return 0;
        }

        $key = $tenantId.':'.$metric->value;

        if (! array_key_exists($key, $this->gauges)) {
            $this->gauges[$key] = $this->computeGauge($metric);
        }

        return $this->gauges[$key];
    }

    /**
     * The live level of a gauge metric, RLS-scoped to the current tenant. Non-archived forms; the sum of
     * non-trashed attachment bytes; active + pending-invited seats (reserve-on-invite, matching
     * {@see TenantMembershipService::listMembers()}). A non-gauge metric returns 0 —
     * flow metrics are read from `usage_counters`, never here.
     */
    private function computeGauge(UsageMetric $metric): int
    {
        return match ($metric) {
            UsageMetric::FormsCount => Form::query()
                ->where('status', '!=', FormStatus::Archived->value)
                ->count(),
            UsageMetric::StorageBytes => (int) Attachment::query()->sum('size_bytes'),
            UsageMetric::ActiveSeats => TenantUser::query()
                ->whereIn('status', [TenantUserStatus::Active->value, TenantUserStatus::Invited->value])
                ->count(),
            // A live COUNT of active (non-soft-deleted) endpoints — the per-tier cap gauge (H13a); moves
            // down when an endpoint is deleted, exactly like FormsCount on archive.
            UsageMetric::WebhookEndpointsCount => WebhookEndpoint::query()->count(),
            default => 0,
        };
    }

    /**
     * The current tenant's current-period FLOW usage in one query, memoized. Gauges are excluded — they are
     * computed live in {@see computeGauge()} and never read from `usage_counters`.
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
     * Per-tenant feature overrides — the grandfather storage (ADR-0008 §D5). Consulted by {@see feature()}
     * AHEAD of the plan flags, so a grandfathered tenant reads `true` for a feature its plan would deny. One
     * row per tenant (RLS-scoped), memoized because {@see snapshot()} calls {@see feature()} once per flag
     * key. Empty (and no query) off-tenant, and empty for any tenant with no override row (born-gated).
     *
     * @return array<string, bool>
     */
    private function legacyOverrides(): array
    {
        $tenantId = TenantContext::currentTenantId();

        if ($tenantId === null) {
            return [];
        }

        if (! array_key_exists($tenantId, $this->overrides)) {
            // Explicit null check rather than `->first()?->feature_flags ?? []`: Larastan flags the nullsafe
            // as unnecessary on the left of ?? (the H5a `tier()` precedent), and this is equally clear.
            $override = LegacyOverride::query()->first();
            $this->overrides[$tenantId] = $override === null ? [] : $override->feature_flags;
        }

        return $this->overrides[$tenantId];
    }
}
