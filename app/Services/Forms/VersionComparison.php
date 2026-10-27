<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Services\Templates\TemplateParser;

/**
 * M189 (`version-diff-ui`, `D169`) — what differs between two versions of a form, question by question and setting by
 * setting, for the Compare page ({@see VersionComparePresenter}) and the hub's "What changed" column
 * ({@see VersionChangeSummary}). PRD Feature #8's acceptance line: *"An interactive, field-by-field version-comparison view
 * (draft vs. currently-published, or any two historical versions)"*.
 *
 * Pure: two snapshots in the shape {@see SchemaSnapshotSerializer::snapshot()} writes — a published version's stored
 * `schema_snapshot`, or a draft's serialized from its rows — and the comparison out.
 *
 * ⚠️ BOTH SIDES ARE NORMALISED FIRST. Publish copies list rows into the version it freezes (a list-backed cascade's options,
 * the Philippine places, a list file's choices, a grid's copied rows: {@see ChoiceListMaterializer::materialize()}) and the
 * next draft drops them again ({@see ChoiceListMaterializer::forNextDraft()}). Compared raw, every such question would read
 * as changed between a draft and the version it came from, so each config goes through `forNextDraft()` on both sides and
 * a question changes when the list it NAMES changes. A list refreshed between two publishes is therefore not shown.
 *
 * Questions are matched by `key` — §5's hard rule: a new key is a fork (`D132`), so a renamed key reads as one question
 * removed and one added. A question's changes are {@see FieldRevisionRecorder::changes()}, the History tab's own diff, so
 * the two pages word a change alike; then its translations, then its place ({@see FieldRevisionRecorder::moved()}: a
 * question renumbered because another was deleted has not moved). Three marks:
 *  - {@see self::COMPARABILITY} — form-versioning-schema-migration.md §5's breaking-but-permitted changes: a new type, made
 *    required, indexing changed ({@see SchemaChangeClassifier::breakingCodeOf()}, the rule publish applies), or removed;
 *  - {@see self::ASKED} — its show-when changed, which changes who is asked (the gap §5 records the classifier leaves);
 *  - {@see self::PIPING} — its text pipes in different answers: piping-output-encoding-design.md §6.1's hole-diffing, "a
 *    text change that adds, removes or repoints a hole is classified with the reference change, not with the prose".
 */
final class VersionComparison
{
    public const string COMPARABILITY = 'comparability';

    public const string ASKED = 'asked';

    public const string PIPING = 'piping';

    /** The breaking code of a question the newer side no longer asks. */
    public const string REMOVED = 'removed';

    /** The question's own text a `${key}` hole can sit in (piping §6.1). */
    private const array TEXT = ['label', 'hint', 'placeholder'];

    /** Compared beside {@see FieldRevisionRecorder::changes()}, which knows the builder's settings and not these. */
    private const array TRANSLATIONS = ['label_translations', 'hint_translations'];

    /** A section's settings, in the order `conflict-diff.ts` lists them. Icon and colour are the hub's, not the form's. */
    private const array SECTION_SETTINGS = [
        'label', 'label_translations', 'description', 'description_translations', 'relevant_expression', 'is_repeatable',
        'min_instances', 'max_instances', 'repeat_layout',
    ];

    public function __construct(private readonly TemplateParser $templates = new TemplateParser) {}

    /**
     * @param  array<string, mixed>  $from  the older side, as a rule — any two versions, either way round
     * @param  array<string, mixed>  $to
     * @return array{sections: list<array<string, mixed>>, questions: list<array<string, mixed>>}
     */
    public function compare(array $from, array $to): array
    {
        $fromSections = self::keyed($from['sections'] ?? null);
        $toSections = self::keyed($to['sections'] ?? null);
        $fromFields = self::ordered(self::keyed($from['fields'] ?? null), $fromSections);
        $toFields = self::ordered(self::keyed($to['fields'] ?? null), $toSections);

        $fromPositions = self::positions($fromFields);
        $toPositions = self::positions($toFields);
        $moved = FieldRevisionRecorder::moved($fromPositions, $toPositions);
        $places = [
            'from' => [FieldRevisionRecorder::orderBySection($fromPositions), $fromPositions, self::labels($fromSections)],
            'to' => [FieldRevisionRecorder::orderBySection($toPositions), $toPositions, self::labels($toSections)],
        ];

        $questions = [];
        foreach ($toFields as $key => $field) {
            $questions[] = isset($fromFields[$key])
                ? $this->question((string) $key, $fromFields[$key], $field, isset($moved[$key]) ? $places : null)
                : self::row((string) $key, $field, 'added');
        }
        foreach (array_diff_key($fromFields, $toFields) as $key => $field) {
            $questions[] = self::row((string) $key, $field, 'removed', breaking: self::REMOVED, marks: [self::COMPARABILITY]);
        }

        $sections = [];
        foreach (self::bySequence($toSections) as $key => $section) {
            $sections[] = isset($fromSections[$key]) ? self::section((string) $key, $fromSections[$key], $section) : self::sectionRow((string) $key, $section, 'added');
        }
        foreach (self::bySequence(array_diff_key($fromSections, $toSections)) as $key => $section) {
            $sections[] = self::sectionRow((string) $key, $section, 'removed', marks: [self::COMPARABILITY]);
        }

        return ['sections' => $sections, 'questions' => $questions];
    }

