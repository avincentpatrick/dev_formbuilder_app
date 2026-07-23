<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a {@see UsageMetric}'s quota is enforced (H5b / ADR-0008 §D4, pricing-feature-gating-matrix.md §2).
 * The three-way split is the spine of enforcement, and it exists for one reason: a respondent's completed
 * submission must NEVER be rejected over the tenant's billing status.
 *
 *   - {@see HardBlock}  — resource-provisioning gauges (`forms_count`, `storage_bytes`, `active_seats`):
 *     safe to refuse past the limit; no data-loss risk.
 *   - {@see NeverBlock} — data collection (`submissions_count`): metered + upsold, never gated.
 *   - {@see RateLimit}  — API/webhook traffic (`api_requests`, `webhook_deliveries`): throttled (429/backoff),
 *     never destroyed.
 *   - {@see Unclassified} — the honest default for a metered-but-ungated metric (`exports_count`) that §D4
 *     places in none of the three classes.
 *
 * Not a string-backed enum: it backs no DB column and no CHECK — it is a pure code classification.
 */
enum EnforcementMode
{
    case HardBlock;
    case NeverBlock;
    case RateLimit;
    case Unclassified;
}
