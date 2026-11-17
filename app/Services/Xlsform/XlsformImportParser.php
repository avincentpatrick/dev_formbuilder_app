<?php

declare(strict_types=1);

namespace App\Services\Xlsform;

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Exceptions\Xlsform\XlsformImportException;
use App\Rules\ContentBlocks;
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
 * UPFRONT — a missing `survey` sheet, an unmapped `type` or a cell too long to store throws
 * {@see XlsformImportException} BEFORE any plan is returned, so the importer's destructive draft-replace never runs
 * on a bad workbook (§6).
 *
 * Lossy inputs degrade with a warning, never a failure: a dynamic `repeat_count`, a matrix/likert grid
 * marker, an illegal `name` (sanitized to a valid key). Cascades — ours and foreign — are reconstructed by
 * the {@see CascadeResolver}.
 *
 * M244 reads the workbook the way ODK and Kobo write it:
 *   - every row is named by the number the author sees ({@see RawWorkbook::rowNumber()});
 *   - languages are codes, and the default language's column is the base text ({@see XlsformLanguages});
 *   - a note's markdown, or a note too long for a label, becomes note content; a question's label, hint and choices
 *     lose their formatting, with a warning ({@see XlsformMarkdown});
 *   - formulas take ODK's spellings, and name what the grammar lacks ({@see XlsformExpressionTranslator});
 *   - a whole `regex`/`string-length` constraint becomes a Pattern or length rule (`D228`);
 *   - a `required` formula becomes "Required when…" ({@see XlsformRequiredFormula});
 *   - a picture, sound or video a row names is reported, since the file cannot come with the workbook;
 *   - every cell is measured against what can store it, and the file is refused naming each one too long
 *     ({@see XlsformColumnLimits}).
 */
final class XlsformImportParser
{
    /**
     * M219 (`R-1fc57252`): the width of `form_fields.appearance`, which is `varchar(60)`. An appearance
     * longer than this is dropped with a warning ({@see self::resolveAppearance()}). `XlsformImportAppearanceTest`
     * reads the real column's width from the database, so this and the schema cannot drift apart unseen.
     */
    public const APPEARANCE_MAX_LENGTH = 60;

    /** M244: the columns that name a picture, sound or video for a row, which an import cannot bring in. */
    private const MEDIA_COLUMNS = ['image', 'big-image', 'audio', 'video'];

    /**
     * M177 (`R-71509975`): each `or_other` question and the Please specify question made for it, kept as objects so the
     * link survives {@see self::sanitizeKeys()} renaming either key. Reset at every parse.
     *
     * @var list<array{0: FieldSpec, 1: FieldSpec}>
     */
    private array $otherPairs = [];

    private XlsformColumnLimits $limits;

    private XlsformLanguages $languages;

    public function __construct(
        private readonly XlsformTypeMap $types,
        private readonly GeoWireConverter $geo,
        private readonly CascadeResolver $cascades,
        private readonly XlsformExpressionTranslator $translator = new XlsformExpressionTranslator,
        private readonly XlsformMarkdown $markdown = new XlsformMarkdown,
        private readonly XlsformRequiredFormula $requiredFormula = new XlsformRequiredFormula,
    ) {
        $this->limits = new XlsformColumnLimits;
        $this->languages = new XlsformLanguages(null, []);
    }

