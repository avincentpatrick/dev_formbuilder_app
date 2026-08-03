<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AnalyticsFieldRefusal;
use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Enums\IndexedDataType;
use App\Models\FormField;
use App\Models\Submission;
use App\Models\User;
use App\Support\Analytics\AnalyticsQuery;
use App\Support\Analytics\ReportableFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Answer-value aggregation over `submission_answer_index` (ADR-0011 §D3/§D4, Increment H24a).
 *
 * **The first and only reader of that table in the repository.** Everything below exists because the table
 * is opt-in, prospective-only and never backfilled, which makes almost every honest answer here a
 * disclosure rather than a number.
 *
 * ── Three rules that are not optional ───────────────────────────────────────────────────────────────────
 *
 * 1. **Never read the index without joining `submissions`** (§D2). Its FK cascade fires on HARD delete only,
 *    so projection rows outlive a soft-deleted submission and a query rooted on the index would count
 *    responses the inbox no longer shows. Every query here is rooted on `submissions` and joins OUTWARD to
 *    the index, so `scopeCountable()` and the SoftDeletes global scope both apply before the index is
 *    reached — the rule is structural rather than remembered.
 *
 * 2. **Authorization is a SEPARATE rule from the countable predicate**, and just as easy to drop when the
 *    query root is not `Submission`. The form set — and with it `visibleTo()`'s org-wide/granted split — is
 *    applied on every query in this class.
 *
 * 3. **A mixed-type key is a visible refusal, never a silent average** (§D4). This makes
 *    `docs/form-versioning-schema-migration.md` §7's advisory normative: it warned that when a key's
 *    `indexed_data_type` changes between versions, `value_number` is populated for some versions and
 *    `value_text` for others, so a naive `AVG(value_number)` *silently drops* rather than errors on the
 *    non-numeric rows. That advice was addressed to a feature that did not exist. It does now.
 *
 * ── Why the type check reads `form_fields`, not the populated columns ───────────────────────────────────
 * Inferring "mixed type" from which `value_*` columns hold data would mistake the projector's ordinary
 * per-answer drops for a schema change: a `number`-indexed field silently skips any answer that is not
 * numeric-like, so a single unparseable value would look like a type change. The AUTHORITATIVE signal is
 * the author's declared `indexed_data_type` across the versions in range. The populated-column partition is
 * still computed and reported, because a disagreement between the two is itself worth surfacing.
 *
 * ── Coverage is computed inside the joined set, never as two counts ─────────────────────────────────────
 * `submission_answer_index` has no `form_id` and no soft-delete column, so a bare `COUNT(*)` against it
 * compared against a separate submission count over-reports by exactly the soft-deleted rows. The shape is
 * one LEFT JOIN with `COUNT(*) FILTER (...)`, both halves under identical predicates.
 */
final class AnswerValueAggregator
{
    private const string AGG = 'aggregate';

    public function __construct(private readonly AnalyticsFormSet $formSet) {}

