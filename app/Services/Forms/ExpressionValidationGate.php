<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\ExpressionKind;
use App\Enums\FieldType;
use App\Enums\ValidationRuleType;
use App\Exceptions\Expressions\ExpressionSyntaxException;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Services\Expressions\Coercion;
use App\Services\Expressions\ExpressionParser;
use App\Services\Validation\StructuredRuleEvaluator;

/**
 * The pre-publish EXPRESSION gate (F3; the item F2 deferred). {@see StructuralValidationGate} checks
 * shape; this checks that every authored `relevant`/`constraint` expression parses and references only
 * known fields, so a syntactically-broken or dangling-reference expression is refused at PUBLISH (naming
 * the field) rather than surfacing as a submit-time failure. Also hardens the structured rows whose
 * `rule_value` is otherwise only exercised at submission — an uncompilable `pattern` regex or a
 * non-numeric `min_value`/`max_value` threshold — both of which would silently fail closed. Parse/reference
 * failures are re-wrapped into {@see PublishValidationException} so the existing web-toast / api-422
 * surfacings fire; a submission-time caller never reaches this (published expressions are pre-validated).
 */
final class ExpressionValidationGate
{
    public function __construct(private readonly ExpressionParser $parser) {}

    public function assertExpressionsResolve(FormVersion $version): void
    {
        $fields = $version->fields()->get();
        $sections = $version->sections()->get();
        $validations = $version->validations()->get();

        /** @var array<string, bool> $knownKeys */
        $knownKeys = [];
        /** @var array<string, string> $fieldKeyById */
        $fieldKeyById = [];
        // Object-valued field keys forbidden as expression operands: grids (G4b) and geo (G5b1). Both are
        // object-shaped with no valid scalar use — a reference would silently coerce to false and drift
        // between the engines. Value = the kind, so the right PublishValidationException is thrown.
        /** @var array<string, 'grid'|'geo'> $objectValuedKeys */
        $objectValuedKeys = [];
        foreach ($fields as $field) {
            $knownKeys[$field->key] = true;
            $fieldKeyById[$field->id] = $field->key;
            if ($field->field_type->isComposite()) {
                $objectValuedKeys[$field->key] = 'grid';
            } elseif ($field->field_type->isGeo()) {
                $objectValuedKeys[$field->key] = 'geo';
            }
        }

        // Section keys are referenceable too (grammar v2.0): `count(${roster})` counts a repeatable section's
        // instances. Field and section keys are globally unique per version, so there is no key collision.
        foreach ($sections as $section) {
            $knownKeys[$section->key] = true;
        }

        foreach ($fields as $field) {
            $this->check($field->relevant_expression, $knownKeys, $objectValuedKeys, ExpressionKind::Relevant, $field->key);
            $this->check($this->calculateFormula($field), $knownKeys, $objectValuedKeys, ExpressionKind::Calculate, $field->key);
        }

        foreach ($sections as $section) {
            $this->check($section->relevant_expression, $knownKeys, $objectValuedKeys, ExpressionKind::Relevant, $section->key);
        }

        foreach ($validations as $validation) {
            $ownerKey = $fieldKeyById[$validation->form_field_id] ?? '(unknown)';

            if ($validation->expression !== null) {
                $this->check($validation->expression, $knownKeys, $objectValuedKeys, ExpressionKind::Constraint, $ownerKey);

                continue;
            }

            $this->assertRuleValue($validation->rule_type, (string) ($validation->rule_value ?? ''), $ownerKey);
        }
    }

    /**
     * @param  array<string, bool>  $knownKeys
     * @param  array<string, 'grid'|'geo'>  $objectValuedKeys  object-valued field keys forbidden as operands (grid G4b / geo G5b1)
     */
    private function check(?string $expression, array $knownKeys, array $objectValuedKeys, ExpressionKind $kind, string $ownerKey): void
    {
        if ($expression === null || trim($expression) === '') {
            return; // blank = no expression
        }

        try {
            $ast = $this->parser->parse($expression);
            $this->parser->assertReferencesResolve($ast, $knownKeys, $kind, $ownerKey);
        } catch (ExpressionSyntaxException $exception) {
            throw PublishValidationException::expressionInvalid($exception->fieldKey() ?? $ownerKey, $exception->slug());
        }

        // A grid (G4b) or geo (G5b1) value is object-shaped and has no valid scalar use — reject any
        // reference rather than let it silently coerce to false (and drift between the engines).
        if ($objectValuedKeys !== []) {
            foreach ($this->parser->referencedKeys($ast) as $key) {
                $kindOfKey = $objectValuedKeys[$key] ?? null;
                if ($kindOfKey === 'geo') {
                    throw PublishValidationException::expressionReferencesGeo($ownerKey, $key);
                }
                if ($kindOfKey === 'grid') {
                    throw PublishValidationException::expressionReferencesComposite($ownerKey, $key);
                }
            }
        }
    }

    /** A calculated field's `config.calculated_formula` (grammar v2.0); null when not a calc / blank. */
    private function calculateFormula(FormField $field): ?string
    {
        if ($field->field_type !== FieldType::Calculated) {
            return null;
        }

        $formula = data_get($field->config, 'calculated_formula');

        return is_string($formula) ? $formula : null;
    }

    private function assertRuleValue(?ValidationRuleType $ruleType, string $ruleValue, string $ownerKey): void
    {
        if ($ruleType === ValidationRuleType::Pattern && ! StructuredRuleEvaluator::isCompilablePattern($ruleValue)) {
            throw PublishValidationException::ruleValueInvalid($ownerKey, 'invalid_pattern');
        }

        $isThreshold = $ruleType === ValidationRuleType::MinValue || $ruleType === ValidationRuleType::MaxValue;
        if ($isThreshold && ! Coercion::isNumericLike($ruleValue)) {
            throw PublishValidationException::ruleValueInvalid($ownerKey, 'non_numeric_threshold');
        }
    }
}
