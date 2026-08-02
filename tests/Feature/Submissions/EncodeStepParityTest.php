<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\PdfFieldRole;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\EncodeFormPresenter;
use App\Services\Submissions\PublicFormPresenter;
use App\Services\Validation\SemanticInput;
use App\Support\Forms\StepDescriptor;
use App\Support\Forms\StepProjection;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment H21c — "the same step list for the same version and answer set" (Doc #27 §9).
|--------------------------------------------------------------------------
| The first test in this repository to compare the two fill channels at all, and it drives the SAME sixteen
| cases of `tests/fixtures/step-projection.json` that H21a's mirror pair drives — this file is the fixture's
| THIRD consumer, after `tests/Unit/Forms/StepProjectionTest.php` and
| `resources/public-runtime/__tests__/steps.test.ts`.
|
| WHAT MAKES THE PARITY REAL, AND WHY IT IS NOT ASSERTED AS "run both projections and compare".
| ---------------------------------------------------------------------------------------------
| After H21c the encode page mounts the guest runtime's own `createFormRuntime()` — the identical function
| `steps.test.ts` drives — so its step list for a live answer set is not a second implementation that could
| drift from the guest's. It is the SAME implementation. Asserting "both produce X" in PHP would therefore be
| asserting a tautology about one function, dressed up as a cross-channel test: exactly the vacuous shape
| Doc #27 §9's closing caution warns about.
|
| The link that can actually break is the INPUT. The guest store is fed `version.schema`, the frozen
| snapshot; the encode presenter has always built its own re-projected `blocks` from live rows and shipped no
| `relevant_expression` at all (§7). So the load-bearing assertion is that both channels hand their store the
| SAME BYTES — and once that holds, "same store + same schema + same answers ⇒ same steps" is true by
| construction rather than by a hand-copied predicate list.
|
| Four assertions per case, each pinning a different way the port could go wrong:
|
|   A. The encode payload's `version.schema` is byte-identical to the guest channel's for the same version.
|      Catches a re-projected or partial schema, and catches `relevant_expression` never reaching the client.
|   B. `steps` equals the projection computed independently from the LIVE ROWS under an empty answer
|      document. Catches the presenter mis-wiring the projection — most sharply, handing it the
|      OMITTED-filtered field list instead of every field (see the `$allFields` note in `steps()`).
|   C. For a case whose fixture answers are empty, `steps` equals `expected_steps` outright — the fixture
|      driving the encode path end to end.
|   D. Rebuilding sections and fields FROM THE SHIPPED SNAPSHOT and projecting under the case's own answers
|      reproduces `expected_steps`. This is the answer-bearing half, and it is the assertion that reddens if
|      the snapshot the encode page hands its engine has lost the branching — every gated section would come
|      back visible.
|
| The anti-vacuity guards are the `PdfFieldRoleTest` ones, restated here rather than assumed: a minimum case
| count, unique names, and at least one empty and one multi-step expectation among the cases each assertion
| actually reaches.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * @return list<array<string, mixed>>
 */
function encodeParityFixture(): array
{
    $path = dirname(__DIR__, 2).'/fixtures/step-projection.json';
    $raw = file_get_contents($path);

    expect($raw)->toBeString("step-projection.json must be readable at {$path}");

    /** @var list<array<string, mixed>> $cases */
    $cases = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);

    return $cases;
}

/**
 * Build and PUBLISH a real form from one fixture case. Real rows through the real publish, deliberately:
 * the unit mirror pair drives hand-built unsaved models, so a key rewrite, a sequence renumbering or a
 * `relevant_expression` lost in the snapshot freeze is invisible to it and visible here.
 *
 * @param  array<string, mixed>  $case
 */
function publishFixtureCaseForm(Tenant $tenant, User $owner, array $case): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Parity: '.$case['name']);
    $draft = $form->draftVersion;

    /** @var list<array<string, mixed>> $rawSections */
    $rawSections = $case['sections'] ?? [];
    /** @var list<array<string, mixed>> $rawFields */
    $rawFields = $case['fields'] ?? [];

    $sectionIdByKey = [];
    foreach ($rawSections as $s) {
        $section = FormSection::create([
            'form_version_id' => $draft->id,
            'key' => $s['key'],
            'label' => ucfirst((string) $s['key']),
            'sequence' => $s['sequence'],
            'is_repeatable' => ($s['repeatable'] ?? false) === true,
            'min_instances' => $s['min'] ?? null,
            'max_instances' => $s['max'] ?? null,
            'relevant_expression' => $s['relevant'] ?? null,
        ]);
        $sectionIdByKey[$s['key']] = $section->id;
    }

    foreach ($rawFields as $f) {
        addFormField(
            $draft,
            $owner,
            (string) $f['key'],
            FieldType::from($f['field_type'] ?? 'short_text'),
            (int) $f['sequence'],
            [
                'form_section_id' => isset($f['section']) ? $sectionIdByKey[$f['section']] : null,
                'relevant_expression' => $f['relevant'] ?? null,
            ],
        );
    }

    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

