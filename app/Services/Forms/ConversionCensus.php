<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\ComparisonOperator;
use App\Enums\ConversionImpact;
use App\Enums\FieldType;
use App\Enums\PipingEligibility;
use App\Enums\ValueShape;
use App\Exceptions\Expressions\ExpressionException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Services\Expressions\Ast\Node;
use App\Services\Expressions\ExpressionParser;
use App\Services\Expressions\StructuredRuleLowering;
use App\Services\Templates\TemplateParser;
use App\Support\Forms\FieldTypeConversion;

/**
 * What converting one field does to the REST of its draft (Increment M122) — the cross-field half of a
 * conversion that {@see FieldTypeConversion} cannot see, because a plan describes one field.
 *
 * A conversion keeps the id and the key, so every reference to the field survives by construction. What it
 * does not keep is the reference's MEANING, and nothing else in the tree says so: the expression gate checks
 * that a key resolves, never how it is used, and `StepGraphInspector` builds a forward graph only. This is the
 * REVERSE index — every use of the key, with the way it is used.
 *
 * ── Two halves, so the gathering runs once per read ─────────────────────────────────────────────────────
 * {@see referencesTo()} reads the draft and returns every use; {@see judge()} is pure and is asked once per
 * target. A `ConversionPlan` carries no field id or key, so the census cannot be derived from a plan alone.
 *
 * ── What counts as a use ────────────────────────────────────────────────────────────────────────────────
 * Every relevance (field and section), calculated formula and constraint expression in the draft — INCLUDING
 * the field's own constraint rows, where `.` stands for the key: the engine keeps those rows and defers to the
 * expression gate, which never checks ordering or list shape, so `. >= 2` on a scale that becomes a
 * multi-select would refuse every answer and publish clean. Every structured rule row on ANOTHER field whose
 * related field is this one, lowered through {@see StructuredRuleLowering} so the operator-less rows
 * (`greater_than_field`, a `required_with` that means "is answered") are read by the same walk as an
 * expression. The field's OWN structured rows are excluded: the engine's plan keeps or drops them. And every
 * `${key}` hole in the template columns {@see TemplateValidationGate} walks, plus the form's confirmation
 * message, read leniently so one malformed template loses only itself.
 *
 * ── Four judges, applied INDEPENDENTLY ──────────────────────────────────────────────────────────────────
 * ⛔ A SITE IS REPORTED IF ANY ONE JUDGE FIRES, and each judge asks about the SOURCE type on its own terms.
 * One combined "was this reference already bad?" was the obvious design and is wrong: a `contains` on a single
 * choice is already a substring test, so a combined verdict would call that reference broken and stay silent
 * while the field lost its answer entirely. Each judge reports only what THIS conversion changes.
 *
 * ⚠️ ADVISORY, AND OUTSIDE THE FINGERPRINT. The plan's fingerprint covers the converted field alone, so
 * another tab editing a field that names it is not caught at the write. That is acceptable for a warning —
 * publish stays the gate, and only a template hole is one it already enforces.
 *
 * @phpstan-type CensusUse array{site: string, key: string, numeric: bool, list: bool}
 * @phpstan-type CensusImpact array{code: string, site: string, key: string, message: string}
 */
final class ConversionCensus
{
    private const TEMPLATE = 'template';

    public function __construct(
        private readonly ExpressionParser $parser,
        private readonly StructuredRuleLowering $lowering,
        private readonly TemplateParser $templates,
    ) {}

