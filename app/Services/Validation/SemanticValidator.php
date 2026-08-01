<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Enums\FieldType;
use App\Enums\PdfFieldRole;
use App\Enums\RequiredMode;
use App\Enums\ValidationRuleType;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormVersion;
use App\Services\Attachments\AttachmentReferenceValidator;
use App\Services\Expressions\Coercion;
use App\Services\Expressions\EvaluationContext;
use App\Services\Expressions\ExpressionEvaluator;
use App\Services\Expressions\Marker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Stage 3 of the Submission Pipeline (technical-architecture.md §4.1 §4.3) — the reusable semantic
 * authority the whole engine converges on. Given a published version's schema + a respondent's answers it
 * produces a {@see SemanticResult}: the relevance mask, per-field constraint/required errors, the
 * relevance-pruned answers Stage 4 persists, and (grammar v2.0 / Increment G3) the `computed` map of every
 * relevant calculated field's formula result. PHP is the sole authority; this must stay byte-identical to
 * the TypeScript mirror (the golden `validation` suite).
 *
 * Relevance is settled to a bounded FIXED POINT: pruning a field's answer can change another field's or
 * section's relevance, so the mask is recomputed over the shrinking effective answer set until it stops
 * changing (a field hidden upstream reads as empty downstream — XLSForm/Kobo semantics). It never throws
 * for a validation FAILURE (a false constraint is a result); only a malformed rule raises.
 */
final class SemanticValidator
{
    private const DEFAULT_CONSTRAINT_MESSAGE = 'This value is not valid.';

    private const DEFAULT_REQUIRED_MESSAGE = 'This field is required.';

    public function __construct(
        private readonly ExpressionEvaluator $evaluator,
        private readonly StructuredRuleEvaluator $rules,
    ) {}

    /**
     * The database-facing entry (F4's Submission Pipeline calls this): load the version's schema, resolve
     * the locale, and delegate to the pure {@see evaluate()}.
     *
     * @param  array<string, mixed>  $answers  field key => value (multi-select values already `list<string>`)
     * @param  ?string  $now  ISO-8601 override for the `today()`/`now()` clock. Null means "right now",
     *                        which keeps every pre-H17 caller byte-identical — the A8 device H6b used when
     *                        it threaded `?string $locale` through two already-shipped read surfaces.
     *
     *                        Only a REPLAY passes this. H17 renders a submission PDF by re-deriving which
     *                        fields the respondent actually saw, and relevance is stored nowhere: the
     *                        `SemanticResult` masks are computed at submit and discarded, and
     *                        {@see effectiveAnswers()} prunes irrelevant keys, so a pruned key is
     *                        indistinguishable from an answered-blank one. Re-deriving is therefore the
     *                        only route — and re-deriving under `Carbon::now()` would evaluate a
     *                        `relevant_expression` reading `today()` against the day someone asked for a
     *                        PDF rather than the day the respondent filled the form, silently producing a
     *                        different mask than the one that actually pruned the document.
     */
    public function validate(FormVersion $version, array $answers, ?string $locale = null, ?string $now = null): SemanticResult
    {
        $locale ??= $this->defaultLocale($version);

        return $this->evaluate(new SemanticInput(
            $version->fields()->orderBy('sequence')->orderBy('key')->get(),
            $version->sections()->orderBy('sequence')->orderBy('key')->get(),
            $version->validations()->orderBy('sequence')->get(),
            $answers,
            $locale,
            $now ?? Carbon::now()->toIso8601String(), // the authoritative clock for today()/now() (Increment G3)
        ));
    }

    /** The form's default locale, read null-safely off the relation query (belongs-to may be absent in theory). */
    private function defaultLocale(FormVersion $version): string
    {
        $locale = $version->form()->value('default_locale');

        return is_string($locale) ? $locale : 'en';
    }

    /** The pure core (also the golden-vector runner's entry) — no database access. */
    public function evaluate(SemanticInput $input): SemanticResult
    {
        $fieldKeyById = [];
        foreach ($input->fields as $field) {
            $fieldKeyById[$field->id] = $field->key;
        }

        // Increment H21a — every field key in the version, relevant or not. Sections and fields carry
        // INDEPENDENT `(tenant_id, form_version_id, key)` unique indexes and every application-level
        // enforcer is table-scoped, so a section key may collide with a field key; this set is the guard
        // that keeps {@see relevanceContext()} from re-admitting a pruned field's answer under a section's
        // name (Doc #27 amendment A7).
        /** @var array<string, true> $fieldKeys */
        $fieldKeys = [];
        foreach ($fieldKeyById as $key) {
            $fieldKeys[$key] = true;
        }

        $ruleSets = $this->buildRuleSets($input);
        $now = $input->now;

        // Repeat-group members are pulled out of the flat pass so the top-level relevance/answers/errors
        // stay exactly as they were pre-G1 (a form with no repeatable sections partitions nothing) — each
        // instance is then evaluated in its own scope by processRepeats().
        [$topLevelFields, $repeatMembersBySectionId] = $this->partitionFields($input);

        [$fieldRelevance, $sectionRelevance] = $this->settleRelevance($input, $topLevelFields, $ruleSets, $fieldKeyById, $fieldKeys, $now);
        $effectiveAnswers = $this->effectiveAnswers($input->answers, $fieldRelevance);
        $errors = $this->collectErrors($topLevelFields, $ruleSets, $fieldRelevance, $effectiveAnswers, $fieldKeyById, $input->locale, $now);

        [$repeatEffective, $repeatErrors, $repeatRelevance] = $this->processRepeats(
            $input,
            $repeatMembersBySectionId,
            $ruleSets,
            $sectionRelevance,
            $effectiveAnswers,
            $fieldKeyById,
            $fieldKeys,
            $now,
        );

        foreach ($repeatEffective as $sectionKey => $instances) {
            $effectiveAnswers[$sectionKey] = $instances;
        }

        // Composite (grid) fields (Increment G4b) are validated + pruned in their own pass, never routed
        // through the scalar collectFieldErrors path (Coercion::isEmpty diverges on an empty object between
        // the PHP and TS engines). Their relevance-pruned object replaces the raw effective answer.
        $compositeErrors = $this->processComposites($topLevelFields, $fieldRelevance, $effectiveAnswers, $ruleSets, $fieldKeyById, $input->locale, $now);

        // Geospatial fields (Increment G5b1) are validated in their own pass for the same reason as
        // composites (an object answer must not hit scalar isEmpty). Structural geometry checks (type,
        // coordinate range, ring closure, min-points) — no grammar change. An empty envelope is unset so
        // effective answers stay byte-identical across the PHP/TS engines ({} vs []).
        $geoErrors = $this->processGeo($topLevelFields, $fieldRelevance, $effectiveAnswers, $ruleSets, $fieldKeyById, $input->locale, $now);

        // Media fields (Increment G6) are validated in their own pass for the same reason as geo/composites
        // (a list-of-objects answer must not hit scalar isEmpty). DB-free rules only — required + min/max
        // count; an empty list is unset so effective answers stay byte-identical across engines. Existence/
        // ownership/scan are the PHP-only AttachmentReferenceValidator, run by the pipeline after this.
        $mediaErrors = $this->processMedia($topLevelFields, $fieldRelevance, $effectiveAnswers, $ruleSets, $fieldKeyById, $input->locale, $now);

        // Calculated fields (grammar v2.0) are computed last, over the full effective answers (flat + repeat
        // instance arrays merged above, so a calc can `count(${section})`); a relevant calc's formula result
        // is written to `computed`. Computed values do not feed back into relevance/constraints (documented).
        $computed = $this->computeCalculated($topLevelFields, $effectiveAnswers, $fieldRelevance, $now);

        return new SemanticResult(
            $fieldRelevance,
            $sectionRelevance,
            array_merge($errors, $repeatErrors, $compositeErrors, $geoErrors, $mediaErrors),
            $effectiveAnswers,
            $computed,
            $repeatRelevance,
        );
    }