function publishedVersionOf(Form $form): FormVersion
{
    return FormVersion::query()->whereKey($form->current_published_version_id)->firstOrFail();
}

/**
 * The step keys a projection produces over the given sections/fields and answer document — the caller's own
 * engine run, never a re-read of what the presenter computed.
 *
 * @param  Collection<int, FormSection>  $sections
 * @param  Collection<int, FormField>  $fields
 * @param  array<string, mixed>  $answers
 * @return list<string>
 */
function projectedStepKeys(Collection $sections, Collection $fields, array $answers): array
{
    $result = makeSemanticValidator()->evaluate(new SemanticInput($fields, $sections, new Collection, $answers));

    return array_map(
        static fn (StepDescriptor $step): string => $step->key,
        StepProjection::of($sections, $fields, $result->sectionRelevance, $result->fieldRelevance, $result->repeatFieldRelevance),
    );
}

/**
 * Rebuild unsaved sections/fields from a SHIPPED SNAPSHOT, the way the client's `buildRenderModel()` does:
 * FK-by-key (so ids are the keys), ordered by `sequence` because the serializer canonicalises by KEY and
 * every consumer re-sorts.
 *
 * @param  array<string, mixed>  $snapshot
 * @return array{0: Collection<int, FormSection>, 1: Collection<int, FormField>}
 */
function modelsFromSnapshot(array $snapshot): array
{
    /** @var list<array<string, mixed>> $rawSections */
    $rawSections = $snapshot['sections'] ?? [];
    /** @var list<array<string, mixed>> $rawFields */
    $rawFields = $snapshot['fields'] ?? [];

    usort($rawSections, static fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence']);
    usort($rawFields, static fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence']);

    $sections = new Collection(array_map(static fn (array $s) => makeSchemaSection([
        'id' => $s['key'],
        'key' => $s['key'],
        'sequence' => $s['sequence'],
        'is_repeatable' => $s['is_repeatable'] === true,
        'min_instances' => $s['min_instances'],
        'max_instances' => $s['max_instances'],
        'relevant_expression' => $s['relevant_expression'],
    ]), $rawSections));

    $fields = new Collection(array_map(static fn (array $f) => makeSchemaField([
        'id' => $f['key'],
        'key' => $f['key'],
        'form_section_id' => $f['section_key'],
        'field_type' => FieldType::from($f['field_type']),
        'sequence' => $f['sequence'],
        'relevant_expression' => $f['relevant_expression'],
    ]), $rawFields));

    return [$sections, $fields];
}

it('hands the encode page the same schema the guest gets, and projects it the same way', function (array $case): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);

    $form = publishFixtureCaseForm($tenant, $owner, $case);
    $version = publishedVersionOf($form);

    $encode = app(EncodeFormPresenter::class)->present($form, $version);
    $guest = app(PublicFormPresenter::class)->present($form, $version);

    $name = $case['name'];

    // ── A. The two channels hand their store the SAME BYTES. ────────────────────────────────────────────
    expect($encode['version']['schema'])->toBe($guest['version']['schema'], "schema parity: {$name}")
        ->and($encode['version']['checksum'])->toBe($guest['version']['checksum'], "checksum parity: {$name}");

    $encodeSteps = array_column($encode['steps'], 'key');

    // ── B. The presenter wired the projection correctly, judged against an independent run over the ─────
    //       LIVE rows under the empty answer document `present()` uses.
    $liveSections = $version->sections()->orderBy('sequence')->get();
    $liveFields = $version->fields()->orderBy('sequence')->get();
    expect($encodeSteps)->toBe(projectedStepKeys($liveSections, $liveFields, []), "presenter wiring: {$name}");

    // ── C. The fixture drives the encode path outright wherever its answers are empty. ──────────────────
    /** @var array<string, mixed> $answers */
    $answers = $case['answers'] ?? [];
    if ($answers === []) {
        expect($encodeSteps)->toBe($case['expected_steps'], "encode payload steps: {$name}");
    }

    // ── D. The answer-bearing half: rebuild from the SHIPPED SNAPSHOT and take the case's own path. ─────
    //       Reddens if the snapshot the encode page hands its engine has lost the branching.
    [$snapSections, $snapFields] = modelsFromSnapshot($encode['version']['schema']);
    expect(projectedStepKeys($snapSections, $snapFields, $answers))
        ->toBe($case['expected_steps'], "snapshot-driven taken path: {$name}");
})->with(function (): array {
    // Keyed by case name so a failure names the shape rather than dumping the whole fixture row.
    $dataset = [];
    foreach (encodeParityFixture() as $case) {
        $dataset[$case['name']] = [$case];
    }

    return $dataset;
});

