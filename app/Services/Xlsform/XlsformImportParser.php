<?php

declare(strict_types=1);

namespace App\Services\Xlsform;

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Exceptions\Xlsform\XlsformImportException;
use App\Services\Xlsform\Dto\FieldSpec;
use App\Services\Xlsform\Dto\ImportPlan;
use App\Services\Xlsform\Dto\RawWorkbook;
use App\Services\Xlsform\Dto\SectionSpec;
use App\Services\Xlsform\Dto\SettingsSpec;
use App\Services\Xlsform\Dto\ValidationSpec;

/**
 * Turns a {@see RawWorkbook} into a DB-free {@see ImportPlan} (Increment G7b / docs/xlsform-interop-spec.md
 * §2–§6). Pure and side-effect-free: it reads the reverse type map ({@see XlsformTypeMap::toFieldType()}) and
 * the geo wire converter ({@see GeoWireConverter::wireToGeoJson()}) from G7a, and validates the whole file
 * UPFRONT — a missing `survey` sheet or an unmapped `type` throws {@see XlsformImportException} BEFORE any
 * plan is returned, so the importer's destructive draft-replace never runs on a bad workbook (§6).
 *
 * Lossy inputs degrade with a warning, never a failure: a dynamic `repeat_count`, a matrix/likert grid
 * marker, an illegal `name` (sanitized to a valid key). Cascades — ours and foreign — are reconstructed by
 * the {@see CascadeResolver}. Structured constraints are never decomposed: a `constraint` is always one
 * expression-based validation row (§2).
 */
final class XlsformImportParser
{
    public function __construct(
        private readonly XlsformTypeMap $types,
        private readonly GeoWireConverter $geo,
        private readonly CascadeResolver $cascades,
    ) {}

    public function parse(RawWorkbook $workbook): ImportPlan
    {
        if (! $workbook->hasSheet('survey')) {
            throw XlsformImportException::missingSurveySheet();
        }

        $warnings = [];
        $choicesByList = $this->normalizeChoices($workbook);

        [$sections, $fields] = $this->walkSurvey($workbook, $choicesByList, $warnings);

        $fields = $this->cascades->resolve($fields, $choicesByList, $warnings);

        $this->sanitizeKeys($sections, $fields, $warnings);
        $this->assignSequences($sections, $fields);

        return new ImportPlan(
            sections: $sections,
            fields: $fields,
            settings: $this->parseSettings($workbook),
            warnings: array_values(array_unique($warnings)),
        );
    }

    // ── Survey walk ──────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, list<array<string, mixed>>>  $choicesByList
     * @param  list<string>  $warnings
     * @return array{0: list<SectionSpec>, 1: list<FieldSpec>}
     */
    private function walkSurvey(RawWorkbook $workbook, array $choicesByList, array &$warnings): array
    {
        /** @var list<SectionSpec> $sections */
        $sections = [];
        /** @var list<FieldSpec> $fields */
        $fields = [];
        /** @var list<array{kind: string, sectionKey: ?string}> $stack */
        $stack = [];

        foreach ($workbook->rows('survey') as $index => $row) {
            $rowNumber = $index + 2; // header is row 1
            $typeRaw = trim((string) ($this->col($row, 'type') ?? ''));
            if ($typeRaw === '') {
                continue;
            }

            $lower = strtolower($typeRaw);

            if (str_starts_with($lower, 'begin ')) {
                $marker = $this->trimToNull($this->col($row, '#meridian'));
                $name = trim((string) ($this->col($row, 'name') ?? ''));

                if ($marker === XlsformTypeMap::MARKER_PAGE_BREAK) {
                    $fields[] = new FieldSpec(
                        key: $name,
                        fieldType: FieldType::PageBreak,
                        sectionKey: $this->currentSectionKey($stack),
                        label: (string) ($this->col($row, 'label') ?? ''),
                    );
                    $stack[] = ['kind' => 'pagebreak', 'sectionKey' => null];

                    continue;
                }

                $section = $this->beginSection($lower, $name, $row, $warnings);
                $sections[] = $section;
                $stack[] = ['kind' => 'section', 'sectionKey' => $section->key];

                continue;
            }

            if (str_starts_with($lower, 'end ')) {
                array_pop($stack);

                continue;
            }

            $fields[] = $this->buildField($typeRaw, $row, $this->currentSectionKey($stack), $rowNumber, $choicesByList, $warnings);
        }

        return [$sections, $fields];
    }

