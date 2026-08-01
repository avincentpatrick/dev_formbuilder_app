<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\FormSection;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionInboxPresenter;
use App\Services\Submissions\SubmissionPdfPresenter;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// H17's render model: what the respondent actually saw, and nothing else.
//
// The four differences from SubmissionInboxPresenter each get a test here, because each one would be
// a defect if the inbox's behaviour had simply been reused: the field set (hidden/calculated are
// dropped), relevance (the replay), the fallback copy (em-dash + "No entries." move server-side) and
// locale (field/section labels resolve their translations, which the inbox never did).
//
// EVERY field gets a DISTINCT `sequence` — Doc #26 §3.3 rule 1 as amended (A2) treats a positional TIE
// as a rejection, and `addFormField()` defaults `sequence` to 0.
// EVERY `${` literal is SINGLE-quoted (PHP 8.3 removed `${var}` interpolation).

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * A published form exercising all three PdfFieldRole verdicts plus a conditional field.
 *
 * `state` is relevant only when `country` is 'US' — the branch the PDF must be able to drop.
 * `secret_ref` (hidden) and `doubled` (calculated) hold real values the respondent never saw.
 */
function pdfBranchForm(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Application');
    $version = $form->draftVersion;

    addFormField($version, $owner, 'applicant', FieldType::ShortText, 1);
    addFormField($version, $owner, 'country', FieldType::ShortText, 2);
    addFormField($version, $owner, 'state', FieldType::ShortText, 3, ['relevant_expression' => "\${country} = 'US'"]);
    addFormField($version, $owner, 'age', FieldType::Integer, 4);
    addFormField($version, $owner, 'doubled', FieldType::Calculated, 5, ['config' => ['calculated_formula' => '${age} * 2']]);
    addFormField($version, $owner, 'secret_ref', FieldType::Hidden, 6);
    addFormField($version, $owner, 'consent', FieldType::Note, 7, ['label' => 'I agree to the terms.']);
    addFormField($version, $owner, 'divider', FieldType::PageBreak, 8);

    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

/** @param array<string, mixed> $answers */
function seedPdfSubmission(Form $form, User $owner, array $answers, ?Carbon $finalizedAt = null): Submission
{
    $submission = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, $answers);

    if ($finalizedAt !== null) {
        $submission->forceFill(['finalized_at' => $finalizedAt])->save();
    }

    return $submission->refresh();
}

/**
 * Every row across every block, as `key => value`.
 *
 * @param  array<string, mixed>  $model
 * @return array<string, string>
 */
function pdfRows(array $model): array
{
    $rows = [];
    foreach ($model['blocks'] as $block) {
        foreach ($block['fields'] as $row) {
            $rows[$row['key']] = $row['value'];
        }
    }

    return $rows;
}

it('drops the fields a respondent never saw, which the inbox still renders', function (): void {
    // Difference 1: the field set. The inbox filters on isDataField() (note + page_break only), so it
    // shows `hidden` and `calculated` rows. Asserting BOTH presenters here is the point — this is a
    // deliberate divergence between two shipped surfaces, not an accident, and if someone "fixes" the
    // PDF to match the inbox this test says why they must not.
    $form = pdfBranchForm($this->tenant, $this->owner);
    $submission = seedPdfSubmission($form, $this->owner, [
        'applicant' => 'Ana', 'country' => 'US', 'state' => 'CA', 'age' => '21',
        'doubled' => '42', 'secret_ref' => 'CASE-9',
    ]);

    $pdf = pdfRows(app(SubmissionPdfPresenter::class)->present($submission));
    $inbox = pdfRows(app(SubmissionInboxPresenter::class)->detail($this->owner, $submission));

    expect($pdf)->toHaveKeys(['applicant', 'country', 'state', 'age'])
        ->and($pdf)->not->toHaveKey('secret_ref')
        ->and($pdf)->not->toHaveKey('doubled')
        ->and($pdf)->not->toHaveKey('divider')
        // …and the inbox does show the two the PDF drops, so this is a divergence, not a shared rule.
        ->and($inbox)->toHaveKey('secret_ref')
        ->and($inbox)->toHaveKey('doubled');
});