    /**
     * Split the fields into the flat (top-level) set and the repeatable-section members, grouped by section
     * id. A field is a repeat member iff its `form_section_id` points at a repeatable section; everything
     * else (no section, or a non-repeatable section) is top-level.
     *
     * @return array{0: Collection<int, FormField>, 1: array<string, list<FormField>>}
     */
    private function partitionFields(SemanticInput $input): array
    {
        /** @var array<string, true> $repeatSectionIds */
        $repeatSectionIds = [];
        foreach ($input->sections as $section) {
            if ($section->is_repeatable === true) {
                $repeatSectionIds[$section->id] = true;
            }
        }

        /** @var list<FormField> $topLevel */
        $topLevel = [];
        /** @var array<string, list<FormField>> $membersBySectionId */
        $membersBySectionId = [];
        foreach ($input->fields as $field) {
            $sectionId = $field->form_section_id;
            if ($sectionId !== null && isset($repeatSectionIds[$sectionId])) {
                $membersBySectionId[$sectionId][] = $field;
            } else {
                $topLevel[] = $field;
            }
        }

        return [new Collection($topLevel), $membersBySectionId];
    }

    /**
     * Group every field's validation rows into evaluation units by family + `logic_group`.
     *
     * @return array<string, array{constraint: list<list<FormFieldValidation>>, required: list<list<FormFieldValidation>>, skip: list<list<FormFieldValidation>>}>
     */
    private function buildRuleSets(SemanticInput $input): array
    {
        /** @var array<string, array<string, list<FormFieldValidation>>> $byField */
        $byField = [];
        foreach ($input->validations as $row) {
            $byField[$row->form_field_id][$this->family($row)][] = $row;
        }

        $result = [];
        foreach ($byField as $fieldId => $families) {
            $result[$fieldId] = [
                'constraint' => $this->toUnits($families['constraint'] ?? []),
                'required' => $this->toUnits($families['required'] ?? []),
                'skip' => $this->toUnits($families['skip'] ?? []),
            ];
        }

        return $result;
    }

    private function family(FormFieldValidation $row): string
    {
        if ($row->expression !== null) {
            return 'constraint';
        }

        return match ($row->rule_type) {
            ValidationRuleType::RequiredIf, ValidationRuleType::RequiredWith => 'required',
            ValidationRuleType::SkipIf, ValidationRuleType::SkipWith => 'skip',
            default => 'constraint',
        };
    }

    /**
     * A standalone row is its own unit; rows sharing a `logic_group` fold together as one unit.
     *
     * @param  list<FormFieldValidation>  $rows
     * @return list<list<FormFieldValidation>>
     */
    private function toUnits(array $rows): array
    {
        $units = [];
        /** @var array<string, list<FormFieldValidation>> $groups */
        $groups = [];

        foreach ($rows as $row) {
            if ($row->logic_group === null) {
                $units[] = [$row];
            } else {
                $groups[$row->logic_group][] = $row;
            }
        }

        foreach ($groups as $groupRows) {
            $units[] = $groupRows;
        }

        return $units;
    }

    /**
     * Recompute the field + section relevance mask over the current effective answers until it stabilises
     * (bounded, so a cyclic dependency terminates rather than spins). A field is relevant iff its section
     * is relevant AND its own `relevant_expression` holds AND no `skip_if`/`skip_with` fires. Only the
     * top-level `$fields` are masked, but ALL sections are evaluated (a repeatable section's relevance is
     * produced here and consumed by processRepeats()).
     *
     * @param  Collection<int, FormField>  $fields  the top-level (non-repeat-member) fields
     * @param  array<string, array{constraint: list<list<FormFieldValidation>>, required: list<list<FormFieldValidation>>, skip: list<list<FormFieldValidation>>}>  $ruleSets
     * @param  array<string, string>  $fieldKeyById
     * @param  array<string, true>  $fieldKeys  every field key in the version (the section-collision guard)
     * @return array{0: array<string, bool>, 1: array<string, bool>}
     */
    private function settleRelevance(SemanticInput $input, Collection $fields, array $ruleSets, array $fieldKeyById, array $fieldKeys, ?string $now): array
    {
        /** @var array<string, bool> $relevant */
        $relevant = [];
        foreach ($fields as $field) {
            $relevant[$field->key] = true; // start optimistic; prune from here
        }

        $maxIterations = $fields->count() + $input->sections->count() + 2;
        /** @var array<string, bool> $sectionRelevance */
        $sectionRelevance = [];
        /** @var array<string, bool> $sectionRelevantById */
        $sectionRelevantById = [];

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $context = new EvaluationContext($this->relevanceContext($input, $relevant, $fieldKeys), now: $now);

            [$sectionRelevance, $sectionRelevantById] = $this->sectionMasks($input, $context);

            /** @var array<string, bool> $next */
            $next = [];
            foreach ($fields as $field) {
                $sectionOk = $field->form_section_id === null
                    ? true
                    : ($sectionRelevantById[$field->form_section_id] ?? true);
                $ownOk = $this->evaluateRelevance($field->relevant_expression, $context);
                $skipped = $this->anyUnitHolds($ruleSets[$field->id]['skip'] ?? [], $context, $fieldKeyById);

                if ($sectionOk && $ownOk && ! $skipped) {
                    $next[$field->key] = true;
                }
            }

            if ($next === $relevant) {
                break;
            }
            $relevant = $next;
        }

        // Increment H21a / Doc #27 §3.2 (amendment A3). The loop assigns the newer FIELD mask while
        // `$sectionRelevance` still holds the verdict computed from the PREVIOUS one, so on BOUND
        // EXHAUSTION the two are returned one iteration apart — invisible to every consumer that reads one
        // of them, and the step model is the first to read both. Recompute the section mask from the FINAL
        // field mask, then TIGHTEN the field mask by that gate so `field ⊆ section` holds by construction.
        //
        // Recomputing alone would NOT be enough: it would pair a fresh section mask with a field mask
        // derived under the stale one, which flips the inconsistency's sign rather than removing it.
        //
        // Both halves are provably no-ops on the fixed-point path, which is why no golden vector moves:
        // the break fires BEFORE `$relevant = $next`, so `$relevant` is exactly the map this context and
        // this section mask were already built from, and every key in it already passed the section gate.
        $context = new EvaluationContext($this->relevanceContext($input, $relevant, $fieldKeys), now: $now);
        [$sectionRelevance, $sectionRelevantById] = $this->sectionMasks($input, $context);

        foreach ($fields as $field) {
            if ($field->form_section_id === null) {
                continue;
            }
            if (($sectionRelevantById[$field->form_section_id] ?? true) !== true) {
                unset($relevant[$field->key]);
            }
        }

