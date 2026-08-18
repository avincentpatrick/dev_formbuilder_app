<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\SubmissionStatus;
use App\Services\Validation\SemanticInput;
use App\Support\Submissions\FinalizedStatus;
use Illuminate\Support\Collection;

/**
 * Increment I9a — the `submitted` vs `screened_out` predicate, exercised where it lives.
 *
 * Unit, not Feature: `tests/Pest.php` binds `TestCase` to `Feature` only, so there is no container and no
 * database here — {@see FinalizedStatus} is pure and the validator is hand-built by `makeSemanticValidator()`,
 * exactly as `tests/Unit/Forms/StepProjectionTest.php` does one level down.
 *
 * THE POINT OF THIS FILE IS THE BOUNDARY, NOT THE HAPPY PATH. `screened_out` means "the respondent was shown
 * no questions", which is NARROWER than the name suggests, so half the cases below assert `submitted` on
 * shapes a reader would guess are screen-outs. Those are the ones that redden if somebody later "improves"
 * the predicate into "the respondent answered a disqualifying question" — see {@see SubmissionStatus} for why
 * that reading inverts the H7 router case this state exists to protect.
 */

/**
 * The one shape under test: build the models, run the real engine over `$answers`, ask for a status.
 *
 * @param  list<array<string, mixed>>  $sectionSpecs
 * @param  list<array<string, mixed>>  $fieldSpecs
 * @param  array<string, mixed>  $answers
 */
function finalizedStatusFor(array $sectionSpecs, array $fieldSpecs, array $answers): SubmissionStatus
{
    $sections = new Collection(array_map(static fn (array $s) => makeSchemaSection([
        'id' => $s['key'],
        'key' => $s['key'],
        'sequence' => $s['sequence'],
        'is_repeatable' => ($s['repeatable'] ?? false) === true,
        'min_instances' => $s['min'] ?? null,
        'max_instances' => $s['max'] ?? null,
        'relevant_expression' => $s['relevant'] ?? null,
    ]), $sectionSpecs));

    $fields = new Collection(array_map(static fn (array $f) => makeSchemaField([
        'id' => $f['key'],
        'key' => $f['key'],
        'form_section_id' => $f['section'] ?? null,
        'field_type' => FieldType::from($f['field_type'] ?? 'short_text'),
        'sequence' => $f['sequence'],
        'relevant_expression' => $f['relevant'] ?? null,
    ]), $fieldSpecs));

    $result = makeSemanticValidator()->evaluate(new SemanticInput($fields, $sections, new Collection, $answers));

    return FinalizedStatus::for($sections, $fields, $result);
}

/*
|--------------------------------------------------------------------------
| The H7 URL-router form — Doc #27 §4.1's own example, and the flagship case.
|--------------------------------------------------------------------------
| A `hidden` gate in a section of its own (predicate 2 drops that section, because `hidden` is
| PdfFieldRole::Omitted / RENDERS_NOTHING), and every other section gated on its value. This is the exact
| shape the seeded "Branching Router" form has, and the shape `public-runtime-axe.spec.ts` scans bare.
*/

/**
 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
 */
function routerFormShape(): array
{
    return [
        [
            ['key' => 'gate', 'sequence' => 1],
            ['key' => 'staff', 'sequence' => 2, 'relevant' => '${role} = "staff"'],
            ['key' => 'visitor', 'sequence' => 3, 'relevant' => '${role} = "visitor"'],
        ],
        [
            ['key' => 'role', 'section' => 'gate', 'sequence' => 1, 'field_type' => 'hidden'],
            ['key' => 'staff_id', 'section' => 'staff', 'sequence' => 2],
            ['key' => 'visitor_name', 'section' => 'visitor', 'sequence' => 3],
        ],
    ];
}

it('screens out a router form arrived at with no prefill — nothing was ever shown', function (): void {
    [$sections, $fields] = routerFormShape();

    expect(finalizedStatusFor($sections, $fields, []))->toBe(SubmissionStatus::ScreenedOut);
});

