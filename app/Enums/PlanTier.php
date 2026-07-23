<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Subscription;
use App\Services\Entitlements\EntitlementService;

/**
 * The five pricing tiers (data-dictionary.md §16 `PlanTier`, backs `plans.code`; ADR-0008). The schema
 * supports all five, but not all are offered for self-serve signup: `free`/`starter`/`professional`
 * activate in Phase 1; `business` is built + gated in Phase 3 but **held from sale** until Track B exists
 * (ADR-0008 §D6, seeded `is_active = false`); `enterprise` activates in Phase 4. `is_active` — not this
 * enum — governs which plans a tenant may select; a super-admin can assign any tier regardless.
 *
 * The backing values are pinned at the database by the `plans_code_check` CHECK, generated from
 * {@see values()} so the enum and the constraint cannot drift (the `AuditEvent`/`ResourceCapacity` idiom).
 * A tenant's *current* tier is derived from the plan of its active {@see Subscription}, never a
 * column on `tenants` — see {@see EntitlementService}.
 */
enum PlanTier: string
{
    case Free = 'free';
    case Starter = 'starter';
    case Professional = 'professional';
    case Business = 'business';
    case Enterprise = 'enterprise';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
