<?php

declare(strict_types=1);

namespace App\Services\Explorer\Sources;

use App\Enums\ExplorerFieldType;
use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Enums\ValueShape;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Submission;
use App\Models\User;
use App\Support\Explorer\ExplorerField;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The answers to ONE form, with each of its questions as a field (ADR-0025 §D6).
 *
 * ── This reads the stored answers, not the reporting index, and that is the decision ────────────────────
 * ADR-0011 §D3 offered answer-value aggregation over `submission_answer_index` only, which holds a question
 * only if an author ticked "Indexed for reporting" before the response arrived. That is empty for nearly
 * every question that exists. This source reads `submission_answers.answers` directly, so every question
 * of a form is chartable for every response it ever received. What makes that affordable is the bound: one
 * form and one date range per query, reached through `submissions_form_finalized_idx`, never a scan across
 * forms.
 *
 * ── What is and is not offered ──────────────────────────────────────────────────────────────────────────
 * A question becomes a field only where its answer is one value or a flat list of choices, at the top level
 * of the answer document. So not a question inside a repeatable section, a grid, a file, a map location, a
 * cascading choice or a paragraph of free text; {@see notes()} says how many were left out. And never a question its author marked
 * personal or sensitive — in ANY version, because a flag added in version 3 describes the answers of
 * version 1 as well.
 *
 * ── A value that does not parse is no value ─────────────────────────────────────────────────────────────
 * Answers are stored as submitted and a question can change type between versions. Every numeric and date
 * expression below is therefore guarded: text that is not a number contributes nothing to a sum, and is
 * never a cast error that takes the whole chart down. The result carries the count of rows that did have a
 * value, so the gap is on the page.
 *
 * ── The key is quoted, not bound ────────────────────────────────────────────────────────────────────────
 * See {@see ExplorerField}. A key reaches SQL only if it is made of the characters a question key is made
 * of ({@see self::KEY_PATTERN}) and then through the connection's own quoting; a key that fails the check is
 * not offered at all.
 */
final class AnswersSource extends AbstractExplorerSource
{
    /** No quote, no backslash, no `?` — nothing a string literal or a PDO statement gives meaning to. */
    private const string KEY_PATTERN = '/^[A-Za-z0-9_.\-]{1,150}$/';

    private const string ANSWERS = 'submission_answers.answers';

    /** @var array<string, array{fields: list<ExplorerField>, withheld: int, unsupported: int}> */
    private array $memo = [];

    public function __construct(private readonly ExplorerFormReach $reach) {}

    public function key(): string
    {
        return 'answers';
    }

    public function label(): string
    {
        return 'Answers to a form';
    }

    public function description(): string
    {
        return 'The answers one form collected, with each question as a field you can group, count and total.';
    }

    public function rowNoun(): string
    {
        return 'responses';
    }

    public function requiresForm(): bool
    {
        return true;
    }

    /** `submissions.view` is what the inbox asks for, and the inbox is where these answers are already read. */
    public function allows(User $user): bool
    {
        return $user->can('submissions.view');
    }

    public function rangeField(): string
    {
        return 'submitted_at';
    }

    public function fields(User $user, ?Form $form): array
    {
        $fields = [
            $this->enumField('status', 'Status', 'submissions.status', SubmissionStatus::class, 'Response'),
            $this->enumField('source', 'Channel', 'submissions.source', SubmissionSource::class, 'Response'),
            $this->category('locale', 'Language', 'submissions.locale', 'Response'),
            $this->time('submitted_at', 'Submitted', 'submissions.submitted_at', 'Response'),
        ];

        return $form === null ? $fields : [...$fields, ...$this->questions($form)['fields']];
    }

    public function query(User $user, ?Form $form): Builder
    {
        // The catalogue refuses a chart on this source that names no reachable form before it gets here;
        // matching nothing is the fail-closed answer if a caller ever skips that.
        if ($form === null || $this->reach->form($user, (string) $form->getKey()) === null) {
            return Submission::query()->whereRaw('false')->toBase();
        }

        return Submission::query()
            ->countable()
            ->where('submissions.form_id', $form->getKey())
            ->toBase()
            ->join('submission_answers', 'submission_answers.submission_id', '=', 'submissions.id');
    }

    public function notes(User $user, ?Form $form): array
    {
        if ($form === null) {
            return [];
        }

        $questions = $this->questions($form);
        $notes = [];

        if ($questions['withheld'] > 0) {
            $notes[] = $questions['withheld'] === 1
                ? '1 question marked personal or sensitive is not offered here.'
                : "{$questions['withheld']} questions marked personal or sensitive are not offered here.";
        }

        if ($questions['unsupported'] > 0) {
            $notes[] = $questions['unsupported'] === 1
                ? '1 question cannot be charted yet: it is long text, a grid, a file, a location or inside a repeating section.'
                : "{$questions['unsupported']} questions cannot be charted yet: long text, grids, files, locations and questions inside a repeating section.";
        }

        return $notes;
    }

    /**
     * The form's questions across every version that ever collected a response, newest wording first.
     *
     * @return array{fields: list<ExplorerField>, withheld: int, unsupported: int}
     */
    private function questions(Form $form): array
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
            ->orderByDesc('form_versions.version_number')
            ->orderBy('form_fields.sequence')
            ->get([
                'form_fields.key', 'form_fields.field_type', 'form_fields.config', 'form_fields.label',
                'form_fields.is_pii', 'form_fields.is_sensitive', 'form_sections.is_repeatable',
            ]);

