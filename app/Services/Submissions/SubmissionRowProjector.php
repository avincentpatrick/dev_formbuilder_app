<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\FieldType;
use App\Enums\SubmissionSource;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Services\Templates\TemplateRenderer;
use App\Services\Templates\TemplateSources;
use App\Support\Mapping\ColumnMapping;
use App\Support\Submissions\SubmissionReference;
use Illuminate\Support\Collection;

/**
 * Turns a {@see Submission} into named, display-ready values — the column resolution and cell formatting that
 * {@see SubmissionExporter} owned privately until H16a needed the same answers in a Google Sheets row.
 *
 * EXTRACTED RATHER THAN RE-IMPLEMENTED, on the repo's standing argument: one implementation, never two that
 * agree. A spreadsheet the tenant syncs live and a spreadsheet they download are the same data in the same
 * shape, and the moment those are two code paths they start disagreeing about a choice label, a decimal, or a
 * soft-deleted field — the R3 mirror-pair failure in a place with no golden corpus to catch it.
 *
 * THE SEAM IS A `fieldKey ⇒ value` MAP, NOT A POSITIONAL ROW, because the two consumers order columns
 * differently and neither ordering is more correct. The export unions every present version's keys and emits
 * them in schema order ({@see resolveColumns()}); a Sheets rule emits them in whatever order the tenant's own
 * spreadsheet already has, through {@see ColumnMapping::project()}. Handing back a list
 * would force one of them to reorder someone else's decision.
 *
 * Metadata values are keyed by the `META_*` constants rather than positionally for the same reason, and those
 * keys are deliberately `__`-prefixed: `form_fields.key` is author-chosen, so a reserved namespace is the only
 * thing stopping a field literally named `submission_id` from colliding with the metadata column of that name
 * in a Sheets mapping. The export never sees the collision because it keeps metadata in a separate leading
 * block; the Google Sheets adapter merges the two maps, so for it the guard is load-bearing.
 */
final class SubmissionRowProjector
{
    /**
     * The short handle (J2e). Leads the metadata block, because it is the identity a human quotes — the
     * uuid beside it is the one a system does.
     */
    public const META_REFERENCE = '__reference';

    /**
     * ⚠️ NEVER RENAME THIS KEY, AND J2e IS WHEN THAT STOPPED BEING HYPOTHETICAL. The obvious tidy-up once a
     * `__reference` column exists is to retire `__submission_id` or re-label it — but this string is
     * PERSISTED as a `field_key` inside `connection_subscriptions.config` ({@see ColumnMapping::toArray()},
     * read back by `fromArray()`, resolved by `project()`). `project()` falls back to `''` for a key it does
     * not recognise rather than raising, so a rename would silently write a column of empty strings into
     * every tenant spreadsheet that had bound it — forever, with no crash and no error to notice.
     */
    public const META_SUBMISSION_ID = '__submission_id';

    public const META_STATUS = '__status';

    public const META_SOURCE = '__source';

    public const META_RESPONDENT = '__respondent';

    public const META_SUBMITTED_AT = '__submitted_at';

    public const META_LOCALE = '__locale';

    public function __construct(
        private readonly SchemaValueFormatter $formatter,
        private readonly TemplateRenderer $templates,
    ) {}

