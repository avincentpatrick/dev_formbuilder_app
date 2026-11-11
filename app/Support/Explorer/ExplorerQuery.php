<?php

declare(strict_types=1);

namespace App\Support\Explorer;

use App\Enums\ExplorerAggregate;
use App\Enums\ExplorerBucket;
use App\Enums\ExplorerChartType;
use App\Enums\ExplorerFilterOperator;
use App\Enums\ExplorerSort;
use App\Exceptions\Explorer\InvalidExplorerQueryException;
use App\Models\ExplorerChart;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

/**
 * A data-explorer chart, as a DECLARATION (ADR-0025 §D2).
 *
 * What a reader builds on `/explore` is this object and nothing else: a data source, up to two group-bys, up
 * to three values, some filters, a date range and a chart type. It names fields by KEY. It never carries a
 * table, a column or a fragment of SQL — the catalogue owns those — so there is no input here that can read
 * something its author could not already open.
 *
 * ── Bounded by construction ─────────────────────────────────────────────────────────────────────────────
 * ADR-0011 §D7 requires an analytics query to be time-bounded and fixed-cardinality, and ADR-0025 §D3 keeps
 * that for this surface. So the bounds below are enforced in the CONSTRUCTOR: a request, a saved chart read
 * back from {@see ExplorerChart::$definition} and a test all pass through the same checks,
 * and an unbounded query is unrepresentable rather than merely unvalidated.
 *
 * What is NOT checked here is anything that needs the catalogue: whether the source exists, whether this
 * reader may use it, whether a field is a number. That is the runner's first step, and it raises the same
 * exception type.
 *
 * `from` and `to` are LOCAL DATES in `$timezone`, turned into a half-open pair of instants exactly once,
 * here — the same construction as `AnalyticsQuery`, for the same reason.
 */
