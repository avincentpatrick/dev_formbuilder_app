<?php

declare(strict_types=1);

namespace App\Services\Explorer;

use App\Enums\ExplorerFieldType;
use App\Exceptions\Explorer\InvalidExplorerQueryException;
use App\Models\User;
use App\Services\Explorer\Sources\ExplorerSource;
use App\Support\Explorer\ExplorerDimension;
use App\Support\Explorer\ExplorerField;
use App\Support\Explorer\ExplorerMeasure;
use App\Support\Explorer\ExplorerQuery;
use Illuminate\Database\Query\Builder;

/**
 * Runs a data-explorer chart (ADR-0025 §D3, §D4).
 *
 * One declaration in, one result out, and at most two statements in between: the grouped query the chart
 * is drawn from, and an ungrouped one over the same rows for the totals. Both run under the request's
 * tenant context like every other read in the application; nothing here elevates.
 *
 * ── Bounded, in the three ways ADR-0011 §D7 asks ────────────────────────────────────────────────────────
 * TIME: the range is mandatory and capped by {@see ExplorerQuery}, and it is applied to the source's
 * range column as a bare comparison, so the tenant-and-date indexes can serve it. ROWS: the source decides
 * which rows this reader may see before anything else is added. CARDINALITY: the grouped query returns at
 * most {@see self::ROW_CAP} groups, largest first; a timeline is refused before it runs if it would hold
 * more than {@see ExplorerQuery::MAX_PERIODS} periods; and the shaper folds everything past the chart's
 * limit into one bucket. No path returns a row per response.
 */
final class ExplorerQueryRunner
{
    /**
     * The most groups one query reads. Two group-bys multiply — 400 periods by a dozen forms — so this is
     * well above any chart's limit, and a query that hits it says so in its result instead of quietly
     * drawing a partial picture.
     */
    public const int ROW_CAP = 5000;

    public function __construct(
        private readonly ExplorerCatalog $catalog,
        private readonly ExplorerCompiler $compiler,
        private readonly ExplorerResultShaper $shaper,
    ) {}

    /**
     * @return array<string, mixed> the result the page draws — see `resources/js/components/explore/types.ts`
     */
    public function run(ExplorerQuery $query, User $user): array
    {
        [$source, $form] = $this->catalog->resolve($query, $user);

        $fields = [];
        foreach ($source->fields($user, $form) as $field) {
            $fields[$field->key] = $field;
        }

        $dimensions = array_map(
            fn (ExplorerDimension $dimension): array => [$dimension, $this->field($fields, $dimension->field)],
            $query->dimensions,
        );

        $measures = array_map(
            fn (ExplorerMeasure $measure): array => [$measure, $measure->field === null ? null : $this->field($fields, $measure->field)],
            $query->measures,
        );

        $rangeField = $this->field($fields, $source->rangeField());
        $this->assertPeriods($query, $dimensions, $rangeField);

        $base = $source->query($user, $form)
            ->whereRaw("{$rangeField->expression} >= ?", [$query->fromUtc()->toIso8601String()])
            ->whereRaw("{$rangeField->expression} < ?", [$query->toUtcExclusive()->toIso8601String()]);

        foreach ($query->filters as $filter) {
            $this->compiler->addFilter($base, $filter, $this->field($fields, $filter->field));
        }

        $measureSql = [];
        foreach ($measures as [$measure, $field]) {
            $measureSql[] = $this->compiler->measureSql($measure, $field);
        }

        $labels = array_map(fn (array $pair): string => $this->shaper->measureLabel($pair[0], $pair[1], $source->rowNoun()), $measures);

        $totals = $this->totals(clone $base, $measures, $measureSql);
        [$rows, $capped] = $this->grouped($base, $query, $dimensions, $measureSql);

        $shape = $this->shaper->shape($query, $dimensions, $measures, $labels, $rows, $rangeField->key);

        return [
            'chart' => $query->chart->value,
            'dimensions' => array_map(static fn (array $pair): array => [
                'key' => $pair[1]->key,
                'label' => $pair[1]->label,
                'type' => $pair[1]->type->value,
                'bucket' => $pair[0]->bucket?->value,
            ], $dimensions),
            'measures' => array_map(static fn (array $pair, int $index): array => [
                'key' => "m{$index}",
                'label' => $labels[$index],
                'aggregate' => $pair[0]->aggregate->value,
                'additive' => $pair[0]->aggregate->isAdditive(),
            ], $measures, array_keys($measures)),
            'categories' => $shape['categories'],
            'series' => $shape['series'],
            'totals' => $totals,
            'meta' => [
                'source' => $source->key(),
                'source_label' => $source->label(),
                'row_noun' => $source->rowNoun(),
                'form' => $form === null ? null : ['id' => (string) $form->getKey(), 'title' => (string) $form->title],
                'from' => $query->from->toDateString(),
                'to' => $query->to->toDateString(),
                'timezone' => $query->timezone,
                'range_label' => $rangeField->label,
                'groups' => $shape['groups'],
                'hidden_groups' => $shape['hidden_groups'],
                'hidden_series' => $shape['hidden_series'],
                'capped' => $capped,
                'notes' => $this->notes($source, $source->notes($user, $form), $dimensions, $capped),
            ],
        ];
    }

