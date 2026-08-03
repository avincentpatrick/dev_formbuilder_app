<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Analytics\AnalyticsMetricsService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The time-bucket width of an analytics series (ADR-0011 §D7, Increment H24a).
 *
 * Paired with `AnalyticsQuery::MAX_RANGE_DAYS = 366`, this is what makes D7's "fixed-cardinality result"
 * enforceable rather than aspirational: the widest permitted range at the finest granularity is 366 points,
 * so no series can grow unbounded regardless of how much data a tenant has.
 *
 * The value is bound straight into PostgreSQL's `date_trunc(field, source, zone)` — hence a closed enum
 * rather than a string: an unvalidated field name would be a raw fragment reaching a SQL function.
 */
enum AnalyticsGranularity: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /**
     * Move `$at` to the start of the bucket containing it, matching `date_trunc` exactly.
     *
     * This is the PHP twin of the SQL, and it exists because gap-filling happens in PHP (see
     * {@see AnalyticsMetricsService::series()}). The two must agree on where a
     * bucket starts or a filled zero would land on a label the aggregate never emits.
     *
     * **`date_trunc('week', …)` is ISO — Monday-start, and not configurable.** Carbon's `startOfWeek()`
     * honours the app locale, so the day is pinned explicitly here rather than inherited; a Sunday-start
     * locale would otherwise silently produce labels one day off from the SQL.
     */
    public function floor(CarbonImmutable $at): CarbonImmutable
    {
        return match ($this) {
            self::Day => $at->startOfDay(),
            self::Week => $at->startOfWeek(CarbonInterface::MONDAY),
            self::Month => $at->startOfMonth(),
        };
    }

    /** The next bucket's start, for stepping the axis while gap-filling. */
    public function next(CarbonImmutable $at): CarbonImmutable
    {
        return match ($this) {
            self::Day => $at->addDay(),
            self::Week => $at->addWeek(),
            self::Month => $at->addMonth(),
        };
    }
}