    /**
     * Every use of $field's key in its draft — at most one entry per (site, use), unjudged.
     *
     * @return list<CensusUse>
     */
    public function referencesTo(Form $form, FormField $field): array
    {
        $versionId = $field->form_version_id;
        $fields = FormField::query()->where('form_version_id', $versionId)->orderBy('sequence')->orderBy('id')->get();
        $sections = FormSection::query()->where('form_version_id', $versionId)->orderBy('sequence')->orderBy('id')->get();
        $rows = FormFieldValidation::query()->where('form_version_id', $versionId)->orderBy('sequence')->orderBy('id')->get();

        /** @var array<string, string> $keysById */
        $keysById = $fields->pluck('key', 'id')->all();
        $key = $field->key;
        $uses = [];

        foreach ($fields as $other) {
            $uses[] = $this->expressionUse('relevance', $other->key, $other->relevant_expression, $key, false);
            $uses[] = $this->expressionUse('formula', $other->key, $this->formulaOf($other), $key, false);

            if ($other->id !== $field->id) {
                $uses[] = $this->templateUse($other->key, [
                    $other->label, $other->hint, $other->placeholder,
                    ...array_values($other->label_translations ?? []),
                    ...array_values($other->hint_translations ?? []),
                ], $key);
            }
        }

        foreach ($sections as $section) {
            $uses[] = $this->expressionUse('section_relevance', $section->key, $section->relevant_expression, $key, false);
            $uses[] = $this->templateUse($section->key, [
                $section->label, $section->description,
                ...array_values($section->label_translations ?? []),
                ...array_values($section->description_translations ?? []),
            ], $key);
        }

        foreach ($rows as $row) {
            $ownerKey = $keysById[$row->form_field_id] ?? null;

            if ($ownerKey === null) {
                continue;
            }

            if ($row->expression !== null) {
                $uses[] = $this->expressionUse('constraint', $ownerKey, $row->expression, $key, $row->form_field_id === $field->id);
            } elseif ($row->form_field_id !== $field->id && $row->related_form_field_id === $field->id) {
                $uses[] = $this->nodeUse('rule', $ownerKey, $this->lowered($row, $keysById), $key, false);
            }
        }

        $uses[] = $this->templateUse('confirmation_message', [
            $form->confirmation_message,
            ...array_values($form->confirmation_message_translations ?? []),
        ], $key);

        return array_values(array_filter($uses));
    }

    /**
     * What converting $from to $to does to each use — de-duplicated per (code, site, key), in use order.
     *
     * @param  list<CensusUse>  $uses
     * @return list<CensusImpact>
     */
    public function judge(array $uses, FieldType $from, FieldType $to): array
    {
        $impacts = [];

        foreach ($uses as $use) {
            foreach ($this->impactsOf($use, $from, $to) as $impact) {
                $impacts[$impact->value.'|'.$use['site'].'|'.$use['key']] = [
                    'code' => $impact->value,
                    'site' => $use['site'],
                    'key' => $use['key'],
                    'message' => $impact->message(),
                ];
            }
        }

        return array_values($impacts);
    }

    /**
     * @param  CensusUse  $use
     * @return list<ConversionImpact>
     */
    private function impactsOf(array $use, FieldType $from, FieldType $to): array
    {
        // The publish gate's own verdict for a hole (TemplateScopeResolver's type arm). A conversion moves
        // neither the field nor its repeat scope, so the type is the only term that can change.
        if ($use['site'] === self::TEMPLATE) {
            return self::pipeable($from) && ! self::pipeable($to) ? [ConversionImpact::TemplateHoleUnanswerable] : [];
        }

        // A reference to nothing subsumes every narrower judge — but it asks only whether the SOURCE had an
        // answer, never whether this use of it was already sound. See the class docblock.
        if (self::answers($from) && ! self::answers($to)) {
            return [ConversionImpact::ReferenceHasNoAnswer];
        }

        $impacts = [];

        if ($use['numeric'] && self::numeric($from) && ! self::numeric($to)) {
            $impacts[] = ConversionImpact::NumericUseOnNonNumber;
        }

        if ($use['list'] && self::isList($from) !== self::isList($to)) {
            $impacts[] = ConversionImpact::ListMeaningChanges;
        }

        return $impacts;
    }

    /** @return CensusUse|null */
    private function expressionUse(string $site, string $siteKey, ?string $expression, string $key, bool $selfIsKey): ?array
    {
        if ($expression === null || trim($expression) === '') {
            return null;
        }

        try {
            $node = $this->parser->parse($expression);
        } catch (ExpressionException) {
            return null; // the expression gate owns an unparsable expression; a census reads past it
        }

        return $this->nodeUse($site, $siteKey, $node, $key, $selfIsKey);
    }