        return [$this->fullMask($fields, $relevant), $sectionRelevance];
    }

    /**
     * Every section's relevance under one context, keyed both ways — by `key` for the returned mask that
     * consumers read, and by `id` for the field loop's section-cascade gate.
     *
     * @return array{0: array<string, bool>, 1: array<string, bool>}
     */
    private function sectionMasks(SemanticInput $input, EvaluationContext $context): array
    {
        /** @var array<string, bool> $byKey */
        $byKey = [];
        /** @var array<string, bool> $byId */
        $byId = [];
        foreach ($input->sections as $section) {
            $ok = $this->evaluateRelevance($section->relevant_expression, $context);
            $byKey[$section->key] = $ok;
            $byId[$section->id] = $ok;
        }

        return [$byKey, $byId];
    }

    /**
     * The answer map a relevance expression is evaluated against: every CURRENTLY-relevant top-level field's
     * answer, plus every REPEATABLE section's raw instance array so `count(${roster})` is answerable inside a
     * `relevant_expression` (Doc #27 §3.3, amendment A2).
     *
     * Three things here are deliberate, and each one is a bug if reversed.
     *
     * 1. The section keys are unioned into the INTERSECT ARGUMENT, never into `$relevant` itself. `$relevant`
     *    is what the fixed-point compare tests, and it is built from fields only — seeding a section key into
     *    it makes `$next === $relevant` unsatisfiable, so every form would run to `$maxIterations` on every
     *    keystroke and §3.2's exhaustion artifact would become the default rather than the exotic case.
     * 2. Only REPEATABLE sections are seeded. A non-repeatable section key is never an answer key, so seeding
     *    it resolves ABSENT and leaves `count()` at 0 forever — the same always-false trap this exists to
     *    close, wearing the appearance of a fix.
     * 3. A section key that COLLIDES with a field key is skipped (amendment A7 — the two tables carry
     *    independent unique indexes, so a collision is reachable and `ExpressionValidationGate`'s claim to
     *    the contrary is false). Seeding it would re-admit the answer of a field relevance had just pruned.
     *
     * `count()` therefore reads the RAW instance array, because repeats are processed after this loop, where
     * `computeCalculated` runs last and sees the relevance-PRUNED instances. Both engines do exactly this, so
     * the difference between the two passes is a documented property, not a divergence.
     *
     * @param  array<string, bool>  $relevant  currently-relevant top-level field keys
     * @param  array<string, true>  $fieldKeys
     * @return array<string, mixed>
     */
    private function relevanceContext(SemanticInput $input, array $relevant, array $fieldKeys): array
    {
        $keys = $relevant;
        foreach ($input->sections as $section) {
            if ($section->is_repeatable === true && ! isset($fieldKeys[$section->key])) {
                $keys[$section->key] = true;
            }
        }

        // array_intersect_key preserves the FIRST argument's order, so the context is ordered by the answer
        // map — which is what the TypeScript twin's `Object.entries(answers)` walk produces too.
        return array_intersect_key($input->answers, $keys);
    }

    /**
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, bool>  $relevant
     * @return array<string, bool>
     */
    private function fullMask(Collection $fields, array $relevant): array
    {
        $mask = [];
        foreach ($fields as $field) {
            $mask[$field->key] = isset($relevant[$field->key]);
        }

        return $mask;
    }

    /** A blank expression means "always relevant" / "no condition" — short-circuit before the engine. */
    private function evaluateRelevance(?string $expression, EvaluationContext $context): bool
    {
        if ($expression === null || trim($expression) === '') {
            return true;
        }

        return $this->evaluator->evaluateBoolean($expression, $context);
    }

    /**
     * @param  list<list<FormFieldValidation>>  $units
     * @param  array<string, string>  $fieldKeyById
     */
    private function anyUnitHolds(array $units, EvaluationContext $context, array $fieldKeyById): bool
    {
        foreach ($units as $unit) {
            $holds = count($unit) === 1
                ? $this->rules->conditionHolds($unit[0], $context, $fieldKeyById)
                : $this->rules->conditionGroupHolds($unit, $context, $fieldKeyById);

            if ($holds) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $answers
     * @param  array<string, bool>  $fieldRelevance
     * @return array<string, mixed>
     */
    private function effectiveAnswers(array $answers, array $fieldRelevance): array
    {
        $effective = [];
        foreach ($answers as $key => $value) {
            if (($fieldRelevance[$key] ?? false) === true) {
                $effective[$key] = $value;
            }
        }

        return $effective;
    }

    /**
     * Required checks (relevance-gated, incl. conditional) + constraint checks (only on relevant, answered
     * fields) for the top-level (flat) fields. Errors follow the field order the caller supplied.
     *
     * @param  Collection<int, FormField>  $fields  the top-level fields
     * @param  array<string, array{constraint: list<list<FormFieldValidation>>, required: list<list<FormFieldValidation>>, skip: list<list<FormFieldValidation>>}>  $ruleSets
     * @param  array<string, bool>  $fieldRelevance
     * @param  array<string, mixed>  $effectiveAnswers
     * @param  array<string, string>  $fieldKeyById
     * @return list<SemanticError>
     */
    private function collectErrors(
        Collection $fields,
        array $ruleSets,
        array $fieldRelevance,
        array $effectiveAnswers,
        array $fieldKeyById,
        string $locale,
        ?string $now,
    ): array {
        $errors = [];

        foreach ($fields as $field) {
            if (($fieldRelevance[$field->key] ?? false) !== true) {
                continue;
            }

            $this->collectFieldErrors($field, $effectiveAnswers, $effectiveAnswers, $ruleSets, $fieldKeyById, $locale, $now, $errors, null, null);
        }

        return $errors;
    }

    /**
     * One relevant field's required + constraint checks (technical-architecture.md §4.1). Shared by the flat
     * pass (answerScope === contextAnswers === the effective answers) and the per-instance repeat pass
     * (answerScope === the instance's effective answers; contextAnswers === the outside scope merged over
     * that instance, so a member constraint may reference an outside field and a same-instance sibling). An
     * empty relevant field is either a required error or fine; an answered field runs its constraints. When
     * `$sectionKey`/`$instanceIndex` are non-null the produced errors carry the repeat address.
     *
     * @param  array<string, mixed>  $answerScope  where THIS field's own answer is read
     * @param  array<string, mixed>  $contextAnswers  the evaluation-context answer map (required-if + field refs)
     * @param  array<string, array{constraint: list<list<FormFieldValidation>>, required: list<list<FormFieldValidation>>, skip: list<list<FormFieldValidation>>}>  $ruleSets
     * @param  array<string, string>  $fieldKeyById
     * @param  list<SemanticError>  $errors
     */
    private function collectFieldErrors(
        FormField $field,
        array $answerScope,
        array $contextAnswers,
        array $ruleSets,
        array $fieldKeyById,
        string $locale,
        ?string $now,
        array &$errors,
        ?string $sectionKey,
        ?int $instanceIndex,
    ): void {
        // A calculated field is a server-computed OUTPUT, never a respondent input — it carries no
        // required/constraint checks (its formula is evaluated in the compute pass instead).
        //
        // A hidden field (Increment H7) is skipped for a different reason with the same shape: the
        // respondent can neither see it, reach it, nor repair it, so ANY error on one is an unfixable dead
        // end — a submit that fails forever, and (in the SPA) an error-summary entry addressing a row that
        // does not render. The publish gate refuses `required`/`conditional` and any validation rule on a
        // hidden field so an author learns this at publish; this early return is what makes the rule hold
        // for a version published BEFORE H7, which that gate can never reach.
        //
        // Both are NARROWINGS — they can only remove errors, never invent one — so neither can introduce a
        // new PHP/TS divergence. Pinned in both engines by tests/golden/validation/hidden.json.
        if ($field->field_type === FieldType::Calculated || $field->field_type === FieldType::Hidden) {
            return;
        }

        // Composite (grid) fields (Increment G4b) hold object-valued answers and are validated by the
        // dedicated processComposites pass — never here, where Coercion::isEmpty on the whole object would
        // diverge from the TS mirror (PHP isEmpty([]) === true vs TS isEmpty({}) === false).
        if ($field->field_type === FieldType::Matrix || $field->field_type === FieldType::LikertMatrix) {
            return;
        }

        // Geospatial fields (Increment G5b1) hold an object-valued GeoJSON envelope and are validated by
        // the dedicated processGeo pass — never here, for the same reason as composites (Coercion::isEmpty
        // on the whole object diverges: PHP isEmpty([]) === true vs TS isEmpty({}) === false).
        if ($field->field_type->isGeo()) {
            return;
        }

        // Media fields (Increment G6) hold a list of attachment-reference objects and are validated by the
        // dedicated processMedia pass (required + count), never here — the object list must not hit scalar
        // coercion, and its existence/scan checks are the DB-backed AttachmentReferenceValidator's.
        if ($field->field_type->isMedia()) {
            return;
        }

        $context = new EvaluationContext($contextAnswers, now: $now);
        $answer = array_key_exists($field->key, $answerScope) ? $answerScope[$field->key] : Marker::Absent;
        $sets = $ruleSets[$field->id] ?? [];

        if (Coercion::isEmpty($answer)) {
            [$required, $trigger] = $this->requiredState($field, $sets['required'] ?? [], $context, $fieldKeyById);
            if ($required) {
                $message = $trigger !== null
                    ? $this->resolveMessage($trigger, $locale, self::DEFAULT_REQUIRED_MESSAGE)
                    : self::DEFAULT_REQUIRED_MESSAGE;
                $errors[] = new SemanticError($field->key, 'field_required', $message, $sectionKey, $instanceIndex);
            }

            return; // an empty field's constraints are not evaluated
        }

        $selfContext = new EvaluationContext($contextAnswers, $answer, $now);
        foreach ($sets['constraint'] ?? [] as $unit) {
            $passes = count($unit) === 1
                ? $this->rules->passesConstraint($unit[0], $field->key, $answer, $selfContext, $fieldKeyById)
                : $this->rules->passesConstraintGroup($unit, $field->key, $answer, $selfContext, $fieldKeyById);

            if (! $passes) {
                $representative = $this->representative($unit);
                $errors[] = new SemanticError(
                    $field->key,
                    $this->ruleId($representative),
                    $this->resolveMessage($representative, $locale, self::DEFAULT_CONSTRAINT_MESSAGE),
                    $sectionKey,
                    $instanceIndex,
                );
            }
        }

        $this->collectMembershipErrors($field, $answer, $errors, $sectionKey, $instanceIndex);
    }

    /**
     * Choice-membership + cascading integrity for an answered, relevant choice-family field (Increment G4a).
     * A `single_select`/`dropdown`/`likert_scale`/`multi_select` answer must be drawn from the field's
     * `config.options`; a `cascading_select` answer's per-level values must each be a known option at that
     * level whose `parent` matches the previous level's choice. Enforced only when the field actually
     * declares options (an unconfigured choice field checks nothing — this is what keeps every pre-G4a
     * golden vector, which carries no option set, byte-identical). Errors carry the caller's repeat address,
     * and cascading additionally carries a per-level `cellPath`. Membership runs alongside constraints, not
     * instead of them.
     *
     * @param  list<SemanticError>  $errors
     */
    private function collectMembershipErrors(
        FormField $field,
        mixed $answer,
        array &$errors,
        ?string $sectionKey,
        ?int $instanceIndex,
    ): void {
        switch ($field->field_type) {
            case FieldType::SingleSelect:
            case FieldType::Dropdown:
            case FieldType::LikertScale:
                $allowed = $this->optionValueSet($field);
                if ($allowed === [] || is_array($answer)) {
                    return; // no configured options → nothing to enforce; an array is a Stage-1 fault
                }
                if (! isset($allowed[Coercion::toStr($answer)])) {
                    $errors[] = new SemanticError($field->key, 'choice_not_in_options', 'The selected option is not available.', $sectionKey, $instanceIndex);
                }

                return;

            case FieldType::MultiSelect:
                $allowed = $this->optionValueSet($field);
                if ($allowed === [] || ! is_array($answer)) {
                    return;
                }
                foreach ($answer as $element) {
                    if (! isset($allowed[Coercion::toStr($element)])) {
                        $errors[] = new SemanticError($field->key, 'choice_not_in_options', 'A selected option is not available.', $sectionKey, $instanceIndex);

                        return; // one membership error per field is enough
                    }
                }

                return;

            case FieldType::CascadingSelect:
                $this->collectCascadeErrors($field, $answer, $errors, $sectionKey, $instanceIndex);

                return;

            default:
                return;
        }
    }

    /**
     * The `config.options` value set for O(1) membership; `[]` when the field declares no options.
     *
     * @return array<string, true>
     */
    private function optionValueSet(FormField $field): array
    {
        $set = [];
        foreach ((array) data_get($field->config, 'options', []) as $option) {
            if (is_array($option) && array_key_exists('value', $option) && $option['value'] !== null) {
                $set[Coercion::toStr($option['value'])] = true;
            }
        }

        return $set;
    }

    /**
     * Cascading select (G4a): the answer is an ordered `list<string>` of one chosen value per level. Each
     * element must be a known option at its positional level, and (below the root) its option's `parent`
     * must equal the value chosen at the previous level. Per-level errors are addressed via `cellPath` (the
     * 0-based level index). Skipped when the field declares no levels/options. An interior blank level is
     * not itself a membership violation (a deeper non-blank level under it will mismatch its parent).
     *
     * @param  list<SemanticError>  $errors
     */
    private function collectCascadeErrors(FormField $field, mixed $answer, array &$errors, ?string $sectionKey, ?int $instanceIndex): void
    {
        $levels = array_values(array_map(
            static fn (mixed $level): string => is_array($level) ? Coercion::toStr($level['key'] ?? '') : Coercion::toStr($level),
            (array) data_get($field->config, 'levels', []),
        ));

        /** @var array<string, array<string, string|null>> $byLevel  levelKey => (value => parent) */
        $byLevel = [];
        foreach ((array) data_get($field->config, 'options', []) as $option) {
            if (! is_array($option)) {
                continue;
            }
            $level = Coercion::toStr($option['level'] ?? '');
            $value = Coercion::toStr($option['value'] ?? '');
            $parent = array_key_exists('parent', $option) && $option['parent'] !== null ? Coercion::toStr($option['parent']) : null;
            $byLevel[$level][$value] = $parent;
        }

        if ($levels === [] || $byLevel === [] || ! is_array($answer)) {
            return;
        }

        $values = array_values($answer);
        foreach ($values as $i => $rawValue) {
            $value = Coercion::toStr($rawValue);
            if ($value === '') {
                continue;
            }

            $levelKey = $levels[$i] ?? null;
            $optionsAtLevel = $levelKey !== null ? ($byLevel[$levelKey] ?? []) : [];
            if (! array_key_exists($value, $optionsAtLevel)) {
                $errors[] = new SemanticError($field->key, 'cascading_choice_invalid', 'This selection is not available at this level.', $sectionKey, $instanceIndex, (string) $i);

                continue;
            }

            $expectedParent = $optionsAtLevel[$value];
            $previous = $i > 0 ? Coercion::toStr($values[$i - 1] ?? '') : null;
            if ($i > 0 && $expectedParent !== null && $expectedParent !== $previous) {
                $errors[] = new SemanticError($field->key, 'cascading_parent_mismatch', 'This selection does not belong under the parent choice.', $sectionKey, $instanceIndex, (string) $i);
            }
        }
    }

    /**
     * Validate every relevant top-level composite (grid) field (Increment G4b): `matrix` holds
     * `{row:{col:cell}}` and `likert_matrix` holds `{row:score}`. These object shapes must NEVER be routed
     * through the scalar {@see collectFieldErrors} path — `Coercion::isEmpty` on an empty object diverges
     * between the engines (PHP `isEmpty([]) === true` vs TS `isEmpty({}) === false`). Instead this pass
     * reads the field's declared rows/columns/cells, prunes the submitted object to the KNOWN cells
     * (dropping empties, coercing each surviving scalar cell) IN CONFIG ORDER — so both engines emit
     * identical key ordering — and applies required-completeness (a required grid demands every row (likert)
     * / every row×column cell (matrix) be answered → per-cell `field_required` addressed by `cellPath`) plus
     * cell-membership (a surviving cell must be a declared option → `choice_not_in_options`). The pruned
     * object replaces the field's raw effective answer, or the key is dropped when nothing survives.
     * Object-emptiness is decided by counting surviving cells, never by `isEmpty(object)`.
     *
     * @param  Collection<int, FormField>  $fields  the top-level fields (only composites are handled)
     * @param  array<string, bool>  $fieldRelevance
     * @param  array<string, mixed>  $effectiveAnswers  MUTATED: each composite key set to its pruned object / unset
     * @param  array<string, array{constraint: list<list<FormFieldValidation>>, required: list<list<FormFieldValidation>>, skip: list<list<FormFieldValidation>>}>  $ruleSets
     * @param  array<string, string>  $fieldKeyById
     * @return list<SemanticError>
     */
    private function processComposites(
        Collection $fields,
        array $fieldRelevance,
        array &$effectiveAnswers,
        array $ruleSets,
        array $fieldKeyById,
        string $locale,
        ?string $now,
    ): array {
        /** @var list<SemanticError> $errors */
        $errors = [];
        $context = new EvaluationContext($effectiveAnswers, now: $now);

        foreach ($fields as $field) {
            if ($field->field_type !== FieldType::Matrix && $field->field_type !== FieldType::LikertMatrix) {
                continue;
            }
            if (($fieldRelevance[$field->key] ?? false) !== true) {
                continue; // hidden composite: already pruned from effectiveAnswers, enforce nothing
            }

            $sets = $ruleSets[$field->id] ?? [];
            [$required, $trigger] = $this->requiredState($field, $sets['required'] ?? [], $context, $fieldKeyById);
            $requiredMessage = $trigger !== null
                ? $this->resolveMessage($trigger, $locale, self::DEFAULT_REQUIRED_MESSAGE)
                : self::DEFAULT_REQUIRED_MESSAGE;

            $raw = $effectiveAnswers[$field->key] ?? null;
            $pruned = $field->field_type === FieldType::Matrix
                ? $this->processMatrix($field, $raw, $required, $requiredMessage, $errors)
                : $this->processLikertMatrix($field, $raw, $required, $requiredMessage, $errors);

            if ($pruned === []) {
                unset($effectiveAnswers[$field->key]);
            } else {
                $effectiveAnswers[$field->key] = $pruned;
            }
        }

        return $errors;
    }

    /**
     * Likert-matrix (`{row:score}`): each declared row must carry a score drawn from the shared column scale.
     * Returns the pruned `{row:score}` (empty when nothing survives). `cellPath = "<rowKey>"`.
     *
     * @param  list<SemanticError>  $errors
     * @return array<string, string>
     */
    private function processLikertMatrix(FormField $field, mixed $raw, bool $required, string $requiredMessage, array &$errors): array
    {
        $rowKeys = $this->compositeValues($field, 'rows');
        $scoreSet = array_flip($this->compositeValues($field, 'columns'));
        $rawMap = is_array($raw) && ! array_is_list($raw) ? $raw : [];

        /** @var array<string, string> $pruned */
        $pruned = [];
        foreach ($rowKeys as $rowKey) {
            $score = $rawMap[$rowKey] ?? null;
            if (! array_key_exists($rowKey, $rawMap) || Coercion::isEmpty($score)) {
                if ($required) {
                    $errors[] = new SemanticError($field->key, 'field_required', $requiredMessage, null, null, $rowKey);
                }

                continue;
            }

            $value = Coercion::toStr($score);
            $pruned[$rowKey] = $value;
            if ($scoreSet !== [] && ! isset($scoreSet[$value])) {
                $errors[] = new SemanticError($field->key, 'choice_not_in_options', 'The selected option is not available.', null, null, $rowKey);
            }
        }

        return $pruned;
    }

    /**
     * Matrix (`{row:{col:cell}}`): each declared row×column cell may carry a value drawn from the shared cell
     * choice pool. Returns the pruned `{row:{col:cell}}` (rows with no surviving cell dropped).
     * `cellPath = "<rowKey>.<colKey>"`.
     *
     * @param  list<SemanticError>  $errors
     * @return array<string, array<string, string>>
     */
    private function processMatrix(FormField $field, mixed $raw, bool $required, string $requiredMessage, array &$errors): array
    {
        $rowKeys = $this->compositeValues($field, 'rows');
        $colKeys = $this->compositeValues($field, 'columns');
        $cellSet = array_flip($this->compositeValues($field, 'cells'));
        $rawMap = is_array($raw) && ! array_is_list($raw) ? $raw : [];

        /** @var array<string, array<string, string>> $pruned */
        $pruned = [];
        foreach ($rowKeys as $rowKey) {
            $rowRaw = $rawMap[$rowKey] ?? null;
            $rowMap = is_array($rowRaw) && ! array_is_list($rowRaw) ? $rowRaw : [];

            /** @var array<string, string> $prunedRow */
            $prunedRow = [];
            foreach ($colKeys as $colKey) {
                $cell = $rowMap[$colKey] ?? null;
                if (! array_key_exists($colKey, $rowMap) || Coercion::isEmpty($cell)) {
                    if ($required) {
                        $errors[] = new SemanticError($field->key, 'field_required', $requiredMessage, null, null, "{$rowKey}.{$colKey}");
                    }

                    continue;
                }

                $value = Coercion::toStr($cell);
                $prunedRow[$colKey] = $value;
                if ($cellSet !== [] && ! isset($cellSet[$value])) {
                    $errors[] = new SemanticError($field->key, 'choice_not_in_options', 'The selected option is not available.', null, null, "{$rowKey}.{$colKey}");
                }
            }

            if ($prunedRow !== []) {
                $pruned[$rowKey] = $prunedRow;
            }
        }

        return $pruned;
    }

    /**
     * The ordered `config.<key>` option-value list (rows/columns/cells) for a composite field, in author
     * order, de-duplicated. Drives both the config-ordered prune and the membership sets.
     *
     * @return list<string>
     */
    private function compositeValues(FormField $field, string $configKey): array
    {
        $values = [];
        foreach ((array) data_get($field->config, $configKey, []) as $option) {
            $value = is_array($option) ? Coercion::toStr($option['value'] ?? '') : Coercion::toStr($option);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * Geospatial pass (Increment G5b1 / ADR-0006), the object-valued sibling of processComposites. Each
     * relevant geo field's GeoJSON envelope is validated structurally (never through scalar isEmpty): the
     * geometry `type` must match the field, coordinates must be in range, a line needs ≥ 2 points, a
     * polygon ring needs ≥ 4 points and must be closed. A structurally-empty envelope (no coordinates)
     * is a required error (if required) and is unset so effective answers stay identical across engines
     * ({} in TS vs [] in PHP). Whole-field faults → no cellPath. Runs identically in the TS mirror.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, bool>  $fieldRelevance
     * @param  array<string, mixed>  $effectiveAnswers
     * @param  array<string, array{constraint: list<list<FormFieldValidation>>, required: list<list<FormFieldValidation>>, skip: list<list<FormFieldValidation>>}>  $ruleSets
     * @param  array<string, string>  $fieldKeyById
     * @return list<SemanticError>
     */
    private function processGeo(
        Collection $fields,
        array $fieldRelevance,
        array &$effectiveAnswers,
        array $ruleSets,
        array $fieldKeyById,
        string $locale,
        ?string $now,
    ): array {
        /** @var list<SemanticError> $errors */
        $errors = [];
        $context = new EvaluationContext($effectiveAnswers, now: $now);

        foreach ($fields as $field) {
            if (! $field->field_type->isGeo()) {
                continue;
            }
            if (($fieldRelevance[$field->key] ?? false) !== true) {
                continue; // hidden geo: already pruned from effectiveAnswers, enforce nothing
            }

            $sets = $ruleSets[$field->id] ?? [];
            [$required, $trigger] = $this->requiredState($field, $sets['required'] ?? [], $context, $fieldKeyById);
            $requiredMessage = $trigger !== null
                ? $this->resolveMessage($trigger, $locale, self::DEFAULT_REQUIRED_MESSAGE)
                : self::DEFAULT_REQUIRED_MESSAGE;

            $raw = $effectiveAnswers[$field->key] ?? null;
            if (! $this->validateGeo($field, $raw, $required, $requiredMessage, $errors)) {
                unset($effectiveAnswers[$field->key]); // empty envelope — keep engines byte-identical
            }
        }

        return $errors;
    }

    /**
     * Validate one geo envelope; append any faults. Returns whether the answer is a non-empty envelope
     * (kept in effective answers) vs structurally empty (the caller unsets it). `geopoint` → `Point`,
     * `geotrace` → `LineString` (≥ 2 pts), `geoshape` → closed `Polygon` (≥ 4 pts, first == last).
     *
     * @param  list<SemanticError>  $errors
     */
    private function validateGeo(FormField $field, mixed $raw, bool $required, string $requiredMessage, array &$errors): bool
    {
        $expectedType = match ($field->field_type) {
            FieldType::Geotrace => 'LineString',
            FieldType::Geoshape => 'Polygon',
            default => 'Point',
        };

        // Structural emptiness — decided WITHOUT scalar isEmpty (which diverges on {} across engines).
        $coordinates = is_array($raw) ? ($raw['coordinates'] ?? null) : null;
        if (! is_array($coordinates) || $coordinates === []) {
            if ($required) {
                $errors[] = new SemanticError($field->key, 'field_required', $requiredMessage);
            }

            return false;
        }

        // $raw is a non-empty array here (else the emptiness guard above returned false).
        if (($raw['type'] ?? null) !== $expectedType) {
            $errors[] = new SemanticError($field->key, 'geo_type_mismatch', 'This location value has the wrong geometry type.');

            return true;
        }

        if ($expectedType === 'Point') {
            $positions = [$coordinates];
        } elseif ($expectedType === 'LineString') {
            $positions = $coordinates;
            if (count($positions) < 2) {
                $errors[] = new SemanticError($field->key, 'geo_too_few_points', 'A line needs at least two points.');
            }
        } else { // Polygon — validate the first (outer) ring
            $ring = $coordinates[0] ?? null;
            $positions = is_array($ring) ? $ring : [];
            if (count($positions) < 4) {
                $errors[] = new SemanticError($field->key, 'geo_too_few_points', 'An area needs at least four points to close a ring.');
            } elseif (! $this->samePosition($positions[0], $positions[count($positions) - 1])) {
                $errors[] = new SemanticError($field->key, 'geo_not_closed', 'The area boundary must be closed: the first and last points must match.');
            }
        }

        foreach ($positions as $position) {
            if (! $this->positionInRange($position)) {
                $errors[] = new SemanticError($field->key, 'geo_out_of_range', 'A coordinate is out of range (longitude ±180, latitude ±90).');
                break;
            }
        }

        return true;
    }

    /**
     * A `[lon, lat]` position is in range when both ordinates are numeric and within WGS84 bounds. A
     * non-array position or a non-numeric ordinate (→ NaN via the shared Coercion primitive, identical in
     * the TS mirror) is out of range.
     */
    private function positionInRange(mixed $position): bool
    {
        if (! is_array($position)) {
            return false;
        }

        $lon = Coercion::toNumber($position[0] ?? null);
        $lat = Coercion::toNumber($position[1] ?? null);

        if (is_nan($lon) || is_nan($lat)) {
            return false;
        }

        return $lon >= -180.0 && $lon <= 180.0 && $lat >= -90.0 && $lat <= 90.0;
    }

    /**
     * Two positions are equal when their numeric lon/lat coincide (NaN never equals NaN, so a malformed
     * endpoint reads as unequal — identical to the TS mirror).
     */
    private function samePosition(mixed $a, mixed $b): bool
    {
        if (! is_array($a) || ! is_array($b)) {
            return false;
        }

        return Coercion::toNumber($a[0] ?? null) === Coercion::toNumber($b[0] ?? null)
            && Coercion::toNumber($a[1] ?? null) === Coercion::toNumber($b[1] ?? null);
    }

    /**
     * Media pass (Increment G6), the list-valued sibling of processGeo/processComposites. Each relevant
     * media field's answer is a `list<AttachmentRef>`; the DB-free checks are: required (an empty/absent
     * list is a required error, if required) and the min/max instance-count bounds from the field config.
     * An empty list is unset so effective answers stay byte-identical across engines. Existence, tenant
     * ownership, and scan status are NOT checked here (they need the database) — that is the PHP-only
     * {@see AttachmentReferenceValidator}, run by the pipeline after Stage 3, so
     * this pass stays golden-parity-able with the TS mirror.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, bool>  $fieldRelevance
     * @param  array<string, mixed>  $effectiveAnswers
     * @param  array<string, array{constraint: list<list<FormFieldValidation>>, required: list<list<FormFieldValidation>>, skip: list<list<FormFieldValidation>>}>  $ruleSets
     * @param  array<string, string>  $fieldKeyById
     * @return list<SemanticError>
     */
    private function processMedia(
        Collection $fields,
        array $fieldRelevance,
        array &$effectiveAnswers,
        array $ruleSets,
        array $fieldKeyById,
        string $locale,
        ?string $now,
    ): array {
        /** @var list<SemanticError> $errors */
        $errors = [];
        $context = new EvaluationContext($effectiveAnswers, now: $now);

        foreach ($fields as $field) {
            if (! $field->field_type->isMedia()) {
                continue;
            }
            if (($fieldRelevance[$field->key] ?? false) !== true) {
                continue; // hidden media: already pruned from effectiveAnswers, enforce nothing
            }

            $raw = $effectiveAnswers[$field->key] ?? null;
            $count = is_array($raw) ? count($raw) : 0;

            if ($count === 0) {
                $sets = $ruleSets[$field->id] ?? [];
                [$required, $trigger] = $this->requiredState($field, $sets['required'] ?? [], $context, $fieldKeyById);
                if ($required) {
                    $message = $trigger !== null
                        ? $this->resolveMessage($trigger, $locale, self::DEFAULT_REQUIRED_MESSAGE)
                        : self::DEFAULT_REQUIRED_MESSAGE;
                    $errors[] = new SemanticError($field->key, 'field_required', $message);
                }
                unset($effectiveAnswers[$field->key]); // empty list — keep engines byte-identical

                continue;
            }

            [$min, $max] = $this->mediaCountBounds($field);
            if ($min !== null && $count < $min) {
                $errors[] = new SemanticError($field->key, 'media_too_few', "Attach at least {$min} file(s).");
            }
            if ($max !== null && $count > $max) {
                $errors[] = new SemanticError($field->key, 'media_too_many', "Attach no more than {$max} file(s).");
            }
        }

        return $errors;
    }

    /**
     * The `[min, max]` attachment-count bounds from a media field's config (`min_count`/`max_count`), each
     * null when unset or non-positive. Read directly off `config` (PHP) — the TS mirror reads the same two
     * values off `SchemaField.media` — so the two engines agree on the count check.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function mediaCountBounds(FormField $field): array
    {
        $min = data_get($field->config, 'min_count');
        $max = data_get($field->config, 'max_count');

        return [
            is_numeric($min) && (int) $min > 0 ? (int) $min : null,
            is_numeric($max) && (int) $max > 0 ? (int) $max : null,
        ];
    }

    /**
     * Validate every relevant repeatable section's instances (technical-architecture.md §4.1). A hidden
     * repeatable section drops its whole array and enforces nothing (section-cascade). Otherwise the
     * instance count is enforced against min/max (max first, as an abuse guard — a huge array is rejected
     * before any per-instance settling), then each instance is settled + error-checked in its own scope.
     * Instances are kept index-aligned with the submitted (normalised) array so an error address `sec[i]`
     * always matches the stored `effective[sec][i]`.
     *
     * @param  array<string, list<FormField>>  $repeatMembersBySectionId
     * @param  array<string, array{constraint: list<list<FormFieldValidation>>, required: list<list<FormFieldValidation>>, skip: list<list<FormFieldValidation>>}>  $ruleSets
     * @param  array<string, bool>  $sectionRelevance  section key => relevant
     * @param  array<string, mixed>  $baseEffective  the top-level effective answers (the outside scope)
     * @param  array<string, string>  $fieldKeyById
     * @param  array<string, true>  $fieldKeys  every field key in the version (the section-collision guard)
     * @return array{0: array<string, list<array<string, mixed>>>, 1: list<SemanticError>, 2: array<string, list<array<string, bool>>>}
     */
    private function processRepeats(
        SemanticInput $input,
        array $repeatMembersBySectionId,
        array $ruleSets,
        array $sectionRelevance,
        array $baseEffective,
        array $fieldKeyById,
        array $fieldKeys,
        ?string $now,
    ): array {
        /** @var array<string, list<array<string, mixed>>> $effective */
        $effective = [];
        /** @var list<SemanticError> $errors */
        $errors = [];
        /** @var array<string, list<array<string, bool>>> $relevance */
        $relevance = [];

        foreach ($input->sections as $section) {
            if ($section->is_repeatable !== true) {
                continue;
            }

            $sectionKey = $section->key;
            if (($sectionRelevance[$sectionKey] ?? false) !== true) {
                continue; // hidden repeatable section: drop the whole group, enforce nothing
            }

            $members = $repeatMembersBySectionId[$section->id] ?? [];
            $rawInstances = $input->answers[$sectionKey] ?? [];
            $instances = is_array($rawInstances)
                ? array_values(array_filter($rawInstances, static fn (mixed $instance): bool => is_array($instance)))
                : [];
            $count = count($instances);

            // `max` stays AHEAD of the per-instance settling — it is the abuse guard this method's docblock
            // names, and moving it below would settle the huge array it exists to reject. It also needs no
            // step-visibility narrowing: a max violation means instances were submitted, so the respondent
            // was shown the group.
            $max = $section->max_instances;
            if ($max !== null && $count > $max) {
                $errors[] = new SemanticError($sectionKey, 'max_instances', $this->maxInstancesMessage($max), $sectionKey, null);
            }

            /** @var list<array<string, bool>> $instanceMasks */
            $instanceMasks = [];

            foreach ($instances as $index => $instanceAnswers) {
                /** @var array<string, mixed> $instanceAnswers */
                [$instanceRelevance, $instanceEffective] = $this->settleInstanceRelevance(
                    $members,
                    $ruleSets,
                    $baseEffective,
                    $instanceAnswers,
                    $fieldKeyById,
                    $fieldKeys,
                    $sectionKey,
                    $instances,
                    $now,
                );

                $contextAnswers = array_merge($baseEffective, $instanceEffective);
                foreach ($members as $field) {
                    if (($instanceRelevance[$field->key] ?? false) !== true) {
                        continue;
                    }
                    $this->collectFieldErrors($field, $instanceEffective, $contextAnswers, $ruleSets, $fieldKeyById, $input->locale, $now, $errors, $sectionKey, $index);
                }

                $effective[$sectionKey][] = $instanceEffective;
                $relevance[$sectionKey][] = $instanceRelevance;
                $instanceMasks[] = $instanceRelevance;
            }

            // `min` runs LAST, gated on whether the respondent's step list would show this group at all
            // (Doc #27 §4.3, amendment A4). It needs the instance masks, which is why it sits here and not
            // beside `max`. Without the gate a repeatable section that vanished from `visibleSteps` still
            // demands instances — a permanent blocker that is INVISIBLE, because the error-summary banner
            // iterates the visible step list and the step is not in it.
            $min = $section->min_instances;
            if ($min !== null && $min > 0 && $count < $min && $this->repeatStepIsVisible($members, $instanceMasks)) {
                $errors[] = new SemanticError($sectionKey, 'min_instances', $this->minInstancesMessage($min), $sectionKey, null);
            }
        }

        return [$effective, $errors, $relevance];
    }

    /**
     * Whether the respondent's step list would SHOW this repeatable section — the server-side twin of the
     * guest SPA's `visibleSteps` emptiness test (Doc #27 §2.2 predicates 2 and 3, amendment A4). The TS
     * mirror is `repeatStepIsVisible()` in `engine/semantic-validator.ts`; the two renders-something sets
     * are `PdfFieldRole::Omitted` and `RENDERS_NOTHING`, pinned equal in both directions by
     * `tests/Unit/Forms/PdfFieldRoleTest.php`.
     *
     * `PdfFieldRole`'s name is historical — H17 minted it for the PDF — but its content is exactly the
     * question asked here: does this field type put a question in front of somebody.
     *
     * Zero instances is VACUOUSLY visible: the step is what lets the respondent add the first one, so a
     * `min_instances` of 2 on an empty group is a real, reachable, visible blocker and must still fire.
     *
     * @param  list<FormField>  $members
     * @param  list<array<string, bool>>  $instanceMasks  one member mask per settled instance
     */
    private function repeatStepIsVisible(array $members, array $instanceMasks): bool
    {
        $rendering = array_values(array_filter(
            $members,
            static fn (FormField $member): bool => PdfFieldRole::for($member->field_type) !== PdfFieldRole::Omitted,
        ));

        if ($rendering === []) {
            return false; // predicate 2 — nothing in the group renders a question at all
        }

        if ($instanceMasks === []) {
            return true;
        }

        foreach ($instanceMasks as $mask) {
            foreach ($rendering as $member) {
                if (($mask[$member->key] ?? false) === true) {
                    return true; // predicate 3 — at least one instance still asks something
                }
            }
        }

        return false;
    }

    /**
     * Settle one repeat instance's member-relevance to a bounded fixed point — the flat {@see settleRelevance}
     * over the members only, with each iteration's context = the outside scope merged over the instance's
     * currently-relevant answers (so a member's `relevant_expression`/`skip_*` may reference an outside gate
     * field and a same-instance sibling). Returns the full member mask + the relevance-pruned instance.
     *
     * @param  list<FormField>  $members
     * @param  array<string, array{constraint: list<list<FormFieldValidation>>, required: list<list<FormFieldValidation>>, skip: list<list<FormFieldValidation>>}>  $ruleSets
     * @param  array<string, mixed>  $baseAnswers  the outside (top-level effective) scope
     * @param  array<string, mixed>  $instanceAnswers  this instance's normalised answers
     * @param  array<string, string>  $fieldKeyById
     * @param  array<string, true>  $fieldKeys
     * @param  list<array<string, mixed>>  $groupInstances  the whole group, so count(${section}) resolves here too
     * @return array{0: array<string, bool>, 1: array<string, mixed>}
     */
    private function settleInstanceRelevance(
        array $members,
        array $ruleSets,
        array $baseAnswers,
        array $instanceAnswers,
        array $fieldKeyById,
        array $fieldKeys,
        string $sectionKey,
        array $groupInstances,
        ?string $now,
    ): array {
        /** @var array<string, bool> $relevant */
        $relevant = [];
        foreach ($members as $field) {
            $relevant[$field->key] = true; // start optimistic; prune from here
        }

        // Doc #27 §3.3 (amendment A2) at the SECOND scope. `$baseAnswers` is the top-level effective map
        // captured BEFORE the repeat arrays are merged into it, so without this the group's own key reads
        // ABSENT and `count(${roster})` inside a MEMBER's relevant_expression is 0 forever — the identical
        // always-false trap at a second scope, and "ask this only when the household has more than one
        // member" is the idiom that hits it. Same collision guard as the top-level seed.
        $groupScope = isset($fieldKeys[$sectionKey]) ? [] : [$sectionKey => $groupInstances];

        $maxIterations = count($members) + 2;

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $prunedInstance = array_intersect_key($instanceAnswers, $relevant);
            // The group scope sits BENEATH the instance's own answers so a member key that collides with
            // the section key still shadows it, exactly as it does without this seed.
            $context = new EvaluationContext(array_merge($baseAnswers, $groupScope, $prunedInstance), now: $now);

            /** @var array<string, bool> $next */
            $next = [];
            foreach ($members as $field) {
                $ownOk = $this->evaluateRelevance($field->relevant_expression, $context);
                $skipped = $this->anyUnitHolds($ruleSets[$field->id]['skip'] ?? [], $context, $fieldKeyById);

                if ($ownOk && ! $skipped) {
                    $next[$field->key] = true;
                }
            }

            if ($next === $relevant) {
                break;
            }
            $relevant = $next;
        }

        $mask = [];
        foreach ($members as $field) {
            $mask[$field->key] = isset($relevant[$field->key]);
        }

        return [$mask, array_intersect_key($instanceAnswers, array_filter($mask))];
    }

    /**
     * Evaluate every relevant top-level calculated field's formula over the full effective answers
     * (technical-architecture.md §4.3, grammar v2.0). A calc's formula lives in `config.calculated_formula`;
     * a hidden calc (fieldRelevance false), a non-calc field, and a blank formula contribute nothing, and a
     * blank/NaN result (missing operands, division by zero) is omitted rather than stored as null. Calculated
     * fields inside a repeatable section are NOT computed in G3 (they are repeat members, not top-level).
     *
     * @param  Collection<int, FormField>  $fields  the top-level fields
     * @param  array<string, mixed>  $effectiveAnswers  flat effective answers + repeat instance arrays (for count())
     * @param  array<string, bool>  $fieldRelevance
     * @return array<string, mixed> calculated field key => computed value
     */
    private function computeCalculated(Collection $fields, array $effectiveAnswers, array $fieldRelevance, ?string $now): array
    {
        $computed = [];
        $context = new EvaluationContext($effectiveAnswers, now: $now);

        foreach ($fields as $field) {
            if ($field->field_type !== FieldType::Calculated) {
                continue;
            }
            if (($fieldRelevance[$field->key] ?? false) !== true) {
                continue;
            }

            $formula = $this->calculateFormula($field);
            if ($formula === null) {
                continue;
            }

            $value = $this->evaluator->evaluate($formula, $context);
            if ($value !== null) {
                $computed[$field->key] = $value;
            }
        }

        return $computed;
    }

    /** The calc formula stored on a calculated field (`config.calculated_formula`); null when blank/absent. */
    private function calculateFormula(FormField $field): ?string
    {
        $formula = data_get($field->config, 'calculated_formula');

        return is_string($formula) && trim($formula) !== '' ? $formula : null;
    }

    private function minInstancesMessage(int $min): string
    {
        return 'Provide at least '.$min.' '.($min === 1 ? 'entry' : 'entries').'.';
    }

    private function maxInstancesMessage(int $max): string
    {
        return 'Provide at most '.$max.' '.($max === 1 ? 'entry' : 'entries').'.';
    }

    /**
     * Effective requiredness: a `Required` field is always required; a `Conditional`/`Optional` field is
     * required only if a `required_if`/`required_with` condition holds (the triggering row carries the
     * message). `Conditional` with no required_* rule falls out as optional.
     *
     * @param  list<list<FormFieldValidation>>  $units
     * @param  array<string, string>  $fieldKeyById
     * @return array{0: bool, 1: FormFieldValidation|null}
     */
    private function requiredState(FormField $field, array $units, EvaluationContext $context, array $fieldKeyById): array
    {
        if ($field->is_required === RequiredMode::Required) {
            return [true, null];
        }

        foreach ($units as $unit) {
            $holds = count($unit) === 1
                ? $this->rules->conditionHolds($unit[0], $context, $fieldKeyById)
                : $this->rules->conditionGroupHolds($unit, $context, $fieldKeyById);

            if ($holds) {
                return [true, $this->representative($unit)];
            }
        }

        return [false, null];
    }

    /**
     * @param  list<FormFieldValidation>  $unit
     */
    private function representative(array $unit): FormFieldValidation
    {
        $rows = $unit;
        usort($rows, static fn (FormFieldValidation $a, FormFieldValidation $b): int => [$a->sequence, $a->id] <=> [$b->sequence, $b->id]);

        return $rows[0];
    }

    private function ruleId(FormFieldValidation $row): string
    {
        if ($row->expression !== null) {
            return 'constraint';
        }

        $raw = $row->getAttributes()['rule_type'] ?? null;

        return is_string($raw) ? $raw : 'constraint';
    }

    private function resolveMessage(FormFieldValidation $row, string $locale, string $default): string
    {
        $translations = $row->error_message_translations ?? [];
        if (isset($translations[$locale]) && is_string($translations[$locale])) {
            return $translations[$locale];
        }

        return $row->error_message ?? $default;
    }
}