    /**
     * The reportable-question catalogue for the query's form set — the source H24b's field picker ENCODES
     * §D3's three properties from rather than discovering them.
     *
     * Restricted to versions that have actually been published: a draft version's fields can carry no
     * submissions, so offering them would promise a report on data that cannot exist yet.
     *
     * @return list<array{key: string, label: string, field_type: string, indexed_data_type: string|null, reportable: bool, refusal: string|null, refusal_label: string|null, first_indexed_version: int|null, type_changed_at_version: int|null}>
     */
    public function questions(AnalyticsQuery $query, User $user): array
    {
        $rows = DB::table('form_fields')
            ->join('form_versions', 'form_versions.id', '=', 'form_fields.form_version_id')
            ->leftJoin('form_sections', 'form_sections.id', '=', 'form_fields.form_section_id')
            ->whereIn('form_versions.form_id', $this->formSet->resolve($query, $user))
            ->whereIn('form_versions.status', [FormVersionStatus::Published->value, FormVersionStatus::Superseded->value])
            ->orderBy('form_versions.version_number')
            ->get([
                'form_fields.key',
                'form_fields.label',
                'form_fields.field_type',
                'form_fields.is_queryable',
                'form_fields.indexed_data_type',
                'form_versions.version_number',
                DB::raw('coalesce(form_sections.is_repeatable, false) as in_repeatable'),
            ]);

        /** @var array<string, object{key: string, label: string, field_type: string, is_queryable: bool, indexed_data_type: ?string, version_number: int, in_repeatable: bool}> $latest */
        $latest = [];
        /** @var array<string, array<int, string>> $declaredTypes */
        $declaredTypes = [];

        foreach ($rows as $row) {
            /** @var object{key: string, label: string, field_type: string, is_queryable: bool, indexed_data_type: ?string, version_number: int, in_repeatable: bool} $row */

            // Ordered by version_number, so the LAST write wins and the catalogue describes the NEWEST
            // version — which is the one an author is looking at when they pick a question.
            $latest[$row->key] = $row;

            if ($row->is_queryable && $row->indexed_data_type !== null) {
                $declaredTypes[$row->key][(int) $row->version_number] = $row->indexed_data_type;
            }
        }

        $catalogue = [];

        foreach ($latest as $key => $row) {
            $refusal = $this->refusalFor($row);
            $declared = $declaredTypes[$key] ?? [];

            $catalogue[] = [
                'key' => $key,
                'label' => $row->label,
                'field_type' => $row->field_type,
                'indexed_data_type' => $row->indexed_data_type,
                'reportable' => $refusal === null,
                'refusal' => $refusal?->value,
                'refusal_label' => $refusal?->label(),
                // §D3's coverage disclosure: the version from which this question became reportable at all.
                // Null means it never was, which is the ordinary state of almost every form.
                'first_indexed_version' => $declared === [] ? null : min(array_keys($declared)),
                'type_changed_at_version' => self::firstTypeChange($declared),
            ];
        }

        return $catalogue;
    }

    /**
     * The aggregate for one question key, or §D4's refusal.
     *
     * @return array{key: string, refused: bool, reason: string|null, message: string|null, indexed_data_type: string|null, first_indexed_version: int|null, type_changed_at_version: int|null, coverage: array{indexed: int, countable: int}, indexed_column: bool, rows: list<array{key: string|null, count: int}>|null, other: array{count: int, categories: int}|null, summary: array<string, float|int|null>|null}
     */
    public function aggregate(AnalyticsQuery $query, User $user, string $fieldKey): array
    {
        $declared = $this->declaredTypes($query, $user, $fieldKey);
        $coverage = $this->coverage($query, $user, $fieldKey);

        $refusal = static fn (string $reason, string $message, ?int $changedAt = null): array => [
            'key' => $fieldKey,
            'refused' => true,
            'reason' => $reason,
            'message' => $message,
            'indexed_data_type' => null,
            'first_indexed_version' => $declared === [] ? null : min(array_keys($declared)),
            'type_changed_at_version' => $changedAt,
            'coverage' => $coverage,
            'indexed_column' => true,
            'rows' => null,
            'other' => null,
            'summary' => null,
        ];

        if ($declared === []) {
            // Not a failure: the ordinary state of essentially every form alive today, since `is_queryable`
            // defaults false everywhere and there is no backfill. Said plainly rather than shown as an
            // empty chart.
            return $refusal(
                'not_indexed',
                "No published version of this form records \"{$fieldKey}\" for reporting.",
            );
        }

        $changedAt = self::firstTypeChange($declared);

        if ($changedAt !== null) {
            // §D4 verbatim in its consequences: name the version, refuse the number. Coercion across the
            // partition is forbidden — an AVG over whichever rows survived the type filter is exactly the
            // silently-partial answer this exists to prevent.
            return $refusal(
                'type_changed',
                "This question changed type in version {$changedAt}; values before it cannot be combined with values after.",
                $changedAt,
            );
        }

        $type = IndexedDataType::from(reset($declared));
        $firstIndexed = min(array_keys($declared));

        $base = [
            'key' => $fieldKey,
            'refused' => false,
            'reason' => null,
            'message' => null,
            'indexed_data_type' => $type->value,
            'first_indexed_version' => $firstIndexed,
            'type_changed_at_version' => null,
            'coverage' => $coverage,
            // `value_boolean` is the one value column with no B-tree (recorded in ADR-0011's Consequences and
            // deliberately not fixed — §D7 authorises ONE index addendum). Declared so the cost is visible
            // rather than mysterious.
            'indexed_column' => $type !== IndexedDataType::Boolean,
            'rows' => null,
            'other' => null,
            'summary' => null,
        ];

        return match ($type) {
            IndexedDataType::Number => [...$base, 'summary' => $this->numericSummary($query, $user, $fieldKey)],
            IndexedDataType::Text, IndexedDataType::Boolean,
            IndexedDataType::Date, IndexedDataType::Datetime => [...$base, ...$this->categories($query, $user, $fieldKey, $type)],
        };
    }

