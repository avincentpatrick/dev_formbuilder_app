<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\ExpressionKind;
use App\Enums\ValidationRuleType;
use App\Exceptions\Expressions\ExpressionSyntaxException;
use App\Exceptions\Forms\PublishValidationException;
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
        foreach ($fields as $field) {
            $knownKeys[$field->key] = true;
            $fieldKeyById[$field->id] = $field->key;
        }

        foreach ($fields as $field) {
            $this->check($field->relevant_expression, $knownKeys, ExpressionKind::Relevant, $field->key);
        }

        foreach ($sections as $section) {
            $this->check($section->relevant_expression, $knownKeys, ExpressionKind::Relevant, $section->key);
        }

        foreach ($validations as $validation) {
            $ownerKey = $fieldKeyById[$validation->form_field_id] ?? '(unknown)';

            if ($validation->expression !== null) {
                $this->check($validation->expression, $knownKeys, ExpressionKind::Constraint, $ownerKey);

                continue;
            }

            $this->assertRuleValue($validation->rule_type, (string) ($validation->rule_value ?? ''), $ownerKey);
        }
    }

    /**
     * @param  array<string, bool>  $knownKeys
     */
    private function check(?string $expression, array $knownKeys, ExpressionKind $kind, string $ownerKey): void
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