it('renders a note as prose with no answer slot', function (): void {
    // The one type the PDF shows that the inbox drops — a consent paragraph the respondent read.
    $form = pdfBranchForm($this->tenant, $this->owner);
    $submission = seedPdfSubmission($form, $this->owner, ['applicant' => 'Ana', 'country' => 'PH', 'age' => '21']);

    $model = app(SubmissionPdfPresenter::class)->present($submission);
    $consent = null;
    foreach ($model['blocks'] as $block) {
        foreach ($block['fields'] as $row) {
            if ($row['key'] === 'consent') {
                $consent = $row;
            }
        }
    }

    expect($consent)->not->toBeNull()
        ->and($consent['role'])->toBe('prose')
        ->and($consent['label'])->toBe('I agree to the terms.')
        // Prose never reaches displayValue(), so it gets '' rather than the em-dash a real but
        // unanswered question gets. The two blanks mean different things and must not collapse.
        ->and($consent['value'])->toBe('');

    expect(pdfRows(app(SubmissionInboxPresenter::class)->detail($this->owner, $submission)))
        ->not->toHaveKey('consent');
});

it('omits a field the respondent never reached', function (): void {
    // Difference 2, and the increment's headline. `state` is relevant only when country = US.
    // A non-US submission never showed it, so the PDF must not carry a `state` row at all —
    // not an em-dash row, which would claim they saw the question and declined to answer.
    $form = pdfBranchForm($this->tenant, $this->owner);
    $submission = seedPdfSubmission($form, $this->owner, ['applicant' => 'Ana', 'country' => 'PH', 'age' => '21']);

    $rows = pdfRows(app(SubmissionPdfPresenter::class)->present($submission));

    expect($rows)->toHaveKeys(['applicant', 'country', 'age'])
        ->and($rows)->not->toHaveKey('state')
        // The inbox, by contrast, renders it as an em-dash — indistinguishable from answered-blank.
        ->and(pdfRows(app(SubmissionInboxPresenter::class)->detail($this->owner, $submission)))
        ->toHaveKey('state');
});

it('keeps a field the respondent did reach', function (): void {
    // The other half of the pair. Without this, "drop everything" would pass the test above.
    $form = pdfBranchForm($this->tenant, $this->owner);
    $submission = seedPdfSubmission($form, $this->owner, [
        'applicant' => 'Ana', 'country' => 'US', 'state' => 'CA', 'age' => '21',
    ]);

    expect(pdfRows(app(SubmissionPdfPresenter::class)->present($submission)))
        ->toHaveKey('state')
        ->and(pdfRows(app(SubmissionPdfPresenter::class)->present($submission))['state'])->toBe('CA');
});

it('shows an answered-blank field as an em-dash rather than dropping it', function (): void {
    // The distinction the whole replay exists to preserve: `state` was SHOWN (country = US) and left
    // empty. That is a different fact from "never shown", and the document must be able to say both.
    $form = pdfBranchForm($this->tenant, $this->owner);
    $submission = seedPdfSubmission($form, $this->owner, ['applicant' => 'Ana', 'country' => 'US', 'age' => '21']);

    $rows = pdfRows(app(SubmissionPdfPresenter::class)->present($submission));

    expect($rows)->toHaveKey('state')
        ->and($rows['state'])->toBe(SubmissionPdfPresenter::BLANK);
});

