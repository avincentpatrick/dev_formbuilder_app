<?php

declare(strict_types=1);

namespace App\Services\Explorer;

use App\Enums\ExplorerAggregate;
use App\Enums\ExplorerBucket;
use App\Enums\ExplorerFieldType;
use App\Enums\ExplorerFilterOperator;
use App\Exceptions\Explorer\InvalidExplorerQueryException;
use App\Support\Explorer\ExplorerDimension;
use App\Support\Explorer\ExplorerField;
use App\Support\Explorer\ExplorerFilter;
use App\Support\Explorer\ExplorerMeasure;
use App\Support\Explorer\ExplorerQuery;
use Illuminate\Database\Query\Builder;

/**
 * Turns the parts of a data-explorer declaration into SQL against a source's fields (ADR-0025 §D4).
 *
 * Every method takes a FIELD — something the catalogue produced — and never a name from the request, so the
 * only text that reaches a statement is text this application wrote. The values a reader supplies (a time
 * zone, the values of a filter) are always bound.
 *
 * Group-bys are selected as text. A chart's groups are labels, and one type coming back means the result
 * shaper does not have to know whether a group key started life as a uuid, a boolean or a date.
 */
final class ExplorerCompiler
{
    /**
     * Add one group-by to the query as column `d{index}`.
     *
     * A multi-choice field is unnested with a lateral join, so a response that chose three options is in
     * three groups. That is what "responses by symptom" means, and it is why the page says so beside it.
     */
    public function addDimension(Builder $query, int $index, ExplorerDimension $dimension, ExplorerField $field, string $timezone): void
    {
        $alias = "d{$index}";

        if (! $field->type->isGroupable()) {
            throw InvalidExplorerQueryException::fieldNotGroupable($field->label);
        }

        if ($field->type->isTemporal()) {
            [$sql, $bindings] = $this->bucketSql($field, $dimension->bucket, $timezone);
            $query->selectRaw("{$sql} as {$alias}", $bindings);

            return;
        }

        if ($dimension->bucket !== null) {
            throw InvalidExplorerQueryException::bucketNotAllowed($field->label);
        }

        if ($field->type === ExplorerFieldType::MultiCategory) {
            $query->crossJoin($query->raw("lateral jsonb_array_elements_text({$field->listExpression}) as dl{$index}(value)"));
            $query->selectRaw("dl{$index}.value as {$alias}");

            return;
        }

        $query->selectRaw("({$field->expression})::text as {$alias}");
    }

    /** The SQL of one value, without an alias. `$field` is null only for a row count. */
    public function measureSql(ExplorerMeasure $measure, ?ExplorerField $field): string
    {
        if ($measure->aggregate === ExplorerAggregate::Count || $field === null) {
            return 'count(*)';
        }

        if ($measure->aggregate === ExplorerAggregate::CountDistinct) {
            if ($field->type === ExplorerFieldType::MultiCategory || $field->type->isTemporal()) {
                throw InvalidExplorerQueryException::fieldNotAggregable($field->label, $measure->aggregate->label());
            }

            return "count(distinct {$field->expression})";
        }

        if ($field->type !== ExplorerFieldType::Number) {
            throw InvalidExplorerQueryException::fieldNotAggregable($field->label, $measure->aggregate->label());
        }

        return match ($measure->aggregate) {
            ExplorerAggregate::Sum => "sum({$field->expression})",
            ExplorerAggregate::Average => "avg({$field->expression})",
            ExplorerAggregate::Minimum => "min({$field->expression})",
            ExplorerAggregate::Maximum => "max({$field->expression})",
            default => "percentile_cont(0.5) within group (order by {$field->expression})",
        };
    }

    public function addFilter(Builder $query, ExplorerFilter $filter, ExplorerField $field): void
    {
        $operator = $filter->operator;

        if (! $operator->appliesTo($field->type)) {
            throw InvalidExplorerQueryException::operatorNotAllowed($field->label);
        }

        if ($operator->takesValues() && $filter->values === []) {
            throw InvalidExplorerQueryException::filterNeedsValue($field->label);
        }

        match ($operator) {
            ExplorerFilterOperator::In, ExplorerFilterOperator::NotIn => $this->membership($query, $filter, $field),
            ExplorerFilterOperator::AtLeast, ExplorerFilterOperator::AtMost => $this->comparison($query, $filter, $field),
            ExplorerFilterOperator::IsEmpty, ExplorerFilterOperator::IsNotEmpty => $this->emptiness($query, $filter, $field),
        };
    }