    /**
     * Every `indexed_data_type` this key has been declared with, keyed by the version that declared it.
     *
     * @return array<int, string>
     */
    private function declaredTypes(AnalyticsQuery $query, User $user, string $fieldKey): array
    {
        $rows = DB::table('form_fields')
            ->join('form_versions', 'form_versions.id', '=', 'form_fields.form_version_id')
            ->whereIn('form_versions.form_id', $this->formSet->resolve($query, $user))
            ->whereIn('form_versions.status', [FormVersionStatus::Published->value, FormVersionStatus::Superseded->value])
            ->where('form_fields.key', $fieldKey)
            ->where('form_fields.is_queryable', true)
            ->whereNotNull('form_fields.indexed_data_type')
            ->orderBy('form_versions.version_number')
            ->pluck('form_fields.indexed_data_type', 'form_versions.version_number');

        /** @var array<int, string> $types */
        $types = $rows->all();

        return $types;
    }

    /**
     * The §D3(iii) coverage numbers: how many countable submissions in range actually carry an indexed value
     * for this key.
     *
     * Both halves come from ONE query under identical predicates. A `COUNT(*)` against the index table
     * compared with a separate submission count would over-report by exactly the soft-deleted rows, because
     * the index has no soft-delete column of its own.
     *
     * The gap is not only about deletes: the projector drops rows *within* a flagged field — a non-numeric
     * answer under a `number` index, an unparseable date — so coverage under-reports against `submissions`
     * for reasons that have nothing to do with the question being unreportable.
     *
     * @return array{indexed: int, countable: int}
     */
    private function coverage(AnalyticsQuery $query, User $user, string $fieldKey): array
    {
        /** @var object{indexed: int|string, countable: int|string}|null $row */
        $row = $this->countableSubmissions($query, $user)
            ->leftJoin('submission_answer_index as sai', function (JoinClause $join) use ($fieldKey): void {
                $join->on('sai.submission_id', '=', 'submissions.id')
                    ->where('sai.field_key', '=', $fieldKey);
            })
            ->selectRaw('count(sai.id) as indexed, count(*) as countable')
            ->first();

        return [
            'indexed' => (int) ($row->indexed ?? 0),
            'countable' => (int) ($row->countable ?? 0),
        ];
    }

    /**
     * A category breakdown over the one populated value column, top-N plus an aggregated remainder — the
     * same fixed-cardinality contract {@see AnalyticsMetricsService::breakdown()} honours.
     *
     * @return array{rows: list<array{key: string|null, count: int}>, other: array{count: int, categories: int}|null}
     */
    private function categories(AnalyticsQuery $query, User $user, string $fieldKey, IndexedDataType $type): array
    {
        $column = self::valueColumn($type);

        $rows = $this->indexedRows($query, $user, $fieldKey)
            ->selectRaw("sai.{$column}::text as bucket, count(*) as ".self::AGG)
            ->groupBy(DB::raw("sai.{$column}"))
            ->orderByDesc(self::AGG)
            ->orderBy('bucket')
            ->get();

        $keyed = [];

        foreach ($rows as $row) {
            /** @var object{bucket: string|null, aggregate: int|string} $row */
            $keyed[] = ['key' => $row->bucket === null ? null : (string) $row->bucket, 'count' => (int) $row->aggregate];
        }

        $top = array_slice($keyed, 0, $query->topN);
        $rest = array_slice($keyed, $query->topN);

        return [
            'rows' => $top,
            'other' => $rest === [] ? null : [
                'count' => array_sum(array_column($rest, 'count')),
                'categories' => count($rest),
            ],
        ];
    }