/** A form whose whole `spouse` section — repeatable or not — is relevant only when married. */
function pdfConditionalSectionForm(Tenant $tenant, User $owner, bool $repeatable): Form
{
    $form = app(FormService::class)->create($tenant, $owner, $repeatable ? 'Dependants' : 'Spouse');
    $version = $form->draftVersion;

    addFormField($version, $owner, 'status', FieldType::ShortText, 1);

    $section = FormSection::create([
        'form_version_id' => $version->id,
        'key' => 'spouse',
        'label' => 'Spouse details',
        'sequence' => 2,
        'is_repeatable' => $repeatable,
        'min_instances' => $repeatable ? 0 : null,
        'max_instances' => $repeatable ? 5 : null,
        'relevant_expression' => "\${status} = 'married'",
    ]);
    addFormField($version, $owner, 'spouse_name', FieldType::ShortText, 3, ['form_section_id' => $section->id]);

    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

it('drops an entire section the respondent never reached', function (): void {
    // The non-repeatable case. Note this ALSO passes with the section-relevance check removed,
    // because every field inside an irrelevant section is itself irrelevant and the row filter
    // empties the block — the `$rows === []` guard then skips it. Kept anyway: it pins the
    // OUTCOME, which must hold however the code arrives at it.
    $form = pdfConditionalSectionForm($this->tenant, $this->owner, repeatable: false);
    $single = seedPdfSubmission($form, $this->owner, ['status' => 'single']);
    $married = seedPdfSubmission($form, $this->owner, ['status' => 'married', 'spouse_name' => 'Ana']);

    $labelsOf = fn (Submission $s): array => array_map(
        fn (array $b) => $b['label'],
        app(SubmissionPdfPresenter::class)->present($s)['blocks'],
    );

    expect($labelsOf($single))->not->toContain('Spouse details')
        ->and($labelsOf($married))->toContain('Spouse details');
});

it('drops an unreached REPEATABLE section instead of announcing it as empty', function (): void {
    // This is where the section-relevance check earns its keep, and mutation testing is how the gap
    // was found: with the check removed, the non-repeatable test above still passed, because the row
    // filter had already emptied the block. A repeatable section takes a different path — it never
    // consults the row filter before deciding to emit — so without the check an unreached section
    // renders its heading plus "No entries.", which states the respondent saw the section and added
    // nothing to it. They never saw it at all. Those are different facts and the document must not
    // confuse them.
    $form = pdfConditionalSectionForm($this->tenant, $this->owner, repeatable: true);
    $single = seedPdfSubmission($form, $this->owner, ['status' => 'single']);

    $blocks = app(SubmissionPdfPresenter::class)->present($single)['blocks'];

    expect(array_map(fn (array $b) => $b['label'], $blocks))->not->toContain('Spouse details');

    // …and a married respondent who added nobody DOES get the "No entries." heading, because that
    // one really is "saw it, added nothing". Without this half, "drop everything" would pass.
    $married = seedPdfSubmission($form, $this->owner, ['status' => 'married']);
    $spouse = array_values(array_filter(
        app(SubmissionPdfPresenter::class)->present($married)['blocks'],
        fn (array $b): bool => $b['label'] === 'Spouse details',
    ));

    expect($spouse)->toHaveCount(1)
        ->and($spouse[0]['notice'])->toBe(SubmissionPdfPresenter::EMPTY_REPEAT);
});

it('reproduces the same mask when replayed over its own pruned output', function (): void {
    // THE fixed-point pin. The replay runs over the already-pruned stored document, not the raw input
    // the original Stage-3 pass saw, so it is not literally a replay — it is only correct because a
    // pruned upstream key is absent and absent reads as empty, which is what the original run's own
    // fixed-point semantics produced. Asserted directly rather than left as an argument in a docblock.
    //
    // Chained relevance makes it a real test: `state` depends on `country`, so if pruning `state`
    // could perturb anything downstream, a second pass would diverge here.
    $form = pdfBranchForm($this->tenant, $this->owner);
    $submission = seedPdfSubmission($form, $this->owner, ['applicant' => 'Ana', 'country' => 'PH', 'age' => '21']);

    $presenter = app(SubmissionPdfPresenter::class);
    $first = $presenter->present($submission);

    // Re-store the document as the PDF understood it, then present again.
    $pruned = [];
    foreach ($first['blocks'] as $block) {
        foreach ($block['fields'] as $row) {
            if ($row['value'] !== SubmissionPdfPresenter::BLANK && $row['value'] !== '') {
                $pruned[$row['key']] = $row['value'];
            }
        }
    }
    $submission->answers->forceFill(['answers' => $pruned])->save();

    expect(array_keys(pdfRows($presenter->present($submission->refresh()))))
        ->toBe(array_keys(pdfRows($first)));
});

it('pins the relevance clock to the submission, not to when the PDF is generated', function (): void {
    // Without the fourth argument to validate(), this field's relevance would be re-evaluated under
    // Carbon::now() and the row would vanish from a PDF generated on any later day — the document
    // would quietly disagree with itself depending on when it was printed.
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Dated');
    $version = $form->draftVersion;
    addFormField($version, $this->owner, 'applicant', FieldType::ShortText, 1);
    addFormField($version, $this->owner, 'same_day_note', FieldType::ShortText, 2, [
        'relevant_expression' => "today() = '2026-03-14'",
    ]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);

    $submission = seedPdfSubmission(
        $form->refresh(),
        $this->owner,
        ['applicant' => 'Ana', 'same_day_note' => 'filed on the day'],
        Carbon::parse('2026-03-14T10:00:00+00:00'),
    );

    // Generate the PDF five months later.
    Carbon::setTestNow(Carbon::parse('2026-08-01T09:00:00+00:00'));
    $rows = pdfRows(app(SubmissionPdfPresenter::class)->present($submission));
    Carbon::setTestNow();

    expect($rows)->toHaveKey('same_day_note')
        ->and($rows['same_day_note'])->toBe('filed on the day');
});

it('resolves field and section label translations, which the inbox never did', function (): void {
    // Difference 4. Doc #26 §4 makes "resolve the locale, THEN render" normative; before H17 only
    // SubmissionExporter::header() implemented the first half, so the inbox showed a Filipino
    // respondent English question text next to their own Filipino answers.
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Sarbey');
    $version = $form->draftVersion;

    $section = FormSection::create([
        'form_version_id' => $version->id,
        'key' => 'about',
        'label' => 'About you',
        'label_translations' => ['fil' => 'Tungkol sa iyo'],
        'sequence' => 1,
    ]);
    addFormField($version, $this->owner, 'full_name', FieldType::ShortText, 1, [
        'form_section_id' => $section->id,
        'label' => 'Full name',
        'label_translations' => ['fil' => 'Buong pangalan'],
    ]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);

    $submission = seedInboxSubmission($form->refresh(), $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ana']);
    $submission->forceFill(['locale' => 'fil'])->save();

    $model = app(SubmissionPdfPresenter::class)->present($submission->refresh());

    expect($model['blocks'][0]['label'])->toBe('Tungkol sa iyo')
        ->and($model['blocks'][0]['fields'][0]['label'])->toBe('Buong pangalan')
        // The inbox is still half-localized. Pinned so the divergence is visible and deliberate.
        ->and(app(SubmissionInboxPresenter::class)->detail($this->owner, $submission)['blocks'][0]['label'])
        ->toBe('About you');
});

it('falls back to the base label when a translation is blank, but honours an explicit empty base', function (): void {
    // LocaleVariant's asymmetry, which is easy to "simplify" into a bug: a BLANK variant is no
    // translation yet (fall back), while a blank base is an author's choice (keep it).
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Fallbacks');
    $version = $form->draftVersion;
    addFormField($version, $this->owner, 'kept', FieldType::ShortText, 1, [
        'label' => 'Base label',
        'label_translations' => ['fil' => ''],
    ]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);

    $submission = seedInboxSubmission($form->refresh(), $this->owner, SubmissionStatus::Submitted, ['kept' => 'x']);
    $submission->forceFill(['locale' => 'fil'])->save();

    $model = app(SubmissionPdfPresenter::class)->present($submission->refresh());

    expect($model['blocks'][0]['fields'][0]['label'])->toBe('Base label');
});

it('emits the repeat fallbacks server-side, where the inbox leaves them to Vue', function (): void {
    // Difference 3. `Show.vue:163` supplies "No entries." and `:167` supplies the em-dash; a PDF has
    // no Vue. Both now come from the presenter, which also makes them assertable without rendering.
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Household');
    $version = $form->draftVersion;
    addFormField($version, $this->owner, 'household_name', FieldType::ShortText, 1);
    $roster = FormSection::create([
        'form_version_id' => $version->id,
        'key' => 'roster', 'label' => 'Member', 'sequence' => 2,
        'is_repeatable' => true, 'min_instances' => 0, 'max_instances' => 10,
    ]);
    addFormField($version, $this->owner, 'member_name', FieldType::ShortText, 3, ['form_section_id' => $roster->id]);
    addFormField($version, $this->owner, 'member_age', FieldType::Integer, 4, [
        'form_section_id' => $roster->id,
        'label' => 'Age of ${member_name}',
    ]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);
    $form = $form->refresh();

    $empty = app(SubmissionPdfPresenter::class)->present(
        seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['household_name' => 'Reyes']),
    );
    $rosterBlock = array_values(array_filter($empty['blocks'], fn (array $b): bool => $b['label'] === 'Member'));

    expect($rosterBlock)->toHaveCount(1)
        ->and($rosterBlock[0]['fields'])->toBe([])
        ->and($rosterBlock[0]['notice'])->toBe(SubmissionPdfPresenter::EMPTY_REPEAT);

    // …and a filled roster still numbers its instances and pipes same-instance siblings (A9).
    $filled = app(SubmissionPdfPresenter::class)->present(
        seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, [
            'household_name' => 'Reyes',
            'roster' => [['member_name' => 'Ana', 'member_age' => '34'], ['member_name' => 'Beni', 'member_age' => '7']],
        ]),
    );
    $blocks = array_values(array_filter($filled['blocks'], fn (array $b): bool => str_starts_with((string) $b['label'], 'Member')));

    expect($blocks)->toHaveCount(2)
        ->and($blocks[0]['label'])->toBe('Member 1')
        ->and($blocks[1]['label'])->toBe('Member 2')
        ->and($blocks[0]['fields'][1]['label'])->toBe('Age of Ana')
        ->and($blocks[1]['fields'][1]['label'])->toBe('Age of Beni')
        ->and($blocks[0]['notice'])->toBeNull();
});