    /**
     * @param  array<string, ?string>  $row
     * @param  list<string>  $warnings
     */
    private function beginSection(string $lowerType, string $name, array $row, array &$warnings): SectionSpec
    {
        $isRepeat = str_contains($lowerType, 'repeat');

        $maxInstances = null;
        $repeatCount = $this->trimToNull($this->col($row, 'repeat_count'));
        if ($isRepeat && $repeatCount !== null) {
            if (ctype_digit($repeatCount)) {
                $maxInstances = (int) $repeatCount;
            } else {
                $warnings[] = "The repeat “{$name}” uses a dynamic repeat_count; it was imported as an unbounded repeat.";
            }
        }

        return new SectionSpec(
            key: $name,
            label: (string) ($this->col($row, 'label') ?? ''),
            labelTranslations: $this->translations($row, 'label') ?: null,
            isRepeatable: $isRepeat,
            maxInstances: $maxInstances,
            relevantExpression: $this->trimToNull($this->col($row, 'relevant')),
        );
    }

    /**
     * @param  array<string, ?string>  $row
     * @param  array<string, list<array<string, mixed>>>  $choicesByList
     * @param  list<string>  $warnings
     */
    private function buildField(string $typeRaw, array $row, ?string $sectionKey, int $rowNumber, array $choicesByList, array &$warnings): FieldSpec
    {
        $appearance = $this->trimToNull($this->col($row, 'appearance'));
        $marker = $this->trimToNull($this->col($row, '#meridian'));

        $type = $this->types->toFieldType($typeRaw, $appearance, $marker);
        if ($type === null) {
            throw XlsformImportException::unsupportedFieldType($rowNumber, $typeRaw);
        }

        $this->warnLossyMarker($marker, $warnings);

        $key = trim((string) ($this->col($row, 'name') ?? ''));
        $listName = $this->listNameFromType($typeRaw);

        $config = [];
        if ($type->hasOptions()) {
            $config['options'] = $this->optionsFromList($choicesByList, $listName);
        }

        [$defaultValue, $defaultIsExpression] = $this->resolveDefault($type, $row, $config);

        return new FieldSpec(
            key: $key,
            fieldType: $type,
            sectionKey: $sectionKey,
            config: $config,
            label: (string) ($this->col($row, 'label') ?? ''),
            labelTranslations: $this->translations($row, 'label') ?: null,
            hint: $this->trimToNull($this->col($row, 'hint')),
            hintTranslations: $this->translations($row, 'hint') ?: null,
            defaultValue: $defaultValue,
            defaultValueIsExpression: $defaultIsExpression,
            isRequired: $this->resolveRequired($this->col($row, 'required')),
            relevantExpression: $this->trimToNull($this->col($row, 'relevant')),
            appearance: $this->resolveAppearance($type, $appearance),
            validations: $this->resolveConstraint($row),
            listName: $listName,
            choiceFilter: $this->trimToNull($this->col($row, 'choice_filter')),
            marker: $marker,
        );
    }

    /**
     * `calculation` vs literal `default` (§2). A `calculate` field's formula lives in `config.calculated_formula`
     * (where the expression gate + exporter read it); any other field's `calculation` is an expression default;
     * a geo `default` is deserialised through the wire converter.
     *
     * @param  array<string, ?string>  $row
     * @param  array<string, mixed>  $config
     * @return array{0: ?string, 1: bool}
     */
    private function resolveDefault(FieldType $type, array $row, array &$config): array
    {
        $calculation = $this->trimToNull($this->col($row, 'calculation'));
        $default = $this->trimToNull($this->col($row, 'default'));

        if ($type === FieldType::Calculated) {
            $config['calculated_formula'] = $calculation ?? '';

            return [null, false];
        }

        if ($calculation !== null) {
            return [$calculation, true];
        }

        if ($default === null) {
            return [null, false];
        }

        if ($type->isGeo()) {
            $envelope = $this->geo->wireToGeoJson($type, $default);

            return [$envelope !== null ? (string) json_encode($envelope) : null, false];
        }

        return [$default, false];
    }

