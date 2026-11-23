<?php

declare(strict_types=1);

namespace App\Services\Explorer\Agreement;

use App\Exceptions\Explorer\InvalidExplorerQueryException;
use App\Models\Form;
use App\Models\User;
use App\Services\Explorer\ExplorerCompiler;
use App\Services\Explorer\Sources\AgreementSource;
use App\Support\Explorer\ExplorerAgreement;
use App\Support\Explorer\ExplorerField;
use App\Support\Explorer\ExplorerQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Counts an agreement list (M250, `agreement-report`, `D234`; ADR-0025 §D10).
 *
 * ── Two statements, however many items ──────────────────────────────────────────────────────────────────
 * One counts the responses in range. The other reads every answer the list needs in one pass: each response's answer
 * document is split into its top-level keys ONCE, joined to the picked items, and each item's cell is read from the
 * piece it lives in — so a list of 150 items over a large document does not read that document 150 times. Grouping
 * sets give, in the same statement, each item's count per answer and its totals: responses, views (a response with an
 * answer that is not left out) and agreeing (a response that gave an answer counted as agreeing).
 *
 * ── Nothing here becomes SQL text ───────────────────────────────────────────────────────────────────────
 * The keys, rows and columns of the items and the answers left out or counted as agreeing all travel as two bound
 * jsonb documents, read by `jsonb_to_recordset`. That is stricter than §D2, which lets the answers source quote a
 * checked key into its expressions.
 *
 * ── Never a truncated count ─────────────────────────────────────────────────────────────────────────────
 * Past {@see self::ROW_CAP} distinct (item, answer) pairs the list is refused rather than drawn from part of its
 * answers, because the part read could change which answer is the most common.
 */
final class AgreementReportRunner
{
    public const int ROW_CAP = 20000;

    public function __construct(
        private readonly ExplorerCompiler $compiler,
        private readonly AgreementShaper $shaper,
    ) {}

    /**
     * @return array<string, mixed> the result the page draws — see `resources/js/components/explore/types.ts`
     */
    public function run(ExplorerQuery $query, User $user, AgreementSource $source, Form $form): array
    {
        $spec = $this->spec($query);
        $items = $source->items($form, $spec->items);
        [$answered, $rangeField] = $this->answered($query, $user, $source, $form);

        $responses = (clone $answered)->count();
        $shaped = $this->shaper->shape($spec, $items, $this->counts($answered, $items, $spec));

        $notes = $source->notes($user, $form);
        foreach ($items as $item) {
            if ($item->multiple) {
                $notes[] = 'A question that takes several answers counts the people who ticked its most common choice, out of those who gave a view.';

                break;
            }
        }

        return [
            'chart' => $query->chart->value,
            'dimensions' => [],
            'measures' => [],
            'categories' => [],
            'series' => [],
            'totals' => ['rows' => $responses, 'values' => [], 'coverage' => []],
            'meta' => [
                'source' => $source->key(),
                'source_label' => $source->label(),
                'row_noun' => $source->rowNoun(),
                'form' => ['id' => (string) $form->getKey(), 'title' => (string) $form->title],
                'from' => $query->from->toDateString(),
                'to' => $query->to->toDateString(),
                'timezone' => $query->timezone,
                'range_label' => $rangeField->label,
                'groups' => count($items),
                'hidden_groups' => 0,
                'hidden_series' => 0,
                'capped' => false,
                'notes' => $notes,
            ],
            'agreement' => $shaped,
        ];
    }

    /**
     * Check a list against the form without counting it — for a save, where a question that has gone should be
     * refused while its author can still fix it. It builds the same statement {@see run()} sends.
     */
    public function assertRunnable(ExplorerQuery $query, User $user, AgreementSource $source, Form $form): void
    {
        $spec = $this->spec($query);
        $items = $source->items($form, $spec->items);
        [$answered] = $this->answered($query, $user, $source, $form);

        $this->statement($answered, $items, $spec);
    }

    private function spec(ExplorerQuery $query): ExplorerAgreement
    {
        // The declaration's shape guarantees it; this is the fail-closed answer if a caller ever builds one without.
        return $query->agreement ?? throw InvalidExplorerQueryException::agreementShape('needs at least one question to report on');
    }