        // The first row seen for a key is its newest version, which supplies the wording and the type.
        $latest = [];
        $flagged = [];

        foreach ($rows as $row) {
            $key = (string) $row->key;
            $latest[$key] ??= $row;

            if ($row->is_pii || $row->is_sensitive) {
                $flagged[$key] = true;
            }
        }

        $fields = [];
        $unsupported = 0;

        foreach ($latest as $key => $row) {
            if (isset($flagged[$key])) {
                continue;
            }

            $field = $this->questionField((string) $key, $row);

            if ($field === null) {
                $unsupported += $this->carriesAnAnswer($row) ? 1 : 0;

                continue;
            }

            $fields[] = $field;
        }

        return $this->memo[$formId] = [
            'fields' => $fields,
            'withheld' => count($flagged),
            'unsupported' => $unsupported,
        ];
    }

    private function carriesAnAnswer(object $row): bool
    {
        $type = FieldType::tryFrom((string) $row->field_type);

        return $type !== null && ValueShape::for($type) !== ValueShape::NoAnswer;
    }

    private function questionField(string $key, object $row): ?ExplorerField
    {
        $type = FieldType::tryFrom((string) $row->field_type);

        if ($type === null || $row->is_repeatable || preg_match(self::KEY_PATTERN, $key) !== 1) {
            return null;
        }

        $literal = DB::connection()->getPdo()->quote($key);
        $text = '('.self::ANSWERS." ->> {$literal})";
        $json = '('.self::ANSWERS." -> {$literal})";
        $label = trim((string) $row->label) !== '' ? (string) $row->label : $key;
        $fieldKey = 'answer:'.$key;

        return match (ValueShape::for($type)) {
            ValueShape::Number => new ExplorerField($fieldKey, $label, ExplorerFieldType::Number, self::numberSql($text, $json), 'Questions'),
            ValueShape::Boolean => new ExplorerField($fieldKey, $label, ExplorerFieldType::Boolean, $text, 'Questions', options: ['true' => 'Yes', 'false' => 'No']),
            ValueShape::Choice, ValueShape::Scale => new ExplorerField($fieldKey, $label, ExplorerFieldType::Category, $text, 'Questions', options: $this->options($row)),
            ValueShape::MultipleChoice => new ExplorerField(
                $fieldKey,
                $label,
                ExplorerFieldType::MultiCategory,
                $text,
                'Questions',
                options: $this->options($row),
                listExpression: "(case when jsonb_typeof({$json}) = 'array' then {$json} else '[]'::jsonb end)",
            ),
            ValueShape::Temporal => $type === FieldType::Time
                ? null
                : new ExplorerField($fieldKey, $label, ExplorerFieldType::Date, self::dateSql($text), 'Questions'),
            // Long text is every respondent's own sentence: grouping by it makes one group per response.
            ValueShape::Text => $type === FieldType::LongText
                ? null
                : new ExplorerField($fieldKey, $label, ExplorerFieldType::Category, $text, 'Questions'),
            default => null,
        };
    }

    /**
     * The choice labels an author wrote, so a chart reads "Female" where the answer stores `female`. A
     * question whose choices come from elsewhere has none here, and its stored text is its own label.
     *
     * @return array<string, string>|null
     */
    private function options(object $row): ?array
    {
        $config = is_string($row->config) ? json_decode($row->config, true) : $row->config;
        $options = is_array($config) ? ($config['options'] ?? null) : null;

        if (! is_array($options) || $options === []) {
            return null;
        }

        $out = [];

        foreach ($options as $option) {
            if (is_array($option) && isset($option['value']) && is_scalar($option['value'])) {
                $label = $option['label'] ?? null;
                $out[(string) $option['value']] = is_string($label) && $label !== '' ? $label : (string) $option['value'];
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * An answer as a number, or NULL. A jsonb number is taken as it is; a string only if it is digits with
     * an optional sign and decimal part. The pattern is written without `?` because a raw `?` in a
     * statement is a placeholder to the driver.
     */
    private static function numberSql(string $text, string $json): string
    {
        return "(case when jsonb_typeof({$json}) = 'number' then {$text}::numeric"
            ." when jsonb_typeof({$json}) = 'string' and {$text} ~ '^-{0,1}[0-9]{1,30}([.][0-9]{1,30}){0,1}$' then {$text}::numeric end)";
    }

    /**
     * An answer as a date, or NULL — for a date, and for the date part of a date-and-time.
     *
     * Two guards, nested so the second is only evaluated once the first has held: the text opens with a
     * well-formed `YYYY-MM-DD`, and the day exists in that month. Without the second, `2026-02-30` passes
     * the pattern and `make_date` raises, which would fail the whole query for one bad row. (PostgreSQL 16
     * has `pg_input_is_valid` for this; the testing server runs 15.)
     */
    private static function dateSql(string $text): string
    {
        $year = "substr({$text}, 1, 4)::int";
        $month = "substr({$text}, 6, 2)::int";
        $day = "substr({$text}, 9, 2)::int";

        return "(case when {$text} ~ '^[1-9][0-9]{3}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])' then"
            ." case when {$day} <= extract(day from (make_date({$year}, {$month}, 1) + interval '1 month' - interval '1 day'))::int"
            ." then make_date({$year}, {$month}, {$day}) end end)";
    }
}
