<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\SubmissionSource;
use App\Models\Form;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPdfPresenter;
use App\Services\Submissions\SubmissionPipeline;
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
| Increment H21c — the third obligation in Doc #27 §9's H21c bullet.
|--------------------------------------------------------------------------
| "…and a cross-check that the encode path's step visibility agrees with `SubmissionPdfPresenter`'s
| taken-path section drop — if those two disagree, one of them is lying about what the respondent saw."
|
| Two PHP projections of the same idea have coexisted since H17, and the `StepProjection` docblock names the
| pair: `SubmissionPdfPresenter::answerBlocks()` groups by section and drops a whole block on
| `sectionRelevance` (H17), while `StepProjection` filters a total order by three predicates (H21a). They are
| reached by different callers, tested in different files, and nothing until now compared them.
|
| THE PDF PRESENTER IS DELIBERATELY NOT REFACTORED ONTO `StepProjection`, and the reason is not timidity.
| The two answer overlapping but distinct questions, and the PDF's extra vocabulary is load-bearing:
|
|   - An added-nothing repeatable section keeps its heading and says "No entries." (H6b's amendment A9). The
|     step model has no way to express "shown, and empty" — a repeat step with zero instances is vacuously
|     VISIBLE, which is the opposite claim.
|   - An answered-blank field renders an em-dash; a pruned one is absent. `fieldKeys` carries no such
|     distinction and is deliberately unfiltered by relevance.
|
| Collapsing them would mean deleting one of those, so the honest collapse is this cross-check — which is
| exactly what §9 asks for in words. What it pins is the SECTION-LEVEL agreement, the only claim both make.
|
| Two exclusions, asserted rather than glossed: the synthetic `__lead__` step (the PDF's ungrouped block
| carries no key at all) and the `EMPTY_REPEAT` notice block described above.
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
 * A published router: a `role` gate leading, then a staff branch, a visitor branch, and an ungated tail. The
 * tail is what stops this degenerating into "one section on, one section off" — a projection that simply
 * echoed the gate would still have to get the tail right.
 */
function pdfCrossCheckForm(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Router');
    $draft = $form->draftVersion;

    addFormField($draft, $owner, 'role', FieldType::ShortText, 1);

    $staff = FormSection::create([
        'form_version_id' => $draft->id, 'key' => 'staff', 'label' => 'Staff details',
        'sequence' => 2, 'relevant_expression' => "\${role} = 'staff'",
    ]);
    addFormField($draft, $owner, 'staff_number', FieldType::ShortText, 3, ['form_section_id' => $staff->id]);

    $visitor = FormSection::create([
        'form_version_id' => $draft->id, 'key' => 'visitor', 'label' => 'Visitor details',
        'sequence' => 4, 'relevant_expression' => "\${role} = 'visitor'",
    ]);
    addFormField($draft, $owner, 'visiting_whom', FieldType::ShortText, 5, ['form_section_id' => $visitor->id]);

    $closing = FormSection::create([
        'form_version_id' => $draft->id, 'key' => 'closing', 'label' => 'Anything else', 'sequence' => 6,
    ]);
    addFormField($draft, $owner, 'comments', FieldType::ShortText, 7, ['form_section_id' => $closing->id]);

    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

/**
 * The persisted answer document, read through the same accessor {@see SubmissionPdfPresenter::answersOf()}
 * uses. The relation is `answers` (a HasOne carrying its own `answers` column), so the path is doubled.
 *
 * @return array<string, mixed>
 */
function storedAnswersOf(Submission $submission): array
{
    $answers = data_get($submission, 'answers.answers');

    return is_array($answers) ? $answers : [];
}

/**
 * The section keys the encode path's step model would show for this answer set — the SAME computation
 * `Encode.vue` runs client-side, `__lead__` dropped because the PDF has no equivalent for it.
 *
 * @param  array<string, mixed>  $answers
 * @return list<string>
 */
function encodeSectionSteps(FormVersion $version, array $answers, string $now): array
{
    /** @var Collection<int, FormSection> $sections */
    $sections = $version->sections()->orderBy('sequence')->get();
    $fields = $version->fields()->orderBy('sequence')->get();

    $result = makeSemanticValidator()->evaluate(new SemanticInput($fields, $sections, new Collection, $answers, 'en', $now));

    return array_values(array_filter(array_map(
        static fn (StepDescriptor $step): ?string => $step->sectionKey,
        StepProjection::of($sections, $fields, $result->sectionRelevance, $result->fieldRelevance, $result->repeatFieldRelevance),
    )));
}

/**
 * The section keys the PDF's taken-path drop keeps, in its own order. Instance blocks are keyed
 * `"{id}#{index}"`, so the id half is taken and de-duplicated.
 *
 * @return list<string>
 */
function pdfSectionBlocks(Submission $submission, FormVersion $version): array
{
    $blocks = app(SubmissionPdfPresenter::class)->present($submission)['blocks'];

    $keyById = $version->sections()->pluck('key', 'id');

    $keys = [];
    foreach ($blocks as $block) {
        if ($block['id'] === null) {
            continue; // the ungrouped block — the PDF's `__lead__`, and it carries no key
        }
        $id = explode('#', (string) $block['id'])[0];
        $key = $keyById->get($id);
        if ($key !== null && ! in_array($key, $keys, true)) {
            $keys[] = $key;
        }
    }

    return $keys;
}

/**
 * Submit through the real pipeline so the stored document is the PRUNED one the PDF replays over — the whole
 * point of H17's re-derivation. A hand-written `Submission::create` would skip Stage 3 and hand the PDF a
 * document the pipeline would never have persisted.
 *
 * @param  array<string, mixed>  $answers
 */
function submitForCrossCheck(Form $form, User $owner, array $answers): Submission
{
    $version = FormVersion::query()->whereKey($form->current_published_version_id)->firstOrFail();

    return app(SubmissionPipeline::class)->submit(new SubmissionPayload(
        version: $version,
        answers: $answers,
        source: SubmissionSource::Manual,
        respondentUserId: $owner->id,
    ))->submission;
}

it('agrees with the PDF about which sections the respondent saw', function (array $answers, array $expected): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);

    $form = pdfCrossCheckForm($tenant, $owner);
    $version = FormVersion::query()->whereKey($form->current_published_version_id)->firstOrFail();

    $submission = submitForCrossCheck($form, $owner, $answers);

    // THE CLOCK IS PINNED ON BOTH SIDES, to the submission's own finalisation instant. H17 gave
    // `SemanticValidator::validate()` its trailing `?string $now` for exactly this: a `today()`-dependent
    // condition judged against "whenever someone clicked Generate" would make the two disagree for a reason
    // that has nothing to do with either projection.
    $at = $submission->finalized_at ?? $submission->submitted_at ?? $submission->created_at;
    $now = $at?->toIso8601String();
    expect($now)->toBeString();

    // Read the persisted document exactly as the PDF does. Asserted non-empty on purpose: reaching for the
    // wrong relation here yields `[]`, every gate then reads ABSENT, and the cross-check passes for the one
    // case whose branches are all off — which is how this assertion was wrong the first time it was written.
    $stored = storedAnswersOf($submission->refresh());
    expect($stored)->not->toBeEmpty()->and($stored)->toHaveKey('role');

    $steps = encodeSectionSteps($version, $stored, (string) $now);
    $pdf = pdfSectionBlocks($submission, $version);

    expect($steps)->toBe($expected)
        ->and($pdf)->toBe($expected, 'the PDF and the encode step model disagree about the taken path');
})->with([
    // Each row is a genuinely different taken path, and each drops a DIFFERENT section — a cross-check whose
    // cases all prune the same node would pass against a projection that hardcoded it.
    'staff branch' => [['role' => 'staff', 'staff_number' => 'S-1', 'comments' => 'ok'], ['staff', 'closing']],
    'visitor branch' => [['role' => 'visitor', 'visiting_whom' => 'Ada', 'comments' => 'ok'], ['visitor', 'closing']],
    'neither branch' => [['role' => 'contractor', 'comments' => 'ok'], ['closing']],
]);

