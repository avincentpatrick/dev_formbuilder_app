<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Enums\EnforcementMode;
use App\Enums\UsageMetric;
use App\Exceptions\Entitlements\QuotaExceededException;
use App\Jobs\TenantAwareJob;
use LogicException;

/**
 * The single hard-block enforcement point (H5b / ADR-0008 §D4). Called at every create/upload/invite site
 * for a provisioning gauge (`forms_count`, `storage_bytes`, `active_seats`) BEFORE the row/bytes are
 * written: it compares the LIVE level ({@see EntitlementService::countGauge()}) against the tenant's plan
 * quota and throws {@see QuotaExceededException} when the create would cross it.
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
}
