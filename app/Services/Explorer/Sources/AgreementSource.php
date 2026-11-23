<?php

declare(strict_types=1);

namespace App\Services\Explorer\Sources;

use App\Enums\ExplorerChartType;
use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Exceptions\Explorer\InvalidExplorerQueryException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\User;
use App\Services\Explorer\Agreement\AgreementItem;
use App\Support\Explorer\ExplorerAgreement;
use App\Support\Forms\GridRules;
use App\Support\Forms\MatrixColumns;
use Illuminate\Database\Query\Builder;

/**
 * How far one form's respondents agree, question by question (M250, `agreement-report`, `D234`; ADR-0025 §D10).
 *
 * ── The same responses as "Answers to a form" ───────────────────────────────────────────────────────────
 * The rows, the filters and the reach are {@see AnswersSource}'s, passed straight through: a list can be narrowed by
 * an answer (the respondent's office), a status, a channel or a range exactly as a chart of the answers can, and the
 * explorer reads no response here that the answers source would not let this reader read.
 *
 * ── What can be put on the list ─────────────────────────────────────────────────────────────────────────
 * A choice question (single, dropdown, Likert scale, yes/no, several choices); a choice column of a question table,
 * which puts every row of the table on the list; a Likert grid, every row of it. Questions are read across every
 * version that collected a response, newest wording first — a table's rows and columns too, so a column retired in a
 * later version still reports the answers it collected (unlike `GridRowsSource`, which charts the newest columns).
 * Withheld exactly as the other two answer sources withhold: marked personal or sensitive in any version, inside a
 * repeating section, or a key that is not a key.
 *
 * A row marked "Not needed" for a column (`GridRules::NOT_NEEDED`) is not asked there, so it is not on the list for
 * that column: NHETE's cut-off column reports 29 of its 35 rows.
 */
final class AgreementSource extends AbstractExplorerSource
{
    private const string KEY_PATTERN = '/^[A-Za-z0-9_.\-]{1,150}$/';

    /** The question types whose answer is one choice, a score, a yes/no or a list of choices. */
    private const array QUESTION_TYPES = [
        FieldType::SingleSelect, FieldType::Dropdown, FieldType::LikertScale, FieldType::YesNo, FieldType::MultiSelect,
    ];

    /** @var array<string, array{entries: array<string, array<string, mixed>>, withheld: int}> */
    private array $memo = [];

    public function __construct(private readonly AnswersSource $answers) {}

    public function key(): string
    {
        return 'agreement';
    }

    public function label(): string
    {
        return 'Agreement across questions';
    }

    public function description(): string
    {
        return 'How far one form’s respondents agree: for each question you choose, the share giving the same answer out of those who gave a view.';
    }

    public function rowNoun(): string
    {
        return 'responses';
    }

    public function requiresForm(): bool
    {
        return true;
    }

    /** A list is usually read over a whole consultation, so it opens on a year. */
    public function defaultRangeDays(): int
    {
        return 365;
    }

    public function charts(): array
    {
        return [ExplorerChartType::Agreement];
    }

    /** The answers it counts are the inbox's: `submissions.view`, as "Answers to a form" asks. */
    public function allows(User $user): bool
    {
        return $this->answers->allows($user);
    }

    public function rangeField(): string
    {
        return $this->answers->rangeField();
    }

    public function fields(User $user, ?Form $form): array
    {
        return $this->answers->fields($user, $form);
    }

    public function query(User $user, ?Form $form): Builder
    {
        return $this->answers->query($user, $form);
    }

    public function notes(User $user, ?Form $form): array
    {
        if ($form === null) {
            return [];
        }

        $withheld = $this->catalogue($form)['withheld'];

        if ($withheld === 0) {
            return [];
        }

        return [$withheld === 1
            ? '1 question marked personal or sensitive is not offered here.'
            : "{$withheld} questions marked personal or sensitive are not offered here."];
    }

    /**
     * What an author may tick, in the form's order: one entry per choice question, per choice column of a question
     * table, and per Likert grid.
     *
     * @return list<array{value: string, label: string, question: string, column: string|null, section: string, kind: string, multiple: bool, rows: int, options: list<array{value: string, label: string}>}>
     */
    public function picks(Form $form): array
    {
        $out = [];

        foreach ($this->catalogue($form)['entries'] as $key => $entry) {
            foreach ($this->entryPicks((string) $key, $entry) as $pick) {
                $out[] = [
                    'value' => $pick['pick'],
                    'label' => $pick['column'] ?? $entry['label'],
                    'question' => $entry['label'],
                    'column' => $pick['column'],
                    'section' => $entry['section'],
                    'kind' => $pick['kind'],
                    'multiple' => $pick['multiple'],
                    'rows' => count($pick['rows']),
                    'options' => self::optionList($pick['options']),
                ];
            }
        }

        return $out;
    }