    public function parse(RawWorkbook $workbook): ImportPlan
    {
        if (! $workbook->hasSheet('survey')) {
            throw XlsformImportException::missingSurveySheet();
        }

        $warnings = [];
        $this->otherPairs = [];
        $this->limits = new XlsformColumnLimits;
        $settingsRow = $workbook->rows('settings')[0] ?? [];
        $this->languages = new XlsformLanguages($this->trimToNull($this->col($settingsRow, 'default_language')), $workbook->headers('survey'));

        $choicesByList = $this->normalizeChoices($workbook, $warnings);

        [$sections, $fields] = $this->walkSurvey($workbook, $choicesByList, $warnings);
        $settings = $this->parseSettings($workbook, $warnings);

        // Every cell is measured before anything is resolved further, and one refusal names them all (§6).
        $this->limits->assertNoneTooLong();

        $fields = $this->cascades->resolve($fields, $choicesByList, $warnings);

        $this->resolveRequiredFormulas($fields, $warnings);
        $this->sanitizeKeys($sections, $fields, $warnings);
        $this->relinkOtherPairs();
        $this->assignSequences($sections, $fields);

        return new ImportPlan(
            sections: $sections,
            fields: $fields,
            settings: $settings,
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
            $rowNumber = $workbook->rowNumber('survey', $index);
            $typeRaw = trim((string) ($this->col($row, 'type') ?? ''));
            if ($typeRaw === '') {
                continue;
            }

            $lower = strtolower($typeRaw);
            $name = trim((string) ($this->col($row, 'name') ?? ''));
            $this->warnMedia('Row', $rowNumber, $row, $name, $warnings);

            if (str_starts_with($lower, 'begin ')) {
                $marker = $this->trimToNull($this->col($row, '#meridian'));

                if ($marker === XlsformTypeMap::MARKER_PAGE_BREAK) {
                    [$label] = $this->plainText($row, 'label', $rowNumber, XlsformColumnLimits::FIELD_LABEL, $warnings, $name);
                    $fields[] = new FieldSpec(
                        key: $name,
                        fieldType: FieldType::PageBreak,
                        sectionKey: $this->currentSectionKey($stack),
                        label: $label ?? '',
                        sourceRow: $rowNumber,
                    );
                    $stack[] = ['kind' => 'pagebreak', 'sectionKey' => null];

                    continue;
                }

                $section = $this->beginSection($lower, $name, $row, $rowNumber, $warnings);
                $sections[] = $section;
                $stack[] = ['kind' => 'section', 'sectionKey' => $section->key];

                continue;
            }

            if (str_starts_with($lower, 'end ')) {
                array_pop($stack);

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

        return [$sections, $fields];
    }

    /**
     * @param  array<string, ?string>  $row
     * @param  list<string>  $warnings
     */
    private function beginSection(string $lowerType, string $name, array $row, int $rowNumber, array &$warnings): SectionSpec
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

        [$label, $translations] = $this->plainText($row, 'label', $rowNumber, XlsformColumnLimits::SECTION_LABEL, $warnings, $name);

        return new SectionSpec(
            key: $name,
            label: $label ?? '',
            labelTranslations: $translations ?: null,
            isRepeatable: $isRepeat,
            maxInstances: $maxInstances,
            relevantExpression: $this->formula($row, 'relevant', $rowNumber, $name, $warnings),
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

        [$defaultValue, $defaultIsExpression] = $this->resolveDefault($type, $row, $rowNumber, $key, $config, $warnings);

        if ($type === FieldType::Note) {
            [$label, $labelTranslations] = $this->noteText($row, $rowNumber, $key, $config, $warnings);
        } else {
            [$label, $labelTranslations] = $this->plainText($row, 'label', $rowNumber, XlsformColumnLimits::FIELD_LABEL, $warnings, $key);
        }
        [$hint, $hintTranslations] = $this->plainText($row, 'hint', $rowNumber, XlsformColumnLimits::HINT, $warnings, $key);

        [$isRequired, $requiredFormula] = $this->resolveRequired($row, $rowNumber, $warnings);
        [$requiredMessage, $requiredMessageTranslations] = $this->message($row, 'required_message', $rowNumber, $warnings);
        if ($isRequired === RequiredMode::Required && $requiredMessage !== null) {
            $warnings[] = "The required message of “{$key}” was not imported: a question that is always required shows this app's own message.";
        }

        return new FieldSpec(
            key: $key,
            fieldType: $type,
            sectionKey: $sectionKey,
            config: $config,
            label: $label ?? '',
            labelTranslations: $labelTranslations ?: null,
            hint: $this->trimToNull($hint),
            hintTranslations: $hintTranslations ?: null,
            defaultValue: $defaultValue,
            defaultValueIsExpression: $defaultIsExpression,
            isRequired: $isRequired,
            relevantExpression: $this->formula($row, 'relevant', $rowNumber, $key, $warnings),
            appearance: $this->resolveAppearance($type, $appearance, $key, $warnings),
            validations: $this->resolveConstraint($row, $type, $rowNumber, $key, $warnings),
            listName: $listName,
            choiceFilter: $this->trimToNull($this->col($row, 'choice_filter')),
            marker: $marker,
            requiredFormula: $requiredFormula,
            requiredMessage: $requiredMessage,
            requiredMessageTranslations: $requiredMessageTranslations ?: null,
            sourceRow: $rowNumber,
        );
    }

    /**
     * A row's text in `$prefix` with its formatting taken out (M244, `R-5957b273`): a question's label, hint or a
     * section's label is plain text here, so `**bold**` would reach a respondent as typed. One warning per row, only
     * when something was taken out. Each language is measured against `$limit`.
     *
     * @param  array<string, ?string>  $row
     * @param  list<string>  $warnings
     * @return array{0: ?string, 1: array<string, string>}
     */
    private function plainText(array $row, string $prefix, int $rowNumber, int $limit, array &$warnings, string $owner = ''): array
    {
        [$base, $translations] = $this->languages->text($row, $prefix, $warnings);

        $changed = false;
        if ($base !== null) {
            $plain = $this->markdown->plainLabel($base);
            $changed = $plain !== $base;
            $base = $plain;
            $this->limits->measure('survey', $rowNumber, $prefix, $base, $limit);
        }
        foreach ($translations as $language => $text) {
            $plain = $this->markdown->plainLabel($text);
            $changed = $changed || $plain !== $text;
            $translations[$language] = $plain;
            $this->limits->measure('survey', $rowNumber, $prefix.'::'.$language, $plain, $limit);
        }

        if ($changed) {
            $warnings[] = "Row {$rowNumber}: the {$prefix} of “{$owner}” had formatting, which was removed; it is plain text in this app.";
        }

        return [$base, $translations];
    }

    /**
     * A note's text (M244, `R-5957b273`, `R-24ec82a6`). It becomes note content when it is formatted or longer than a
     * label holds, unless it shows an earlier answer (content is not piped) or, at a label's length, has translations
     * (content shows in the default language only, `D70`, so its translations would stop reaching anyone). The label
     * kept beside content is its plain words, cut to fit: it names the note in the builder and prints on paper.
     *
     * @param  array<string, ?string>  $row
     * @param  array<string, mixed>  $config
     * @param  list<string>  $warnings
     * @return array{0: ?string, 1: array<string, string>}
     */
    private function noteText(array $row, int $rowNumber, string $key, array &$config, array &$warnings): array
    {
        [$base, $translations] = $this->languages->text($row, 'label', $warnings);
        if ($base === null) {
            return [null, $translations];
        }

        $pipes = str_contains($base, '${');
        $long = mb_strlen($base) > XlsformColumnLimits::FIELD_LABEL;

        if (! $pipes && ($long || $translations === [])) {
            $reading = $this->markdown->toBlocks($base);
            $blocks = $reading['blocks'];

            if (($reading['formatted'] || $long) && $blocks !== []) {
                if (count($blocks) > ContentBlocks::MAX_BLOCKS) {
                    $this->limits->measureCount('survey', $rowNumber, 'label', count($blocks), ContentBlocks::MAX_BLOCKS, 'note content blocks');

                    return [$base, $translations];
                }

                $problem = ContentBlocks::problem($blocks, true);
                if ($problem === null) {
                    $config['content'] = $blocks;
                    $this->warnMarkdownIssues($rowNumber, $key, $reading['issues'], $warnings);
                    if ($translations !== []) {
                        $warnings[] = "Row {$rowNumber}: the note “{$key}” is too long for a label, so it became note content, which shows in the form's default language only; its translations were kept as its name.";
                    }

                    $label = XlsformMarkdown::cut(XlsformMarkdown::plainText($blocks), XlsformColumnLimits::FIELD_LABEL);
                    foreach ($translations as $language => $text) {
                        $translations[$language] = XlsformMarkdown::cut($this->markdown->plainLabel($text), XlsformColumnLimits::FIELD_LABEL);
                    }

                    return [$label, $translations];
                }

                $warnings[] = "Row {$rowNumber}: the note “{$key}” could not be carried as formatted content ({$problem}), so it was imported as plain text.";
            }
        }

        $plain = $this->markdown->plainLabel($base);
        $changed = $plain !== $base;
        $this->limits->measure('survey', $rowNumber, 'label', $plain, XlsformColumnLimits::FIELD_LABEL);
        foreach ($translations as $language => $text) {
            $plainTranslation = $this->markdown->plainLabel($text);
            $changed = $changed || $plainTranslation !== $text;
            $translations[$language] = $plainTranslation;
            $this->limits->measure('survey', $rowNumber, 'label::'.$language, $plainTranslation, XlsformColumnLimits::FIELD_LABEL);
        }

        if ($changed) {
            $why = $pipes ? 'it shows an earlier answer, which formatted note content cannot' : 'it has translations, and formatted note content shows in one language only';
            $warnings[] = "Row {$rowNumber}: the note “{$key}” had formatting, which was removed because {$why}.";
        }

        return [$plain, $translations];
    }

    /**
     * @param  list<array{kind: string, detail: string}>  $issues
     * @param  list<string>  $warnings
     */
    private function warnMarkdownIssues(int $rowNumber, string $key, array $issues, array &$warnings): void
    {
        foreach ($issues as $issue) {
            $warnings[] = match ($issue['kind']) {
                'image' => "Row {$rowNumber}: the note “{$key}” shows the picture “{$issue['detail']}”, which an import cannot bring in. Upload it into the note.",
                'unsafe_link' => "Row {$rowNumber}: the note “{$key}” links to “{$issue['detail']}”, which is not a web, email or phone link, so its words were kept without the link.",
                default => "Row {$rowNumber}: the note “{$key}” held HTML, whose tags were removed; its words were kept.",
            };
        }
    }

    /**
     * A row's message column (`constraint_message`, `required_message`), measured in every language.
     *
     * @param  array<string, ?string>  $row
     * @param  list<string>  $warnings
     * @return array{0: ?string, 1: array<string, string>}
     */
    private function message(array $row, string $prefix, int $rowNumber, array &$warnings): array
    {
        [$base, $translations] = $this->languages->text($row, $prefix, $warnings);
        $base = $this->trimToNull($base);
        $this->limits->measure('survey', $rowNumber, $prefix, $base, XlsformColumnLimits::MESSAGE);
        foreach ($translations as $language => $text) {
            $this->limits->measure('survey', $rowNumber, $prefix.'::'.$language, $text, XlsformColumnLimits::MESSAGE);
        }

        return [$base, $translations];
    }

    /**
     * A formula column in ODK's spelling, renamed where this product has its own name for it, with a warning for
     * anything the grammar does not have (M244, `R-bb470c3a`). It is kept as written, so nothing the author wrote is
     * lost, and publish refuses it naming the function.
     *
     * @param  array<string, ?string>  $row
     * @param  list<string>  $warnings
     */
    private function formula(array $row, string $column, int $rowNumber, string $owner, array &$warnings): ?string
    {
        $value = $this->trimToNull($this->col($row, $column));
        if ($value === null) {
            return null;
        }
        $this->limits->measure('survey', $rowNumber, $column, $value, XlsformColumnLimits::EXPRESSION);

        $translated = $this->translator->translate($value);
        $this->warnFormula($column, $owner, $translated, $warnings);

        return $translated;
    }

    /**
     * @param  list<string>  $warnings
     */
    private function warnFormula(string $column, string $owner, ?string $expression, array &$warnings): void
    {
        if ($expression === null) {
            return;
        }

        $unsupported = $this->translator->unsupported($expression);
        if ($unsupported !== []) {
            $names = implode(', ', array_map(
                static fn (string $name): string => XlsformExpressionTranslator::isOperatorWord($name) ? "the operator “{$name}”" : "“{$name}”",
                $unsupported,
            ));
            $warnings[] = "The {$column} of “{$owner}” uses {$names}, which this app does not have. It was kept as written, so the form will not publish until it is changed.";

            return;
        }

        $problem = $this->translator->syntaxProblem($expression);
        if ($problem !== null) {
            $warnings[] = "The {$column} of “{$owner}” cannot be read: {$problem} It was kept as written, so the form will not publish until it is changed.";
        }
    }

    /**
     * `calculation` vs literal `default` (§2). A `calculate` field's formula lives in `config.calculated_formula`
     * (where the expression gate + exporter read it); any other field's `calculation` is an expression default;
     * a geo `default` is deserialised through the wire converter.
     *
     * @param  array<string, ?string>  $row
     * @param  array<string, mixed>  $config
     * @param  list<string>  $warnings
     * @return array{0: ?string, 1: bool}
     */
    private function resolveDefault(FieldType $type, array $row, int $rowNumber, string $key, array &$config, array &$warnings): array
    {
        $calculation = $this->formula($row, 'calculation', $rowNumber, $key, $warnings);
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
     * A `constraint` as this product's rows (§2; M244, `D228`): each top-level `and`-part that is a whole
     * `regex(., …)` or `string-length(.) op N` becomes a Pattern or length rule where the question can take it, and the
     * rest stays one expression row in the author's words. Every row carries the constraint's message.
     *
     * @param  array<string, ?string>  $row
     * @param  list<string>  $warnings
     * @return list<ValidationSpec>
     */
    private function resolveConstraint(array $row, FieldType $type, int $rowNumber, string $key, array &$warnings): array
    {
        $constraint = $this->trimToNull($this->col($row, 'constraint'));
        [$message, $messageTranslations] = $this->message($row, 'constraint_message', $rowNumber, $warnings);
        if ($constraint === null) {
            return [];
        }
        $this->limits->measure('survey', $rowNumber, 'constraint', $constraint, XlsformColumnLimits::EXPRESSION);

        [$rules, $kept] = $this->translator->constraint($this->translator->translate($constraint), $type);
        $this->warnFormula('constraint', $key, $kept, $warnings);

        $rows = $rules;
        if ($kept !== null) {
            $rows[] = new ValidationSpec(expression: $kept);
        }

        $out = [];
        foreach ($rows as $i => $spec) {
            $out[] = $spec->withMessage($message, $messageTranslations ?: null)->at($i);
        }

        return $out;
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

    /**
     * `required` as yes or no, or (M244, `R-ea0cc482`) a formula, kept to be read as "Required when…" once every
     * question is known ({@see self::resolveRequiredFormulas()}).
     *
     * @param  array<string, ?string>  $row
     * @param  list<string>  $warnings
     * @return array{0: RequiredMode, 1: ?string}
     */
    private function resolveRequired(array $row, int $rowNumber, array &$warnings): array
    {
        $value = $this->trimToNull($this->col($row, 'required'));
        if ($value === null) {
            return [RequiredMode::Optional, null];
        }

        $lower = strtolower($value);
        if (in_array($lower, ['yes', 'true', '1', 'true()'], true)) {
            return [RequiredMode::Required, null];
        }
        if (in_array($lower, ['no', 'false', '0', 'false()'], true)) {
            return [RequiredMode::Optional, null];
        }

        $this->limits->measure('survey', $rowNumber, 'required', $value, XlsformColumnLimits::EXPRESSION);

        return [RequiredMode::Optional, $value];
    }

    /**
     * Each question's `required` formula as "Required when…" rules (M244, `R-ea0cc482`), read once every question is
     * known so a rule may name a question further down. One that cannot be read leaves the question optional, with a
     * warning naming it and its formula. Runs before {@see self::sanitizeKeys()}, because the formula names
     * questions as the workbook writes them; each rule holds its question as an object, so a rename carries.
     *
     * @param  list<FieldSpec>  $fields
     * @param  list<string>  $warnings
     */
    private function resolveRequiredFormulas(array $fields, array &$warnings): void
    {
        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field->key] ??= $field;
        }

        foreach ($fields as $field) {
            if ($field->requiredFormula === null) {
                continue;
            }

            $formula = $this->translator->translate($field->requiredFormula);
            $rules = $this->requiredFormula->read($formula, $field, $byKey);
            if ($rules === null) {
                $warnings[] = "The required formula of “{$field->key}” ({$field->requiredFormula}) could not be read as “Required when…”, so the question was imported as optional. Set “Required when…” in the builder.";

                continue;
            }

            $field->isRequired = RequiredMode::Conditional;
            $rows = [...$field->validations];
            foreach ($rules as $rule) {
                $rows[] = $rule->withMessage($field->requiredMessage, $field->requiredMessageTranslations);
            }
            $field->validations = array_map(
                static fn (ValidationSpec $spec, int $i): ValidationSpec => $spec->at($i),
                $rows,
                array_keys($rows),
            );
        }
    }

    /**
     * M244 (`R-915e5d0c`): a row that names a picture, sound or video. The workbook carries only the file's name, so
     * the import cannot attach it; the author is told which row lost which file.
     *
     * @param  array<string, ?string>  $row
     * @param  list<string>  $warnings
     */
    private function warnMedia(string $where, int $rowNumber, array $row, string $name, array &$warnings): void
    {
        foreach ($row as $header => $value) {
            if ($value === null || trim($value) === '') {
                continue;
            }
            // `media::image`, `media::image::English (en)`, or the older bare `image`, `audio`, `video`, `big-image`.
            $lower = strtolower(trim((string) $header));
            $base = explode('::', str_starts_with($lower, 'media::') ? substr($lower, 7) : $lower)[0];
            if (! in_array($base, self::MEDIA_COLUMNS, true)) {
                continue;
            }

            $warnings[] = "{$where} {$rowNumber}: “{$name}” names the file “".trim($value).'”, which an import cannot bring in. Upload it in the builder.';
        }
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
     * `raw` is a lowercase-keyed copy so the cascade resolver can read arbitrary foreign filter columns. M244: a
     * label's formatting is taken out (one warning per list), its languages are codes, and each cell is measured.
     *
     * @param  list<string>  $warnings
     * @return array<string, list<array<string, mixed>>>
     */
    private function normalizeChoices(RawWorkbook $workbook, array &$warnings): array
    {
        $byList = [];
        $formatted = [];
        foreach ($workbook->rows('choices') as $index => $row) {
            $listName = $this->trimToNull($this->col($row, 'list_name')) ?? $this->trimToNull($this->col($row, 'list name'));
            if ($listName === null) {
                continue;
            }
            $rowNumber = $workbook->rowNumber('choices', $index);
            $value = trim((string) ($this->col($row, 'name') ?? ''));
            $this->warnMedia('Choices row', $rowNumber, $row, $value, $warnings);
            $this->limits->measure('choices', $rowNumber, 'name', $value, XlsformColumnLimits::CHOICE_NAME);

            [$label, $translations] = $this->languages->text($row, 'label', $warnings);
            $label ??= '';
            $plain = $this->markdown->plainLabel($label);
            if ($plain !== $label) {
                $formatted[$listName] = true;
            }
            $this->limits->measure('choices', $rowNumber, 'label', $plain, XlsformColumnLimits::CHOICE_LABEL);
            foreach ($translations as $language => $text) {
                $plainTranslation = $this->markdown->plainLabel($text);
                if ($plainTranslation !== $text) {
                    $formatted[$listName] = true;
                }
                $translations[$language] = $plainTranslation;
                $this->limits->measure('choices', $rowNumber, 'label::'.$language, $plainTranslation, XlsformColumnLimits::CHOICE_LABEL);
            }

            $byList[$listName][] = [
                'value' => $value,
                'label' => $plain,
                'label_translations' => $translations,
                'level' => $this->col($row, 'level'),
                'parent' => $this->col($row, 'parent'),
                'raw' => $this->lowercaseKeys($row),
            ];
        }

        foreach (array_keys($formatted) as $listName) {
            $warnings[] = "The choices of the list “{$listName}” had formatting, which was removed; a choice is plain text in this app.";
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

    /**
     * @param  list<string>  $warnings
     */
    private function parseSettings(RawWorkbook $workbook, array &$warnings): SettingsSpec
    {
        $row = $workbook->rows('settings')[0] ?? [];
        $title = $this->trimToNull($this->col($row, 'form_title'));
        $this->limits->measure('settings', $workbook->rowNumber('settings', 0), 'form_title', $title, XlsformColumnLimits::FORM_TITLE);

        return new SettingsSpec(
            formTitle: $title,
            formId: $this->trimToNull($this->col($row, 'form_id')),
            defaultLanguage: $this->languages->locale($warnings),
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
     * M244: a key is at most {@see XlsformColumnLimits::KEY} characters with its `_2` suffix, the width of the key
     * columns, so a long ODK name is shortened (and the rename warned) rather than failing the insert.
     *
     * @param  array<string, true>  $used
     */
    private function sanitizeKey(string $key, array $used): string
    {
        $slug = strtolower(trim($key));
        $slug = (string) preg_replace('/[^a-z0-9_]/', '_', $slug);
        if ($slug === '' || ! preg_match('/^[a-z]/', $slug)) {
            $slug = 'x_'.$slug;
        }
        $slug = substr($slug, 0, XlsformColumnLimits::KEY);

        $candidate = $slug;
        $n = 2;
        while (isset($used[$candidate])) {
            $suffix = '_'.$n;
            $candidate = substr($slug, 0, XlsformColumnLimits::KEY - strlen($suffix)).$suffix;
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
