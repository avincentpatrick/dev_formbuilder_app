<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\BillingInterval;
use App\Enums\TenantStatus;
use App\Enums\UsageMetric;
use App\Models\Domain;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Branding\TenantBrandingService;
use App\Services\Feedback\FeedbackConsolePresenter;
use App\Services\Tenancy\CustomDomainService;
use App\Services\Tenancy\DomainPresenter;
use App\Support\Entitlements\ToggleableModules;
use App\Support\Tenancy\TenantUrl;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

/**
 * Read model for the super-admin tenant-detail page (Increment I7b, RBAC §9 console scope).
 *
 * The context-adopting read itself belongs to {@see SuperAdminService::tenantSnapshot()} — §9 requires one
 * named service for cross-tenant operations — so this class owns only prop shape: the plan catalog, the
 * usage rows, the effective-vs-granted feature table, and the domain rows. The
 * {@see FeedbackConsolePresenter} seam, applied a second time.
 *
 * ── WHAT RUNS WHERE, AND WHY IT IS SPLIT THAT WAY ───────────────────────────────────────────────────────
 * Three of this page's four cards read tables that need NO tenant context: `tenants` and `plans` are
 * RLS-exempt, and `domains` is RLS-exempt with `CustomDomainService::forTenant()`'s explicit
 * `where('tenant_id')` as its isolation. Only the entitlement snapshot, the subscription and the owner need
 * the adopted context, and they are the only things inside the service's transaction. Keeping the rest out
 * keeps the `SET LOCAL` window as short as possible — {@see FeedbackConsolePresenter}'s
 * argument about the elevated transaction, applied to the adopted one.
 *
 * ── USAGE STRINGS ARE FORMATTED SERVER-SIDE, DELIBERATELY ───────────────────────────────────────────────
 * The eight metrics are heterogeneous — `storage_bytes` is bytes and the other seven are counts — and only
 * the server knows which is which ({@see UsageMetric}). Formatting on the client would make the page
 * re-encode the metric taxonomy in TypeScript, the same "the page re-derives the server's vocabulary"
 * defect I7a designed out of the feedback console's transition map. Raw `used`/`limit` integers ship
 * alongside `display`, so tests assert numbers and the page never parses a string.
 *
 * ⚠️ **A `limit` of `0` IS NOT UNLIMITED — `null` is.** The Free tier seeds literal `0` for `api_requests`,
 * `webhook_deliveries` and `webhook_endpoints_count`, so any `$limit ? … : 'Unlimited'` ternary reports
 * three hard-blocked metrics as unrestricted. `unlimited` is shipped as its own boolean for that reason.
 *
 * ── THE FEATURE TABLE SHOWS BOTH ANSWERS, BECAUSE ONLY THE PAIR IS USEFUL ───────────────────────────────
 * `snapshot()['features']` is the EFFECTIVE map — `(legacy override ∥ plan flag) AND NOT tenant-disabled`
 * — while the current plan's `feature_flags` is what the plan GRANTS. An operator's actual question is
 * "why can't this workspace use webhooks?", and neither column answers it alone: a mismatch means either a
 * grandfathered override or the tenant's own module switch, and `reason` says which.
 */
final class TenantDetailPresenter
{
    public function __construct(
        private readonly SuperAdminService $superAdmin,
        private readonly CustomDomainService $domains,
        private readonly DomainPresenter $domainRows,
    ) {}

    /** @return array<string, mixed> */
    public function show(Tenant $tenant): array
    {
        $snapshot = $this->superAdmin->tenantSnapshot($tenant);
        $catalog = $this->planCatalog();
        $publicHost = TenantUrl::publicHost($tenant);

        $entitlements = $snapshot['entitlements'];
        $subscription = $snapshot['subscription'];
        $currentPlan = $this->planFor($catalog, $subscription['plan_id'] ?? null);

        return [
            'tenant' => $this->identity($tenant, $snapshot['owner'], $publicHost),
            'plan' => [
                'current' => $subscription === null ? null : [
                    'plan_id' => $subscription['plan_id'],
                    'code' => $subscription['plan_code'],
                    'name' => $subscription['plan_name'],
                    'interval' => $subscription['billing_interval'],
                    'interval_label' => $this->intervalLabel($subscription['billing_interval']),
                    'stripe_status' => $subscription['stripe_status'],
                    // Surfaced so a divergence between the row assignPlan() upserts (name = 'default') and
                    // the row EntitlementService picks (active, latest) is visible rather than silently
                    // wrong. They agree today; the card says which one it is reporting.
                    'subscription_name' => $subscription['name'],
                    'assigned_at' => $subscription['assigned_at'],
                ],
                // The plan actually IN FORCE. Differs from `current` when there is no subscription at all,
                // where EntitlementService falls back to the seeded `free` plan — a real state the card
                // must distinguish from "no plan catalog seeded".
                'effective' => $entitlements['plan'] ?? null,
                'catalog' => $catalog,
                'intervals' => $this->allIntervals(),
            ],
            'usage' => $this->usage($entitlements),
            'features' => $this->features($catalog, $currentPlan, $entitlements),
            'domains' => [
                'rows' => $this->domainList($tenant, $publicHost),
                'app_host' => TenantUrl::appHost($tenant),
                'public_host' => $publicHost,
            ],
        ];
    }