it('records the two exclusions rather than hiding them', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);

    // A section-less lead field plus an OPTIONAL repeatable section left empty — the two shapes the
    // cross-check above deliberately filters out.
    $form = app(FormService::class)->create($tenant, $owner, 'Edges');
    $draft = $form->draftVersion;
    addFormField($draft, $owner, 'intro', FieldType::ShortText, 1);
    $hh = FormSection::create([
        'form_version_id' => $draft->id, 'key' => 'hh', 'label' => 'Household members',
        'sequence' => 2, 'is_repeatable' => true,
    ]);
    addFormField($draft, $owner, 'member_name', FieldType::ShortText, 3, ['form_section_id' => $hh->id]);
    app(PublishService::class)->publish($form->refresh(), $owner);
    $form->refresh();

    $version = FormVersion::query()->whereKey($form->current_published_version_id)->firstOrFail();
    $submission = submitForCrossCheck($form, $owner, ['intro' => 'hello', 'hh' => []]);
    $at = ($submission->finalized_at ?? $submission->submitted_at ?? $submission->created_at)?->toIso8601String();

    $blocks = app(SubmissionPdfPresenter::class)->present($submission->refresh())['blocks'];

    // EXCLUSION 1 — the lead block. The step model calls it `__lead__`; the PDF emits it with `id => null`
    // and no key at all, so there is nothing to compare it BY. Both agree it is present, which is the most
    // the two vocabularies can say to each other.
    $ungrouped = array_values(array_filter($blocks, static fn (array $b): bool => $b['id'] === null));
    expect($ungrouped)->toHaveCount(1)
        ->and(array_column($ungrouped[0]['fields'], 'key'))->toContain('intro');

    $stepKeys = array_map(
        static fn (StepDescriptor $s): string => $s->key,
        StepProjection::of(
            $version->sections()->orderBy('sequence')->get(),
            $version->fields()->orderBy('sequence')->get(),
            ...(function () use ($version, $submission, $at): array {
                $r = makeSemanticValidator()->evaluate(new SemanticInput(
                    $version->fields()->orderBy('sequence')->get(),
                    $version->sections()->orderBy('sequence')->get(),
                    new Collection,
                    storedAnswersOf($submission),
                    'en',
                    (string) $at,
                ));

                return [$r->sectionRelevance, $r->fieldRelevance, $r->repeatFieldRelevance];
            })(),
        ),
    );
    expect($stepKeys)->toContain(StepProjection::LEAD_STEP_KEY);

    // EXCLUSION 2 — the added-nothing repeat. The PDF keeps the heading and SAYS SO; the step model keeps the
    // step because zero instances is vacuously visible. Same verdict, incompatible vocabulary: "shown and
    // empty" is a sentence only one of them can form, which is why the collapse §9 muses about would have to
    // delete it.
    $empty = array_values(array_filter($blocks, static fn (array $b): bool => ($b['notice'] ?? null) !== null));
    expect($empty)->toHaveCount(1)
        ->and($empty[0]['notice'])->toBe('No entries.')
        ->and($empty[0]['fields'])->toBe([])
        ->and($stepKeys)->toContain('hh');
});