    /**
     * @param  array<string, ?string>  $row
     * @return list<ValidationSpec>
     */
    private function resolveConstraint(array $row): array
    {
        $constraint = $this->trimToNull($this->col($row, 'constraint'));
        if ($constraint === null) {
            return [];
        }

        return [new ValidationSpec(
            expression: $constraint,
            errorMessage: $this->trimToNull($this->col($row, 'constraint_message')),
            errorMessageTranslations: $this->translations($row, 'constraint_message') ?: null,
        )];
    }

    /**
     * Keep a genuine author appearance; drop a synthetic one the exporter forced to carry the type (a
     * `text`/`multiline` LongText round-tripping back must NOT keep `appearance = multiline`, or its config
     * drifts from the original).
     */
    private function resolveAppearance(FieldType $type, ?string $appearance): ?string
    {
        if ($appearance === null) {
            return null;
        }

        $forced = $this->types->toXlsform($type)->appearance;
        if ($forced !== null && strcasecmp($forced, $appearance) === 0) {
            return null;
        }

        return $appearance;
    }

    private function resolveRequired(?string $required): RequiredMode
    {
        $value = strtolower(trim((string) $required));

        return in_array($value, ['yes', 'true', '1'], true) ? RequiredMode::Required : RequiredMode::Optional;
    }

    /**
     * @param  list<string>  $warnings
     */
    private function warnLossyMarker(?string $marker, array &$warnings): void
    {
        if ($marker === null) {
            return;
        }

        $origin = explode(':', $marker)[2] ?? '';
        $named = $origin !== '' ? "“{$origin}”" : 'a grid';

        if (str_starts_with($marker, XlsformTypeMap::MARKER_MATRIX)) {
            $warnings[] = "The matrix {$named} is not importable; its cells were imported as separate single-select questions.";
        } elseif (str_starts_with($marker, XlsformTypeMap::MARKER_LIKERT_MATRIX)) {
            $warnings[] = "The Likert matrix {$named} is not importable; its rows were imported as separate single-select questions.";
        } elseif ($marker === XlsformTypeMap::MARKER_DURATION) {
            $warnings[] = 'A duration field was imported as a decimal (XLSForm has no native duration type).';
        }
    }

    // ── Choices / settings ───────────────────────────────────────────────────────────────────────

    /**
     * Group the `choices` sheet by `list_name`, normalized: `{value, label, label_translations, level, parent, raw}`.
     * `raw` is a lowercase-keyed copy so the cascade resolver can read arbitrary foreign filter columns.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function normalizeChoices(RawWorkbook $workbook): array
    {
        $byList = [];
        foreach ($workbook->rows('choices') as $row) {
            $listName = $this->trimToNull($this->col($row, 'list_name')) ?? $this->trimToNull($this->col($row, 'list name'));
            if ($listName === null) {
                continue;
            }

            $byList[$listName][] = [
                'value' => trim((string) ($this->col($row, 'name') ?? '')),
                'label' => (string) ($this->col($row, 'label') ?? ''),
                'label_translations' => $this->translations($row, 'label'),
                'level' => $this->col($row, 'level'),
                'parent' => $this->col($row, 'parent'),
                'raw' => $this->lowercaseKeys($row),
            ];
        }

        return $byList;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $choicesByList
     * @return list<array<string, mixed>>
     */
    private function optionsFromList(array $choicesByList, ?string $listName): array
    {
        if ($listName === null) {
            return [];
        }

        $options = [];
        foreach ($choicesByList[$listName] ?? [] as $row) {
            $value = (string) ($row['value'] ?? '');
            $option = ['value' => $value, 'label' => $row['label'] !== '' ? (string) $row['label'] : $value];
            if (! empty($row['label_translations'])) {
                $option['label_translations'] = $row['label_translations'];
            }
            $options[] = $option;
        }

        return $options;
    }

    private function parseSettings(RawWorkbook $workbook): SettingsSpec
    {
        $row = $workbook->rows('settings')[0] ?? [];

        return new SettingsSpec(
            formTitle: $this->trimToNull($this->col($row, 'form_title')),
            formId: $this->trimToNull($this->col($row, 'form_id')),
            defaultLanguage: $this->trimToNull($this->col($row, 'default_language')),
        );
    }