    /**
     * @param  array{name: string, email: string}|null  $owner
     * @return array<string, mixed>
     */
    private function identity(Tenant $tenant, ?array $owner, string $publicHost): array
    {
        // ⚠️ `status` is an UNCAST ?string on this model (stancl's virtual-column machinery makes casts
        // fragile), so `$tenant->status !== TenantStatus::Active` compares a string to an enum OBJECT and
        // is ALWAYS true. Go through tryFrom()/isActive(), never a direct comparison.
        $status = (string) $tenant->status;

        return [
            'id' => (string) $tenant->getKey(),
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $status,
            'status_label' => TenantStatus::tryFrom($status) === TenantStatus::Suspended ? 'Suspended' : 'Active',
            'is_active' => $tenant->isActive(),
            'created_at' => $tenant->created_at?->toIso8601String(),
            'default_locale' => (string) $tenant->default_locale,
            'maintenance_mode' => (bool) $tenant->maintenance_mode,
            'maintenance_message' => $tenant->maintenance_message,
            'app_host' => TenantUrl::appHost($tenant),
            'public_host' => $publicHost,
            'owner' => $owner,
        ];
    }

    /**
     * The assignable plan catalog.
     *
     * **Not filtered on `is_active`, and that is load-bearing.** ADR-0008 §D6 seeds Business and Enterprise
     * inactive because they are held from sale, yet they remain admin-assignable — pinned at the service
     * level by `SuperAdminAssignPlanTest`'s "can assign a held-from-sale plan". Hiding them here would make
     * the console unable to do the one thing that test proves it can, so they ship with the flag instead
     * and the page labels them. The {@see TenantBrandingService::requiredTier()}
     * precedent, which makes the same argument for the same reason.
     *
     * @return list<array<string, mixed>>
     */
    private function planCatalog(): array
    {
        /** @var Collection<int, Plan> $plans */
        $plans = Plan::query()->orderBy('sort_order')->get();

        return array_values($plans->map(fn (Plan $plan): array => [
            'id' => (string) $plan->getKey(),
            'code' => $plan->code->value,
            'name' => $plan->name,
            'description' => (string) $plan->description,
            'is_active' => $plan->is_active,
            'sort_order' => $plan->sort_order,
            'feature_flags' => $plan->feature_flags,
            // Per-plan rather than the global enum: `billing_interval_options` is what the plan OFFERS, and
            // although every seeded tier offers both today, deriving the select from the selected plan
            // costs nothing now and is right the day a tier drops yearly. Mapped through tryFrom() and
            // filtered, because the column is untyped jsonb a hand-edited row could fill with anything.
            'interval_options' => $this->intervalOptions($plan),
        ])->all());
    }

    /**
     * @param  list<array<string, mixed>>  $catalog
     * @return array<string, mixed>|null
     */
    private function planFor(array $catalog, ?string $planId): ?array
    {
        if ($planId === null) {
            return null;
        }

        foreach ($catalog as $plan) {
            if ($plan['id'] === $planId) {
                return $plan;
            }
        }

        return null;
    }

    /** @return list<array{value: string, label: string}> */
    private function intervalOptions(Plan $plan): array
    {
        $offered = [];

        foreach ($plan->billing_interval_options as $option) {
            $interval = is_string($option) ? BillingInterval::tryFrom($option) : null;

            if ($interval !== null) {
                $offered[] = ['value' => $interval->value, 'label' => $this->intervalLabel($interval->value)];
            }
        }

        // A plan whose column is empty or entirely unparseable still needs a submittable form; without the
        // fallback the interval select renders empty and the assign button can never succeed.
        return $offered === [] ? $this->allIntervals() : $offered;
    }

    /** @return list<array{value: string, label: string}> */
    private function allIntervals(): array
    {
        return array_map(
            fn (BillingInterval $i): array => ['value' => $i->value, 'label' => $this->intervalLabel($i->value)],
            BillingInterval::cases(),
        );
    }

    private function intervalLabel(string $interval): string
    {
        return $interval === BillingInterval::Yearly->value ? 'Yearly' : 'Monthly';
    }