    /**
     * The picks of a list, expanded into its items in the form's order — a question table's column into its rows.
     *
     * @param  list<string>  $picks
     * @return list<AgreementItem>
     */
    public function items(Form $form, array $picks): array
    {
        $wanted = array_fill_keys($picks, true);
        $items = [];

        foreach ($this->catalogue($form)['entries'] as $key => $entry) {
            foreach ($this->entryPicks((string) $key, $entry) as $pick) {
                if (! isset($wanted[$pick['pick']])) {
                    continue;
                }

                unset($wanted[$pick['pick']]);

                if ($pick['rows'] === []) {
                    $items[] = new AgreementItem(
                        pick: $pick['pick'],
                        key: (string) $key,
                        field: (string) $key,
                        row: null,
                        column: null,
                        label: $entry['label'],
                        question: $entry['label'],
                        columnLabel: null,
                        section: $entry['section'],
                        position: count($items),
                        options: $pick['options'],
                        multiple: $pick['multiple'],
                    );

                    continue;
                }

                foreach ($pick['rows'] as $row => $rowLabel) {
                    $items[] = new AgreementItem(
                        pick: $pick['pick'],
                        key: $pick['columnValue'] === null ? "{$key}.{$row}" : "{$key}.{$row}.{$pick['columnValue']}",
                        field: (string) $key,
                        row: (string) $row,
                        column: $pick['columnValue'],
                        label: $rowLabel,
                        question: $entry['label'],
                        columnLabel: $pick['column'],
                        section: $entry['section'],
                        position: count($items),
                        options: $pick['options'],
                        multiple: $pick['multiple'],
                    );
                }
            }
        }

        if ($wanted !== []) {
            throw InvalidExplorerQueryException::unknownItem((string) array_key_first($wanted));
        }

        if (count($items) > ExplorerAgreement::MAX_ITEMS) {
            throw InvalidExplorerQueryException::tooMany('questions and table rows', ExplorerAgreement::MAX_ITEMS);
        }

        return $items;
    }

    /**
     * The picks one question offers.
     *
     * @param  array<string, mixed>  $entry
     * @return list<array{pick: string, kind: string, column: string|null, columnValue: string|null, multiple: bool, rows: array<string, string>, options: array<string, string>}>
     */
    private function entryPicks(string $key, array $entry): array
    {
        /** @var FieldType $type */
        $type = $entry['type'];

        if ($type === FieldType::LikertMatrix) {
            $rows = array_map(static fn (array $row): string => $row['label'], $entry['rows']);

            return $rows === [] ? [] : [[
                'pick' => "grid:{$key}", 'kind' => 'scale_table', 'column' => null, 'columnValue' => null,
                'multiple' => false, 'rows' => $rows, 'options' => $entry['options'],
            ]];
        }

        if ($type === FieldType::Matrix) {
            $out = [];

            foreach ($entry['columns'] as $value => $column) {
                $rows = [];
                foreach ($entry['rows'] as $row => $meta) {
                    if (! in_array((string) $value, $meta['not_needed'], true)) {
                        $rows[(string) $row] = $meta['label'];
                    }
                }

                if ($rows === []) {
                    continue;
                }

                $out[] = [
                    'pick' => "grid:{$key}:{$value}", 'kind' => 'table_column', 'column' => $column['label'], 'columnValue' => (string) $value,
                    'multiple' => $column['multiple'], 'rows' => $rows, 'options' => $column['options'],
                ];
            }

            return $out;
        }

        return [[
            'pick' => "answer:{$key}", 'kind' => 'question', 'column' => null, 'columnValue' => null,
            'multiple' => $type === FieldType::MultiSelect, 'rows' => [], 'options' => $entry['options'],
        ]];
    }