    // ── Key sanitization + sequencing ─────────────────────────────────────────────────────────────

    /**
     * @param  list<SectionSpec>  $sections
     * @param  list<FieldSpec>  $fields
     * @param  list<string>  $warnings
     */
    private function sanitizeKeys(array $sections, array $fields, array &$warnings): void
    {
        // Sections + fields share one namespace per version (keys are globally unique for expression refs).
        $used = [];
        $sectionMap = [];

        foreach ($sections as $section) {
            $new = $this->sanitizeKey($section->key, $used);
            if ($new !== $section->key) {
                $warnings[] = "The section name “{$section->key}” is not a valid key; imported as “{$new}”.";
            }
            $sectionMap[$section->key] = $new;
            $section->key = $new;
            $used[$new] = true;
        }

        foreach ($fields as $field) {
            $new = $this->sanitizeKey($field->key, $used);
            if ($new !== $field->key) {
                $warnings[] = "The field name “{$field->key}” is not a valid key; imported as “{$new}”.";
            }
            $field->key = $new;
            $used[$new] = true;

            if ($field->sectionKey !== null) {
                $field->sectionKey = $sectionMap[$field->sectionKey] ?? $field->sectionKey;
            }
        }
    }

    /**
     * @param  array<string, true>  $used
     */
    private function sanitizeKey(string $key, array $used): string
    {
        $slug = strtolower(trim($key));
        $slug = (string) preg_replace('/[^a-z0-9_]/', '_', $slug);
        if ($slug === '' || ! preg_match('/^[a-z]/', $slug)) {
            $slug = 'x_'.$slug;
        }

        $candidate = $slug;
        $n = 2;
        while (isset($used[$candidate])) {
            $candidate = $slug.'_'.$n;
            $n++;
        }

        return $candidate;
    }

    /**
     * @param  list<SectionSpec>  $sections
     * @param  list<FieldSpec>  $fields
     */
    private function assignSequences(array $sections, array $fields): void
    {
        foreach ($sections as $i => $section) {
            $section->sequence = $i;
        }

        $sectionCounters = [];
        foreach ($fields as $i => $field) {
            $field->sequence = $i;
            if ($field->sectionKey !== null) {
                $sectionCounters[$field->sectionKey] ??= 0;
                $field->sectionSequence = $sectionCounters[$field->sectionKey]++;
            } else {
                $field->sectionSequence = null;
            }
        }
    }

    // ── Small helpers ────────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<array{kind: string, sectionKey: ?string}>  $stack
     */
    private function currentSectionKey(array $stack): ?string
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if ($stack[$i]['kind'] === 'section') {
                return $stack[$i]['sectionKey'];
            }
        }

        return null;
    }

    /** The `list_name` inline in a `select_one <list>` / `select_multiple <list>` type token, or null. */
    private function listNameFromType(string $typeRaw): ?string
    {
        $tokens = preg_split('/\s+/', trim($typeRaw)) ?: [];
        $head = strtolower($tokens[0] ?? '');
        if (($head === 'select_one' || $head === 'select_multiple') && isset($tokens[1])) {
            return $tokens[1];
        }

        return null;
    }

    /**
     * The `{prefix}::{lang}` translation columns of a row → `{lang: value}`, lang case preserved.
     *
     * @param  array<string, ?string>  $row
     * @return array<string, string>
     */
    private function translations(array $row, string $prefix): array
    {
        $needle = strtolower($prefix).'::';
        $out = [];
        foreach ($row as $header => $value) {
            if (! str_starts_with(strtolower((string) $header), $needle)) {
                continue;
            }
            $lang = trim(substr((string) $header, strlen($prefix) + 2));
            if ($lang !== '' && $value !== null && trim($value) !== '') {
                $out[$lang] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, ?string>  $row
     */
    private function col(array $row, string $name): ?string
    {
        foreach ($row as $header => $value) {
            if (strcasecmp((string) $header, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, ?string>  $row
     * @return array<string, ?string>
     */
    private function lowercaseKeys(array $row): array
    {
        $out = [];
        foreach ($row as $header => $value) {
            $out[strtolower((string) $header)] = $value;
        }

        return $out;
    }

    private function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
