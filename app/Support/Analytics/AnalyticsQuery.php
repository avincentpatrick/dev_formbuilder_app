<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Enums\AnalyticsAxis;
use App\Enums\AnalyticsFormSelection;
use App\Enums\AnalyticsGranularity;
use App\Exceptions\Analytics\InvalidAnalyticsQueryException;
use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * A bounded analytics declaration (ADR-0011 §D7), and the shape a saved report view stores (§D8).
 *
 * ── Why the bounds live in the constructor ──────────────────────────────────────────────────────────────
 * §D7 says "bounded is enforceable, not aspirational". Enforcing it here — rather than in a FormRequest —
 * means a query object cannot exist in an unbounded state, so the guarantee holds for the saved-view path,
 * the export path and any future caller without each of them remembering the rules. The FormRequest still
 * validates first, so a user sees a 422 with field errors rather than an exception; this is the floor.
 *
 * Three bounds, all mandatory:
 *   (a) TIME — `from`/`to` are required, and the span may not exceed {@see self::MAX_RANGE_DAYS}.
 *   (b) FORM — an explicit set, a scope subtree, or the stated `all` mode.
 *   (c) CARDINALITY — a breakdown returns at most {@see self::MAX_TOP_N} categories plus one remainder, and
 *       a series at most 366 points (the widest range at the finest granularity).
 *
 * ── Dates, not instants ─────────────────────────────────────────────────────────────────────────────────
 * `from` and `to` are LOCAL DATES in `$timezone`, converted to a half-open `[fromUtc, toUtcExclusive)`
 * instant pair exactly once, here. Two reasons this is not incidental. A `BETWEEN … '23:59:59'` upper bound
 * silently loses sub-second rows in the final second — the bug already live in `ReconcileTenantUsageJob:59`
 * — and mixing dates with instants is how the last bucket of a range comes back a day short.
 *
 * ── Why a timezone at all, when there is no tenant timezone column ──────────────────────────────────────
 * Because `date_trunc('day', <timestamptz>)` silently uses the session `TimeZone` GUC, and `config/database.php`
 * sets no `timezone` key on the `pgsql` connection — so Laravel never issues `SET TIME ZONE` and the GUC is
 * whatever `postgresql.conf` says. That is UTC in the container and unknown on the self-hosted box of
 * ADR-0005: the same data would bucket differently on different hosts, and a saved view would not be
 * reproducible. Carrying an explicit IANA zone and binding it into `date_trunc`'s three-argument form
 * (PG 16+) fixes the answer to the declaration rather than to the server. Per-view is also the more useful
 * granularity than per-tenant, which is why §D6's "no new schema" holds here too.
 *
 * ── Why a schema version ────────────────────────────────────────────────────────────────────────────────
 * This object is persisted verbatim in `saved_report_views.definition`. A stored declaration with no version
 * tag cannot be migrated when the shape moves, and §D8's read-time resolution would have no way to tell
 * "written by an older build" from "corrupt".
 */