    /**
     * Usage split on {@see UsageMetric::isGauge()} — a semantic split the enum argues for itself, not a
     * layout preference. A gauge is a current LEVEL (it moves down as well as up); a flow is a per-period
     * counter, which is why the page qualifies the flow block rather than every row in it.
     *
     * `available` is false only when the snapshot is null, i.e. no plan catalog resolved at all. The page
     * says so rather than rendering eight honest-looking zeros.
     *
     * @param  array{plan: array{code: string, name: string}, features: array<string, bool>, quotas: array<string, array{limit: int|null, used: int}>}|null  $entitlements
     * @return array<string, mixed>
     */
    private function usage(?array $entitlements): array
    {
        if ($entitlements === null) {
            return ['available' => false, 'gauges' => [], 'flows' => []];
        }

        $gauges = [];
        $flows = [];

        foreach (UsageMetric::cases() as $metric) {
            $quota = $entitlements['quotas'][$metric->value] ?? ['limit' => null, 'used' => 0];
            $row = $this->metricRow($metric, $quota['limit'], $quota['used']);

            if ($metric->isGauge()) {
                $gauges[] = $row;
            } else {
                $flows[] = $row;
            }
        }

        return ['available' => true, 'gauges' => $gauges, 'flows' => $flows];
    }

    /** @return array<string, mixed> */
    private function metricRow(UsageMetric $metric, ?int $limit, int $used): array
    {
        $isBytes = $metric === UsageMetric::StorageBytes;
        $usedText = $isBytes ? $this->bytes($used) : number_format($used);

        return [
            'metric' => $metric->value,
            'label' => $metric->label(),
            'limit' => $limit,
            'used' => $used,
            'unlimited' => $limit === null,
            'display' => $limit === null
                ? $usedText
                : $usedText.' / '.($isBytes ? $this->bytes($limit) : number_format($limit)),
            'at_limit' => $limit !== null && $used >= $limit,
        ];
    }

    private function bytes(int $value): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $value;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) (int) $size : number_format($size, 1)).' '.$units[$unit];
    }

    /**
     * The feature table: what the plan grants beside what the tenant actually has.
     *
     * The key universe is the UNION of every plan's flags, not the current plan's own key set. The seeded
     * catalog fills all keys on every tier, so the two agree in practice — but a factory-made or
     * hand-edited plan row does not, and keying off one plan would silently shorten the table rather than
     * showing a missing flag as `false`.
     *
     * @param  list<array<string, mixed>>  $catalog
     * @param  array<string, mixed>|null  $currentPlan
     * @param  array{plan: array{code: string, name: string}, features: array<string, bool>, quotas: array<string, array{limit: int|null, used: int}>}|null  $entitlements
     * @return list<array<string, mixed>>
     */
    private function features(array $catalog, ?array $currentPlan, ?array $entitlements): array
    {
        if ($entitlements === null) {
            return [];
        }

        $keys = [];
        foreach ($catalog as $plan) {
            /** @var array<string, mixed> $flags */
            $flags = is_array($plan['feature_flags']) ? $plan['feature_flags'] : [];
            $keys = [...$keys, ...array_keys($flags)];
        }
        $keys = array_values(array_unique([...$keys, ...array_keys($entitlements['features'])]));
        sort($keys);

        /** @var array<string, mixed> $grantedFlags */
        $grantedFlags = is_array($currentPlan['feature_flags'] ?? null) ? $currentPlan['feature_flags'] : [];

        $rows = [];
        foreach ($keys as $key) {
            $grants = (bool) ($grantedFlags[$key] ?? false);
            $effective = (bool) ($entitlements['features'][$key] ?? false);

            $rows[] = [
                'key' => $key,
                'label' => Arr::get(
                    ['ocr_single' => 'OCR (single)', 'ocr_linelist' => 'OCR (line list)', 'api_access' => 'API access', 'sso_saml' => 'SSO (SAML)'],
                    $key,
                    ucfirst(str_replace('_', ' ', $key)),
                ),
                'plan_grants' => $grants,
                'effective' => $effective,
                // Only `tenant_disabled` is reachable for a ToggleableModules key; anything else that is
                // on without a grant came from a legacy_overrides row (ADR-0008 §D5).
                'reason' => match (true) {
                    $grants === $effective => null,
                    $grants && ! $effective && in_array($key, ToggleableModules::KEYS, true) => 'tenant_disabled',
                    $grants && ! $effective => 'unavailable',
                    default => 'legacy_override',
                },
            ];
        }

        return $rows;
    }

    /**
     * Custom-domain rows, built from {@see DomainPresenter::row()} directly rather than its `index()`.
     * `index()` is tenant-request-only — it calls `EntitlementService::feature()` and `$user->can()`, both
     * of which resolve false on the central host — so reusing it here would report every workspace as
     * un-entitled. `forTenant()` itself needs no context (`domains` is RLS-exempt and its explicit tenant
     * filter IS the isolation) and already excludes the dotless subdomain label.
     *
     * `verification` is dropped: it is the tenant's own DNS-recovery affordance, the console offers no
     * copy-to-clipboard, and `failure_reason`/`failure_hint` already tell an operator what went wrong.
     *
     * @return list<array<string, mixed>>
     */
    private function domainList(Tenant $tenant, string $publicHost): array
    {
        return array_values(
            $this->domains->forTenant($tenant)
                ->map(fn (Domain $domain): array => Arr::except($this->domainRows->row($domain, $publicHost), ['verification']))
                ->all()
        );
    }
}
