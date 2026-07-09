<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Enums\RequiredMode;
use App\Enums\ValidationRuleType;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormVersion;
use App\Services\Expressions\Coercion;
use App\Services\Expressions\EvaluationContext;
use App\Services\Expressions\ExpressionEvaluator;
use App\Services\Expressions\Marker;

/**
 * Stage 3 of the Submission Pipeline (technical-architecture.md §4.1 §4.3) — the reusable semantic
 * authority the whole engine converges on. Given a published version's schema + a respondent's answers it
 * produces a {@see SemanticResult}: the relevance mask, per-field constraint/required errors, the
 * relevance-pruned answers Stage 4 persists, and a (Phase-1-empty) computed-values slot. PHP is the sole
 * authority; this must stay byte-identical to the Phase-1 TypeScript mirror (the golden `validation` suite).
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
     */
    public function validate(FormVersion $version, array $answers, ?string $locale = null): SemanticResult
    {
        $locale ??= $this->defaultLocale($version);

        return $this->evaluate(new SemanticInput(
            $version->fields()->orderBy('sequence')->orderBy('key')->get(),
            $version->sections()->orderBy('sequence')->orderBy('key')->get(),
            $version->validations()->orderBy('sequence')->get(),
            $answers,
            $locale,
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

        $ruleSets = $this->buildRuleSets($input);
        [$fieldRelevance, $sectionRelevance] = $this->settleRelevance($input, $ruleSets, $fieldKeyById);
        $effectiveAnswers = $this->effectiveAnswers($input->answers, $fieldRelevance);
        $errors = $this->collectErrors($input, $ruleSets, $fieldRelevance, $effectiveAnswers, $fieldKeyById);

        return new SemanticResult($fieldRelevance, $sectionRelevance, $errors, $effectiveAnswers, []);
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
     * is relevant AND its own `relevant_expression` holds AND no `skip_if`/`skip_with` fires.
     *
     * @param  array<string, array{constraint: list<list<FormFieldValidation>>, required: list<list<FormFieldValidation>>, skip: list<list<FormFieldValidation>>}>  $ruleSets
     * @param  array<string, string>  $fieldKeyById
     * @return array{0: array<string, bool>, 1: array<string, bool>}
     */
    private function settleRelevance(SemanticInput $input, array $ruleSets, array $fieldKeyById): array
    {
        /** @var array<string, bool> $relevant */
        $relevant = [];
        foreach ($input->fields as $field) {
            $relevant[$field->key] = true; // start optimistic; prune from here
        }

        $maxIterations = $input->fields->count() + $input->sections->count() + 2;
        /** @var array<string, bool> $sectionRelevance */
        $sectionRelevance = [];

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $context = new EvaluationContext(array_intersect_key($input->answers, $relevant));

            $sectionRelevance = [];
            $sectionRelevantById = [];
            foreach ($input->sections as $section) {
                $ok = $this->evaluateRelevance($section->relevant_expression, $context);
                $sectionRelevance[$section->key] = $ok;
                $sectionRelevantById[$section->id] = $ok;
            }

            /** @var array<string, bool> $next */
            $next = [];
            foreach ($input->fields as $field) {
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

        return [$this->fullMask($input, $relevant), $sectionRelevance];
    }

    /**
     * @param  array<string, bool>  $relevant
     * @return array<string, bool>
     */
    private function fullMask(SemanticInput $input, array $relevant): array
    {
        $mask = [];
        foreach ($input->fields as $field) {
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
     * fields). An empty relevant field is either a required error or fine; an answered field runs its
     * constraints. Errors follow the field order the caller supplied.
     *
     * @param  array<string, array{constraint: list<list<FormFieldValidation>>, required: list<list<FormFieldValidation>>, skip: list<list<FormFieldValidation>>}>  $ruleSets
     * @param  array<string, bool>  $fieldRelevance
     * @param  array<string, mixed>  $effectiveAnswers
     * @param  array<string, string>  $fieldKeyById
     * @return list<SemanticError>
     */
    private function collectErrors(
        SemanticInput $input,
        array $ruleSets,
        array $fieldRelevance,
        array $effectiveAnswers,
        array $fieldKeyById,
    ): array {
        $errors = [];
        $context = new EvaluationContext($effectiveAnswers);

        foreach ($input->fields as $field) {
            if (($fieldRelevance[$field->key] ?? false) !== true) {
                continue;
            }

            $answer = array_key_exists($field->key, $effectiveAnswers) ? $effectiveAnswers[$field->key] : Marker::Absent;
            $sets = $ruleSets[$field->id] ?? [];

            if (Coercion::isEmpty($answer)) {
                [$required, $trigger] = $this->requiredState($field, $sets['required'] ?? [], $context, $fieldKeyById);
                if ($required) {
                    $message = $trigger !== null
                        ? $this->resolveMessage($trigger, $input->locale, self::DEFAULT_REQUIRED_MESSAGE)
                        : self::DEFAULT_REQUIRED_MESSAGE;
                    $errors[] = new SemanticError($field->key, 'field_required', $message);
                }

                continue; // an empty field's constraints are not evaluated
            }

            $selfContext = new EvaluationContext($effectiveAnswers, $answer);
            foreach ($sets['constraint'] ?? [] as $unit) {
                $passes = count($unit) === 1
                    ? $this->rules->passesConstraint($unit[0], $field->key, $answer, $selfContext, $fieldKeyById)
                    : $this->rules->passesConstraintGroup($unit, $field->key, $answer, $selfContext, $fieldKeyById);

                if (! $passes) {
                    $representative = $this->representative($unit);
                    $errors[] = new SemanticError(
                        $field->key,
                        $this->ruleId($representative),
                        $this->resolveMessage($representative, $input->locale, self::DEFAULT_CONSTRAINT_MESSAGE),
                    );
                }
            }
        }

        return $errors;
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