    /**
     * Union the data-field keys across every present version (newest first). Returns the ordered
     * `key ⇒ header` column map and a `versionId ⇒ key ⇒ {type,config}` resolution map.
     *
     * Moved verbatim from {@see SubmissionExporter}: submissions FK to the immutable `form_version_id` they
     * were collected against, so a republished form can have differing field sets, and a stable key defines
     * one column no matter which versions carry it.
     *
     * @param  Collection<int, FormVersion>  $versions
     * @return array{0: array<string, string>, 1: array<string, array<string, array{type: FieldType, config: array<string, mixed>, repeat: string|null}>>}
     */
    public function resolveColumns(Collection $versions, string $locale): array
    {
        $columns = [];
        $fieldMeta = [];

        foreach ($versions as $version) {
            $fields = $version->schema_snapshot['fields'] ?? [];
            usort($fields, fn (array $a, array $b): int => ($a['sequence'] ?? 0) <=> ($b['sequence'] ?? 0));

            // Piping sources for this version's headers (H6a) — every field, since `hidden`/`calculated`
            // are pipeable sources without being data columns of their own.
            $sources = TemplateSources::fromSnapshot($fields);

            // M146 (`R-6c0f0e56`, `D100` A) — the members of a repeatable section, by the section's key: their
            // answers live under THAT key as a list of instances ({@see StructuralAnswerNormalizer}), never at the
            // top level, which is where this projector read every member until M146 and wrote blanks on every channel.
            $repeatSections = [];
            foreach ($version->schema_snapshot['sections'] ?? [] as $section) {
                if (is_array($section) && ($section['is_repeatable'] ?? false) === true && is_string($section['key'] ?? null)) {
                    $repeatSections[$section['key']] = true;
                }
            }

            foreach ($fields as $field) {
                $type = FieldType::tryFrom((string) ($field['field_type'] ?? ''));
                if ($type === null || ! $this->formatter->isDataField($type)) {
                    continue;
                }

                $key = (string) $field['key'];
                $config = is_array($field['config'] ?? null) ? $field['config'] : [];
                $sectionKey = $field['section_key'] ?? null;
                $fieldMeta[$version->id][$key] = [
                    'type' => $type,
                    'config' => $config,
                    'repeat' => is_string($sectionKey) && isset($repeatSections[$sectionKey]) ? $sectionKey : null,
                ];

                if (! array_key_exists($key, $columns)) {
                    $columns[$key] = $this->header($field, $locale, $key, $sources);
                }
            }
        }

        return [$columns, $fieldMeta];
    }

    /**
     * One submission's answer cells, keyed by field key, resolved through ITS OWN version's definitions.
     *
     * A key the submission's version does not define is ABSENT from the map rather than present-and-empty —
     * the caller decides what a column with no definition on this version means. The export writes `''`
     * (the column exists for other versions); a Sheets mapping writes `''` too, through
     * {@see ColumnMapping::project()}'s own missing-key fallback.
     *
     * `$locale` is the READING language — the export's, not `$submission->locale` (H6b): one document, one
     * language, or a column of choice labels in per-respondent languages becomes unusable for analysis. The
     * respondent's own locale is still available as {@see META_LOCALE}.
     *
     * A repeat member (M146, `D100` A) is read from its section's instance list and joined into one cell by
     * {@see repeatCell()}; the column keeps the member's own key, because a Sheets or Airtable mapping persists it.
     *
     * @param  array<string, array<string, array{type: FieldType, config: array<string, mixed>, repeat: string|null}>>  $fieldMeta
     * @return array<string, string>
     */
    public function answerValues(Submission $submission, array $fieldMeta, string $locale): array
    {
        $rawAnswers = data_get($submission, 'answers.answers');
        /** @var array<string, mixed> $answers */
        $answers = is_array($rawAnswers) ? $rawAnswers : [];
        $versionMap = $fieldMeta[$submission->form_version_id] ?? [];

        $values = [];

        foreach ($versionMap as $key => $meta) {
            $values[$key] = $meta['repeat'] === null
                ? $this->formatter->displayValue($meta['type'], $answers[$key] ?? null, $meta['config'], $locale)
                : $this->repeatCell($meta['type'], $meta['config'], $this->instancesOf($answers, $meta['repeat']), $key, $locale);
        }

        return $values;
    }

    /**
     * A repeatable section's stored instances — a list of per-instance field-key ⇒ value maps, the shape
     * {@see StructuralAnswerNormalizer} persists; the mirror of `SubmissionInboxPresenter::instancesOf()`.
     * Fail-closed: anything else is no instances at all.
     *
     * @param  array<string, mixed>  $answers
     * @return list<array<string, mixed>>
     */
    private function instancesOf(array $answers, string $sectionKey): array
    {
        $instances = $answers[$sectionKey] ?? null;

        if (! is_array($instances)) {
            return [];
        }

        return array_values(array_filter($instances, is_array(...)));
    }

