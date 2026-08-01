<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Enums\PdfFieldRole;
use App\Models\FormField;
use App\Models\FormSection;
use App\Services\Validation\SemanticValidator;
use Illuminate\Support\Collection;

/**
 * The step list a respondent would see, computed in PHP — the twin of the guest runtime's `visibleSteps`
 * (`resources/public-runtime/composables/useFormRuntime.ts`). Increment H21a, Doc #27 §2.
 *
 * THE STEP GRAPH IS A TOTAL ORDER FILTERED BY RELEVANCE, and this class declines to call it a graph. It
 * walks sections in `sequence` order, drops the ones that fail the three visibility predicates, and emits a
 * {@see StepDescriptor} for each survivor. There is no successor edge, no branch target and no author-drawn
 * adjacency of any kind: every "branch" in this product is a PREDICATE ON A NODE, never an edge between
 * nodes.
 *
 * PURE AND STATIC — no container, no database, no expression engine, exactly like {@see LocaleVariant} and
 * `DraftCompleteness`. It consumes the MASKS a `SemanticResult` already carries rather than evaluating
 * anything itself, and that split is deliberate twice over: it keeps the projection out of the dual-engine
 * settle loop (so this class needs no TypeScript mirror of its own), and it lets the shared parity fixture
 * pin the PROJECTION alone, leaving the evaluator to the golden corpus that already pins it.
 *
 * PARITY. `tests/Unit/Forms/StepProjectionTest.php` and
 * `resources/public-runtime/__tests__/steps.test.ts` drive the SAME fixture — `tests/fixtures/step-projection.json`
 * — through this class and through `visibleSteps`. That fixture is deliberately NOT under `tests/golden/`:
 * it carries no `grammar_version` key and no manifest, so it moves neither the 296-site count nor the
 * 114-vector total, and it is not a fifth corpus. Doc #27 §9 rejected a `tests/golden/steps/` corpus because
 * the language-neutral vector shape cannot carry a section list, per-section sequences, a repeat topology or
 * a field→section map. A purpose-built fixture carries all four.
 *
 * WHY NOT A SOURCE-PARSING TEST, which §9 originally prescribed (amendment A6): the H17 `PdfFieldRole`
 * device works because it parses actual DATA — `RENDERS_NOTHING`'s members ARE the rule. A step projection's
 * rule is control flow, so parsing it would yield a list of predicate NAMES, and this class could implement
 * any of them with inverted polarity and still pass. That is precisely the vacuous-test shape §9's own
 * closing caution warns about.
 *
 * NOT the first PHP projection in the codebase, and that is recorded rather than glossed:
 * `SubmissionPdfPresenter::answerBlocks()` already groups by section and drops a whole block on
 * `sectionRelevance`. H21c is the increment that collapses them — it makes `EncodeFormPresenter` consume
 * this class and owes the cross-check that the encode path and the PDF agree about what the respondent saw.
 */
final class StepProjection
{
    /**
     * The synthetic leading step holding every renderable field with no section. It is emitted first, before
     * any section step, and it is the one step that can never carry a `relevant_expression` of its own,
     * because it has no `form_sections` row to hang one on.
     *
     * The reservation is structurally safe from the two authoring paths that validate keys —
     * `UpdateSectionRequest`'s `regex:/^[a-z][a-z0-9_]*$/` rejects a leading underscore, and
     * `XlsformImportParser::sanitizeKey()` prefixes `x_` to anything not starting with a letter — but
     * `SchemaBlueprintMaterializer` (form templates) and the field library write through `Model::create`
     * with no FormRequest in the path. So it is a convention with two verified enforcers and two unverified
     * writers, which is the shape Doc #26 recorded for `*_translations`. Doc #27 §2.1 owes it a test, not a
     * migration.
     */
    public const LEAD_STEP_KEY = '__lead__';

