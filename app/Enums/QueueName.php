<?php

declare(strict_types=1);

namespace App\Enums;

use App\Jobs\MaintenanceJob;
use App\Jobs\TenantAwareJob;
use Illuminate\Queue\Attributes\Queue as QueueAttribute;

/**
 * The five queue names (ADR-0007 §D6), adopted verbatim from `docs/deployment-infrastructure.md` §6
 * and `docs/observability-incident-response.md`. Before ADR-0007 this catalog was documented as
 * binding while `onQueue(` had ZERO hits repository-wide; this enum is what makes it real.
 *
 * Priority runs highest-first in declaration order: `submissions`/`webhooks` are user-facing or
 * near-real-time, `exports`/`ocr-processing` show a pending state, `scheduled-maintenance` is
 * background sweeping. That order is expressed as the `--queue=` ordering string in the documented
 * `queue:work` invocation ({@see self::workerOrder()}) until a supervisor with real per-queue
 * weighting exists — there is no Horizon (§D1).
 *
 * A job selects its queue with the framework's {@see QueueAttribute} class attribute, NEVER a `$queue`
 * property: `Illuminate\Bus\Queueable:29` declares `public $queue;` untyped, so redeclaring it with a
 * different initial value is a FATAL ERROR in any class using that trait. The attribute takes a
 * `UnitEnum|string`, so a case of this enum passes directly. It is read first by
 * `Illuminate\Bus\Dispatcher::pushCommandToQueue()` and is INHERITED from a base class
 * (`ReadsClassAttributes::getAttributeInstance()` walks `getParentClass()` in a do/while), which is
 * why {@see MaintenanceJob} can declare it once for every sweep. `scripts/job-payload-lint.php`
 * asserts every concrete {@see TenantAwareJob}/{@see MaintenanceJob} carries the attribute with a
 * value drawn from this enum.
 */
enum QueueName: string
{
    case Submissions = 'submissions';
    case Webhooks = 'webhooks';
    case Exports = 'exports';
    case OcrProcessing = 'ocr-processing';
    case ScheduledMaintenance = 'scheduled-maintenance';

    /**
     * Laravel's un-annotated fallback. Deliberately NOT a case: nothing may target it on purpose, and
     * it is deliberately absent from {@see self::workerOrder()} so that a job which escaped the linter
     * strands visibly (`select queue, count(*) from jobs group by 1`) rather than being silently
     * rescued by the worker — silently rescuing it would defeat the gate that exists to catch it.
     */
    public const string FALLBACK = 'default';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The `--queue=` argument for `queue:work`, in priority order. The compose `worker` service's
     * command and `docs/deployment-infrastructure.md` §6 must agree with this byte-for-byte.
     */
    public static function workerOrder(): string
    {
        return implode(',', self::values());
    }
}
