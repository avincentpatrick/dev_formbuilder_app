<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\FormSection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionInboxPresenter;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// The inbox detail read model's piping behaviour (Increment H6b, Doc #26 §3.3/§4).
//
// Two corrections land here, and the first is not only about piping: before H6b this surface was
// repeat-BLIND. It emitted one row per member field and read `$answers[$field->key]`, but a repeat
// member's answers live nested under the SECTION key — so an answered roster displayed as a column of
// em-dashes. Piping made that unfixable in place (§3.3 rule 2 lets a member's label name a same-instance
// sibling, and one row cannot carry N differently-filled labels), so a repeatable section now emits one
// BLOCK PER INSTANCE, resolved against `array_merge($base, $instance)`.
//
// The second is amendment A8: the submission's own locale now resolves choice-option labels, so the
// reviewer reads the same words the respondent picked.
//
// EVERY field gets a DISTINCT `sequence` — §3.3 rule 1 as amended treats a positional TIE as a rejection,
// and `addFormField()` defaults `sequence` to 0.
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

/** A published form with one repeatable `roster` section whose member label pipes a same-instance sibling. */
function pipingRosterForm(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Household');
    $version = $form->draftVersion;

    addFormField($version, $owner, 'household_name', FieldType::ShortText, 1);

    $roster = FormSection::create([
        'form_version_id' => $version->id,
        'key' => 'roster',
        'label' => 'Member',
        'sequence' => 2,
        'is_repeatable' => true,
        'min_instances' => 1,
        'max_instances' => 10,
    ]);

    addFormField($version, $owner, 'member_name', FieldType::ShortText, 3, ['form_section_id' => $roster->id]);
    // The same-instance reference §3.3 rule 2 permits — and that no PHP surface could render before H6b.
    addFormField($version, $owner, 'member_age', FieldType::Integer, 4, [
        'form_section_id' => $roster->id,
        'label' => 'Age of ${member_name}',
    ]);

    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

it('emits one block per repeat instance, each resolving its OWN answers', function (): void {
    $form = pipingRosterForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, [
        'household_name' => 'Reyes',
        'roster' => [
            ['member_name' => 'Ana', 'member_age' => '34'],
            ['member_name' => 'Beni', 'member_age' => '7'],
        ],
    ]);

    $detail = app(SubmissionInboxPresenter::class)->detail($this->owner, $submission);
    /** @var list<array<string, mixed>> $blocks */
    $blocks = $detail['blocks'];

    $roster = array_values(array_filter($blocks, fn (array $b): bool => is_string($b['label']) && str_starts_with($b['label'], 'Member')));

    expect($roster)->toHaveCount(2)
        // Numbered exactly as the respondent saw it — `RepeatGroup.vue`'s legend is `${label} ${index+1}`.
        ->and($roster[0]['label'])->toBe('Member 1')
        ->and($roster[1]['label'])->toBe('Member 2')
        // The hole fills from the instance that owns it, not from instance 0 and not from the flat map.
        ->and($roster[0]['fields'][1]['label'])->toBe('Age of Ana')
        ->and($roster[1]['fields'][1]['label'])->toBe('Age of Beni')
        // …and the VALUE column, which read the flat map before H6b and was always an em-dash.
        ->and($roster[0]['fields'][1]['value'])->toBe('34')
        ->and($roster[1]['fields'][1]['value'])->toBe('7')
        ->and($roster[0]['fields'][0]['value'])->toBe('Ana');
});

it('never emits the raw ${key} token, even where the hole cannot fill', function (): void {
    $form = pipingRosterForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, [
        'household_name' => 'Reyes',
        'roster' => [['member_age' => '34']],   // no member_name in this instance
    ]);

    $detail = app(SubmissionInboxPresenter::class)->detail($this->owner, $submission);
    $blocks = $detail['blocks'];

    $labels = [];
    foreach ($blocks as $block) {
        foreach ($block['fields'] as $row) {
            $labels[] = $row['label'];
        }
    }

    // §3.4: an unanswered source renders as the EMPTY STRING, never the token, and render never throws.
    expect($labels)->toContain('Age of ')
        ->and(implode('|', $labels))->not->toContain('${');
});

it('shows a repeatable section the respondent added nothing to as an empty block, not as blank rows', function (): void {
    $form = pipingRosterForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['household_name' => 'Reyes']);

    $detail = app(SubmissionInboxPresenter::class)->detail($this->owner, $submission);
    $roster = array_values(array_filter($detail['blocks'], fn (array $b): bool => $b['label'] === 'Member'));

    // Pre-H6b this emitted two em-dash rows, which reads as "answered blank". It was not answered at all.
    expect($roster)->toHaveCount(1)
        ->and($roster[0]['fields'])->toBe([]);
});

it('resolves a choice label into the SUBMISSION\'s locale (amendment A8)', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Survey');
    addFormField($form->draftVersion, $this->owner, 'region', FieldType::SingleSelect, 1, [
        'config' => ['options' => [
            ['value' => 'ncr', 'label' => 'Metro Manila', 'label_translations' => ['fil' => 'Kalakhang Maynila']],
        ]],
    ]);
    addFormField($form->draftVersion, $this->owner, 'note_field', FieldType::ShortText, 2, [
        'label' => 'Anything else about ${region}?',
    ]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);
    $form = $form->refresh();

    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['region' => 'ncr']);
    $submission->forceFill(['locale' => 'fil'])->save();

    $detail = app(SubmissionInboxPresenter::class)->detail($this->owner, $submission->refresh());
    $rows = $detail['blocks'][0]['fields'];

    // Both surfaces of the same answer — the value column AND the label it was piped into.
    expect($rows[0]['value'])->toBe('Kalakhang Maynila')
        ->and($rows[1]['label'])->toBe('Anything else about Kalakhang Maynila?');
});

it('falls back to the base label when the submission carries no locale', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Survey');
    addFormField($form->draftVersion, $this->owner, 'region', FieldType::SingleSelect, 1, [
        'config' => ['options' => [
            ['value' => 'ncr', 'label' => 'Metro Manila', 'label_translations' => ['fil' => 'Kalakhang Maynila']],
        ]],
    ]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);

    $submission = seedInboxSubmission($form->refresh(), $this->owner, SubmissionStatus::Submitted, ['region' => 'ncr']);
    $submission->forceFill(['locale' => null])->save();

    $detail = app(SubmissionInboxPresenter::class)->detail($this->owner, $submission->refresh());

    expect($detail['blocks'][0]['fields'][0]['value'])->toBe('Metro Manila');
});

it('gives every block a distinct id so the page can key them', function (): void {
    $form = pipingRosterForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, [
        'household_name' => 'Reyes',
        'roster' => [['member_name' => 'Ana'], ['member_name' => 'Beni']],
    ]);

    $ids = array_column(app(SubmissionInboxPresenter::class)->detail($this->owner, $submission)['blocks'], 'id');

    expect($ids)->toHaveCount(count(array_unique($ids, SORT_REGULAR)));
});