    /**
     * The responses in range, narrowed by the list's filters exactly as a chart on the answers source is.
     *
     * @return array{0: Builder, 1: ExplorerField}
     */
    private function answered(ExplorerQuery $query, User $user, AgreementSource $source, Form $form): array
    {
        $fields = [];
        foreach ($source->fields($user, $form) as $field) {
            $fields[$field->key] = $field;
        }

        $rangeField = $fields[$source->rangeField()] ?? throw InvalidExplorerQueryException::unknownField($source->rangeField());

        $base = $source->query($user, $form)
            ->whereRaw("{$rangeField->expression} >= ?", [$query->fromUtc()->toIso8601String()])
            ->whereRaw("{$rangeField->expression} < ?", [$query->toUtcExclusive()->toIso8601String()]);

        foreach ($query->filters as $filter) {
            $this->compiler->addFilter($base, $filter, $fields[$filter->field] ?? throw InvalidExplorerQueryException::unknownField($filter->field));
        }

        return [$base, $rangeField];
    }

    /**
     * @param  list<AgreementItem>  $items
     * @return list<array{n: int, value: string|null, whole: bool, responses: int, views: int, agreeing: int}>
     */
    private function counts(Builder $answered, array $items, ExplorerAgreement $spec): array
    {
        $rows = $this->statement($answered, $items, $spec)->limit(self::ROW_CAP + 1)->get();

        if ($rows->count() > self::ROW_CAP) {
            throw InvalidExplorerQueryException::tooManyAnswers(self::ROW_CAP);
        }

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'n' => (int) $row->n,
                'value' => $row->value === null ? null : (string) $row->value,
                'whole' => (int) $row->whole === 1,
                'responses' => (int) $row->responses,
                'views' => (int) $row->views,
                'agreeing' => (int) $row->agreeing,
            ];
        }

        return $out;
    }

    /**
     * @param  list<AgreementItem>  $items
     */
    private function statement(Builder $answered, array $items, ExplorerAgreement $spec): Builder
    {
        $answered = (clone $answered)->select(['submissions.id as response', 'submission_answers.answers as answers']);

        $picked = DB::query()->fromRaw(
            'jsonb_to_recordset(cast(? as jsonb)) as picked_items(n int, k text, r text, c text)',
            [json_encode(array_map(static fn (AgreementItem $item): array => [
                'n' => $item->position,
                'k' => $item->field,
                'r' => $item->row,
                'c' => $item->column,
            ], $items), JSON_THROW_ON_ERROR)],
        );

        $marks = [];
        foreach ($spec->leaveOut as $value) {
            $marks[] = ['value' => $value, 'left_out' => true, 'agreeing' => false];
        }
        foreach ($spec->agreeing as $value) {
            $marks[] = ['value' => $value, 'left_out' => false, 'agreeing' => true];
        }

        $marked = DB::query()->fromRaw(
            'jsonb_to_recordset(cast(? as jsonb)) as mark_items(value text, left_out boolean, agreeing boolean)',
            [json_encode($marks, JSON_THROW_ON_ERROR)],
        );

        $chosen = DB::query()
            ->fromSub($answered, 'answered')
            // Each response's document split into its top-level keys once — not read once per item.
            ->joinLateral(
                "select answer_each.key as k, answer_each.value as v from jsonb_each(case when jsonb_typeof(answered.answers) = 'object' then answered.answers else '{}'::jsonb end) as answer_each",
                'top',
            )
            ->joinSub($picked, 'picked', 'picked.k', '=', 'top.k')
            // The item's cell: the answer itself, a Likert row's score, or a table row's column.
            ->joinLateral(
                'select case when picked.r is null then top.v when picked.c is null then top.v -> picked.r else top.v -> picked.r -> picked.c end as v',
                'cell',
            )
            // One value per answer given: a choice, a score or a yes/no is one; a list of choices is each of them.
            ->joinLateral(
                "select element #>> '{}' as value from jsonb_array_elements(case jsonb_typeof(cell.v) when 'array' then cell.v when 'string' then jsonb_build_array(cell.v) when 'number' then jsonb_build_array(cell.v) when 'boolean' then jsonb_build_array(cell.v) else '[]'::jsonb end) as element where jsonb_typeof(element) in ('string', 'number', 'boolean') and element #>> '{}' <> ''",
                'chosen_value',
            )
            ->leftJoinSub($marked, 'marks', 'marks.value', '=', 'chosen_value.value')
            ->selectRaw('picked.n as n, answered.response as response, chosen_value.value as value, coalesce(marks.left_out, false) as left_out, coalesce(marks.agreeing, false) as agreeing');

        return DB::query()
            ->fromSub($chosen, 'chosen')
            ->selectRaw(
                'chosen.n as n, chosen.value as value, grouping(chosen.value) as whole,'
                .' count(distinct chosen.response) as responses,'
                .' count(distinct chosen.response) filter (where not chosen.left_out) as views,'
                .' count(distinct chosen.response) filter (where chosen.agreeing) as agreeing',
            )
            ->groupByRaw('grouping sets ((chosen.n, chosen.value), (chosen.n))');
    }
}