it('keeps EncodeFormPresenter::OMITTED a strict subset of the respondent renders-nothing set', function (): void {
    // The invariant that makes `steps()`'s use of the COMPLETE field list a contract rather than a
    // preference, and it exists because a mutation reddened nothing: passing the OMITTED-filtered list to
    // `StepProjection` is a no-op TODAY, purely because {page_break, calculated} ⊂ {hidden, calculated,
    // page_break}. Nothing enforced that, and the two lists answer different questions — "never a manually
    // entered answer" is not "the respondent sees nothing". Add a type to OMITTED that a respondent DOES
    // see and the encode step list would quietly stop matching the one the respondent walked.
    //
    // Read reflectively because OMITTED is private, which is correct — a test-only accessor on production
    // code would be a worse trade than one ReflectionClass call in a test.
    /** @var list<FieldType> $omitted */
    $omitted = (new ReflectionClass(EncodeFormPresenter::class))->getConstant('OMITTED');

    expect($omitted)->not->toBeEmpty('the constant must still exist and still hold FieldType cases');

    foreach ($omitted as $type) {
        expect(PdfFieldRole::for($type))->toBe(
            PdfFieldRole::Omitted,
            "EncodeFormPresenter::OMITTED holds {$type->value}, which the respondent DOES see — the encode "
            .'step projection must be handed the complete field list, and `blocks` now hides a real step.',
        );
    }

    // Non-vacuity: the relation must be STRICT, or the two sets have converged and H7's deliberate asymmetry
    // (a keyer sees a `url`-sourced hidden field; a respondent never does) has been flattened.
    $respondentOmitted = array_filter(
        FieldType::cases(),
        static fn (FieldType $type): bool => PdfFieldRole::for($type) === PdfFieldRole::Omitted,
    );
    expect(count($respondentOmitted))->toBeGreaterThan(count($omitted));
});

it('reads a non-trivial fixture that discriminates both halves of the parity claim', function (): void {
    // Anti-vacuity, the `PdfFieldRoleTest` shape carried by both existing drivers. A fixture that failed to
    // load, or a dataset that silently collapsed, would make every assertion above pass by never running.
    $cases = encodeParityFixture();

    expect(count($cases))->toBeGreaterThanOrEqual(16);

    $names = array_column($cases, 'name');
    expect($names)->toHaveCount(count(array_unique($names)), 'fixture case names must be unique');

    $expected = array_column($cases, 'expected_steps');
    expect(array_filter($expected, static fn (array $steps): bool => $steps === []))->not->toBeEmpty();
    expect(array_filter($expected, static fn (array $steps): bool => count($steps) > 1))->not->toBeEmpty();

    // Assertion C only reaches the empty-answer cases and assertion D only DISCRIMINATES on the rest, so
    // both subsets must be non-trivial or half this file is decoration. C additionally needs an empty and a
    // multi-step expectation among the cases it reaches, for the same reason the whole-fixture guard does.
    $emptyAnswer = array_values(array_filter($cases, static fn (array $c): bool => ($c['answers'] ?? []) === []));
    $withAnswers = array_values(array_filter($cases, static fn (array $c): bool => ($c['answers'] ?? []) !== []));

    expect(count($emptyAnswer))->toBeGreaterThanOrEqual(4);
    expect(count($withAnswers))->toBeGreaterThanOrEqual(4);

    $reachedByC = array_column($emptyAnswer, 'expected_steps');
    expect(array_filter($reachedByC, static fn (array $steps): bool => $steps === []))->not->toBeEmpty();
    expect(array_filter($reachedByC, static fn (array $steps): bool => count($steps) > 1))->not->toBeEmpty();

    // At least one answer-bearing case must expect FEWER steps than its section count, or assertion D would
    // pass against a projection that ignored relevance entirely.
    $prunes = array_filter(
        $withAnswers,
        static fn (array $c): bool => count($c['expected_steps']) < count($c['sections'] ?? []),
    );
    expect($prunes)->not->toBeEmpty('at least one answer-bearing case must actually prune a section');
});
