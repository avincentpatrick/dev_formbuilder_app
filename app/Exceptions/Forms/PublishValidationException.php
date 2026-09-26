<?php

declare(strict_types=1);

namespace App\Exceptions\Forms;

use App\Enums\ValueShape;
use App\Exceptions\Expressions\ExpressionException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Exceptions\Templates\TemplateSyntaxException;
use App\Services\Templates\TemplateScopeResolver;
use RuntimeException;

/**
 * A structural pre-publish validation failure (form-versioning-schema-migration.md §4). The publish is
 * refused and the SPECIFIC violation is surfaced (not a generic failure) so the builder UI can point at
 * the offending field/section. Distinct from an authorization failure (403 from FormPolicy) and from a
 * lifecycle-rule violation ({@see FormException}).
 *
 * ⛔ IT CARRIES A STRUCTURED LIST AS WELL AS PROSE, SINCE M112 — AND THE PROSE IS THE HARD CONSTRAINT.
 * Until M112 this envelope was message-only and a publish refused on the FIRST violation, so an author
 * with four broken fields learned about one of them per attempt. {@see violations()} now carries every
 * one as `{field, code, message}` — the shape
 * {@see SubmissionValidationException} already ships for submissions and
 * `bootstrap/app.php` already renders to both surfaces.
 *
 * ⛔ THE AGGREGATE MESSAGE IS THE SENTENCES JOINED, NEVER A SUMMARY, AND THAT IS NOT A STYLE CHOICE.
 * Roughly twenty-seven assertions across six test files read `getMessage()` and assert a `toContain()`
 * on a field key or a slug; not one asserts a count. `SubmissionValidationException::summarize()` writes
 * "N fields failed structural validation.", which drops every key and slug and would redden all of them
 * at once. Joining preserves each sentence verbatim, so **a single violation produces a message
 * byte-identical to the pre-M112 one** — that identity is what keeps those assertions green, rather
 * than a hope that they survive. Borrow the class shape from that sibling; refuse that one method.
 *
 * ⚠️ `code` IS STABLE AND IS WHAT A CONSUMER SHOULD MATCH ON, NEVER THE WORDING. Five factories already
 * received a stable snake_case slug as `$detail` and pass it straight through. The four that carried
 * free prose — `choiceOptionsInvalid`, `cascadingConfigInvalid`, `matrixConfigInvalid` and
 * `mediaConfigInvalid` — gained a slug derived from the factory name, and their prose stays in
 * `message` untouched. So message and structure are gated independently, which is what lets a mutation
 * prove one without the other.
 */
final class PublishValidationException extends RuntimeException
{
    public static function validationReferencesForeignVersion(string $fieldKey): self
    {
        return self::one($fieldKey, 'validation_references_foreign_version', "The validation rule on “{$fieldKey}” references a field from a different version.");
    }

    public static function sectionBelongsToForeignVersion(string $fieldKey): self
    {
        return self::one($fieldKey, 'section_belongs_to_foreign_version', "The field “{$fieldKey}” is placed in a section that belongs to a different version.");
    }

    public static function queryableFieldMissingType(string $fieldKey): self
    {
        return self::one($fieldKey, 'queryable_field_missing_type', "The queryable field “{$fieldKey}” must declare an indexed data type before publishing.");
    }

    /**
     * An authored `relevant`/`constraint` expression that will not parse or references an unknown field
     * (F3's publish-time expression gate). `$detail` is the wrapped {@see ExpressionException::slug()}.
     */
    public static function expressionInvalid(?string $fieldKey, string $detail): self
    {
        $where = $fieldKey !== null ? "on “{$fieldKey}” " : '';

        return self::one($fieldKey, $detail, "The expression {$where}is invalid ({$detail}).");
    }

    /** A structured rule whose `rule_value` cannot be used at submission time (bad regex / non-numeric threshold). */
    public static function ruleValueInvalid(string $fieldKey, string $detail): self
    {
        return self::one($fieldKey, $detail, "The validation rule on “{$fieldKey}” is invalid ({$detail}).");
    }