    /**
     * One repeat member's cell (M146, `D100` A): every instance formatted on its own through
     * {@see SchemaValueFormatter::displayValue()} — so a multi-select keeps its `; ` — and joined with ` | `
     * IN POSITION: an instance that left the member blank still contributes an empty part, so the member columns
     * of one response line up (`Kid A | Kid B` beside ` | Cooking` says which kid cooks). A cell whose every part
     * is empty is `''`, as every other empty answer is. A pipe rather than `; ` because the inner join already
     * spends `; `, and nothing else in an answer is written with one. `D100` B (a Kobo-style sheet per repeat)
     * would build on this cell, not replace it.
     *
     * @param  array<string, mixed>  $config
     * @param  list<array<string, mixed>>  $instances
     */
    private function repeatCell(FieldType $type, array $config, array $instances, string $key, string $locale): string
    {
        $parts = array_map(
            fn (array $instance): string => $this->formatter->displayValue($type, $instance[$key] ?? null, $config, $locale),
            $instances,
        );

        if (array_filter($parts, fn (string $part): bool => $part !== '') === []) {
            return '';
        }

        return implode(' | ', $parts);
    }

    /**
     * The fixed metadata values, in the export's column order.
     *
     * ⚠️ THIS AND {@see metaLabels()} MUST BE EDITED TOGETHER, AND A ONE-SIDED EDIT FAILS SILENTLY.
     * `SubmissionExporter` builds the header row from the labels and every body row from these values, so a
     * key present in one and absent from the other shifts every column right by one — a corrupted export
     * with no exception and nothing for a type check to catch. `SubmissionExportTest` pins their arity.
     *
     * @return array<string, string>
     */
    public function metaValues(Submission $submission): array
    {
        return [
            self::META_REFERENCE => SubmissionReference::format($submission->reference),
            self::META_SUBMISSION_ID => (string) $submission->id,
            self::META_STATUS => $submission->status->label(),
            self::META_SOURCE => $submission->source->label(),
            self::META_RESPONDENT => $this->respondentLabel($submission),
            self::META_SUBMITTED_AT => $submission->submitted_at?->toIso8601String() ?? '',
            self::META_LOCALE => $submission->locale ?? '',
        ];
    }

    /**
     * The human labels for the metadata keys, for any surface that needs to offer them as mappable columns
     * (H16b's field-map UI) or head them (the export).
     *
     * @return array<string, string>
     */
    public static function metaLabels(): array
    {
        return [
            self::META_REFERENCE => 'Reference',
            self::META_SUBMISSION_ID => 'Submission ID',
            self::META_STATUS => 'Status',
            self::META_SOURCE => 'Source',
            self::META_RESPONDENT => 'Respondent',
            self::META_SUBMITTED_AT => 'Submitted at',
            self::META_LOCALE => 'Locale',
        ];
    }

    /**
     * One column header: the label resolved into `$locale`, then rendered as a template (H6a).
     *
     * The order is normative (Doc #26 §4): resolve the locale, THEN fill the holes — rendering first would
     * fill holes into a string nobody is going to read.
     *
     * The answer map is deliberately EMPTY. One header row serves every submission, so a piped header has no
     * single answer to fill from — it is structurally unfillable, and §3.4 requires the gap rather than the raw
     * `${key}` token. A label of "Age of ${child_name}" therefore heads its column as "Age of ".
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, array{type: FieldType, config: array<string, mixed>}>  $sources
     */
    private function header(array $field, string $locale, string $key, array $sources): string
    {
        $translations = is_array($field['label_translations'] ?? null) ? $field['label_translations'] : [];

        $label = (string) ($translations[$locale] ?? $field['label'] ?? $key);

        return $this->templates->render($label, $sources, [], $locale);
    }

    private function respondentLabel(Submission $submission): string
    {
        if ($submission->respondent !== null) {
            return $submission->respondent->name;
        }

        return $submission->source === SubmissionSource::Guest ? 'Guest' : '';
    }
}