    /**
     * The form's reportable questions across every version that collected a response, keyed by question key in the
     * newest version's order: wording and type from the newest version, answers, rows and columns merged newest first.
     *
     * @return array{entries: array<string, array<string, mixed>>, withheld: int}
     */
    private function catalogue(Form $form): array
    {
        $formId = (string) $form->getKey();

        if (isset($this->memo[$formId])) {
            return $this->memo[$formId];
        }

        $types = array_map(static fn (FieldType $type): string => $type->value, [...self::QUESTION_TYPES, FieldType::Matrix, FieldType::LikertMatrix]);

        $rows = FormField::query()
            ->toBase()
            ->join('form_versions', 'form_versions.id', '=', 'form_fields.form_version_id')
            ->leftJoin('form_sections', 'form_sections.id', '=', 'form_fields.form_section_id')
            ->where('form_versions.form_id', $formId)
            ->whereIn('form_versions.status', [FormVersionStatus::Published->value, FormVersionStatus::Superseded->value])
            ->whereIn('form_fields.field_type', $types)
            ->orderByDesc('form_versions.version_number')
            ->orderByRaw('coalesce(form_sections.sequence, -1)')
            ->orderBy('form_fields.sequence')
            ->get([
                'form_fields.key', 'form_fields.field_type', 'form_fields.config', 'form_fields.label',
                'form_fields.is_pii', 'form_fields.is_sensitive', 'form_sections.is_repeatable',
                'form_sections.label as section_label',
            ]);

        $entries = [];
        $flagged = [];
        $excluded = [];

        foreach ($rows as $row) {
            $key = (string) $row->key;

            if ($row->is_pii || $row->is_sensitive) {
                $flagged[$key] = true;
            }

            if ($row->is_repeatable || preg_match(self::KEY_PATTERN, $key) !== 1) {
                $excluded[$key] = true;
            }

            $type = FieldType::tryFrom((string) $row->field_type);
            $config = is_string($row->config) ? json_decode($row->config, true) : $row->config;
            $config = is_array($config) ? $config : [];

            if ($type === null) {
                continue;
            }

            // The first row seen for a key is its newest version: its wording, type and section.
            if (! isset($entries[$key])) {
                $entries[$key] = [
                    'type' => $type,
                    'label' => trim((string) $row->label) !== '' ? (string) $row->label : $key,
                    'section' => trim((string) $row->section_label),
                    'options' => [],
                    'rows' => [],
                    'columns' => [],
                ];
            }

            // An older version of another type described different answers; only its own type's are merged.
            if ($entries[$key]['type'] === $type) {
                $this->merge($entries[$key], $type, $config);
            }
        }

        return $this->memo[$formId] = [
            'entries' => array_diff_key($entries, $flagged, $excluded),
            'withheld' => count($flagged),
        ];
    }

    /**
     * Add one version's answers, rows and columns to a question's entry. The newest version came first, so `+=` and
     * `??=` keep its wording and append only what an older version alone had.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $config
     */
    private function merge(array &$entry, FieldType $type, array $config): void
    {
        if ($type === FieldType::YesNo) {
            $entry['options'] = ['true' => 'Yes', 'false' => 'No'];

            return;
        }

        if (in_array($type, self::QUESTION_TYPES, true)) {
            $entry['options'] += AnswerSql::labels($config['options'] ?? null) ?? [];

            return;
        }

        foreach ((array) ($config['rows'] ?? []) as $row) {
            if (! is_array($row) || ! is_scalar($row['value'] ?? null) || (string) $row['value'] === '') {
                continue;
            }

            $value = (string) $row['value'];
            $label = is_string($row['label'] ?? null) && trim($row['label']) !== '' ? $row['label'] : $value;
            $notNeeded = is_array($row[GridRules::NOT_NEEDED] ?? null)
                ? array_values(array_map('strval', array_filter($row[GridRules::NOT_NEEDED], 'is_scalar')))
                : [];

            $entry['rows'][$value] ??= ['label' => $label, 'not_needed' => $notNeeded];
        }

        if ($type === FieldType::LikertMatrix) {
            $entry['options'] += AnswerSql::labels(MatrixColumns::columns($config)) ?? [];

            return;
        }

        foreach (MatrixColumns::columns($config) as $column) {
            $value = is_scalar($column['value'] ?? null) ? (string) $column['value'] : '';
            $columnType = MatrixColumns::type($column);

            if (! MatrixColumns::isChoice($columnType) || preg_match(self::KEY_PATTERN, $value) !== 1) {
                continue;
            }

            $entry['columns'][$value] ??= [
                'label' => is_string($column['label'] ?? null) && trim($column['label']) !== '' ? $column['label'] : $value,
                'multiple' => $columnType === FieldType::MultiSelect,
                'options' => [],
            ];
            $entry['columns'][$value]['options'] += AnswerSql::labels(MatrixColumns::choices($column, $config)) ?? [];
        }
    }

    /**
     * @param  array<string, string>  $options
     * @return list<array{value: string, label: string}>
     */
    private static function optionList(array $options): array
    {
        $out = [];

        foreach ($options as $value => $label) {
            $out[] = ['value' => (string) $value, 'label' => $label];
        }

        return $out;
    }
}