    /**
     * Check a declaration against the catalogue without running it — for a save, where a chart that names
     * a field of another source should be refused while its author can still fix it.
     *
     * It builds exactly the statement {@see run()} would send, which is the point: there is no second,
     * lighter list of rules to drift from the real one.
     */
    public function assertRunnable(ExplorerQuery $query, User $user): void
    {
        [$source, $form] = $this->catalog->resolve($query, $user);

        $fields = [];
        foreach ($source->fields($user, $form) as $field) {
            $fields[$field->key] = $field;
        }

        $rangeField = $this->field($fields, $source->rangeField());
        $builder = $source->query($user, $form);

        foreach ($query->dimensions as $index => $dimension) {
            $this->compiler->addDimension($builder, $index, $dimension, $this->field($fields, $dimension->field), $query->timezone);
        }

        foreach ($query->measures as $measure) {
            $this->compiler->measureSql($measure, $measure->field === null ? null : $this->field($fields, $measure->field));
        }

        foreach ($query->filters as $filter) {
            $this->compiler->addFilter($builder, $filter, $this->field($fields, $filter->field));
        }

        $this->assertPeriods(
            $query,
            array_map(fn (ExplorerDimension $dimension): array => [$dimension, $this->field($fields, $dimension->field)], $query->dimensions),
            $rangeField,
        );
    }

    /**
     * @param  array<string, ExplorerField>  $fields
     */
    private function field(array $fields, string $key): ExplorerField
    {
        return $fields[$key] ?? throw InvalidExplorerQueryException::unknownField($key);
    }

    /**
     * @param  list<array{0: ExplorerDimension, 1: ExplorerField}>  $dimensions
     */
    private function assertPeriods(ExplorerQuery $query, array $dimensions, ExplorerField $rangeField): void
    {
        foreach ($dimensions as [$dimension, $field]) {
            if ($dimension->bucket === null || $field->key !== $rangeField->key) {
                continue;
            }

            $periods = $this->compiler->periods($query, $dimension->bucket);

            if ($periods > ExplorerQuery::MAX_PERIODS) {
                throw InvalidExplorerQueryException::tooManyPeriods($periods, ExplorerQuery::MAX_PERIODS);
            }
        }
    }

    /**
     * The whole range as one group: how many rows, each value over all of them, and — for a value taken
     * over a field — how many rows HAD that field. The last is the coverage the page prints, because a
     * guarded cast contributes nothing for a row it could not read and the reader is owed the count.
     *
     * @param  list<array{0: ExplorerMeasure, 1: ExplorerField|null}>  $measures
     * @param  list<literal-string>  $measureSql
     * @return array{rows: int, values: list<int|float|null>, coverage: list<int|null>}
     */
    private function totals(Builder $query, array $measures, array $measureSql): array
    {
        $query->selectRaw('count(*) as total');

        foreach ($measures as $index => [, $field]) {
            $alias = ExplorerCompiler::measureAlias($index);
            $query->selectRaw("{$measureSql[$index]} as {$alias}");

            if ($field !== null) {
                $covered = ExplorerCompiler::coverageAlias($index);
                $query->selectRaw("count({$field->expression}) as {$covered}");
            }
        }

        $row = $query->first();

        $values = [];
        $coverage = [];

        foreach ($measures as $index => [$measure, $field]) {
            $values[] = $this->shaper->value($measure->aggregate, $row->{ExplorerCompiler::measureAlias($index)} ?? null);
            $coverage[] = $field === null ? null : (int) ($row->{ExplorerCompiler::coverageAlias($index)} ?? 0);
        }

        return ['rows' => (int) ($row->total ?? 0), 'values' => $values, 'coverage' => $coverage];
    }

    /**
     * @param  list<array{0: ExplorerDimension, 1: ExplorerField}>  $dimensions
     * @param  list<literal-string>  $measureSql
     * @return array{0: list<object>, 1: bool} the rows, and whether the cap cut them short
     */
    private function grouped(Builder $query, ExplorerQuery $declaration, array $dimensions, array $measureSql): array
    {
        if ($dimensions === []) {
            return [[], false];
        }

        foreach ($dimensions as $index => [$dimension, $field]) {
            $this->compiler->addDimension($query, $index, $dimension, $field, $declaration->timezone);
        }

        foreach ($measureSql as $index => $sql) {
            $alias = ExplorerCompiler::measureAlias($index);
            $query->selectRaw("{$sql} as {$alias}");
        }

        // By POSITION: PostgreSQL will not match a `GROUP BY` expression to a select expression when both
        // hold placeholders, and the time-zone bindings make these hold them. The first value follows the
        // group-bys, so it is column 2 with one of them and column 3 with two.
        count($dimensions) === 1
            ? $query->groupByRaw('1')->orderByRaw('2 desc nulls last, 1')
            : $query->groupByRaw('1, 2')->orderByRaw('3 desc nulls last, 1, 2');

        $rows = array_values($query->limit(self::ROW_CAP + 1)->get()->all());

        $capped = count($rows) > self::ROW_CAP;

        return [$capped ? array_slice($rows, 0, self::ROW_CAP) : $rows, $capped];
    }

    /**
     * @param  list<string>  $notes
     * @param  list<array{0: ExplorerDimension, 1: ExplorerField}>  $dimensions
     * @return list<string>
     */
    private function notes(ExplorerSource $source, array $notes, array $dimensions, bool $capped): array
    {
        foreach ($dimensions as [, $field]) {
            if ($field->type === ExplorerFieldType::MultiCategory) {
                $notes[] = "{$field->label} takes several choices at once, so each of the {$source->rowNoun()} is counted once for every choice it made.";
            }
        }

        if ($capped) {
            $notes[] = 'This chart has more than '.number_format(self::ROW_CAP).' groups. Only the largest were read; narrow it with a filter or a shorter range.';
        }

        return $notes;
    }
}
