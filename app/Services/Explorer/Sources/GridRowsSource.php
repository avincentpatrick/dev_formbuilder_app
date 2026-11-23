<?php

declare(strict_types=1);

namespace App\Services\Explorer\Sources;

use App\Enums\ExplorerFieldType;
use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Submission;
use App\Models\User;
use App\Support\Explorer\ExplorerField;
use App\Support\Forms\MatrixColumns;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The answered rows of ONE grid of a form — M247 (`grid-wide-sticky-explorer`, `D231`; ADR-0025 §D9).
 *
 * A grid's answer is one object per response, `{row: {column: cell}}` (a Likert grid's `{row: score}`), so no single
 * value of it can be grouped. This source unnests it: a row of the chart's data is one row of the grid as one
 * response answered it, and a response counts once for each row it answered. The Row is a field, and so is each
 * column, typed by the column — so one chart shows, for every row, how many chose each answer.
 *
 * ── What is an answered row ─────────────────────────────────────────────────────────────────────────────
 * A Grid's row is answered when any of its cells holds a value (not null, `""`, `[]` or `{}`); a Likert row when it
 * holds a score. A row the respondent left blank, or never saw (M246's row show-when prunes it), is not counted.
 *
 * ── The same bounds as the answers ─────────────────────────────────────────────────────────────────────
 * It reads `submission_answers.answers` for one form and one range, as {@see AnswersSource} does, and withholds
 * what that source withholds: a grid marked personal or sensitive in ANY version, and a grid inside a repeating
 * section. A row value is data (`jsonb_each`'s key), never SQL; a column value reaches SQL only if it matches
 * {@see self::KEY_PATTERN} and then through the connection's quoting — a column that fails is left out and counted.
 */
final class GridRowsSource extends AbstractExplorerSource implements GridScopedSource
{
    /** No quote, no backslash, no `?` — nothing a string literal or a PDO statement gives meaning to. */
    private const string KEY_PATTERN = '/^[A-Za-z0-9_.\-]{1,150}$/';

    private const string ANSWERS = 'submission_answers.answers';

    /** The unnested row: its key, and its cells (a Grid's object of cells, or a Likert row's score). */
    private const string ROW_KEY = 'grid_row.row_key';

    private const string CELLS = 'grid_row.cells';

    /** @var array<string, array<string, array{label: string, type: FieldType, config: array<string, mixed>, rows: array<string, string>}>> */
    private array $memo = [];

    /**
     * @param  array{key: string, label: string, type: FieldType, config: array<string, mixed>, rows: array<string, string>}|null  $grid  the grid this instance reads; null for the catalogue's unbound entry
     */
    public function __construct(
        private readonly ExplorerFormReach $reach,
        private readonly ?array $grid = null,
    ) {}

    public function key(): string
    {
        return 'grid_rows';
    }

    public function label(): string
    {
        return 'Rows of a grid';
    }

    public function description(): string
    {
        return 'Each row of one grid as each response answered it, with the row and every column as fields — for example, how many chose each answer, row by row.';
    }

    public function rowNoun(): string
    {
        return 'answered rows';
    }

    public function requiresForm(): bool
    {
        return true;
    }

    /** `submissions.view`, as for the answers: the inbox is where these grids are already read. */
    public function allows(User $user): bool
    {
        return $user->can('submissions.view');
    }

    public function rangeField(): string
    {
        return 'submitted_at';
    }

    public function grids(User $user, Form $form): array
    {
        return array_map(static fn (array $grid): string => $grid['label'], $this->chartable($form));
    }

    public function forGrid(User $user, Form $form, string $grid): ?ExplorerSource
    {
        $found = $this->chartable($form)[$grid] ?? null;

        return $found === null ? null : new self($this->reach, ['key' => $grid, ...$found]);
    }

    public function fields(User $user, ?Form $form): array
    {
        $fields = [
            // A draft is not a response yet: the countable predicate below never returns one.
            $this->enumField('status', 'Status', 'submissions.status', SubmissionStatus::class, 'Response', [SubmissionStatus::Draft]),
            $this->enumField('source', 'Channel', 'submissions.source', SubmissionSource::class, 'Response'),
            $this->category('locale', 'Language', 'submissions.locale', 'Response'),
            $this->time('submitted_at', 'Submitted', 'submissions.submitted_at', 'Response'),
        ];

        if ($this->grid === null || $form === null) {
            return $fields;
        }

        return [...$fields, ...$this->gridFields()['fields']];
    }

    public function query(User $user, ?Form $form): Builder
    {
        // Only a bound instance of a reachable form reads anything; matching nothing is the fail-closed answer.
        if ($this->grid === null || $form === null || $this->reach->form($user, (string) $form->getKey()) === null) {
            return Submission::query()->whereRaw('false')->toBase();
        }

        /** @var literal-string $key */
        $key = DB::connection()->getPdo()->quote($this->grid['key']);
        $json = '('.self::ANSWERS." -> {$key})";
        $rows = "select grid_each.key as row_key, grid_each.value as cells from jsonb_each(case when jsonb_typeof({$json}) = 'object' then {$json} else '{}'::jsonb end) as grid_each";

        $answered = $this->grid['type'] === FieldType::LikertMatrix
            ? "jsonb_typeof(grid_row.cells) in ('string', 'number') and grid_row.cells <> '\"\"'::jsonb"
            : "jsonb_typeof(grid_row.cells) = 'object' and exists (select 1 from jsonb_each(grid_row.cells) as grid_cell"
                ." where grid_cell.value not in ('null'::jsonb, '\"\"'::jsonb, '[]'::jsonb, '{}'::jsonb))";

        return Submission::query()
            ->countable()
            ->where('submissions.form_id', $form->getKey())
            ->toBase()
            ->join('submission_answers', 'submission_answers.submission_id', '=', 'submissions.id')
            ->joinLateral($rows, 'grid_row')
            ->whereRaw($answered);
    }

    public function notes(User $user, ?Form $form): array
    {
        if ($this->grid === null || $form === null) {
            return [];
        }

        $notes = ['Each answered row counts once: a response counts once for every row it answered, and a row left blank is not counted.'];
        $left = $this->gridFields()['left_out'];

        if ($left > 0) {
            $notes[] = $left === 1
                ? '1 column cannot be charted: it is long text, or its name has characters a chart cannot use.'
                : "{$left} columns cannot be charted: long text, or names with characters a chart cannot use.";
        }

        return $notes;
    }

    /**
     * The form's grids a chart may count by row, newest wording first: key ⇒ label, kind, newest config, and the row
     * labels of every version (a row that existed only in an older version still names its answers).
     *
     * @return array<string, array{label: string, type: FieldType, config: array<string, mixed>, rows: array<string, string>}>
     */
    private function chartable(Form $form): array
    {
        $formId = (string) $form->getKey();

        if (isset($this->memo[$formId])) {
            return $this->memo[$formId];
        }

        $rows = FormField::query()
            ->toBase()
            ->join('form_versions', 'form_versions.id', '=', 'form_fields.form_version_id')
            ->leftJoin('form_sections', 'form_sections.id', '=', 'form_fields.form_section_id')
            ->where('form_versions.form_id', $formId)
            ->whereIn('form_versions.status', [FormVersionStatus::Published->value, FormVersionStatus::Superseded->value])
            ->whereIn('form_fields.field_type', [FieldType::Matrix->value, FieldType::LikertMatrix->value])
            ->orderByDesc('form_versions.version_number')
            ->orderBy('form_fields.sequence')
            ->get([
                'form_fields.key', 'form_fields.field_type', 'form_fields.config', 'form_fields.label',
                'form_fields.is_pii', 'form_fields.is_sensitive', 'form_sections.is_repeatable',
            ]);

        $grids = [];
        $withheld = [];

        foreach ($rows as $row) {
            $key = (string) $row->key;

            if ($row->is_pii || $row->is_sensitive || $row->is_repeatable || preg_match(self::KEY_PATTERN, $key) !== 1) {
                $withheld[$key] = true;

                continue;
            }

            $config = is_string($row->config) ? json_decode($row->config, true) : $row->config;
            $config = is_array($config) ? $config : [];

            // The first row seen for a key is its newest version: its wording, kind and columns.
            $grids[$key] ??= [
                'label' => trim((string) $row->label) !== '' ? (string) $row->label : $key,
                'type' => FieldType::from((string) $row->field_type),
                'config' => $config,
                'rows' => [],
            ];

            foreach (AnswerSql::labels($config['rows'] ?? null) ?? [] as $value => $label) {
                $grids[$key]['rows'][$value] ??= $label;
            }
        }

        return $this->memo[$formId] = array_diff_key($grids, $withheld);
    }

    /**
     * The bound grid's Row and column fields, and how many columns are left out.
     *
     * @return array{fields: list<ExplorerField>, left_out: int}
     */
    private function gridFields(): array
    {
        $grid = $this->grid;
        if ($grid === null) {
            return ['fields' => [], 'left_out' => 0];
        }

        $fields = [new ExplorerField('row', 'Row', ExplorerFieldType::Category, self::ROW_KEY, 'Grid', options: $grid['rows'] === [] ? null : $grid['rows'])];

        if ($grid['type'] === FieldType::LikertMatrix) {
            $scale = AnswerSql::labels(MatrixColumns::columns($grid['config'])) ?? [];
            $text = '('.self::CELLS." #>> '{}')";
            $fields[] = new ExplorerField('rating', 'Rating', ExplorerFieldType::Category, $text, 'Grid', options: $scale === [] ? null : $scale);

            if ($scale !== [] && array_filter(array_keys($scale), static fn (int|string $value): bool => ! is_numeric($value)) === []) {
                $fields[] = new ExplorerField('rating_score', 'Rating, as a number', ExplorerFieldType::Number, AnswerSql::number($text, self::CELLS), 'Grid');
            }

            return ['fields' => $fields, 'left_out' => 0];
        }

        $leftOut = 0;

        foreach (MatrixColumns::columns($grid['config']) as $column) {
            $value = is_scalar($column['value'] ?? null) ? (string) $column['value'] : '';
            $field = preg_match(self::KEY_PATTERN, $value) === 1 ? $this->columnField($column, $value, $grid['config']) : null;

            if ($field === null) {
                $leftOut++;

                continue;
            }

            $fields[] = $field;
        }

        return ['fields' => $fields, 'left_out' => $leftOut];
    }

    /**
     * One column as a field, typed by the column. Long text is every respondent's own sentence — one group per
     * answer — so it is left out, as the answers source leaves out a long-text question.
     *
     * @param  array<string, mixed>  $column
     * @param  array<string, mixed>  $config
     */
    private function columnField(array $column, string $value, array $config): ?ExplorerField
    {
        // ⛔ As in `AnswersSource::questionField()`: the value has just matched KEY_PATTERN, and goes through the driver's quoting.
        /** @var literal-string $literal */
        $literal = DB::connection()->getPdo()->quote($value);
        $text = '('.self::CELLS." ->> {$literal})";
        $json = '('.self::CELLS." -> {$literal})";
        $label = is_string($column['label'] ?? null) && trim($column['label']) !== '' ? $column['label'] : $value;
        $key = 'column:'.$value;
        $choices = AnswerSql::labels(MatrixColumns::choices($column, $config));

        return match (MatrixColumns::type($column)) {
            FieldType::SingleSelect => new ExplorerField($key, $label, ExplorerFieldType::Category, $text, 'Grid', options: $choices),
            FieldType::MultiSelect => new ExplorerField(
                $key,
                $label,
                ExplorerFieldType::MultiCategory,
                $text,
                'Grid',
                options: $choices,
                listExpression: "(case when jsonb_typeof({$json}) = 'array' then {$json} else '[]'::jsonb end)",
            ),
            FieldType::Integer, FieldType::Decimal => new ExplorerField($key, $label, ExplorerFieldType::Number, AnswerSql::number($text, $json), 'Grid'),
            FieldType::Date => new ExplorerField($key, $label, ExplorerFieldType::Date, AnswerSql::date($text), 'Grid'),
            FieldType::ShortText => new ExplorerField($key, $label, ExplorerFieldType::Category, $text, 'Grid'),
            default => null,
        };
    }
}
