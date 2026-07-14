<?php

declare(strict_types=1);

namespace App\Services\Xlsform;

use App\Enums\FieldType;
use App\Models\Form;
use App\Models\FormVersion;
use App\Services\Forms\SchemaSnapshotSerializer;
use App\Services\Submissions\SubmissionExporter;
use Illuminate\Support\Str;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writes a form version out as an XLSForm `.xlsx` workbook (Increment G7a / docs/xlsform-interop-spec.md).
 * The single read source is {@see SchemaSnapshotSerializer::snapshot()} — the id-free, FK-by-key canonical
 * `{sections, fields}` shape — resolved entirely in request scope, so the streaming closure only walks an
 * in-memory array (no DB during `Response::send()`, unlike {@see SubmissionExporter}).
 *
 * Field-type mapping is the {@see XlsformTypeMap}; the geo lat/lon flip is confined to {@see GeoWireConverter}.
 * The four documented lossy types (§3) are expanded here: `page_break` → a zero-width group boundary,
 * `likert_matrix` → one `select_one` per row, `matrix` → one `select_one` per cell, `duration` → `decimal`.
 * A `#meridian` column carries round-trip breadcrumbs XLSForm cannot natively express.
 */
final class XlsformExporter
{
    public function __construct(
        private readonly SchemaSnapshotSerializer $serializer,
        private readonly XlsformTypeMap $types,
        private readonly GeoWireConverter $geo,
        private readonly XlsformWorkbookWriter $writer,
    ) {}

