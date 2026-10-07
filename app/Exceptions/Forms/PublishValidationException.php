<?php

declare(strict_types=1);

namespace App\Exceptions\Forms;

use App\Enums\ValueShape;
use App\Exceptions\Expressions\ExpressionException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Exceptions\Templates\TemplateSyntaxException;
use App\Services\Expressions\StructuredRuleLowering;
use App\Services\Forms\LinkedChoiceGate;
use App\Services\Templates\TemplateScopeResolver;
use App\Services\Validation\SemanticValidator;
use App\Support\Forms\GuestReachability;
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

    /**
     * M130 (`R-db169c29`, `D76`) — the form respondents go to after submitting is not one they can open: it needs a
     * public link, guest access and a published version, and must not be archived ({@see GuestReachability}).
     */
    public static function redirectTargetUnavailable(): self
    {
        return self::one('redirect_form_id', 'redirect_target_unavailable', 'The form respondents go to after submitting is not open to them: it needs a public link, guest access and a published version, and must not be archived.');
    }

    /** M130 (`R-db169c29`, `D76`) — the web address respondents go to after submitting is one the redirect rule refuses. */
    public static function redirectUrlInvalid(): self
    {
        return self::one('redirect_url', 'redirect_url_invalid', 'The web address respondents go to after submitting must be a full address that starts with https://.');
    }

    /** A choice field (Increment G4a) with no options or duplicate option values — unanswerable / ambiguous. */
    public static function choiceOptionsInvalid(string $fieldKey, string $detail): self
    {
        return self::one($fieldKey, 'choice_options_invalid', "The choices on “{$fieldKey}” are invalid ({$detail}).");
    }

    /**
     * A choice question that takes its choices from another form (M133, `R-5da4a30f` — Connect project v1) whose link
     * cannot serve. `$code` is the stable slug {@see LinkedChoiceGate} found; each says what the
     * author can do about it.
     */
    public static function linkedChoicesInvalid(string $fieldKey, string $code): self
    {
        $message = match ($code) {
            'linked_choices_wrong_type' => "“{$fieldKey}” cannot take its choices from another form: only a single-choice or dropdown question can.",
            'linked_choices_incomplete' => "Choose the form and the question that “{$fieldKey}” takes its choices from.",
            'linked_choices_with_typed_options' => "“{$fieldKey}” takes its choices from another form, so it cannot also have choices typed here.",
            'linked_choices_self' => "“{$fieldKey}” cannot take its choices from this same form.",
            'linked_choices_source_missing' => "The form “{$fieldKey}” takes its choices from no longer exists.",
            'linked_choices_not_shared' => "The form “{$fieldKey}” takes its choices from does not share that question. Its owner can share it in the form's Data sharing settings.",
            'linked_choices_owner_cannot_read' => "This form's owner cannot see the responses of the form “{$fieldKey}” takes its choices from, so its answers cannot be offered here.",
            default => "The choices on “{$fieldKey}” cannot be taken from another form ({$code}).",
        };

        return self::one($fieldKey, $code, $message);
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
     * A rule that names no second question (Increment M116). All six kinds where
     * {@see ValidationRuleType::takesRelatedField()} holds reach
     * {@see StructuredRuleLowering::relatedKeyOrThrow()} first, before any
     * dispatch on the rule type, and it throws `missing_related_field` when the column is null.
     *
     * ⚠️ THE CODE IS PREFIXED ON PURPOSE. {@see expressionInvalid()} forwards
     * `ExpressionException::slug()` straight through as its `code`, so the bare `missing_related_field`
     * slug can already appear in this envelope minted by a different gate against a different surface.
     */
    public static function ruleMissingRelatedField(string $fieldKey, string $ruleType): self
    {
        return self::one(
            $fieldKey,
            'rule_missing_related_field',
            "The “{$ruleType}” rule on “{$fieldKey}” does not say which question it compares against, so every submission would be refused. Choose a question or remove the rule.",
        );
    }

    /**
     * A `required_if` or `skip_if` carrying no operator (Increment M116).
     *
     * ⛔ `required_with` AND `skip_with` ARE EXEMPT, AND THE EXEMPTION IS NOT A LENIENCY. A null operator
     * there is itself the condition — {@see StructuredRuleLowering::lowerCondition()}
     * lowers it to `isNotNull(relatedKey)`, *"when that question is answered at all"* — so refusing it would
     * refuse the commonest authoring choice. Only these two reach `conditionForOperator()`'s default arm,
     * which throws. The predicate that separates them is
     * {@see ValidationRuleType::operatorMayBeEmpty()}, never a literal list of rule names.
     */
    public static function ruleMissingOperator(string $fieldKey, string $ruleType): self
    {
        return self::one(
            $fieldKey,
            'rule_missing_operator',
            "The “{$ruleType}” rule on “{$fieldKey}” does not say how to compare, so every submission would be refused. Choose a comparison or remove the rule.",
        );
    }

    /**
     * A rule group with a later member that does not say how it joins (M131, `R-799d60f5`).
     *
     * Both engines fold a group left to right, each later member joining by its own `logic_operator`, and throw
     * `malformed_logic_group` when one is missing — so every submission would fail, and nothing said so before
     * the builder could author groups. The builder's all/any switch writes the connective on every member; a row
     * can still arrive without one from a template or the question library.
     */
    public static function logicGroupMissingOperator(string $fieldKey): self
    {
        return self::one(
            $fieldKey,
            'logic_group_missing_operator',
            "Rules on “{$fieldKey}” are grouped, but one of them does not say whether it joins the others with AND or OR, so every submission would be refused. Choose all or any for the group, or ungroup the rules.",
        );
    }

    /**
     * Conditional requiredness that nothing can ever trigger (Increment M116).
     *
     * ⛔ REFUSED BECAUSE THE ALTERNATIVE IS SILENCE, WHICH IS THE SAME REASON
     * {@see hiddenFieldNotAnswerable()} exists. {@see SemanticValidator::requiredState()}
     * honours `Conditional` only through a `required_*` unit; with none the field falls out as optional and
     * the author is told nothing, so the setting is a control that does nothing. A `skip_if` does not count:
     * it makes a field irrelevant, never required — hence {@see ValidationRuleType::governsRequiredness()}
     * rather than "owns any validation row".
     *
     * ⚠️ THERE IS NO SYMMETRIC PARTNER, DELIBERATELY. An `Optional` field carrying a `required_if` row IS
     * conditionally required today — `requiredState()` special-cases only `Required` and treats `Optional`
     * and `Conditional` identically — and that direction is legitimate and must keep publishing.
     */
    public static function conditionalRequirednessHasNoRule(string $fieldKey): self
    {
        return self::one(
            $fieldKey,
            'conditional_requiredness_without_rule',
            "The field “{$fieldKey}” is set to be conditionally required but carries no rule saying when, so it behaves as optional. Add a “required when…” rule to it, or mark it optional.",
        );
    }

    /**
     * A display-only field that demands an answer it cannot take (Increment M116).
     *
     * ⛔ MEASURED, NOT REASONED — a `note` marked `Required` publishes clean today and then refuses every
     * submission with `field_required` on a field that has no input control at all, which no respondent and
     * no keyer can ever clear. {@see SemanticValidator::collectFieldErrors()}
     * early-returns for calculated, hidden, grid, geo and media fields and for these it does not, so the
     * answer is permanently absent, `Coercion::isEmpty()` is true, and `requiredState()` short-circuits to
     * required on the spot.
     *
     * ⚠️ DISPATCHED ON THE SHAPE, NEVER ON THE FIELD TYPE, per this gate's own rule: a type list needs
     * editing every time a type is added, while `ValueShape::NoAnswer` is the property that makes the
     * demand impossible.
     */
    public static function displayOnlyFieldRequired(string $fieldKey, string $requiredness): self
    {
        return self::one(
            $fieldKey,
            'display_only_field_required',
            "The field “{$fieldKey}” is set to “{$requiredness}” but takes no answer, so every submission would be refused with an error nobody can clear. Mark it optional.",
        );
    }

    /**
     * A calculated question with no formula (Increment M122, `R-244d53dc`). It would publish, compute nothing in
     * every submission, and say so nowhere — the runtime skips a blank formula in silence on both engines.
     *
     * ⚠️ THE CODE IS PREFIXED ON PURPOSE, as {@see ruleMissingRelatedField()} explains: a bare
     * `missing_formula` is already a code the builder's DRAFT projection mints for the preview, and the two
     * must stay distinguishable in any log that carries both.
     */
    public static function calculatedFormulaMissing(string $fieldKey): self
    {
        return self::one(
            $fieldKey,
            'calculated_formula_missing',
            "The calculated question “{$fieldKey}” has no formula, so it would compute nothing in every submission. Add a formula or remove the question.",
        );
    }

    /**
     * A conditional rule whose comparison the RELATED question's answer can never support (Increment M123,
     * `R-2c172882`) — an ordering on a choice or a note, `contains` on a number, "is answered" on a note.
     *
     * ⛔ THE CONDITION IS CONSTANT, AND NEITHER ENGINE SAYS SO. An ordered comparison returns false on a NaN
     * operand, `contains` on a scalar is a substring test, and a note is never answered — so the rule is always
     * true or always false whatever the respondent does, and the field it governs is silently always (or never)
     * required or skipped. `$relatedKind` is the related field type's own label, so the author reads the kind
     * of question in plain words; `field` is the rule's OWNER, where the author edits the rule.
     */
    public static function ruleOperatorNotAllowedForRelatedShape(string $fieldKey, string $ruleType, string $relatedKey, string $relatedKind, string $comparison): self
    {
        return self::one(
            $fieldKey,
            'rule_operator_not_allowed_for_related_shape',
            "The “{$ruleType}” rule on “{$fieldKey}” compares the {$relatedKind} question “{$relatedKey}” using “{$comparison}”, which that kind of answer cannot support, so the condition never changes with the answer. Choose a different comparison or a different question.",
        );
    }

    /**
     * A field comparison against a question whose answer cannot be ordered (Increment M123, `R-2c172882`).
     *
     * ⚠️ THE OWNER-SHAPE ARM CANNOT SEE THIS ONE, because the owner is a number and therefore allowed the rule.
     * The failure is on the other side: the related answer coerces to NaN, the ordering is false, and every
     * non-empty answer to the owner is refused with no way for the respondent to discover why — the same
     * fail-closed shape {@see ruleNotAllowedForShape()} refuses, reached through the question it compares with.
     */
    public static function ruleRelatedFieldNotOrderable(string $fieldKey, string $ruleType, string $relatedKey, string $relatedKind): self
    {
        return self::one(
            $fieldKey,
            'rule_related_field_not_orderable',
            "The “{$ruleType}” rule on “{$fieldKey}” compares it with the {$relatedKind} question “{$relatedKey}”, whose answers are not numbers, so every answer to “{$fieldKey}” would be refused. Compare it with a number question or remove the rule.",
        );
    }

    /**
     * A note whose content blocks a respondent must not be shown (Increment M125, `R-6dedc3a9`) — a blank heading, an
     * empty paragraph, a link with no address, or a shape no renderer reads. The builder's save accepts the first three
     * mid-edit; publish is where they are refused. `$detail` is `ContentBlocks::problem()`'s phrase.
     */
    public static function noteContentInvalid(string $fieldKey, string $detail): self
    {
        return self::one($fieldKey, 'note_content_invalid', "The note “{$fieldKey}” has content that cannot be shown: {$detail}.");
    }

    /**
     * An expression that does arithmetic on a date, time or date-and-time question, or turns one into a number with
     * `int()` (Increment M126, `R-87160c81`; narrowed by M134). Since `M134` both engines ORDER dates and times
     * chronologically (`R-62b638e1`), so this code no longer refuses `>`/`<`; date arithmetic is still NaN in both
     * engines, so the expression never changes with the answer. ⚠️ "NOT SUPPORTED YET", because it is.
     * `$ownerKey` is the question or section the expression belongs to; `$kind` is the compared type's own label.
     */
    public static function expressionOrdersDate(string $ownerKey, string $dateKey, string $kind): self
    {
        return self::one(
            $ownerKey,
            'expression_orders_date',
            "The expression on “{$ownerKey}” does arithmetic with the {$kind} question “{$dateKey}”, or turns it into a number with int(). Arithmetic on dates and times is not supported yet, so the expression never changes with the answer. Compare it with more than or less than instead, or remove the calculation.",
        );
    }

    /**
     * An expression that reads a question as a number when its answers never are one — a note, a page break, a
     * yes/no, a list of choices, a file (Increment M126, `R-87160c81`). The ordering is false and the arithmetic NaN
     * whatever the respondent does. A count of a list is a number; the list itself is not.
     */
    public static function expressionOrdersNonNumber(string $ownerKey, string $otherKey, string $kind): self
    {
        return self::one(
            $ownerKey,
            'expression_orders_non_number',
            "The expression on “{$ownerKey}” uses the {$kind} question “{$otherKey}” as a number, with more than, less than or arithmetic, but its answers are never numbers, so the expression never changes with the answer. Compare a number question, or remove the comparison.",
        );
    }

    /**
     * An expression that orders a date, time or date-and-time question against `now()`, or against a value carrying
     * a time zone (Increment M134, `D89`). An answer is wall-clock with no zone and `now()` is UTC, so the engines
     * never compare them — "not supported yet", because a workspace time zone is filed. Same code as
     * {@see expressionOrdersDate()}: both lift when the work behind them ships.
     */
    public static function expressionOrdersDateAgainstClock(string $ownerKey, string $dateKey, string $kind): self
    {
        return self::one(
            $ownerKey,
            'expression_orders_date',
            "The expression on “{$ownerKey}” compares the {$kind} question “{$dateKey}” with now(), or with a time that carries a time zone. An answer has no time zone and now() is in UTC, so comparing them is not supported yet. Compare a date with today(), or a time with a fixed time such as '09:00'.",
        );
    }

    /**
     * An expression that orders a date, time or date-and-time question against something that is never one of its
     * kind — a number, a time against a date, text that is not written as an ISO date (Increment M134,
     * `R-62b638e1`). Both engines read the pair as never holding, so the expression never changes with the answer.
     * `$other` names what it was compared with; `$accepts` says what it can be compared with instead.
     */
    public static function expressionOrdersTemporalMismatch(string $ownerKey, string $dateKey, string $kind, string $other, string $accepts): self
    {
        return self::one(
            $ownerKey,
            'expression_orders_temporal_mismatch',
            "The expression on “{$ownerKey}” compares the {$kind} question “{$dateKey}” with {$other}, which it can never be ordered against, so the comparison never holds. Compare it with {$accepts}.",
        );
    }

    /**
     * An expression that compares a list question — a multi-select, a cascade, a file — with one value using `=` or
     * `!=` (Increment M134, `R-87160c81`). `equals()` makes a list on either side false, so `=` never holds and `!=`
     * always does, whatever the respondent picks. `selected()` asks whether a choice was picked; `= ''` whether the
     * question was answered.
     */
    public static function expressionEqualsList(string $ownerKey, string $listKey, string $kind): self
    {
        return self::one(
            $ownerKey,
            'expression_equals_list',
            "The expression on “{$ownerKey}” compares the {$kind} question “{$listKey}” with a single value using = or !=, but its answer is a list, so the comparison never changes with the answer. Use selected(\${{$listKey}}, 'value') to ask whether a choice was picked, or = '' to ask whether it was answered.",
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
