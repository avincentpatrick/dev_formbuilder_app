<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Models\Form;
use App\Models\FormSection;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment H21c — Doc #27 §7's defect (b), the cheap half.
|--------------------------------------------------------------------------
| "A staffer keying a paper form sees every section, including branches the respondent's own answers
| exclude… Stage 4 persists the pruned document. The controller then discards the `SubmissionResult`
| entirely and redirects with a flat 'Submission recorded.' The keyer is never told that half of what they
| typed was dropped."
|
| Every case here asserts the submission SUCCEEDED and the loss was NAMED. A test asserting a refusal would
| pin the opposite of the design: an irrelevant answer is not an error, it is simply not part of the taken
| path, and blocking on it would make a branching form unencodable.
|
| The anti-vacuity discipline the H21a/H21b suites earned applies: the silence cases pair "no
| `prunedAnswers`" with a POSITIVE assertion that the submission exists and the success toast fired, so a
| controller that flashed nothing at all — or 500'd — could not satisfy them.
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

function prunedTenant(): Tenant
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);

    return $tenant;
}

/**
 * A published branching form: a `role` gate, a `staff_number` gated on `role = 'staff'`, and a whole
 * `visitor` SECTION gated on `role = 'visitor'`. Distinct `sequence` values throughout — the field factory
 * defaults `sequence` to 0 and Doc #26 amendment A2 made a positional tie a rejection, so an all-defaults
 * fixture would pass or fail for the wrong reason.
 */
function publishedBranchingEncodeForm(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Router');
    $draft = $form->draftVersion;

    addFormField($draft, $owner, 'role', FieldType::ShortText, 1);
    addFormField($draft, $owner, 'staff_number', FieldType::ShortText, 2, [
        'label' => 'Staff number',
        'relevant_expression' => "\${role} = 'staff'",
    ]);

    $visitor = FormSection::create([
        'form_version_id' => $draft->id,
        'key' => 'visitor',
        'label' => 'Visitor details',
        'sequence' => 3,
        'relevant_expression' => "\${role} = 'visitor'",
    ]);
    addFormField($draft, $owner, 'visiting_whom', FieldType::ShortText, 4, [
        'form_section_id' => $visitor->id,
        'label' => 'Who are you visiting?',
    ]);

    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

/** An encoder with the permission to use the channel. */
function prunedEncoder(Tenant $tenant): User
{
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    return $admin;
}

it('names the answer a field-level condition dropped, and still records the submission', function (): void {
    $tenant = prunedTenant();
    $admin = prunedEncoder($tenant);
    $form = publishedBranchingEncodeForm($tenant, $admin);

    $this->actingAs($admin)
        ->post("http://acme.meridian.test/forms/{$form->id}/submissions", [
            'answers' => ['role' => 'visitor', 'staff_number' => 'S-1234', 'visiting_whom' => 'Ada'],
        ])
        ->assertRedirect()
        // The label as the keyer read it on the page, never the bare key.
        ->assertSessionHas('prunedAnswers', ['Staff number'])
        ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'info'
            && $toast['message'] === 'Submission recorded. 1 answer was not saved.');

    enterTenant($tenant->id, $admin->id);
    $submission = Submission::query()->firstOrFail();
    expect(SubmissionAnswer::query()->findOrFail($submission->id)->answers)
        ->toEqual(['role' => 'visitor', 'visiting_whom' => 'Ada']);
});

it('names an answer a SECTION-level condition dropped', function (): void {
    $tenant = prunedTenant();
    $admin = prunedEncoder($tenant);
    $form = publishedBranchingEncodeForm($tenant, $admin);

    $this->actingAs($admin)
        ->post("http://acme.meridian.test/forms/{$form->id}/submissions", [
            'answers' => ['role' => 'staff', 'staff_number' => 'S-1234', 'visiting_whom' => 'Ada'],
        ])
        ->assertRedirect()
        ->assertSessionHas('prunedAnswers', ['Who are you visiting?']);
});

it('pluralises the count and lists every drop in document order', function (): void {
    $tenant = prunedTenant();
    $admin = prunedEncoder($tenant);
    $form = publishedBranchingEncodeForm($tenant, $admin);

    // `role` answers neither branch, so BOTH gated answers fall away.
    $this->actingAs($admin)
        ->post("http://acme.meridian.test/forms/{$form->id}/submissions", [
            'answers' => ['role' => 'contractor', 'staff_number' => 'S-1234', 'visiting_whom' => 'Ada'],
        ])
        ->assertRedirect()
        ->assertSessionHas('prunedAnswers', ['Staff number', 'Who are you visiting?'])
        ->assertSessionHas('toast', fn (array $toast): bool => $toast['message'] === 'Submission recorded. 2 answers were not saved.');
});

it('says nothing when every answer survived — and the submission is still there', function (): void {
    $tenant = prunedTenant();
    $admin = prunedEncoder($tenant);
    $form = publishedBranchingEncodeForm($tenant, $admin);

    $this->actingAs($admin)
        ->post("http://acme.meridian.test/forms/{$form->id}/submissions", [
            'answers' => ['role' => 'staff', 'staff_number' => 'S-1234'],
        ])
        ->assertRedirect()
        ->assertSessionMissing('prunedAnswers')
        ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'success'
            && $toast['message'] === 'Submission recorded.');

    enterTenant($tenant->id, $admin->id);
    expect(Submission::query()->count())->toBe(1);
});