it('does NOT screen out the same router form when the gate is prefilled', function (): void {
    // The control. Without this, a predicate that always returned ScreenedOut would satisfy the case above.
    [$sections, $fields] = routerFormShape();

    expect(finalizedStatusFor($sections, $fields, ['role' => 'staff']))->toBe(SubmissionStatus::Submitted);
});

/*
|--------------------------------------------------------------------------
| The boundary: an ordinary screener is NOT a screen-out.
|--------------------------------------------------------------------------
*/

it('leaves an ordinary disqualifying screener as `submitted`, because its own step stayed visible', function (): void {
    // `age` sits in the LEAD block (no section), so `anyFlatRelevant` keeps the lead step whatever the answer.
    // A respondent who answers 15 sees the question they answered, so they were shown something and they
    // consume a capacity slot. This is the honest boundary of the feature, pinned so it is not "fixed".
    $sections = [['key' => 'adults', 'sequence' => 1, 'relevant' => '${age} >= 18']];
    $fields = [
        ['key' => 'age', 'sequence' => 1, 'field_type' => 'integer'],
        ['key' => 'household_size', 'section' => 'adults', 'sequence' => 2, 'field_type' => 'integer'],
    ];

    expect(finalizedStatusFor($sections, $fields, ['age' => 15]))->toBe(SubmissionStatus::Submitted)
        ->and(finalizedStatusFor($sections, $fields, ['age' => 40]))->toBe(SubmissionStatus::Submitted);
});

it('screens out a section that hides ITSELF once answered, leaving nothing behind it', function (): void {
    // The other reachable shape: the screener lives INSIDE the only section, and its own relevance turns
    // false. Now there is genuinely no step left, so this one IS a screen-out — the contrast with the
    // lead-block case above is the whole distinction.
    $sections = [['key' => 'eligibility', 'sequence' => 1, 'relevant' => '${consent} != "no"']];
    $fields = [['key' => 'consent', 'section' => 'eligibility', 'sequence' => 1]];

    expect(finalizedStatusFor($sections, $fields, ['consent' => 'no']))->toBe(SubmissionStatus::ScreenedOut)
        ->and(finalizedStatusFor($sections, $fields, ['consent' => 'yes']))->toBe(SubmissionStatus::Submitted);
});

/*
|--------------------------------------------------------------------------
| Ordinary forms, and the two shapes that look like edge cases and are not.
|--------------------------------------------------------------------------
*/

it('never screens out an ordinary single-step form', function (): void {
    expect(finalizedStatusFor([], [['key' => 'full_name', 'sequence' => 1]], ['full_name' => 'Ada']))
        ->toBe(SubmissionStatus::Submitted);
});

it('screens out a form whose every field renders nothing', function (): void {
    // The sharpest FALSE POSITIVE, recorded rather than prevented: a URL-prefilled acknowledgement form built
    // only from hidden/calculated fields shows nobody anything, so every submission is screened out and its
    // cap never fills. Not fixable by narrowing the predicate (that inverts the router case above), and it is
    // loud rather than silent — the rows land in the inbox by default as "Screened out" pills. Pinned so the
    // behaviour is a known consequence rather than a surprise bug report.
    $fields = [
        ['key' => 'source', 'sequence' => 1, 'field_type' => 'hidden'],
        ['key' => 'campaign', 'sequence' => 2, 'field_type' => 'hidden'],
    ];

    expect(finalizedStatusFor([], $fields, ['source' => 'sms']))->toBe(SubmissionStatus::ScreenedOut);
});

it('does not screen out a repeatable section with zero instances', function (): void {
    // ZERO INSTANCES IS VACUOUSLY VISIBLE (StepProjection's own rule): the step is what lets the respondent
    // add the first one, so it counts as shown. A projection that tested the FLAT mask for repeat members
    // would annihilate this step and screen the respondent out.
    $sections = [['key' => 'roster', 'sequence' => 1, 'repeatable' => true]];
    $fields = [['key' => 'member_name', 'section' => 'roster', 'sequence' => 1]];

    expect(finalizedStatusFor($sections, $fields, []))->toBe(SubmissionStatus::Submitted);
});