    /**
     * One question's settings as one comparable text, without where it sits: two versions' questions differ in a setting
     * exactly when their fingerprints differ. The hub's "What changed" column reads this, so it never lists a question the
     * Compare page shows as unchanged, nor a move as a change of settings.
     *
     * @param  array<string, mixed>  $field
     */
    public static function fingerprint(array $field): string
    {
        return FieldRevisionRecorder::canonical(self::normalise($field));
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @param  array{from: array{0: array<string, list<string>>, 1: array<string, array{section_id: ?string, sequence: int}>, 2: array<string, string>}, to: array{0: array<string, list<string>>, 1: array<string, array{section_id: ?string, sequence: int}>, 2: array<string, string>}}|null  $places  set when the question moved
     * @return array<string, mixed>
     */
    private function question(string $key, array $from, array $to, ?array $places): array
    {
        $before = self::normalise($from);
        $after = self::normalise($to);
        $changes = FieldRevisionRecorder::changes($before, $after);

        foreach (self::TRANSLATIONS as $setting) {
            if (FieldRevisionRecorder::canonical($before[$setting]) !== FieldRevisionRecorder::canonical($after[$setting])) {
                $changes[] = ['setting' => $setting, 'before' => $before[$setting], 'after' => $after[$setting]];
            }
        }
        if ($places !== null) {
            [$fromOrder, $fromPositions, $fromLabels] = $places['from'];
            [$toOrder, $toPositions, $toLabels] = $places['to'];
            $changes[] = [
                'setting' => 'position',
                'before' => FieldRevisionRecorder::place($fromOrder, $fromPositions, $key, $fromLabels),
                'after' => FieldRevisionRecorder::place($toOrder, $toPositions, $key, $toLabels),
            ];
        }

        $breaking = SchemaChangeClassifier::breakingCodeOf($before, $after);
        $marks = array_keys(array_filter([
            self::COMPARABILITY => $breaking !== null,
            self::ASKED => $before['relevant_expression'] !== $after['relevant_expression'],
            self::PIPING => $this->holes($from) !== $this->holes($to),
        ]));

        return self::row($key, $to, $changes === [] ? 'same' : 'changed', $changes, $breaking, $marks);
    }

    /**
     * @param  array<string, mixed>  $field  the side the row is drawn from: the newer one, or the older for a removal
     * @param  list<array<string, mixed>>  $changes
     * @param  list<string>  $marks
     * @return array<string, mixed>
     */
    private static function row(string $key, array $field, string $status, array $changes = [], ?string $breaking = null, array $marks = []): array
    {
        return [
            'key' => $key,
            'label' => self::label($field, $key),
            'field_type' => is_string($field['field_type'] ?? null) ? $field['field_type'] : '',
            'section_key' => is_string($field['section_key'] ?? null) ? $field['section_key'] : null,
            'status' => $status,
            'changes' => $changes,
            'breaking' => $breaking,
            'marks' => $marks,
        ];
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array<string, mixed>
     */
    private static function section(string $key, array $from, array $to): array
    {
        $before = self::sectionSettings($from);
        $after = self::sectionSettings($to);
        $changes = [];
        foreach (self::SECTION_SETTINGS as $setting) {
            if (FieldRevisionRecorder::canonical($before[$setting]) !== FieldRevisionRecorder::canonical($after[$setting])) {
                $changes[] = ['setting' => $setting, 'before' => $before[$setting], 'after' => $after[$setting]];
            }
        }
        $marks = $before['relevant_expression'] !== $after['relevant_expression'] ? [self::ASKED] : [];

        return self::sectionRow($key, $to, $changes === [] ? 'same' : 'changed', $changes, $marks);
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  list<array<string, mixed>>  $changes
     * @param  list<string>  $marks
     * @return array<string, mixed>
     */
    private static function sectionRow(string $key, array $section, string $status, array $changes = [], array $marks = []): array
    {
        return ['key' => $key, 'label' => self::label($section, $key), 'status' => $status, 'changes' => $changes, 'marks' => $marks];
    }

    /**
     * A question's compared settings: the builder's ({@see FieldRevisionRecorder::COLUMNS}), its config without the rows
     * publish copied in, its rules without their order column, and its translations — an empty map read as none.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private static function normalise(array $field): array
    {
        $settings = [];
        foreach (FieldRevisionRecorder::COLUMNS as $column) {
            $settings[$column] = $field[$column] ?? null;
        }
        $settings['relevant_expression'] = self::expression($field['relevant_expression'] ?? null);
        $settings['config'] = is_array($field['config'] ?? null) ? ChoiceListMaterializer::forNextDraft($field['config']) : [];
        $settings['validations'] = array_map(static function (mixed $rule): mixed {
            if (! is_array($rule)) {
                return $rule;
            }
            unset($rule['sequence']);
            $rule['error_message_translations'] = self::emptyAsNull($rule['error_message_translations'] ?? null);

            return $rule;
        }, array_values(is_array($field['validations'] ?? null) ? $field['validations'] : []));
        foreach (self::TRANSLATIONS as $setting) {
            $settings[$setting] = self::emptyAsNull($field[$setting] ?? null);
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private static function sectionSettings(array $section): array
    {
        $settings = [];
        foreach (self::SECTION_SETTINGS as $setting) {
            $settings[$setting] = $section[$setting] ?? null;
        }
        $settings['relevant_expression'] = self::expression($settings['relevant_expression']);
        $settings['label_translations'] = self::emptyAsNull($settings['label_translations']);
        $settings['description_translations'] = self::emptyAsNull($settings['description_translations']);

        return $settings;
    }

    /**
     * Every `${key}` the question's text pipes in, sorted. Read leniently: a draft's text need not parse yet.
     *
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    private function holes(array $field): array
    {
        $keys = [];
        foreach (self::TEXT as $setting) {
            $text = $field[$setting] ?? null;
            if (! is_string($text) || ! str_contains($text, '${')) {
                continue;
            }
            foreach ($this->templates->parseLenient($text) as $segment) {
                if ($segment['type'] === 'hole') {
                    $keys[$segment['value']] = true;
                }
            }
        }
        ksort($keys);

        return array_map('strval', array_keys($keys));
    }

    /**
     * @return array<string, array<string, mixed>> key => the row as the snapshot holds it
     */
    private static function keyed(mixed $rows): array
    {
        $keyed = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_string($row['key'] ?? null)) {
                $keyed[$row['key']] = $row;
            }
        }

        return $keyed;
    }

    /**
     * Questions in the order a respondent meets them: by their section's place (the top level first), then their own.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, array<string, mixed>>  $sections
     * @return array<string, array<string, mixed>>
     */
    private static function ordered(array $fields, array $sections): array
    {
        $sectionPlace = array_flip(array_map('strval', array_keys(self::bySequence($sections))));
        uasort($fields, static fn (array $a, array $b): int => [
            isset($a['section_key']) ? 1 + ($sectionPlace[$a['section_key']] ?? PHP_INT_MAX - 1) : 0,
            (int) ($a['sequence'] ?? 0),
        ] <=> [
            isset($b['section_key']) ? 1 + ($sectionPlace[$b['section_key']] ?? PHP_INT_MAX - 1) : 0,
            (int) ($b['sequence'] ?? 0),
        ]);

        return $fields;
    }

    /**
     * @param  array<string, array<string, mixed>>  $sections
     * @return array<string, array<string, mixed>>
     */
    private static function bySequence(array $sections): array
    {
        uasort($sections, static fn (array $a, array $b): int => (int) ($a['sequence'] ?? 0) <=> (int) ($b['sequence'] ?? 0));

        return $sections;
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, array{section_id: ?string, sequence: int}> keyed by question key, as the recorder keys by id
     */
    private static function positions(array $fields): array
    {
        $positions = [];
        foreach ($fields as $key => $field) {
            $positions[(string) $key] = [
                'section_id' => is_string($field['section_key'] ?? null) ? $field['section_key'] : null,
                'sequence' => (int) ($field['sequence'] ?? 0),
            ];
        }

        return $positions;
    }

    /**
     * @param  array<string, array<string, mixed>>  $sections
     * @return array<string, string> section key => its title
     */
    private static function labels(array $sections): array
    {
        $labels = [];
        foreach ($sections as $key => $section) {
            $labels[(string) $key] = self::label($section, (string) $key);
        }

        return $labels;
    }

    /** @param  array<string, mixed>  $row */
    private static function label(array $row, string $key): string
    {
        return is_string($row['label'] ?? null) && trim($row['label']) !== '' ? $row['label'] : $key;
    }

    private static function expression(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function emptyAsNull(mixed $value): mixed
    {
        return $value === [] || $value === '' ? null : $value;
    }
}
