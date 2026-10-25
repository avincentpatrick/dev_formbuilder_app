<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\SubmissionStatus;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BuilderPresenter;
use App\Services\Forms\ChoicePreviewPresenter;
use App\Services\Forms\FormService;
use App\Services\Forms\FormSettingsPresenter;
use App\Services\Forms\LinkedChoiceService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PsgcFixture;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M186 (`R-ed7f3127`, `D162`, `D163`) — choices narrowed by what another form recorded
|--------------------------------------------------------------------------
| The testers' question (2026-10-15): *"can options in a target form dynamically filter based on whether a specific
| barangay has recorded value in a source form?"*. A cascade level, or a list-fed question, may carry
| `{form_id, field_key, mode}`; the linked-choices response carries the source's distinct answers beside the lists; the
| page narrows what it SHOWS, and no answer is ever refused for being outside them. What must hold:
|   - a cascade is shared to narrow choices only, and only when ticked — never through "every question", never as a list;
|   - the set is the source's finished answers at the comparable level (a Philippine places level by the same level);
|   - publish refuses what cannot serve, in words: unshared, incomparable, unfinished, or a typed list;
|   - manual entry and the builder's Preview get the same sets; a set that cannot be read narrows nothing.
|
| ⚠️ Helpers are prefixed `choiceFilter*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    // The encode page renders the Blade shell, whose `@vite` needs a build manifest CI does not have (M129's trap).
    $this->withoutVite();
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = guestTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'admin');
    PsgcFixture::load();

    $this->source = choiceFilterSource($this->tenant, $this->owner);
    choiceFilterReport($this->source, ['place' => ['1300000000', '1380600000', '1380600000', '1380601001'], 'team' => 'Alpha', 'district' => 'north']);
    choiceFilterReport($this->source, ['place' => ['1300000000', '1381701000', '1381701000', '1381701001'], 'team' => 'Bravo', 'district' => 'north']);
    // A draft is nobody's report yet.
    choiceFilterReport($this->source, ['place' => ['1300000000', '1380600000', '1380600000', '1380601002']], SubmissionStatus::Draft);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** @return list<array<string, mixed>> the four Philippine places levels, the barangay narrowed by `$filter` */
function choiceFilterPlaces(?array $filter = null): array
{
    return [
        ['key' => 'region', 'label' => 'Region', 'list' => 'psgc:region'],
        ['key' => 'province', 'label' => 'Province or district', 'list' => 'psgc:province'],
        ['key' => 'city', 'label' => 'City or municipality', 'list' => 'psgc:city'],
        ['key' => 'barangay', 'label' => 'Barangay', 'list' => 'psgc:barangay', ...($filter === null ? [] : ['filter' => $filter])],
    ];
}

/** A published "Barangay Report" — its place on the Philippine places, a team, a district — sharing only the place and the team. */
function choiceFilterSource(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Barangay Report');
    addFormField($form->draftVersion, $owner, 'place', FieldType::CascadingSelect, 1, ['config' => ['levels' => choiceFilterPlaces(), 'options' => []]]);
    addFormField($form->draftVersion, $owner, 'team', FieldType::ShortText, 2);
    addFormField($form->draftVersion, $owner, 'district', FieldType::Dropdown, 3, ['config' => ['options' => [
        ['value' => 'north', 'label' => 'North'], ['value' => 'south', 'label' => 'South'],
    ]]]);
    app(PublishService::class)->publish($form->refresh(), $owner);

    return app(FormService::class)->setDataSharing($form->refresh(), true, ['place', 'team', 'district'], $owner);
}

/** @param array<string, mixed> $answers */
function choiceFilterReport(Form $source, array $answers, SubmissionStatus $status = SubmissionStatus::Submitted): void
{
    enterTenant($source->tenant_id); // a request leaves the test's connection without the tenant GUC
    seedInboxSubmission($source, null, $status, $answers);
}

/** A draft "Barangay Visit" with one question carrying `$config`; published unless told not to. */
function choiceFilterTarget(User $owner, FieldType $type, array $config, bool $publish = true): Form
{
    enterTenant(test()->tenant->id, $owner->id);
    $form = app(FormService::class)->create(test()->tenant, $owner, 'Barangay Visit');
    addFormField($form->draftVersion, $owner, 'visit', $type, 1, ['config' => $config]);
    if ($publish) {
        app(PublishService::class)->publish($form->refresh(), $owner);
        $form->refresh()->update(['public_slug' => 'barangay-visit-'.substr((string) $form->id, -8), 'allow_guest_submissions' => true]);
    }

    return $form->refresh();
}

function choiceFilterSets(Form $form): array
{
    return test()->getJson('http://acme.meridian.test/api/v1/public/linked-choices/'.shareTokenFor($form).'/'.$form->current_published_version_id)
        ->assertOk()->json('data.sets');
}

it('serves the barangays the other form has finished reports for, at the comparable level, beside the lists', function (): void {
    $form = choiceFilterTarget($this->owner, FieldType::CascadingSelect, [
        'levels' => choiceFilterPlaces(['form_id' => $this->source->id, 'field_key' => 'place', 'mode' => 'present']),
        'options' => [],
    ]);

    $sets = choiceFilterSets($form);

    expect($sets)->toHaveCount(1)
        ->and($sets[0]['field_key'])->toBe('visit')
        ->and($sets[0]['level'])->toBe(3)
        ->and($sets[0]['available'])->toBeTrue()
        ->and($sets[0]['truncated'])->toBeFalse()
        // The draft's barangay is not among them.
        ->and($sets[0]['values'])->toBe(['1380601001', '1381701001']);
});

it('compares a Philippine places level by the same level, whatever either is called', function (): void {
    $levels = choiceFilterPlaces(['form_id' => $this->source->id, 'field_key' => 'place', 'mode' => 'absent']);
    $levels[3]['key'] = 'brgy';
    $form = choiceFilterTarget($this->owner, FieldType::CascadingSelect, ['levels' => $levels, 'options' => []]);

    expect(choiceFilterSets($form)[0]['values'])->toBe(['1380601001', '1381701001']);
});

it('serves a list-fed question’s set from a one-answer question, with a choice question’s labels beside its values', function (): void {
    $byTeam = choiceFilterTarget($this->owner, FieldType::Dropdown, [
        'options' => [], 'options_source' => ['form_id' => $this->source->id, 'field_key' => 'team'],
        'options_filter' => ['form_id' => $this->source->id, 'field_key' => 'team', 'mode' => 'present'],
    ]);
    expect(choiceFilterSets($byTeam)[0])->toMatchArray(['level' => null, 'available' => true, 'values' => ['Alpha', 'Bravo']]);

    $byDistrict = choiceFilterTarget($this->owner, FieldType::Dropdown, [
        'options' => [], 'options_source' => ['form_id' => $this->source->id, 'field_key' => 'team'],
        'options_filter' => ['form_id' => $this->source->id, 'field_key' => 'district', 'mode' => 'present'],
    ]);
    expect(choiceFilterSets($byDistrict)[0]['values'])->toBe(['North', 'north']);
});

it('shares a cascade to narrow choices only when its owner ticked it — never through every question, never as a list', function (): void {
    $links = app(LinkedChoiceService::class);
    $everything = app(FormService::class)->setDataSharing($this->source->refresh(), true, null, $this->owner);

    expect($links->sharedKeys($everything, true))->toBe(['team', 'district'])
        ->and($links->sharedKeys($everything))->toBe(['team', 'district']);

    $ticked = app(FormService::class)->setDataSharing($everything->refresh(), true, ['place', 'team'], $this->owner);

    expect($links->sharedKeys($ticked, true))->toBe(['place', 'team'])
        // Never to feed a list: options, suggestions, a grid's copy and the scan read without the filter's purpose.
        ->and($links->sharedKeys($ticked))->toBe(['team']);

    $draft = choiceFilterTarget($this->owner, FieldType::Dropdown, ['options' => []], false);
    $offered = collect(app(BuilderPresenter::class)->present($draft, $this->owner)['linkable_sources'])->firstWhere('id', $this->source->id);

    expect(collect($offered['questions'])->firstWhere('key', 'place'))->toMatchArray([
        'field_type' => 'cascading_select',
        'levels' => [
            ['key' => 'region', 'list' => 'psgc:region'],
            ['key' => 'province', 'list' => 'psgc:province'],
            ['key' => 'city', 'list' => 'psgc:city'],
            ['key' => 'barangay', 'list' => 'psgc:barangay'],
        ],
    ]);

    $linked = choiceFilterTarget($this->owner, FieldType::Dropdown, ['options' => [], 'options_source' => ['form_id' => $this->source->id, 'field_key' => 'place']], false);
    expect(fn () => app(PublishService::class)->publish($linked, $this->owner))->toThrow(PublishValidationException::class, 'does not share that question');
});

it('lets the source tick a cascade in its Data sharing settings, and names the forms it narrows', function (): void {
    $form = choiceFilterTarget($this->owner, FieldType::CascadingSelect, [
        'levels' => choiceFilterPlaces(['form_id' => $this->source->id, 'field_key' => 'place', 'mode' => 'present']),
        'options' => [],
    ]);

    $this->actingAs($this->owner)
        ->patch('http://acme.meridian.test/forms/'.$this->source->id.'/data-sharing', ['enabled' => true, 'acknowledged' => true, 'field_keys' => ['place']])
        ->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $this->owner->id);
    $section = app(FormSettingsPresenter::class)->dataSharing(Form::findOrFail($this->source->id), $this->owner);

    expect(collect($section['questions'])->firstWhere('key', 'place'))->toBe(['key' => 'place', 'label' => 'Place', 'narrows_only' => true])
        ->and($section['used_by'])->toBe([['id' => $form->id, 'title' => 'Barangay Visit']]);
});

it('refuses at publish, in words, a filter that cannot serve', function (array $config, string $words): void {
    $config = json_decode(str_replace('SOURCE', (string) $this->source->id, (string) json_encode($config)), true);
    $form = choiceFilterTarget($this->owner, FieldType::from($config['type']), $config['config'], false);

    expect(fn () => app(PublishService::class)->publish($form, $this->owner))->toThrow(PublishValidationException::class, $words);
})->with([
    'a question the source does not share' => [['type' => 'cascading_select', 'config' => ['levels' => [
        ['key' => 'region', 'label' => 'Region', 'list' => 'psgc:region', 'filter' => ['form_id' => 'SOURCE', 'field_key' => 'nobody', 'mode' => 'present']],
    ], 'options' => []]], 'does not share that question'],
    'a Philippine places level narrowed by a one-answer question' => [['type' => 'cascading_select', 'config' => ['levels' => [
        ['key' => 'region', 'label' => 'Region', 'list' => 'psgc:region', 'filter' => ['form_id' => 'SOURCE', 'field_key' => 'team', 'mode' => 'present']],
    ], 'options' => []]], 'can never match these choices'],
    'a list narrowed by a cascade' => [['type' => 'dropdown', 'config' => [
        'options' => [], 'options_source' => ['form_id' => 'SOURCE', 'field_key' => 'team'],
        'options_filter' => ['form_id' => 'SOURCE', 'field_key' => 'place', 'mode' => 'present'],
    ]], 'can never match these choices'],
    'typed choices' => [['type' => 'dropdown', 'config' => [
        'options' => [['value' => 'a', 'label' => 'A']],
        'options_filter' => ['form_id' => 'SOURCE', 'field_key' => 'team', 'mode' => 'present'],
    ]], 'only choices that come from a list file or another form'],
    'a filter half picked' => [['type' => 'cascading_select', 'config' => ['levels' => [
        ['key' => 'region', 'label' => 'Region', 'list' => 'psgc:region', 'filter' => ['form_id' => 'SOURCE', 'field_key' => null, 'mode' => 'present']],
    ], 'options' => []]], 'choose the form and the question that narrow them'],
]);

it('never refuses an answer outside the set — the server checks the version’s own list', function (): void {
    $form = choiceFilterTarget($this->owner, FieldType::CascadingSelect, [
        'levels' => choiceFilterPlaces(['form_id' => $this->source->id, 'field_key' => 'place', 'mode' => 'present']),
        'options' => [],
    ]);

    // Barangay 2 has no report, so the page would not offer it — and it still saves.
    $this->postJson('http://acme.meridian.test/api/v1/public/f/'.shareTokenFor($form).'/submissions', [
        'answers' => ['visit' => ['1300000000', '1380600000', '1380600000', '1380601002']],
    ])->assertCreated();
});

it('gives manual entry the same narrowing — and nothing to narrow by once the source stops sharing', function (): void {
    $form = choiceFilterTarget($this->owner, FieldType::CascadingSelect, [
        'levels' => choiceFilterPlaces(['form_id' => $this->source->id, 'field_key' => 'place', 'mode' => 'present']),
        'options' => [],
    ]);
    $create = 'http://acme.meridian.test/forms/'.$form->id.'/submissions/create';

    $this->actingAs($this->owner)->get($create)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('blocks.0.fields.0.choice_filter', [['level' => 3, 'mode' => 'present', 'values' => ['1380601001', '1381701001']]]));

    enterTenant($this->tenant->id, $this->owner->id);
    app(FormService::class)->setDataSharing($this->source->refresh(), false, null, $this->owner);

    // `D163`: the set cannot be read, so the page is told to narrow nothing and say so.
    $this->actingAs($this->owner)->get($create)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('blocks.0.fields.0.choice_filter', [['level' => 3, 'mode' => 'present', 'values' => null]]));
});

it('gives the builder’s Preview the draft’s sets, as the guest page is served them', function (): void {
    $form = choiceFilterTarget($this->owner, FieldType::CascadingSelect, [
        'levels' => choiceFilterPlaces(['form_id' => $this->source->id, 'field_key' => 'place', 'mode' => 'present']),
        'options' => [],
    ], false);

    $payload = app(ChoicePreviewPresenter::class)->forDraft($form, FormVersion::findOrFail($form->draft_version_id));

    expect($payload['sets'])->toHaveCount(1)
        ->and($payload['sets'][0]['values'])->toBe(['1380601001', '1381701001']);
});