it('does not report a field the keyer left blank, even when it was pruned', function (): void {
    $tenant = prunedTenant();
    $admin = prunedEncoder($tenant);
    $form = publishedBranchingEncodeForm($tenant, $admin);

    // `staff_number` is irrelevant AND empty — nothing was taken away, so naming it would be noise. The
    // encode page posts a slot for every field, so this is the COMMON case, not an edge one.
    $this->actingAs($admin)
        ->post("http://acme.meridian.test/forms/{$form->id}/submissions", [
            'answers' => ['role' => 'visitor', 'staff_number' => '', 'visiting_whom' => 'Ada'],
        ])
        ->assertRedirect()
        ->assertSessionMissing('prunedAnswers');

    enterTenant($tenant->id, $admin->id);
    expect(Submission::query()->count())->toBe(1);
});

it('reports a whole irrelevant repeat GROUP once, not once per member per instance', function (): void {
    $tenant = prunedTenant();
    $admin = prunedEncoder($tenant);

    $form = app(FormService::class)->create($tenant, $admin, 'Roster');
    $draft = $form->draftVersion;
    addFormField($draft, $admin, 'has_household', FieldType::ShortText, 1);
    $section = FormSection::create([
        'form_version_id' => $draft->id,
        'key' => 'hh',
        'label' => 'Household members',
        'sequence' => 2,
        'is_repeatable' => true,
        'relevant_expression' => "\${has_household} = 'yes'",
    ]);
    addFormField($draft, $admin, 'member_name', FieldType::ShortText, 3, ['form_section_id' => $section->id, 'label' => 'Member name']);
    app(PublishService::class)->publish($form->refresh(), $admin);
    $form->refresh();

    $this->actingAs($admin)
        ->post("http://acme.meridian.test/forms/{$form->id}/submissions", [
            'answers' => [
                'has_household' => 'no',
                'hh' => [['member_name' => 'Ana'], ['member_name' => 'Ben']],
            ],
        ])
        ->assertRedirect()
        // ONE entry for two instances: the condition is one decision, and listing four rows would describe
        // the shape of the data rather than what the keyer needs to know.
        ->assertSessionHas('prunedAnswers', ['Household members']);
});

it('reports a repeat MEMBER gated off inside one instance and not another', function (): void {
    $tenant = prunedTenant();
    $admin = prunedEncoder($tenant);

    $form = app(FormService::class)->create($tenant, $admin, 'Roster');
    $draft = $form->draftVersion;
    $section = FormSection::create([
        'form_version_id' => $draft->id,
        'key' => 'hh',
        'label' => 'Household members',
        'sequence' => 1,
        'is_repeatable' => true,
    ]);
    addFormField($draft, $admin, 'is_adult', FieldType::ShortText, 2, ['form_section_id' => $section->id, 'label' => 'Is an adult?']);
    addFormField($draft, $admin, 'employer', FieldType::ShortText, 3, [
        'form_section_id' => $section->id,
        'label' => 'Employer',
        'relevant_expression' => "\${is_adult} = 'yes'",
    ]);
    app(PublishService::class)->publish($form->refresh(), $admin);
    $form->refresh();

    $this->actingAs($admin)
        ->post("http://acme.meridian.test/forms/{$form->id}/submissions", [
            'answers' => [
                'hh' => [
                    ['is_adult' => 'yes', 'employer' => 'Acme'],   // survives
                    ['is_adult' => 'no', 'employer' => 'Nobody'],  // pruned, instance 2 only
                ],
            ],
        ])
        ->assertRedirect()
        // Numbered exactly as the page numbered the instance for the keyer.
        ->assertSessionHas('prunedAnswers', ['Household members 2 · Employer']);
});

it('reports a piped label the way the keyer saw it, with its holes empty', function (): void {
    $tenant = prunedTenant();
    $admin = prunedEncoder($tenant);

    // H6a's contract for this page: it serves a BLANK keying form, so every `${key}` hole renders as the
    // empty string — never as the raw token. The report must match what was on screen, not the template.
    $form = app(FormService::class)->create($tenant, $admin, 'Piped');
    $draft = $form->draftVersion;
    addFormField($draft, $admin, 'role', FieldType::ShortText, 1);
    addFormField($draft, $admin, 'staff_number', FieldType::ShortText, 2, [
        'label' => 'Staff number for ${role}',
        'relevant_expression' => "\${role} = 'staff'",
    ]);
    app(PublishService::class)->publish($form->refresh(), $admin);
    $form->refresh();

    $this->actingAs($admin)
        ->post("http://acme.meridian.test/forms/{$form->id}/submissions", [
            'answers' => ['role' => 'visitor', 'staff_number' => 'S-1234'],
        ])
        ->assertRedirect()
        ->assertSessionHas('prunedAnswers', ['Staff number for ']);
});

it('falls back to the key when a field carries no label at all', function (): void {
    $tenant = prunedTenant();
    $admin = prunedEncoder($tenant);

    $form = app(FormService::class)->create($tenant, $admin, 'Unlabelled');
    $draft = $form->draftVersion;
    addFormField($draft, $admin, 'role', FieldType::ShortText, 1);
    addFormField($draft, $admin, 'staff_number', FieldType::ShortText, 2, [
        'label' => '',
        'is_required' => RequiredMode::Optional,
        'relevant_expression' => "\${role} = 'staff'",
    ]);
    app(PublishService::class)->publish($form->refresh(), $admin);
    $form->refresh();

    $this->actingAs($admin)
        ->post("http://acme.meridian.test/forms/{$form->id}/submissions", [
            'answers' => ['role' => 'visitor', 'staff_number' => 'S-1234'],
        ])
        ->assertRedirect()
        // An empty quotation would name nothing; the key is at least findable.
        ->assertSessionHas('prunedAnswers', ['staff_number']);
});