    /** Stream the workbook as a download. The workbook is fully built up front (no DB in the closure). */
    public function stream(Form $form, FormVersion $version): StreamedResponse
    {
        $sheets = $this->build($form, $version);
        $filename = Str::slug($form->title ?: 'form').'-v'.$version->version_number.'.xlsx';

        return response()->streamDownload(function () use ($sheets): void {
            $writer = new XlsxWriter;
            $writer->openToFile('php://output');
            $this->writer->write($writer, $sheets);
            $writer->close();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * Build the three-sheet workbook structure (pure — the round-trip test asserts on this directly).
     *
     * @return array<string, array{headers: list<string>, rows: list<array<string, scalar|null>>}>
     */
    public function build(Form $form, FormVersion $version): array
    {
        $snapshot = $this->serializer->snapshot($version);
        /** @var list<array<string, mixed>> $sections */
        $sections = is_array($snapshot['sections'] ?? null) ? $snapshot['sections'] : [];
        /** @var list<array<string, mixed>> $fields */
        $fields = is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];

        $locale = $form->default_locale ?: 'en';
        $langs = $this->extraLanguages($sections, $fields, $locale);
        $hasCascade = $this->anyOfType($fields, FieldType::CascadingSelect);

        // Partition fields: top-level (no section) render first, then each section as a group/repeat.
        $topLevel = [];
        $bySection = [];
        foreach ($fields as $field) {
            $sectionKey = $field['section_key'] ?? null;
            if ($sectionKey === null) {
                $topLevel[] = $field;
            } else {
                $bySection[(string) $sectionKey][] = $field;
            }
        }
        usort($topLevel, $this->bySequence(...));
        usort($sections, $this->bySequence(...));

        $survey = [];
        $choices = [];
        $listsSeen = [];

        foreach ($topLevel as $field) {
            $this->emitField($field, $locale, $langs, $survey, $choices, $listsSeen);
        }

        foreach ($sections as $section) {
            $survey[] = $this->sectionRow($section, $locale, $langs, true);
            $sectionFields = $bySection[(string) $section['key']] ?? [];
            usort($sectionFields, $this->bySectionSequence(...));
            foreach ($sectionFields as $field) {
                $this->emitField($field, $locale, $langs, $survey, $choices, $listsSeen);
            }
            $survey[] = $this->sectionRow($section, $locale, $langs, false);
        }

        return [
            'survey' => ['headers' => $this->surveyHeaders($langs), 'rows' => $survey],
            'choices' => ['headers' => $this->choicesHeaders($langs, $hasCascade), 'rows' => $choices],
            'settings' => $this->settingsSheet($form, $version, $locale),
        ];
    }

    /**
     * Emit one field's survey row(s) (+ any choices) into the accumulators. Dispatches the lossy expansions.
     *
     * @param  array<string, mixed>  $field
     * @param  list<string>  $langs
     * @param  list<array<string, scalar|null>>  $survey
     * @param  list<array<string, scalar|null>>  $choices
     * @param  array<string, true>  $listsSeen
     */
    private function emitField(array $field, string $locale, array $langs, array &$survey, array &$choices, array &$listsSeen): void
    {
        $type = FieldType::tryFrom((string) ($field['field_type'] ?? ''));
        if ($type === null) {
            return;
        }

        match (true) {
            $type === FieldType::PageBreak => $this->emitPageBreak($field, $survey),
            $type === FieldType::Matrix => $this->emitMatrix($field, $locale, $langs, $survey, $choices, $listsSeen),
            $type === FieldType::LikertMatrix => $this->emitLikertMatrix($field, $locale, $langs, $survey, $choices, $listsSeen),
            default => $this->emitScalar($type, $field, $locale, $langs, $survey, $choices, $listsSeen),
        };
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $langs
     * @param  list<array<string, scalar|null>>  $survey
     * @param  list<array<string, scalar|null>>  $choices
     * @param  array<string, true>  $listsSeen
     */
    private function emitScalar(FieldType $type, array $field, string $locale, array $langs, array &$survey, array &$choices, array &$listsSeen): void
    {
        $mapping = $this->types->toXlsform($type);
        $key = (string) $field['key'];
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];

        $row = $this->commonColumns($field, $locale, $langs);
        $row['appearance'] = $this->appearance($field, $mapping->appearance);
        $row['#meridian'] = $mapping->marker ?? '';

        $typeToken = $mapping->type;

        if ($type === FieldType::YesNo) {
            $typeToken = 'select_one yes_no';
            $this->pushYesNoList($choices, $langs, $listsSeen);
        } elseif ($type === FieldType::CascadingSelect) {
            $typeToken = 'select_one '.$key;
            $row['#meridian'] = XlsformTypeMap::MARKER_CASCADING;
            $this->pushCascadeList($key, $config, $locale, $langs, $choices, $listsSeen);
        } elseif ($type->hasOptions()) {
            // single_select / multi_select / dropdown / likert_scale
            $typeToken = $mapping->type.' '.$key;
            $this->pushOptionList($key, $config, $locale, $langs, $choices, $listsSeen);
        }

        $row['type'] = $typeToken;

        // Calculation vs literal default (§2). A geo default is serialised through the wire converter.
        $this->applyDefault($type, $field, $config, $row);

        $survey[] = $row;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<array<string, scalar|null>>  $survey
     */
    private function emitPageBreak(array $field, array &$survey): void
    {
        $key = (string) $field['key'];
        $survey[] = ['type' => 'begin group', 'name' => $key, 'label' => '', 'appearance' => 'field-list', '#meridian' => XlsformTypeMap::MARKER_PAGE_BREAK];
        $survey[] = ['type' => 'end group', 'name' => $key];
    }

    /**
     * matrix → one `select_one` per (row × column) cell, named `{key}_{row}_{col}` (§3), all sharing a
     * synthesized cells list. The grid is not natively importable; the `#meridian` marker documents its origin.
     *
     * @param  array<string, mixed>  $field
     * @param  list<string>  $langs
     * @param  list<array<string, scalar|null>>  $survey
     * @param  list<array<string, scalar|null>>  $choices
     * @param  array<string, true>  $listsSeen
     */
    private function emitMatrix(array $field, string $locale, array $langs, array &$survey, array &$choices, array &$listsSeen): void
    {
        $key = (string) $field['key'];
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];
        $listName = $key.'_cells';
        $this->pushPairList($listName, $this->pairs($config, 'cells'), $locale, $langs, $choices, $listsSeen);

        foreach ($this->pairs($config, 'rows') as $rowOpt) {
            foreach ($this->pairs($config, 'columns') as $colOpt) {
                $survey[] = [
                    'type' => 'select_one '.$listName,
                    'name' => $key.'_'.$rowOpt['value'].'_'.$colOpt['value'],
                    'label' => trim($rowOpt['label'].' — '.$colOpt['label']),
                    '#meridian' => XlsformTypeMap::MARKER_MATRIX.':'.$key,
                ];
            }
        }
    }

    /**
     * likert_matrix → one `select_one` per row, all sharing the synthesized score-scale list (§3).
     *
     * @param  array<string, mixed>  $field
     * @param  list<string>  $langs
     * @param  list<array<string, scalar|null>>  $survey
     * @param  list<array<string, scalar|null>>  $choices
     * @param  array<string, true>  $listsSeen
     */
    private function emitLikertMatrix(array $field, string $locale, array $langs, array &$survey, array &$choices, array &$listsSeen): void
    {
        $key = (string) $field['key'];
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];
        $listName = $key.'_scale';
        $this->pushPairList($listName, $this->pairs($config, 'columns'), $locale, $langs, $choices, $listsSeen);

        foreach ($this->pairs($config, 'rows') as $rowOpt) {
            $survey[] = [
                'type' => 'select_one '.$listName,
                'name' => $key.'_'.$rowOpt['value'],
                'label' => $rowOpt['label'] !== '' ? $rowOpt['label'] : $rowOpt['value'],
                '#meridian' => XlsformTypeMap::MARKER_LIKERT_MATRIX.':'.$key,
            ];
        }
    }

    /**
     * The shared survey columns every scalar field carries (name, label/hint + translations, required,
     * relevant, constraint + message + translations).
     *
     * @param  array<string, mixed>  $field
     * @param  list<string>  $langs
     * @return array<string, scalar|null>
     */
    private function commonColumns(array $field, string $locale, array $langs): array
    {
        $row = ['name' => (string) $field['key']];

        $this->fillText($row, 'label', $field['label'] ?? null, $field['label_translations'] ?? null, $langs);
        $this->fillText($row, 'hint', $field['hint'] ?? null, $field['hint_translations'] ?? null, $langs);

        $row['required'] = in_array($field['is_required'] ?? 'optional', ['required', 'conditional'], true) ? 'yes' : '';
        $row['relevant'] = (string) ($field['relevant_expression'] ?? '');

        [$constraint, $message, $messageTranslations] = $this->constraint($field);
        $row['constraint'] = $constraint;
        $this->fillText($row, 'constraint_message', $message, $messageTranslations, $langs);

        return $row;
    }

    /**
     * Resolve `default` vs `calculation` (§2): a `calculated` field's formula, or any field's
     * expression-valued default, goes to `calculation`; a literal default goes to `default` (geo defaults
     * are serialised through the wire converter).
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $config
     * @param  array<string, scalar|null>  $row
     */
    private function applyDefault(FieldType $type, array $field, array $config, array &$row): void
    {
        if ($type === FieldType::Calculated) {
            $row['calculation'] = (string) ($config['calculated_formula'] ?? '');

            return;
        }

        $default = $field['default_value'] ?? null;
        if ($default === null || $default === '') {
            return;
        }

        if (($field['default_value_is_expression'] ?? false) === true) {
            $row['calculation'] = (string) $default;

            return;
        }

        if ($type->isGeo()) {
            $decoded = json_decode((string) $default, true);
            $row['default'] = is_array($decoded) ? $this->geo->geoJsonToWire($type, $decoded) : (string) $default;

            return;
        }

        $row['default'] = (string) $default;
    }

    /**
     * A field's XLSForm `constraint` expression, message, and message translations. Expression-based
     * validation rows pass through verbatim; the numeric/field-comparison structured rules our frozen
     * grammar can express are rendered; all rendered pieces are ANDed. Other structured rule types
     * (pattern, length, required_if, …) are a documented export limitation and are omitted.
     *
     * @param  array<string, mixed>  $field
     * @return array{0: string, 1: ?string, 2: mixed}
     */
    private function constraint(array $field): array
    {
        $validations = is_array($field['validations'] ?? null) ? $field['validations'] : [];
        $parts = [];
        $message = null;
        $messageTranslations = null;

        foreach ($validations as $validation) {
            $expression = $validation['expression'] ?? null;
            $rendered = is_string($expression) && $expression !== ''
                ? $expression
                : $this->renderStructured($validation);

            if ($rendered !== null && $rendered !== '') {
                $parts[] = $rendered;
                if ($message === null && ! empty($validation['error_message'])) {
                    $message = (string) $validation['error_message'];
                    $messageTranslations = $validation['error_message_translations'] ?? null;
                }
            }
        }

        return [implode(' and ', $parts), $message, $messageTranslations];
    }

    /**
     * @param  array<string, mixed>  $validation
     */
    private function renderStructured(array $validation): ?string
    {
        $value = $validation['rule_value'] ?? null;
        $relatedKey = $validation['related_field_key'] ?? null;

        return match ($validation['rule_type'] ?? null) {
            'min_value' => is_numeric($value) ? '. >= '.$value : null,
            'max_value' => is_numeric($value) ? '. <= '.$value : null,
            'greater_than_field' => $relatedKey !== null ? '. > ${'.$relatedKey.'}' : null,
            'less_than_field' => $relatedKey !== null ? '. < ${'.$relatedKey.'}' : null,
            default => null, // pattern / length / required_if / … : documented export limitation
        };
    }

    /**
     * A group/repeat boundary row for a section. `$open` chooses begin/end; a repeat carries its
     * relevant + a static `repeat_count`.
     *
     * @param  array<string, mixed>  $section
     * @param  list<string>  $langs
     * @return array<string, scalar|null>
     */
    private function sectionRow(array $section, string $locale, array $langs, bool $open): array
    {
        $repeatable = ($section['is_repeatable'] ?? false) === true;
        $verb = $open ? 'begin' : 'end';
        $noun = $repeatable ? 'repeat' : 'group';

        $row = ['type' => $verb.' '.$noun, 'name' => (string) $section['key']];
        if (! $open) {
            return $row;
        }

        $this->fillText($row, 'label', $section['label'] ?? null, $section['label_translations'] ?? null, $langs);
        $row['relevant'] = (string) ($section['relevant_expression'] ?? '');
        if ($repeatable && ($section['max_instances'] ?? null) !== null) {
            $row['repeat_count'] = (int) $section['max_instances'];
        }

        return $row;
    }

    // ── Choices sheet builders ───────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $langs
     * @param  list<array<string, scalar|null>>  $choices
     * @param  array<string, true>  $listsSeen
     */
    private function pushOptionList(string $listName, array $config, string $locale, array $langs, array &$choices, array &$listsSeen): void
    {
        $this->pushPairList($listName, $this->pairs($config, 'options'), $locale, $langs, $choices, $listsSeen);
    }

    /**
     * @param  list<array{value: string, label: string, translations?: mixed}>  $pairs
     * @param  list<string>  $langs
     * @param  list<array<string, scalar|null>>  $choices
     * @param  array<string, true>  $listsSeen
     */
    private function pushPairList(string $listName, array $pairs, string $locale, array $langs, array &$choices, array &$listsSeen): void
    {
        if (isset($listsSeen[$listName])) {
            return;
        }
        $listsSeen[$listName] = true;

        foreach ($pairs as $pair) {
            $row = ['list_name' => $listName, 'name' => $pair['value']];
            $this->fillText($row, 'label', $pair['label'], $pair['translations'] ?? null, $langs);
            $choices[] = $row;
        }
    }

    /**
     * The cascading hierarchy, folded into the choices sheet with `level`/`parent` columns our own import
     * reconstructs into `config.levels`/`config.options` (§2/§4).
     *
     * @param  array<string, mixed>  $config
     * @param  list<string>  $langs
     * @param  list<array<string, scalar|null>>  $choices
     * @param  array<string, true>  $listsSeen
     */
    private function pushCascadeList(string $listName, array $config, string $locale, array $langs, array &$choices, array &$listsSeen): void
    {
        if (isset($listsSeen[$listName])) {
            return;
        }
        $listsSeen[$listName] = true;

        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }
            $row = ['list_name' => $listName, 'name' => (string) ($option['value'] ?? '')];
            $this->fillText($row, 'label', $option['label'] ?? null, $option['label_translations'] ?? null, $langs);
            $row['level'] = (string) ($option['level'] ?? '');
            $row['parent'] = (string) ($option['parent'] ?? '');
            $choices[] = $row;
        }
    }

    /**
     * @param  list<array<string, scalar|null>>  $choices
     * @param  list<string>  $langs
     * @param  array<string, true>  $listsSeen
     */
    private function pushYesNoList(array &$choices, array $langs, array &$listsSeen): void
    {
        $this->pushPairList('yes_no', [
            ['value' => 'yes', 'label' => 'Yes'],
            ['value' => 'no', 'label' => 'No'],
        ], 'en', $langs, $choices, $listsSeen);
    }

    // ── Settings sheet + headers ─────────────────────────────────────────────────────────────────

    /**
     * @return array{headers: list<string>, rows: list<array<string, scalar|null>>}
     */
    private function settingsSheet(Form $form, FormVersion $version, string $locale): array
    {
        return [
            'headers' => ['form_title', 'form_id', 'version', 'default_language'],
            'rows' => [[
                'form_title' => $form->title,
                'form_id' => $form->public_slug ?: Str::slug($form->title ?: 'form'),
                'version' => (string) $version->version_number,
                'default_language' => $locale,
            ]],
        ];
    }

    /**
     * @param  list<string>  $langs
     * @return list<string>
     */
    private function surveyHeaders(array $langs): array
    {
        $headers = ['type', 'name', 'label'];
        foreach ($langs as $lang) {
            $headers[] = 'label::'.$lang;
        }
        $headers[] = 'hint';
        foreach ($langs as $lang) {
            $headers[] = 'hint::'.$lang;
        }
        $headers = array_merge($headers, ['required', 'relevant', 'constraint', 'constraint_message']);
        foreach ($langs as $lang) {
            $headers[] = 'constraint_message::'.$lang;
        }

        return array_merge($headers, ['calculation', 'default', 'appearance', 'choice_filter', 'repeat_count', '#meridian']);
    }

    /**
     * @param  list<string>  $langs
     * @return list<string>
     */
    private function choicesHeaders(array $langs, bool $hasCascade): array
    {
        $headers = ['list_name', 'name', 'label'];
        foreach ($langs as $lang) {
            $headers[] = 'label::'.$lang;
        }
        if ($hasCascade) {
            $headers[] = 'level';
            $headers[] = 'parent';
        }

        return $headers;
    }

    // ── Small helpers ────────────────────────────────────────────────────────────────────────────

    /**
     * Set the base (default-locale) text column + one `{prefix}::{lang}` column per extra language.
     *
     * @param  array<string, scalar|null>  $row
     * @param  list<string>  $langs
     */
    private function fillText(array &$row, string $prefix, mixed $base, mixed $translations, array $langs): void
    {
        $row[$prefix] = $base !== null ? (string) $base : '';
        $map = is_array($translations) ? $translations : [];
        foreach ($langs as $lang) {
            $row[$prefix.'::'.$lang] = isset($map[$lang]) ? (string) $map[$lang] : '';
        }
    }

    private function appearance(mixed $field, ?string $forced): string
    {
        if ($forced !== null) {
            return $forced;
        }

        return is_array($field) && is_string($field['appearance'] ?? null) ? $field['appearance'] : '';
    }

    /**
     * A `{value,label,translations}` list from a config option-array key (`options`/`rows`/`columns`/`cells`).
     *
     * @param  array<string, mixed>  $config
     * @return list<array{value: string, label: string, translations: mixed}>
     */
    private function pairs(array $config, string $key): array
    {
        $raw = is_array($config[$key] ?? null) ? $config[$key] : [];
        $pairs = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $value = (string) ($entry['value'] ?? '');
            $pairs[] = [
                'value' => $value,
                'label' => (string) ($entry['label'] ?? $value),
                'translations' => $entry['label_translations'] ?? null,
            ];
        }

        return $pairs;
    }

    /**
     * The union of extra (non-default) languages across every translatable column, sorted for determinism.
     *
     * @param  list<array<string, mixed>>  $sections
     * @param  list<array<string, mixed>>  $fields
     * @return list<string>
     */
    private function extraLanguages(array $sections, array $fields, string $locale): array
    {
        $langs = [];
        $collect = function (mixed $translations) use (&$langs): void {
            if (is_array($translations)) {
                foreach (array_keys($translations) as $lang) {
                    $langs[(string) $lang] = true;
                }
            }
        };

        foreach ($sections as $section) {
            $collect($section['label_translations'] ?? null);
        }
        foreach ($fields as $field) {
            $collect($field['label_translations'] ?? null);
            $collect($field['hint_translations'] ?? null);
            foreach (is_array($field['validations'] ?? null) ? $field['validations'] : [] as $validation) {
                $collect($validation['error_message_translations'] ?? null);
            }
        }

        unset($langs[$locale]);
        $result = array_keys($langs);
        sort($result);

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    private function anyOfType(array $fields, FieldType $type): bool
    {
        foreach ($fields as $field) {
            if (($field['field_type'] ?? null) === $type->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function bySequence(array $a, array $b): int
    {
        return ((int) ($a['sequence'] ?? 0)) <=> ((int) ($b['sequence'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function bySectionSequence(array $a, array $b): int
    {
        return ((int) ($a['section_sequence'] ?? $a['sequence'] ?? 0)) <=> ((int) ($b['section_sequence'] ?? $b['sequence'] ?? 0));
    }
}