    /**
     * A template-bearing value (`docs/piping-output-encoding-design.md` §6, Increment H6a) that will not
     * parse, or whose `${key}` hole does not resolve to a pipeable field the host may legally reference.
     * `$column` names WHICH text — `label`, `hint`, `placeholder`, `description`, `confirmation_message`,
     * or a locale variant of one (`label[fil]`) — because a field can carry several templates and the
     * builder needs to point at the offending one, not merely at the field.
     *
     * `$detail` is the stable snake_case slug: {@see TemplateSyntaxException}'s
     * for a grammar failure, {@see TemplateScopeResolver}'s for a scope one. Tests
     * match the slug, never the wording.
     */
    public static function templateInvalid(string $ownerKey, string $column, string $detail): self
    {
        return self::one($ownerKey, $detail, "The {$column} on “{$ownerKey}” has an invalid reference ({$detail}).");
    }

    /** A choice field (Increment G4a) with no options or duplicate option values — unanswerable / ambiguous. */
    public static function choiceOptionsInvalid(string $fieldKey, string $detail): self
    {
        return self::one($fieldKey, 'choice_options_invalid', "The choices on “{$fieldKey}” are invalid ({$detail}).");
    }

    /** A cascading-select field (Increment G4a) whose level/option hierarchy does not resolve. */
    public static function cascadingConfigInvalid(string $fieldKey, string $detail): self
    {
        return self::one($fieldKey, 'cascading_config_invalid', "The cascading choices on “{$fieldKey}” are invalid ({$detail}).");
    }

    /** A composite grid field (Increment G4b: matrix / likert_matrix) whose row/column/cell config is invalid. */
    public static function matrixConfigInvalid(string $fieldKey, string $detail): self
    {
        return self::one($fieldKey, 'matrix_config_invalid', "The grid on “{$fieldKey}” is invalid ({$detail}).");
    }

    /**
     * A composite grid field (Increment G4b) nested in a repeatable section — unsupported, and a value-shape
     * drift hazard (a grid inside a repeat instance is never routed through the composite pass).
     */
    public static function compositeInRepeatableSection(string $fieldKey): self
    {
        return self::one($fieldKey, 'composite_in_repeatable_section', "The grid field “{$fieldKey}” cannot be placed inside a repeatable section.");
    }

    /**
     * An expression references a composite grid field (Increment G4b). Grid values are object-shaped and
     * are never valid scalar operands (they would drift between the PHP and TS engines), so any reference is
     * refused at publish rather than silently coercing to `false`.
     */
    public static function expressionReferencesComposite(string $ownerKey, string $compositeKey): self
    {
        return self::one($ownerKey, 'expression_references_composite', "The expression on “{$ownerKey}” references the grid field “{$compositeKey}”, which cannot be used in an expression.");
    }

    /**
     * A geospatial field (Increment G5b1) nested in a repeatable section — its geometry projection is one
     * row per field per submission (top-level only), so geo-in-a-repeat is unsupported for now.
     */
    public static function geoInRepeatableSection(string $fieldKey): self
    {
        return self::one($fieldKey, 'geo_in_repeatable_section', "The location field “{$fieldKey}” cannot be placed inside a repeatable section.");
    }

    /**
     * An expression references a geospatial field (Increment G5b1). Geo values are object-shaped GeoJSON
     * envelopes and are never valid scalar operands (they would drift between the PHP and TS engines), so
     * any reference is refused at publish rather than silently coercing to `false`.
     */
    public static function expressionReferencesGeo(string $ownerKey, string $geoKey): self
    {
        return self::one($ownerKey, 'expression_references_geo', "The expression on “{$ownerKey}” references the location field “{$geoKey}”, which cannot be used in an expression.");
    }

    /**
     * A media field (Increment G6) nested in a repeatable section — its attachments are owned + counted per
     * submission (top-level only), so media-in-a-repeat is unsupported for now (relaxing it later is
     * non-breaking).
     */
    public static function mediaInRepeatableSection(string $fieldKey): self
    {
        return self::one($fieldKey, 'media_in_repeatable_section', "The media field “{$fieldKey}” cannot be placed inside a repeatable section.");
    }

    /** A media field (Increment G6) whose optional count bounds are incoherent (e.g. min_count > max_count). */
    public static function mediaConfigInvalid(string $fieldKey, string $detail): self
    {
        return self::one($fieldKey, 'media_config_invalid', "The media field “{$fieldKey}” is invalid ({$detail}).");
    }