    /**
     * Count / min / max / average / median for a numeric question.
     *
     * `value_number` was widened to unbounded `numeric`, and PDO hands `numeric` back as a STRING — so every
     * result is cast explicitly rather than trusted to juggle. `percentile_cont` is used for the median for
     * the same reason it is used in §D5's fill-time metric: it is the only one of these that a plain
     * aggregate cannot express.
     *
     * @return array<string, float|int|null>
     */
    private function numericSummary(AnalyticsQuery $query, User $user, string $fieldKey): array
    {
        /** @var object{n: int|string, minimum: string|float|null, maximum: string|float|null, average: string|float|null, median: string|float|null}|null $row */
        $row = $this->indexedRows($query, $user, $fieldKey)
            ->selectRaw(
                'count(*) as n, min(sai.value_number) as minimum, max(sai.value_number) as maximum, '
                .'avg(sai.value_number) as average, '
                .'percentile_cont(0.5) within group (order by sai.value_number) as median'
            )
            ->first();

        return [
            'count' => (int) ($row->n ?? 0),
            'min' => $row?->minimum === null ? null : (float) $row->minimum,
            'max' => $row?->maximum === null ? null : (float) $row->maximum,
            'average' => $row?->average === null ? null : round((float) $row->average, 4),
            'median' => $row?->median === null ? null : round((float) $row->median, 4),
        ];
    }

    /**
     * Countable submissions in range, for this user's form set — the base every query here starts from.
     *
     * @return Builder<Submission>
     */
    private function countableSubmissions(AnalyticsQuery $query, User $user): Builder
    {
        return Submission::query()
            ->countable()
            ->whereIn('submissions.form_id', $this->formSet->resolve($query, $user))
            ->where('submissions.submitted_at', '>=', $query->fromUtc())
            ->where('submissions.submitted_at', '<', $query->toUtcExclusive());
    }

    /**
     * Index rows for one key, INNER-joined so only submissions that actually carry a value participate.
     *
     * Rooted on `submissions` rather than on the index table, which is what makes §D2's rule structural here
     * rather than remembered: the countable predicate and the soft-delete exclusion are already applied
     * before the index is reached.
     *
     * @return Builder<Submission>
     */
    private function indexedRows(AnalyticsQuery $query, User $user, string $fieldKey): Builder
    {
        return $this->countableSubmissions($query, $user)
            ->join('submission_answer_index as sai', function (JoinClause $join) use ($fieldKey): void {
                $join->on('sai.submission_id', '=', 'submissions.id')
                    ->where('sai.field_key', '=', $fieldKey);
            });
    }

    /** The refusal for one catalogue row, reconstructed onto a detached model so the resolver stays pure. */
    private function refusalFor(object $row): ?AnalyticsFieldRefusal
    {
        /** @var object{field_type: string, is_queryable: bool, indexed_data_type: ?string, in_repeatable: bool} $row */
        $field = new FormField;
        $field->field_type = FieldType::from($row->field_type);
        $field->is_queryable = (bool) $row->is_queryable;
        $field->indexed_data_type = $row->indexed_data_type === null
            ? null
            : IndexedDataType::from($row->indexed_data_type);

        return ReportableFields::refusalFor($field, (bool) $row->in_repeatable);
    }

    /**
     * The version at which a key's declared `indexed_data_type` first changed, or null if it never did.
     *
     * @param  array<int, string>  $declaredTypes  version_number => indexed_data_type, ascending
     */
    private static function firstTypeChange(array $declaredTypes): ?int
    {
        $seen = null;

        foreach ($declaredTypes as $version => $type) {
            if ($seen !== null && $type !== $seen) {
                return $version;
            }

            $seen = $type;
        }

        return null;
    }

    /** @return literal-string */
    private static function valueColumn(IndexedDataType $type): string
    {
        return match ($type) {
            IndexedDataType::Text => 'value_text',
            IndexedDataType::Number => 'value_number',
            IndexedDataType::Boolean => 'value_boolean',
            IndexedDataType::Date => 'value_date',
            IndexedDataType::Datetime => 'value_datetime',
        };
    }
}