final readonly class ExplorerQuery
{
    /** The shape version written into every stored definition. Bump it when a key changes meaning. */
    public const int SCHEMA_VERSION = 1;

    public const int MAX_DIMENSIONS = 2;

    public const int MAX_MEASURES = 3;

    public const int MAX_FILTERS = 8;

    public const int MAX_FILTER_VALUES = 50;

    /** Three years. A year-on-year comparison needs two; the third is headroom, not a target. */
    public const int MAX_RANGE_DAYS = 1096;

    public const int DEFAULT_RANGE_DAYS = 90;

    /** The most groups the first group-by may show before the rest fold into "Other". */
    public const int MAX_LIMIT = 50;

    public const int DEFAULT_LIMIT = 10;

    /** The most periods a timeline may hold. Past it the reader is asked for a longer period. */
    public const int MAX_PERIODS = 400;

    public CarbonImmutable $from;

    public CarbonImmutable $to;

    /**
     * @param  list<ExplorerDimension>  $dimensions
     * @param  list<ExplorerMeasure>  $measures
     * @param  list<ExplorerFilter>  $filters
     */
    public function __construct(
        public string $source,
        public ?string $formId,
        public ExplorerChartType $chart,
        public array $dimensions,
        public array $measures,
        public array $filters,
        CarbonImmutable $from,
        CarbonImmutable $to,
        public string $timezone,
        public ExplorerSort $sort = ExplorerSort::ValueDescending,
        public int $limit = self::DEFAULT_LIMIT,
    ) {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw InvalidExplorerQueryException::unknownTimezone($timezone);
        }

        $this->from = $from->startOfDay();
        $this->to = $to->startOfDay();

        if ($this->to->lessThan($this->from)) {
            throw InvalidExplorerQueryException::rangeInverted();
        }

        if ($this->spanInDays() > self::MAX_RANGE_DAYS) {
            throw InvalidExplorerQueryException::rangeTooLong($this->spanInDays(), self::MAX_RANGE_DAYS);
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw InvalidExplorerQueryException::limitOutOfRange(self::MAX_LIMIT);
        }

        $this->assertShape();
    }

    public function spanInDays(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /** The instant the range opens: local midnight of `from`, in UTC. */
    public function fromUtc(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->from->toDateString(), $this->timezone)->startOfDay()->utc();
    }

    /** The instant the range closes, EXCLUSIVE: local midnight of the day after `to`, in UTC. */
    public function toUtcExclusive(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->to->toDateString(), $this->timezone)->startOfDay()->addDay()->utc();
    }

    /**
     * The stored shape. One author, so a saved chart cannot carry a key this class does not read.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'v' => self::SCHEMA_VERSION,
            'source' => $this->source,
            'form_id' => $this->formId,
            'chart' => $this->chart->value,
            'dimensions' => array_map(static fn (ExplorerDimension $d): array => $d->toArray(), $this->dimensions),
            'measures' => array_map(static fn (ExplorerMeasure $m): array => $m->toArray(), $this->measures),
            'filters' => array_map(static fn (ExplorerFilter $f): array => $f->toArray(), $this->filters),
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'timezone' => $this->timezone,
            'sort' => $this->sort->value,
            'limit' => $this->limit,
        ];
    }

    /**
     * Read a declaration from a request body or a stored definition.
     *
     * Total over arbitrary input: whatever arrives, the result is a valid query or one
     * {@see InvalidExplorerQueryException}. A stored row written under an older shape, or edited by hand in
     * the database, therefore becomes a sentence on the page and never a type error.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $version = $data['v'] ?? self::SCHEMA_VERSION;
        if ($version !== self::SCHEMA_VERSION) {
            throw InvalidExplorerQueryException::malformed('it was saved in a format this version does not read');
        }

        $source = $data['source'] ?? null;
        if (! is_string($source) || $source === '') {
            throw InvalidExplorerQueryException::malformed('it names no data source');
        }

        $formId = $data['form_id'] ?? null;
        if ($formId !== null && ! is_string($formId)) {
            throw InvalidExplorerQueryException::malformed('its form is not an id');
        }

        $chart = self::enum(ExplorerChartType::class, $data['chart'] ?? null, 'chart type');
        $sort = self::enum(ExplorerSort::class, $data['sort'] ?? ExplorerSort::ValueDescending->value, 'sort order');

        $limit = $data['limit'] ?? self::DEFAULT_LIMIT;
        if (! is_int($limit) && ! (is_string($limit) && ctype_digit($limit))) {
            throw InvalidExplorerQueryException::limitOutOfRange(self::MAX_LIMIT);
        }

        $timezone = $data['timezone'] ?? null;
        if (! is_string($timezone)) {
            throw InvalidExplorerQueryException::unknownTimezone('');
        }

        return new self(
            source: $source,
            formId: $formId === '' ? null : $formId,
            chart: $chart,
            dimensions: self::dimensions($data['dimensions'] ?? []),
            measures: self::measures($data['measures'] ?? []),
            filters: self::filters($data['filters'] ?? []),
            from: self::date($data['from'] ?? null),
            to: self::date($data['to'] ?? null),
            timezone: $timezone,
            sort: $sort,
            limit: (int) $limit,
        );
    }

    /**
     * The counts each chart type admits, and the two rules no chart type relaxes: at least one value, and no
     * field grouped twice.
     */
    private function assertShape(): void
    {
        if (count($this->dimensions) > self::MAX_DIMENSIONS) {
            throw InvalidExplorerQueryException::tooMany('group-bys', self::MAX_DIMENSIONS);
        }

        if (count($this->measures) > self::MAX_MEASURES) {
            throw InvalidExplorerQueryException::tooMany('values', self::MAX_MEASURES);
        }

        if (count($this->filters) > self::MAX_FILTERS) {
            throw InvalidExplorerQueryException::tooMany('filters', self::MAX_FILTERS);
        }

        if ($this->measures === []) {
            throw InvalidExplorerQueryException::chartShape($this->chart->value, 'needs at least one value');
        }

        $dimensions = count($this->dimensions);
        $name = strtolower($this->chart->label());

        if ($dimensions < $this->chart->minDimensions()) {
            throw InvalidExplorerQueryException::chartShape($name, 'needs something to group by');
        }

        if ($dimensions > $this->chart->maxDimensions()) {
            throw InvalidExplorerQueryException::chartShape(
                $name,
                $this->chart->maxDimensions() === 0 ? 'shows totals, so it has no group-by' : 'takes one group-by',
            );
        }

        $maxMeasures = $this->chart->maxMeasures($dimensions);
        if (count($this->measures) > $maxMeasures) {
            throw InvalidExplorerQueryException::chartShape(
                $name,
                $dimensions === 2 && $maxMeasures === 1
                    ? 'split by a second field shows one value'
                    : 'shows one value',
            );
        }

        if ($dimensions === 2 && $this->dimensions[0]->field === $this->dimensions[1]->field) {
            throw InvalidExplorerQueryException::duplicateDimension();
        }

        foreach ($this->measures as $measure) {
            if ($measure->aggregate->needsField() && $measure->field === null) {
                throw InvalidExplorerQueryException::measureNeedsField(strtolower($measure->aggregate->label()));
            }
        }
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private static function enum(string $enum, mixed $value, string $what): \BackedEnum
    {
        $case = is_string($value) ? $enum::tryFrom($value) : null;

        if ($case === null) {
            throw InvalidExplorerQueryException::malformed("its {$what} is not one this version knows");
        }

        return $case;
    }

    private static function date(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw InvalidExplorerQueryException::rangeMissing();
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (Throwable) {
            throw InvalidExplorerQueryException::rangeMissing();
        }

        // `createFromFormat` rolls 2026-02-31 over into March without complaint; a range that silently
        // means a different day than it says is the defect, so the round trip has to be exact.
        if ($date === null || $date->toDateString() !== $value) {
            throw InvalidExplorerQueryException::rangeMissing();
        }

        return $date;
    }

    /** @return list<ExplorerDimension> */
    private static function dimensions(mixed $rows): array
    {
        $out = [];

        foreach (self::rows($rows, 'group-bys', self::MAX_DIMENSIONS) as $row) {
            $bucket = $row['bucket'] ?? null;

            $out[] = new ExplorerDimension(
                self::fieldKey($row['field'] ?? null),
                $bucket === null || $bucket === '' ? null : self::enum(ExplorerBucket::class, $bucket, 'date grouping'),
            );
        }

        return $out;
    }

    /** @return list<ExplorerMeasure> */
    private static function measures(mixed $rows): array
    {
        $out = [];

        foreach (self::rows($rows, 'values', self::MAX_MEASURES) as $row) {
            $field = $row['field'] ?? null;

            $out[] = new ExplorerMeasure(
                self::enum(ExplorerAggregate::class, $row['aggregate'] ?? null, 'value'),
                $field === null || $field === '' ? null : self::fieldKey($field),
            );
        }

        return $out;
    }

    /** @return list<ExplorerFilter> */
    private static function filters(mixed $rows): array
    {
        $out = [];

        foreach (self::rows($rows, 'filters', self::MAX_FILTERS) as $row) {
            $values = $row['values'] ?? [];
            if (! is_array($values) || count($values) > self::MAX_FILTER_VALUES) {
                throw InvalidExplorerQueryException::tooMany('values in one filter', self::MAX_FILTER_VALUES);
            }

            $clean = [];
            foreach ($values as $value) {
                if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                    throw InvalidExplorerQueryException::malformed('a filter holds something that is not text or a number');
                }

                $clean[] = (string) $value;
            }

            $out[] = new ExplorerFilter(
                self::fieldKey($row['field'] ?? null),
                self::enum(ExplorerFilterOperator::class, $row['operator'] ?? null, 'filter'),
                $clean,
            );
        }

        return $out;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function rows(mixed $rows, string $what, int $max): array
    {
        if (! is_array($rows)) {
            throw InvalidExplorerQueryException::malformed("its {$what} are not a list");
        }

        if (count($rows) > $max) {
            throw InvalidExplorerQueryException::tooMany($what, $max);
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw InvalidExplorerQueryException::malformed("one of its {$what} is not an entry");
            }

            $out[] = $row;
        }

        return $out;
    }

    private static function fieldKey(mixed $value): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 200) {
            throw InvalidExplorerQueryException::malformed('it names a field that is not a field key');
        }

        return $value;
    }
}