    /**
     * A `hidden` field (Increment H7) that could produce an error the respondent can never repair — it is
     * marked required/conditional, or it carries a validation rule. Either way the failure mode is a submit
     * that fails forever against a field nobody can see, so it is refused at publish where the author can
     * still act on it.
     *
     * `$detail` is a stable snake_case slug (`hidden_field_required` / `hidden_field_has_validations`);
     * tests match the slug, never the wording (the A4 rule — this envelope is message-only).
     */
    public static function hiddenFieldNotAnswerable(string $fieldKey, string $detail): self
    {
        return self::one($fieldKey, $detail, "The hidden field “{$fieldKey}” cannot require an answer ({$detail}).");
    }

    /**
     * A `hidden` field (Increment H7) nested in a repeatable section. Neither prefill source can address one
     * instance out of N — a URL carries one value for the whole form, and an authored literal is the same
     * for every row — so the field could only ever be written flat, outside the instance list where nothing
     * would read it.
     */
    public static function hiddenInRepeatableSection(string $fieldKey): self
    {
        return self::one($fieldKey, 'hidden_in_repeatable_section', "The hidden field “{$fieldKey}” cannot be placed inside a repeatable section.");
    }

    /**
     * A `hidden` field (Increment H7) sourced from the link whose declared query-parameter name is not a
     * usable one. `$detail` is the stable snake_case slug `prefill_param_invalid`.
     */
    public static function prefillConfigInvalid(string $fieldKey, string $detail): self
    {
        return self::one($fieldKey, $detail, "The prefill settings on “{$fieldKey}” are invalid ({$detail}).");
    }

    /**
     * A validation rule whose owning field's {@see ValueShape} can never satisfy it (Increment
     * M113). This is NOT "unusual but allowed" — the rule fails CLOSED, so the field becomes unanswerable.
     *
     * ⛔ THE FAILURE IS SILENT AND TOTAL, WHICH IS WHY IT IS REFUSED RATHER THAN WARNED ABOUT.
     * `Coercion::NUMERIC_RE` does not match `2026-01-15`, `StructuredRuleEvaluator`'s `MinValue` arm is
     * `isEmpty($answer) || (isNumericLike($answer) && …)`, and `ExpressionEvaluator`'s ordered comparison
     * returns `false` on a NaN operand. So `end_date > start_date` rejects EVERY non-empty answer, and the
     * respondent is given no way to discover why. Both engines behave identically here — `coercion.ts` and
     * `evaluator.ts` mirror the same two rules — so this is a correctness defect, not a parity one.
     *
     * `$ruleType` and `$shape` are the backed enum values, so the sentence carries stable slugs and the
     * `code` stays a single constant that tests can match without pinning wording (the A4 rule).
     */
    public static function ruleNotAllowedForShape(string $fieldKey, string $ruleType, string $shape): self
    {
        return self::one(
            $fieldKey,
            'rule_not_allowed_for_shape',
            "The “{$ruleType}” rule on “{$fieldKey}” can never be satisfied by a {$shape} answer, so every submission would be refused. Remove the rule or change the field's type.",
        );
    }

    /**
     * @param  list<array{field: ?string, code: string, message: string}>  $violations
     */
    private function __construct(string $message, private readonly array $violations)
    {
        parent::__construct($message);
    }

    /**
     * Every violation this refusal carries, in the order the gate met them.
     *
     * @return list<array{field: ?string, code: string, message: string}>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    /**
     * Fold several refusals into one. The message is the parts' sentences joined by a single space —
     * see the class docblock for why it is never a summary.
     *
     * @param  non-empty-list<self>  $parts
     */
    public static function several(array $parts): self
    {
        $violations = array_merge(...array_map(static fn (self $p): array => $p->violations, $parts));

        return new self(implode(' ', array_column($violations, 'message')), $violations);
    }

    /** One violation, which is what every named factory above produces. */
    private static function one(?string $field, string $code, string $message): self
    {
        return new self($message, [['field' => $field, 'code' => $code, 'message' => $message]]);
    }
}