final readonly class AnalyticsQuery
{
    /** Bumped only alongside a documented migration of stored `definition` payloads. */
    public const int SCHEMA_VERSION = 1;

    /** A full year including a leap day. At `day` granularity this is also the series' point cap. */
    public const int MAX_RANGE_DAYS = 366;

    public const int MAX_FORM_IDS = 100;

    public const int MAX_TOP_N = 20;

    /** Matches §D11's five-series categorical cap, so the default breakdown never needs an Other bucket. */
    public const int DEFAULT_TOP_N = 5;

    /**
     * @param  list<string>  $formIds
     * @param  list<string>  $statuses
     * @param  list<string>  $sources
     * @param  list<string>  $locales
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $timezone = 'UTC',
        public AnalyticsGranularity $granularity = AnalyticsGranularity::Day,
        public AnalyticsFormSelection $selection = AnalyticsFormSelection::All,
        public array $formIds = [],
        public ?string $scopeNodeId = null,
        public AnalyticsAxis $axis = AnalyticsAxis::Form,
        public array $statuses = [],
        public array $sources = [],
        public array $locales = [],
        public int $topN = self::DEFAULT_TOP_N,
    ) {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            // Never interpolated into SQL, but validated anyway: it is bound into date_trunc's zone argument
            // and an unknown zone is a runtime error from PostgreSQL rather than a usable message.
            throw InvalidAnalyticsQueryException::unknownTimezone($timezone);
        }

        if ($to->lessThan($from)) {
            throw InvalidAnalyticsQueryException::rangeInverted();
        }

        $days = $this->spanInDays();

        if ($days > self::MAX_RANGE_DAYS) {
            throw InvalidAnalyticsQueryException::rangeTooLong($days, self::MAX_RANGE_DAYS);
        }

        if ($selection === AnalyticsFormSelection::Forms) {
            if ($formIds === []) {
                throw InvalidAnalyticsQueryException::emptySelection($selection->value);
            }

            if (count($formIds) > self::MAX_FORM_IDS) {
                throw InvalidAnalyticsQueryException::tooManyForms(count($formIds), self::MAX_FORM_IDS);
            }
        }

        if ($selection === AnalyticsFormSelection::ScopeNode && $scopeNodeId === null) {
            throw InvalidAnalyticsQueryException::emptySelection($selection->value);
        }

        if ($topN < 1 || $topN > self::MAX_TOP_N) {
            throw InvalidAnalyticsQueryException::topNOutOfRange(self::MAX_TOP_N);
        }
    }

    /** Inclusive day count, so a single-day range is 1 rather than 0. */
    public function spanInDays(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    /** The instant the range opens: local midnight of `from`, in UTC. */
    public function fromUtc(): CarbonImmutable
    {
        return $this->from->setTimezone($this->timezone)->startOfDay()->utc();
    }

    /**
     * The instant the range closes, EXCLUSIVE: local midnight of the day after `to`, in UTC. Half-open by
     * design — see the class docblock on why a `23:59:59` bound is a defect and not a rounding choice.
     */
    public function toUtcExclusive(): CarbonImmutable
    {
        return $this->to->setTimezone($this->timezone)->startOfDay()->addDay()->utc();
    }

    /**
     * The equal-length range immediately preceding this one — `docs/PRD.md:197`'s "current period vs prior
     * period". Everything else about the declaration is carried over, so the comparison is like-for-like.
     */
    public function priorPeriod(): self
    {
        $days = $this->spanInDays();

        return $this->withRange(
            $this->from->subDays($days),
            $this->to->subDays($days),
        );
    }

    public function withRange(CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self(
            from: $from,
            to: $to,
            timezone: $this->timezone,
            granularity: $this->granularity,
            selection: $this->selection,
            formIds: $this->formIds,
            scopeNodeId: $this->scopeNodeId,
            axis: $this->axis,
            statuses: $this->statuses,
            sources: $this->sources,
            locales: $this->locales,
            topN: $this->topN,
        );
    }

    public function withAxis(AnalyticsAxis $axis): self
    {
        return new self(
            from: $this->from,
            to: $this->to,
            timezone: $this->timezone,
            granularity: $this->granularity,
            selection: $this->selection,
            formIds: $this->formIds,
            scopeNodeId: $this->scopeNodeId,
            axis: $axis,
            statuses: $this->statuses,
            sources: $this->sources,
            locales: $this->locales,
            topN: $this->topN,
        );
    }

    /**
     * The persisted shape (§D8). Dates are stored as LOCAL dates beside their zone rather than as instants,
     * so re-reading a view a year later reproduces the same buckets rather than the same UTC boundaries.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'v' => self::SCHEMA_VERSION,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'timezone' => $this->timezone,
            'granularity' => $this->granularity->value,
            'selection' => $this->selection->value,
            'form_ids' => $this->formIds,
            'scope_node_id' => $this->scopeNodeId,
            'axis' => $this->axis->value,
            'statuses' => $this->statuses,
            'sources' => $this->sources,
            'locales' => $this->locales,
            'top_n' => $this->topN,
        ];
    }

    /**
     * Rebuild from a stored `definition` or a validated request payload.
     *
     * Every enum lookup is `tryFrom`-with-refusal rather than `from`, because this reads persisted data: a
     * value written by a build that knew a case this one does not must produce §D8's stated refusal, not an
     * uncaught `ValueError`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $version = $data['v'] ?? self::SCHEMA_VERSION;

        if ($version !== self::SCHEMA_VERSION) {
            throw InvalidAnalyticsQueryException::malformed("it was saved in format v{$version}");
        }

        $from = $data['from'] ?? null;
        $to = $data['to'] ?? null;

        if (! is_string($from) || ! is_string($to)) {
            throw InvalidAnalyticsQueryException::rangeMissing();
        }

        $timezone = is_string($data['timezone'] ?? null) ? $data['timezone'] : 'UTC';

        return new self(
            from: self::parseDate($from, $timezone),
            to: self::parseDate($to, $timezone),
            timezone: $timezone,
            granularity: self::enum(AnalyticsGranularity::class, $data['granularity'] ?? null, AnalyticsGranularity::Day),
            selection: self::enum(AnalyticsFormSelection::class, $data['selection'] ?? null, AnalyticsFormSelection::All),
            formIds: self::stringList($data['form_ids'] ?? []),
            scopeNodeId: is_string($data['scope_node_id'] ?? null) ? $data['scope_node_id'] : null,
            axis: self::enum(AnalyticsAxis::class, $data['axis'] ?? null, AnalyticsAxis::Form),
            statuses: self::stringList($data['statuses'] ?? []),
            sources: self::stringList($data['sources'] ?? []),
            locales: self::stringList($data['locales'] ?? []),
            topN: is_int($data['top_n'] ?? null) ? $data['top_n'] : self::DEFAULT_TOP_N,
        );
    }

    private static function parseDate(string $value, string $timezone): CarbonImmutable
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw InvalidAnalyticsQueryException::unknownTimezone($timezone);
        }

        // Carbon returns null (not false) on a format mismatch; `createFromFormat` is used rather than
        // `parse` so that "2026-13-45" is a refusal instead of a silently rolled-over date.
        $parsed = CarbonImmutable::createFromFormat('Y-m-d', $value, $timezone);

        if ($parsed === null) {
            throw InvalidAnalyticsQueryException::malformed("'{$value}' is not a YYYY-MM-DD date");
        }

        return $parsed->startOfDay();
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @param  T  $default
     * @return T
     */
    private static function enum(string $enum, mixed $value, mixed $default): mixed
    {
        if ($value === null) {
            return $default;
        }

        if (! is_string($value)) {
            throw InvalidAnalyticsQueryException::malformed('a setting holds an unreadable value');
        }

        $case = $enum::tryFrom($value);

        if ($case === null) {
            throw InvalidAnalyticsQueryException::malformed("'{$value}' is not a setting this version knows");
        }

        return $case;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            throw InvalidAnalyticsQueryException::malformed('a filter holds an unreadable value');
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