    /** @return CensusUse|null */
    private function nodeUse(string $site, string $siteKey, ?Node $node, string $key, bool $selfIsKey): ?array
    {
        if ($node === null) {
            return null;
        }

        $use = ExpressionKeyUse::of($node, $key, $selfIsKey);

        return $use['present'] ? ['site' => $site, 'key' => $siteKey, 'numeric' => $use['numeric'], 'list' => $use['list']] : null;
    }

    /**
     * A rule row on another field, lowered to the AST its evaluator would run — or null when the row cannot
     * be lowered at all, which is the publish gate's finding to make rather than this one's.
     *
     * @param  array<string, string>  $keysById
     */
    private function lowered(FormFieldValidation $row, array $keysById): ?Node
    {
        $type = $row->rule_type;

        if ($type === null || ! $type->takesRelatedField()) {
            return null;
        }

        try {
            return $type->takesOperator()
                ? $this->lowering->lowerCondition($row, $keysById)
                : $this->lowering->lower($row, $keysById);
        } catch (ExpressionException) {
            return null;
        }
    }

    /**
     * @param  list<mixed>  $texts  a column and its locale variants; a non-string variant is a shape problem, not a use
     * @return CensusUse|null
     */
    private function templateUse(string $siteKey, array $texts, string $key): ?array
    {
        foreach ($texts as $text) {
            if (! is_string($text) || ! str_contains($text, '${')) {
                continue;
            }

            foreach ($this->templates->parseLenient($text) as $segment) {
                if ($segment['type'] === 'hole' && $segment['value'] === $key) {
                    return ['site' => self::TEMPLATE, 'key' => $siteKey, 'numeric' => false, 'list' => false];
                }
            }
        }

        return null;
    }

    private function formulaOf(FormField $field): ?string
    {
        if ($field->field_type !== FieldType::Calculated) {
            return null;
        }

        $formula = data_get($field->config, 'calculated_formula');

        return is_string($formula) ? $formula : null;
    }

    private static function answers(FieldType $type): bool
    {
        return ValueShape::for($type) !== ValueShape::NoAnswer;
    }

    /** Whether the shape is one an ordered comparison is offered for — the editor's own table. */
    private static function numeric(FieldType $type): bool
    {
        return ValueShape::for($type)->allowsOperator(ComparisonOperator::Gt);
    }

    private static function pipeable(FieldType $type): bool
    {
        return PipingEligibility::for($type) === PipingEligibility::Pipeable;
    }

    /**
     * Whether the stored answer is a LIST (`StructuralAnswerNormalizer::coerce()`), so `contains` is membership,
     * `count()` is a length and an equality against one value never holds. A grid or geo answer is an object,
     * not a list, and any expression reference to one is already refused at publish.
     *
     * ⛔ A `match` ON THE ENUM WITH NO `default` ARM — a thirty-second field type is a PHPStan error here. Kept
     * out of `ValueShape`, which partitions what a RULE may assert: the list answers span three of its shapes.
     */
    private static function isList(FieldType $type): bool
    {
        return match ($type) {
            FieldType::MultiSelect, FieldType::CascadingSelect,
            FieldType::FileUpload, FieldType::ImageCapture, FieldType::AudioCapture,
            FieldType::VideoCapture, FieldType::Signature => true,

            FieldType::ShortText, FieldType::LongText, FieldType::Email, FieldType::Phone, FieldType::Url,
            FieldType::Hidden, FieldType::Integer, FieldType::Decimal, FieldType::Calculated,
            FieldType::Date, FieldType::Time, FieldType::Datetime, FieldType::Duration,
            FieldType::SingleSelect, FieldType::Dropdown, FieldType::LikertScale, FieldType::YesNo,
            FieldType::Geopoint, FieldType::Geotrace, FieldType::Geoshape,
            FieldType::Matrix, FieldType::LikertMatrix, FieldType::Note, FieldType::PageBreak => false,
        };
    }
}
