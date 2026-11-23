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
use App\Support\Forms\OtherSpecify;

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
    /**
     * M219 (`R-1fc57252`): the width of `form_fields.appearance`, which is `varchar(60)`. An appearance
     * longer than this is dropped with a warning ({@see self::resolveAppearance()}). `XlsformImportAppearanceTest`
     * reads the real column's width from the database, so this and the schema cannot drift apart unseen.
     */
    public const APPEARANCE_MAX_LENGTH = 60;

    /**
     * M177 (`R-71509975`): each `or_other` question and the Please specify question made for it, kept as objects so the
     * link survives {@see self::sanitizeKeys()} renaming either key. Reset at every parse.
     *
     * @var list<array{0: FieldSpec, 1: FieldSpec}>
     */
    private array $otherPairs = [];

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
        $this->otherPairs = [];
        $choicesByList = $this->normalizeChoices($workbook);

        [$sections, $fields] = $this->walkSurvey($workbook, $choicesByList, $warnings);

        $fields = $this->cascades->resolve($fields, $choicesByList, $warnings);

        $this->sanitizeKeys($sections, $fields, $warnings);
        $this->relinkOtherPairs();
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
        // M249: each section's enclosing section and its `appearance`, for {@see self::foldChapters()}.
        /** @var list<SectionSpec|null> $open */
        $open = [];
        /** @var array<int, array{parent: ?SectionSpec, appearance: string}> $meta */
        $meta = [];

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
                    $open[] = null;

                    continue;
                }

                $section = $this->beginSection($lower, $name, $row, $warnings);
                $parent = null;
                for ($i = count($open) - 1; $i >= 0 && $parent === null; $i--) {
                    $parent = $open[$i];
                }
                $meta[spl_object_id($section)] = ['parent' => $parent, 'appearance' => strtolower((string) ($this->col($row, 'appearance') ?? ''))];
                $sections[] = $section;
                $stack[] = ['kind' => 'section', 'sectionKey' => $section->key];
                $open[] = $section;

                continue;
            }

            if (str_starts_with($lower, 'end ')) {
                array_pop($stack);
                array_pop($open);

                continue;
            }

            $field = $this->buildField($typeRaw, $row, $this->currentSectionKey($stack), $rowNumber, $choicesByList, $warnings);
            $fields[] = $field;

            // M177 (`R-71509975`): `select_one list or_other` was dropped to a plain select; it is the Other, please
            // specify pair — the choice `other`, and a text question right after it shown only when it is picked.
            if ($this->hasOrOther($typeRaw) && OtherSpecify::offers($field->fieldType)) {
                $fields[] = $this->otherSpecifyFor($field);
            }
        }

        return [$this->foldChapters($sections, $fields, $meta, $warnings), $fields];
    }

    /**
     * M249 (`progress-chapters`, `D233`): an outermost group that is only a container of groups becomes a CHAPTER — the way
     * an ODK or Kobo form names a run of pages — instead of an empty section of its own. Its label (and `label::<lang>`)
     * becomes the chapter its first inner group starts.
     *
     * Only a pure container folds: outermost, a plain group (not a repeat), no `relevant`, no `field-list`, no question,
     * note or page break of its own, at least one group inside, and a label that is plain text. Every other group keeps
     * the flattening it always had. A label longer than a chapter name may be is cut, and the import says so.
     *
     * @param  list<SectionSpec>  $sections
     * @param  list<FieldSpec>  $fields
     * @param  array<int, array{parent: ?SectionSpec, appearance: string}>  $meta
     * @param  list<string>  $warnings
     * @return list<SectionSpec>
     */
    private function foldChapters(array $sections, array $fields, array $meta, array &$warnings): array
    {
        $holdsQuestions = [];
        foreach ($fields as $field) {
            if ($field->sectionKey !== null) {
                $holdsQuestions[$field->sectionKey] = true;
            }
        }
        /** @var array<int, list<SectionSpec>> $children */
        $children = [];
        foreach ($sections as $section) {
            $parent = $meta[spl_object_id($section)]['parent'] ?? null;
            if ($parent !== null) {
                $children[spl_object_id($parent)][] = $section;
            }
        }

        $kept = [];
        foreach ($sections as $section) {
            $info = $meta[spl_object_id($section)] ?? ['parent' => null, 'appearance' => ''];
            $first = ($children[spl_object_id($section)] ?? [])[0] ?? null;
            $label = trim(preg_replace('/\s+/u', ' ', $section->label) ?? '');
            $pure = $first !== null
                && $info['parent'] === null
                && ! $section->isRepeatable
                && $section->relevantExpression === null
                && ! str_contains($info['appearance'], 'field-list')
                && ! isset($holdsQuestions[$section->key])
                && $label !== ''
                && ! str_contains($label, '${');
            if (! $pure) {
                $kept[] = $section;

                continue;
            }

            $first->chapter = self::chapterName($label, $section->key, $warnings);
            $translations = [];
            foreach ($section->labelTranslations ?? [] as $locale => $text) {
                $plain = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
                if ($plain !== '' && ! str_contains($plain, '${')) {
                    $translations[$locale] = self::chapterName($plain, $section->key, $warnings);
                }
            }
            $first->chapterTranslations = $translations === [] ? null : $translations;
        }

        return $kept;
    }

    /**
     * M249: a group's label as a chapter name — at most the 60 characters `form_sections.chapter` holds, cut at a word.
     *
     * @param  list<string>  $warnings
     */
    private static function chapterName(string $label, string $group, array &$warnings): string
    {
        if (mb_strlen($label) <= 60) {
            return $label;
        }
        $cut = mb_substr($label, 0, 60);
        $space = mb_strrpos($cut, ' ');
        $cut = rtrim($space !== false && $space > 30 ? mb_substr($cut, 0, $space) : $cut);
        $warnings[] = "The group “{$group}” became a chapter, and its name was cut to “{$cut}” — a chapter name holds at most 60 characters.";

        return $cut;
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
            appearance: $this->resolveAppearance($type, $appearance, $key, $warnings),
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
     *
     * M219 (`R-1fc57252`): AN APPEARANCE LONGER THAN THE COLUMN IS DROPPED WITH A WARNING, rather than
     * kept and left to fail the whole import at the insert. ODK's `search(...)` appearances run long, and no
     * width would hold every one, so widening the column would only move the line. The builder can never
     * write one this long (`UpdateFieldRequest` holds it to the type's own list), so only an import meets it.
     *
     * @param  list<string>  $warnings
     */
    private function resolveAppearance(FieldType $type, ?string $appearance, string $key, array &$warnings): ?string
    {
        if ($appearance === null) {
            return null;
        }

        $forced = $this->types->toXlsform($type)->appearance;
        if ($forced !== null && strcasecmp($forced, $appearance) === 0) {
            return null;
        }

        if (mb_strlen($appearance) > self::APPEARANCE_MAX_LENGTH) {
            $warnings[] = sprintf(
                'The appearance of “%s” is longer than %d characters, so it was not imported; the question was imported without it.',
                $key,
                self::APPEARANCE_MAX_LENGTH
            );

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
            $warnings[] = "The matrix {$named} is not importable; its cells were imported as separate questions, each of its column's type.";
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
                // M249 (`D233`): what a closed dropdown shows while this choice is chosen — our own column, and its languages.
                'short_label' => $this->trimToNull($this->col($row, 'short_label')),
                'short_label_translations' => $this->translations($row, 'short_label'),
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
            // M249: a short label is cut to the 60 characters the builder holds, as typed text would be refused past it.
            if (is_string($row['short_label'] ?? null)) {
                $option['short_label'] = mb_substr($row['short_label'], 0, 60);
                $shortTranslations = array_map(static fn (string $text): string => mb_substr($text, 0, 60), array_filter((array) ($row['short_label_translations'] ?? []), 'is_string'));
                if ($shortTranslations !== []) {
                    $option['short_label_translations'] = $shortTranslations;
                }
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

    /** Whether a `select_one <list> or_other` / `select_multiple <list> or_other` type carries the `or_other` modifier. */
    private function hasOrOther(string $typeRaw): bool
    {
        $tokens = preg_split('/\s+/', strtolower(trim($typeRaw))) ?: [];

        return ($tokens[2] ?? null) === 'or_other';
    }

    /** The Please specify question for an `or_other` question, which gains the `other` choice and the link. */
    private function otherSpecifyFor(FieldSpec $choice): FieldSpec
    {
        /** @var list<array<string, mixed>> $options */
        $options = array_values((array) ($choice->config['options'] ?? []));
        $choice->config['options'] = OtherSpecify::withOther($options);

        $child = new FieldSpec(
            key: "{$choice->key}_other",
            fieldType: FieldType::ShortText,
            sectionKey: $choice->sectionKey,
            label: OtherSpecify::CHILD_LABEL,
            isRequired: RequiredMode::Required,
        );
        $this->otherPairs[] = [$choice, $child];

        return $child;
    }

    /** After the keys are final: the link names the Please specify question's key, and its condition the choice's. */
    private function relinkOtherPairs(): void
    {
        foreach ($this->otherPairs as [$choice, $child]) {
            $choice->config[OtherSpecify::CONFIG_KEY] = $child->key;
            $child->relevantExpression = OtherSpecify::condition($choice->key);
        }
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