it('carries a calculated value into the PDF through the label the respondent read it in', function (): void {
    // The consequence of omitting `calculated` rows, made explicit. The value is NOT lost — it reaches
    // the document through any label that pipes it, which is exactly where the respondent saw it.
    // PipingEligibility::Pipeable and PdfFieldRole::Omitted agreeing on `calculated` is what makes
    // this work, and it is why TemplateSources is built from ALL fields, not the visible ones.
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Totals');
    $version = $form->draftVersion;
    addFormField($version, $this->owner, 'age', FieldType::Integer, 1);
    addFormField($version, $this->owner, 'doubled', FieldType::Calculated, 2, ['config' => ['calculated_formula' => '${age} * 2']]);
    addFormField($version, $this->owner, 'confirm', FieldType::ShortText, 3, ['label' => 'Confirm your score of ${doubled}']);
    app(PublishService::class)->publish($form->refresh(), $this->owner);

    $submission = seedInboxSubmission($form->refresh(), $this->owner, SubmissionStatus::Submitted, [
        'age' => '21', 'doubled' => '42', 'confirm' => 'yes',
    ]);

    $model = app(SubmissionPdfPresenter::class)->present($submission);
    $labels = [];
    foreach ($model['blocks'] as $block) {
        foreach ($block['fields'] as $row) {
            $labels[$row['key']] = $row['label'];
        }
    }

    expect($labels)->not->toHaveKey('doubled')
        ->and($labels['confirm'])->toBe('Confirm your score of 42');
});