    /**
     * Project the step list. Sections and fields are consumed in the order given — callers pass them
     * ordered by `sequence`, which is what {@see SemanticValidator::validate()}
     * already loads.
     *
     * The three predicates, all of which must hold for a section step to be emitted (Doc #27 §2.2):
     *
     *  1. THE SECTION IS RELEVANT. Read fail-OPEN (`?? true`), matching `SubmissionPdfPresenter` rather than
     *     the settle loop's fail-closed repeat gate: a section missing from the mask is a schema the caller
     *     built by hand, and showing one step too many is a cosmetic fault where hiding one is a lie about
     *     what was asked.
     *  2. THE SECTION HOLDS AT LEAST ONE FIELD THAT RENDERS SOMETHING. A section holding nothing but
     *     `hidden` / `calculated` / `page_break` has no question in it, so it vanishes entirely rather than
     *     rendering a heading over a blank panel.
     *  3. THE SECTION HOLDS AT LEAST ONE FIELD THAT IS CURRENTLY RELEVANT. New in H21a. Predicate 2 is a
     *     STATIC TYPE test, so without this a relevant section whose every field is individually gated off
     *     renders a heading, a Next button and zero questions — the grayed-out-skipped step
     *     `form-filling-ux-flow.md` §3.2 explicitly rejected, arriving through a different door.
     *
     * For a REPEATABLE section predicate 3 reads `$repeatFieldRelevance`, not `$fieldRelevance`: a repeat
     * member is absent from the flat mask entirely (it is not `false` there, it is missing), so testing the
     * flat mask would annihilate every repeatable step. ZERO INSTANCES IS VACUOUSLY VISIBLE — the step is
     * what lets the respondent add the first one.
     *
     * `fieldKeys` is NOT filtered by relevance, mirroring the guest store: consumers that care already gate
     * on the masks themselves, and filtering here would silently change what an error summary addresses.
     *
     * @param  Collection<int, FormSection>  $sections  ordered by `sequence`
     * @param  Collection<int, FormField>  $fields  ordered by `sequence`
     * @param  array<string, bool>  $sectionRelevance  section key => relevant
     * @param  array<string, bool>  $fieldRelevance  top-level field key => relevant
     * @param  array<string, list<array<string, bool>>>  $repeatFieldRelevance  section key => one member mask per instance
     * @return list<StepDescriptor>
     */
    public static function of(
        Collection $sections,
        Collection $fields,
        array $sectionRelevance,
        array $fieldRelevance,
        array $repeatFieldRelevance = [],
    ): array {
        $sectionKeyById = [];
        foreach ($sections as $section) {
            $sectionKeyById[$section->id] = $section->key;
        }

        /** @var list<StepDescriptor> $steps */
        $steps = [];

        $leadFields = self::rendering($fields->filter(
            static fn (FormField $field): bool => $field->form_section_id === null,
        ));

        // The lead block takes predicate 3 too — its fields are top-level, so the flat mask covers them, and
        // a fully-gated lead block is exactly the dead step predicate 3 abolishes.
        if ($leadFields !== [] && self::anyFlatRelevant($leadFields, $fieldRelevance)) {
            $steps[] = new StepDescriptor(
                self::LEAD_STEP_KEY,
                null,
                array_map(static fn (FormField $field): string => $field->key, $leadFields),
                false,
            );
        }

        foreach ($sections as $section) {
            if (($sectionRelevance[$section->key] ?? true) !== true) {
                continue; // predicate 1
            }

            $members = self::rendering($fields->filter(
                static fn (FormField $field): bool => $field->form_section_id !== null
                    && ($sectionKeyById[$field->form_section_id] ?? null) === $section->key,
            ));

            if ($members === []) {
                continue; // predicate 2
            }

            $isRepeat = $section->is_repeatable === true;

            $currentlyRelevant = $isRepeat
                ? self::anyInstanceRelevant($members, $repeatFieldRelevance[$section->key] ?? [])
                : self::anyFlatRelevant($members, $fieldRelevance);

            if (! $currentlyRelevant) {
                continue; // predicate 3
            }

            $steps[] = new StepDescriptor(
                $section->key,
                $section->key,
                array_map(static fn (FormField $field): string => $field->key, $members),
                $isRepeat,
            );
        }

        return $steps;
    }

    /**
     * Whether the projection is empty — the decidable half of Doc #27 §4.1, used by the publish-time
     * empty-at-open notice. Not merely `of(...) === []` for the reader's benefit; it says what it means.
     *
     * @param  Collection<int, FormSection>  $sections
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, bool>  $sectionRelevance
     * @param  array<string, bool>  $fieldRelevance
     * @param  array<string, list<array<string, bool>>>  $repeatFieldRelevance
     */
    public static function isEmpty(
        Collection $sections,
        Collection $fields,
        array $sectionRelevance,
        array $fieldRelevance,
        array $repeatFieldRelevance = [],
    ): bool {
        return self::of($sections, $fields, $sectionRelevance, $fieldRelevance, $repeatFieldRelevance) === [];
    }

    /**
     * The fields that put a question in front of somebody, in the order given.
     *
     * `PdfFieldRole`'s name is historical — H17 minted it for the PDF — but its content is exactly this
     * question, and it is the `default`-less 31-case match that makes a 32nd field type a compile-time
     * decision rather than a silent inheritance. It is pinned equal to the guest runtime's
     * `RENDERS_NOTHING` in both directions by `tests/Unit/Forms/PdfFieldRoleTest.php`.
     *
     * @param  Collection<int, FormField>  $fields
     * @return list<FormField>
     */
    private static function rendering(Collection $fields): array
    {
        return array_values($fields->filter(
            static fn (FormField $field): bool => PdfFieldRole::for($field->field_type) !== PdfFieldRole::Omitted,
        )->all());
    }

    /**
     * @param  list<FormField>  $fields
     * @param  array<string, bool>  $fieldRelevance
     */
    private static function anyFlatRelevant(array $fields, array $fieldRelevance): bool
    {
        foreach ($fields as $field) {
            if (($fieldRelevance[$field->key] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<FormField>  $members
     * @param  list<array<string, bool>>  $instanceMasks
     */
    private static function anyInstanceRelevant(array $members, array $instanceMasks): bool
    {
        if ($instanceMasks === []) {
            return true; // no instance yet — the step is what lets them add one
        }

        foreach ($instanceMasks as $mask) {
            foreach ($members as $member) {
                if (($mask[$member->key] ?? false) === true) {
                    return true;
                }
            }
        }

        return false;
    }
}