    /**
     * How many periods a timeline over this range would hold — asked BEFORE the query runs, so a three-year
     * range by day is a sentence asking for a longer period and never a thousand-point chart.
     */
    public function periods(ExplorerQuery $query, ExplorerBucket $bucket): int
    {
        $days = $query->spanInDays();

        return match ($bucket) {
            ExplorerBucket::Day => $days,
            ExplorerBucket::Week => intdiv($days, 7) + 2,
            ExplorerBucket::Month => intdiv($days, 28) + 2,
            ExplorerBucket::Quarter => intdiv($days, 90) + 2,
            ExplorerBucket::Year => intdiv($days, 365) + 2,
            ExplorerBucket::Weekday => 7,
            ExplorerBucket::Hour => 24,
        };
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function bucketSql(ExplorerField $field, ?ExplorerBucket $bucket, string $timezone): array
    {
        if ($bucket === null) {
            throw InvalidExplorerQueryException::bucketRequired($field->label);
        }

        $expression = $field->expression;

        // A calendar date an answer holds has no time of day and no zone: it is grouped as written.
        if ($field->type === ExplorerFieldType::Date) {
            return match ($bucket) {
                ExplorerBucket::Hour => throw InvalidExplorerQueryException::bucketNotAllowed($field->label),
                ExplorerBucket::Weekday => ["extract(isodow from {$expression})::int::text", []],
                default => ["date_trunc('{$bucket->value}', ({$expression})::timestamp)::date::text", []],
            };
        }

        // An instant is grouped in the READER's zone, by the construction `AnalyticsMetricsService` uses:
        // truncate in the zone, then read the result as a local date. Truncating the bare `timestamptz`
        // would use the session zone and put a late-evening response in the wrong day.
        return match ($bucket) {
            ExplorerBucket::Weekday => ["extract(isodow from ({$expression} at time zone ?))::int::text", [$timezone]],
            ExplorerBucket::Hour => ["extract(hour from ({$expression} at time zone ?))::int::text", [$timezone]],
            default => ["(date_trunc('{$bucket->value}', {$expression}, ?) at time zone ?)::date::text", [$timezone, $timezone]],
        };
    }

    private function membership(Builder $query, ExplorerFilter $filter, ExplorerField $field): void
    {
        $in = $filter->operator === ExplorerFilterOperator::In;
        $marks = implode(', ', array_fill(0, count($filter->values), '?'));

        if ($field->type === ExplorerFieldType::MultiCategory) {
            $exists = "exists (select 1 from jsonb_array_elements_text({$field->listExpression}) as fv(value) where fv.value in ({$marks}))";
            $query->whereRaw($in ? $exists : "not {$exists}", $filter->values);

            return;
        }

        $text = "({$field->expression})::text";

        // "Is none of" keeps the rows with no value at all: a response that left the question blank did
        // not answer "Yes", and SQL's three-valued `NOT IN` would silently drop it.
        $query->whereRaw(
            $in ? "{$text} in ({$marks})" : "({$text} not in ({$marks}) or {$field->expression} is null)",
            $filter->values,
        );
    }

    private function comparison(Builder $query, ExplorerFilter $filter, ExplorerField $field): void
    {
        $value = $filter->values[0];

        if (! is_numeric($value)) {
            throw InvalidExplorerQueryException::filterNeedsValue($field->label);
        }

        $sign = $filter->operator === ExplorerFilterOperator::AtLeast ? '>=' : '<=';

        $query->whereRaw("{$field->expression} {$sign} ?::numeric", [$value]);
    }

    private function emptiness(Builder $query, ExplorerFilter $filter, ExplorerField $field): void
    {
        $empty = match ($field->type) {
            ExplorerFieldType::MultiCategory => "jsonb_array_length({$field->listExpression}) = 0",
            ExplorerFieldType::Number => "{$field->expression} is null",
            default => "({$field->expression} is null or ({$field->expression})::text = '')",
        };

        $query->whereRaw($filter->operator === ExplorerFilterOperator::IsEmpty ? $empty : "not ({$empty})");
    }
}
