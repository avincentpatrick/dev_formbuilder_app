<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Enums\EnforcementMode;
use App\Enums\UsageMetric;
use App\Exceptions\Entitlements\QuotaExceededException;
use App\Exceptions\Entitlements\RateLimitExceededException;
use App\Jobs\TenantAwareJob;
use LogicException;

/**
 * The single quota-enforcement point (ADR-0008 §D4). Two classes, two methods:
 * {@see assertCanCreate()} (H5b) hard-blocks a provisioning gauge (`forms_count`, `storage_bytes`,
 * `active_seats`) BEFORE the row/bytes are written, comparing the LIVE level
 * ({@see EntitlementService::countGauge()}) against the plan quota and throwing {@see QuotaExceededException}
 * (402); {@see assertWithinRateQuota()} (H5c) 429s a rate-limit metric (`api_requests`, `webhook_deliveries`)
 * whose metered current-period usage has reached the monthly quota, throwing {@see RateLimitExceededException}.
 * The never-block metric (`submissions_count`) is enforced by neither.
 *
 * A null quota is unlimited — the same fail-open state as off-tenant or an unseeded catalog — and never
 * blocks, so the guard is inert until a real quota is assigned (which is why the existing suite, which
 * seeds no plans, is unaffected). Only the three hard-block gauges may be asserted here: a never-block
 * metric (`submissions_count`) or a rate-limit metric reaching this method is a programming error, not a
 * runtime condition, so it is a {@see LogicException}, not a refusal.
 *
 * Context-agnostic — {@see EntitlementService::countGauge()} is a plain RLS-scoped read, correct in-request
 * AND inside a {@see TenantAwareJob} transaction (the "incl. in-worker" clause: no such create
 * path exists today, but the guard is ready for one). It reads through the one entitlement resolver, so UI
 * gating and server enforcement cannot diverge.
 */
final class QuotaGuard
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /**
     * Assert the current tenant may create `$incoming` more of `$metric` before writing it. `$incoming`
     * defaults to 1 (one form / one seat); for `storage_bytes` it is the byte size of the incoming file.
     *
     * @throws QuotaExceededException when the create would cross the plan limit
     */
    public function assertCanCreate(UsageMetric $metric, int $incoming = 1): void
    {
        if ($metric->enforcementMode() !== EnforcementMode::HardBlock) {
            throw new LogicException("QuotaGuard only enforces hard-block metrics; {$metric->value} is not one.");
        }

        $limit = $this->entitlements->quota($metric);

        if ($limit === null) {
            return; // unlimited, off-tenant, or unseeded catalog — never block
        }

        $used = $this->entitlements->countGauge($metric);

        if ($used + $incoming > $limit) {
            throw QuotaExceededException::forMetric($metric, $limit, $used);
        }
    }

    /**
     * Assert the current tenant is within its per-month quota for a rate-limit metric (`api_requests`,
     * `webhook_deliveries`) before serving the request. Reads the metered current-period usage (a FLOW
     * metric from `usage_counters`), NOT a live gauge.
     *
     * A null quota is unlimited, and a quota of 0 is the Free "no access" sentinel — reached here only when a
     * grandfather override has granted the paired feature (e.g. `api_access`), which must NOT translate to a
     * 0-request throttle. So no positive monthly quota ever 429s; access itself is governed by the feature
     * gate upstream. Only a rate-limit metric may be asserted here — anything else is a programming error.
     *
     * @throws RateLimitExceededException when the metered usage has reached the plan's monthly quota
     */
    public function assertWithinRateQuota(UsageMetric $metric): void
    {
        if ($metric->enforcementMode() !== EnforcementMode::RateLimit) {
            throw new LogicException("QuotaGuard::assertWithinRateQuota only enforces rate-limit metrics; {$metric->value} is not one.");
        }

        $limit = $this->entitlements->quota($metric);

        if ($limit === null || $limit <= 0) {
            return; // unlimited, off-tenant/unseeded, or the Free "no access" sentinel — never 429
        }

        $used = $this->entitlements->usage($metric);

        if ($used >= $limit) {
            throw RateLimitExceededException::forMetric($metric, $limit, $used);
        }
    }

    /**
     * The non-throwing predicate behind {@see assertWithinRateQuota()}, for callers that must DECIDE rather
     * than reject — the H13a webhook delivery job hard-caps `webhook_deliveries` by dead-lettering an
     * over-quota delivery instead of throwing (there is no inbound request to 429). Same semantics: a null,
     * off-tenant, or `<= 0` (Free "no access" sentinel) quota is never a cap, so this returns true; otherwise
     * true iff the metered current-period usage is below the plan's monthly quota. Only a rate-limit metric
     * may be asked — anything else is a programming error.
     */
    public function hasRateQuotaRemaining(UsageMetric $metric): bool
    {
        if ($metric->enforcementMode() !== EnforcementMode::RateLimit) {
            throw new LogicException("QuotaGuard::hasRateQuotaRemaining only enforces rate-limit metrics; {$metric->value} is not one.");
        }

        $limit = $this->entitlements->quota($metric);

        if ($limit === null || $limit <= 0) {
            return true;
        }

        return $this->entitlements->usage($metric) < $limit;
    }
}
