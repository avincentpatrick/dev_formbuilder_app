<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\FormField;
use App\Models\FormSection;
use App\Services\Validation\SemanticInput;
use App\Support\Forms\StepDescriptor;
use App\Support\Forms\StepProjection;
use Illuminate\Support\Collection;

/**
 * The PHP half of H21a's fifth R3 mirror pair (Doc #27 §9, amendment A6).
 *
 * This file and `resources/public-runtime/__tests__/steps.test.ts` drive the SAME fixture —
 * `tests/fixtures/step-projection.json` — through {@see StepProjection::of()} and through the guest store's
 * `visibleSteps`. Each side runs its OWN engine over the fixture's expressions and answers, so a difference
 * in the resulting step list is a projection difference and nothing else: the two evaluators are already
 * held equal by the golden corpus.
 *
 * WHY A FIXTURE AND NOT A SOURCE-PARSING TEST. Doc #27 §9 prescribed the H17 `PdfFieldRole` device (a test
 * that regex-parses the TypeScript). That device works there because it parses actual DATA — `RENDERS_NOTHING`'s
 * members ARE the rule. A step projection's rule is control flow, so parsing it would yield a list of
 * predicate NAMES, and either side could implement one with inverted polarity and still pass. That is exactly
 * the vacuous shape §9's own closing caution warns about, so the mirror is behavioural instead.
 *
 * The fixture is deliberately NOT under `tests/golden/`: it carries no `grammar_version` key and no manifest,
 * so it moves neither the 296-site count nor the 114-vector total, and it is not a fifth corpus. §9 rejected
 * a `tests/golden/steps/` corpus because the language-neutral vector shape cannot carry a section list,
 * per-section sequences, a repeat topology or a field→section map. A purpose-built fixture carries all four.
 *
 * Unit, not Feature: `tests/Pest.php` binds `TestCase` to `Feature` only, so there is no container and no
 * database here — the projection is pure and the validator is hand-built by `makeSemanticValidator()`.
 */

/**
 * @return list<array<string, mixed>>
 */
function stepProjectionFixture(): array
{
    $path = dirname(__DIR__, 2).'/fixtures/step-projection.json';
    $raw = file_get_contents($path);

    expect($raw)->toBeString("step-projection.json must be readable at {$path}");

    /** @var list<array<string, mixed>> $cases */
    $cases = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);

    return $cases;
}

/**
 * Build the unsaved models one fixture case describes. Ids are synthetic and equal to the keys, exactly as
 * the frozen snapshot is FK-by-key — which is also what the TypeScript side does.
 *
 * @param  array<string, mixed>  $case
 * @return array{0: Collection<int, FormSection>, 1: Collection<int, FormField>}
 */
function stepProjectionModels(array $case): array
{
    /** @var list<array<string, mixed>> $rawSections */
    $rawSections = $case['sections'] ?? [];
    /** @var list<array<string, mixed>> $rawFields */
    $rawFields = $case['fields'] ?? [];

    $sections = new Collection(array_map(static fn (array $s) => makeSchemaSection([
        'id' => $s['key'],
        'key' => $s['key'],
        'sequence' => $s['sequence'],
        'is_repeatable' => ($s['repeatable'] ?? false) === true,
        'min_instances' => $s['min'] ?? null,
        'max_instances' => $s['max'] ?? null,
        'relevant_expression' => $s['relevant'] ?? null,
    ]), $rawSections));

    $fields = new Collection(array_map(static fn (array $f) => makeSchemaField([
        'id' => $f['key'],
        'key' => $f['key'],
        'form_section_id' => $f['section'] ?? null,
        'field_type' => FieldType::from($f['field_type'] ?? 'short_text'),
        'sequence' => $f['sequence'],
        'relevant_expression' => $f['relevant'] ?? null,
    ]), $rawFields));

    return [$sections, $fields];
}

it('projects the same step list the guest runtime does', function (array $case): void {
    [$sections, $fields] = stepProjectionModels($case);

    /** @var array<string, mixed> $answers */
    $answers = $case['answers'] ?? [];

    $result = makeSemanticValidator()->evaluate(new SemanticInput(
        $fields,
        $sections,
        new Collection,
        $answers,
    ));

    $steps = StepProjection::of(
        $sections,
        $fields,
        $result->sectionRelevance,
        $result->fieldRelevance,
        $result->repeatFieldRelevance,
    );

    expect(array_map(static fn (StepDescriptor $s): string => $s->key, $steps))
        ->toBe($case['expected_steps'], "step-projection case: {$case['name']}");
})->with(function (): array {
    // Keyed by case name so a failure names the shape rather than dumping the whole fixture row.
    $dataset = [];
    foreach (stepProjectionFixture() as $case) {
        $dataset[$case['name']] = [$case];
    }

    return $dataset;
});

it('reads a non-trivial fixture with unique case names', function (): void {
    // Anti-vacuity, the `PdfFieldRoleTest` shape: a fixture that failed to load, or a dataset that silently
    // collapsed, would make every assertion above pass by never running.
    $cases = stepProjectionFixture();

    expect(count($cases))->toBeGreaterThanOrEqual(16);

    $names = array_column($cases, 'name');
    expect($names)->toHaveCount(count(array_unique($names)), 'fixture case names must be unique');

    // At least one case must expect an EMPTY list and at least one a MULTI-step list, or a projection that
    // always returned [] — or always returned every section — would satisfy the whole file.
    $expected = array_column($cases, 'expected_steps');
    expect(array_filter($expected, static fn (array $steps): bool => $steps === []))->not->toBeEmpty();
    expect(array_filter($expected, static fn (array $steps): bool => count($steps) > 1))->not->toBeEmpty();
});

it('reserves the lead step key against the two authoring paths that validate keys', function (): void {
    // Doc #27 §2.1 owes the `__lead__` reservation a test, not a migration, because it is a convention with
    // TWO verified enforcers and TWO unverified writers — `SchemaBlueprintMaterializer` (form templates) and
    // the field library both write through `Model::create` with no FormRequest in the path, which is the same
    // shape Doc #26 recorded for `*_translations`.
    //
    // What this pins is the RULES, restated from their sources so a change to either reddens here; the call
    // sites themselves are exercised by `FormSectionRoutesTest` and the XLSForm import suite. Both rules are
    // private to their classes, so asserting them any closer would mean adding a test-only seam to
    // production code for no behavioural gain.
    expect(StepProjection::LEAD_STEP_KEY)->toBe('__lead__');

    // `UpdateSectionRequest`: `regex:/^[a-z][a-z0-9_]*$/` — a leading underscore is rejected outright.
    expect(preg_match('/^[a-z][a-z0-9_]*$/', StepProjection::LEAD_STEP_KEY))->toBe(0);

    // `XlsformImportParser::sanitizeKey()`: anything not starting with `[a-z]` is prefixed `x_`, so an
    // imported `__lead__` lands on `x___lead__` and can never collide with the sentinel.
    expect(preg_match('/^[a-z]/', StepProjection::LEAD_STEP_KEY))->toBe(0);
});
