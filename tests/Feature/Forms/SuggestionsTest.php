<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\SubmissionStatus;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\User;
use App\Services\Forms\FormChoiceListService;
use App\Services\Forms\FormService;
use App\Services\Forms\FormSettingsPresenter;
use App\Services\Forms\PublishService;
use App\Support\Forms\FieldTypeConversion;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M184 (`R-596d6afd`) — a Short text suggests answers from a list file or from another form's answers.
|--------------------------------------------------------------------------
| `config.suggestions_source = {kind: list, name} | {kind: form, form_id, field_key}`. A list's labels are written into
| `config.suggestions` at publish; another form's answers are served live beside the schema, as a linked choice
| question's are. Suggestions never restrict: any text is still an answer, on every channel.
|
| ⚠️ Helpers are prefixed `suggest*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = guestTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'admin');

    $this->source = app(FormService::class)->create($this->tenant, $this->owner, 'Facility Register');
    addFormField($this->source->draftVersion, $this->owner, 'facility_name', FieldType::ShortText, 1);
    app(PublishService::class)->publish($this->source->refresh(), $this->owner);
    $this->source = app(FormService::class)->setDataSharing($this->source->refresh(), true, null, $this->owner);
    seedInboxSubmission($this->source, null, SubmissionStatus::Submitted, ['facility_name' => 'San Jose RHU']);

    $this->form = app(FormService::class)->create($this->tenant, $this->owner, 'Referral');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** @param  array<string, mixed>  $source */
function suggestField(object $test, array $source): FormField
{
    return addFormField($test->form->refresh()->draftVersion, $test->owner, 'facility', FieldType::ShortText, 1, ['config' => ['suggestions_source' => $source]]);
}

function suggestList(object $test, string $name, string $content): void
{
    $path = (string) tempnam(sys_get_temp_dir(), 'm184');
    file_put_contents($path, $content);
    app(FormChoiceListService::class)->upload($test->form->refresh(), new UploadedFile($path, $name, null, null, true));
}

function suggestPublishError(object $test): string
{
    try {
        app(PublishService::class)->publish($test->form->refresh(), $test->owner);
    } catch (PublishValidationException $e) {
        return $e->getMessage();
    }

    return 'published';
}

function suggestGuestForm(object $test): Form
{
    $test->form->refresh()->update(['public_slug' => 'referral', 'allow_guest_submissions' => true]);

    return $test->form->refresh();
}

it('writes a list file\'s labels into the published question, each once, and keeps none in the next draft', function (): void {
    suggestList($this, 'facilities.csv', "name,label\nRHU1,San Jose RHU\nBHS2,Poblacion BHS\nRHU3,San Jose RHU\n");
    suggestField($this, ['kind' => 'list', 'name' => 'facilities']);
    $draftId = (string) $this->form->refresh()->draft_version_id;

    expect(suggestPublishError($this))->toBe('published');

    $published = collect(FormVersion::query()->findOrFail($draftId)->schema_snapshot['fields'])->firstWhere('key', 'facility');
    $next = FormField::query()->where('form_version_id', $this->form->refresh()->draft_version_id)->where('key', 'facility')->firstOrFail();

    expect($published['config']['suggestions'])->toBe(['San Jose RHU', 'Poblacion BHS'])
        ->and(array_key_exists('suggestions', $next->config))->toBeFalse()
        ->and($next->config['suggestions_source'])->toEqual(['kind' => 'list', 'name' => 'facilities']);
});

it('refuses suggestions it cannot offer, each in words about suggestions', function (Closure $source, string $reason): void {
    suggestList($this, 'facilities.csv', "name,label\nRHU1,San Jose RHU\n");
    suggestField($this, $source($this));

    expect(suggestPublishError($this))->toContain($reason);
})->with([
    'no list chosen' => [fn () => ['kind' => 'list', 'name' => ''], 'no list file is chosen for its suggestions'],
    'a list the form does not hold' => [fn () => ['kind' => 'list', 'name' => 'clinics'], 'it suggests answers from “clinics”, which this form does not hold — upload clinics.csv or clinics.xlsx'],
    'no form picked' => [fn () => ['kind' => 'form', 'form_id' => null, 'field_key' => null], 'choose the form and the question it suggests answers from'],
    'a question the source does not share' => [fn (object $t) => ['kind' => 'form', 'form_id' => $t->source->id, 'field_key' => 'no_such_key'], 'does not share that question'],
]);

it('serves another form\'s answers as suggestions beside the schema, and on the encode page', function (): void {
    suggestField($this, ['kind' => 'form', 'form_id' => $this->source->id, 'field_key' => 'facility_name']);
    app(PublishService::class)->publish($this->form->refresh(), $this->owner);
    $form = suggestGuestForm($this);

    $this->getJson('http://acme.meridian.test/api/v1/public/linked-choices/'.shareTokenFor($form).'/'.$form->current_published_version_id)
        ->assertOk()
        ->assertJsonPath('data.lists.facility.options', [['value' => 'San Jose RHU', 'label' => 'San Jose RHU']]);

    enterTenant($this->tenant->id, $this->owner->id);
    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/forms/{$form->id}/submissions/create")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('blocks.0.fields.0.key', 'facility')
            ->where('blocks.0.fields.0.suggestions', ['San Jose RHU']));
});

it('gives the encode page a list file\'s suggestions, and no other question any', function (): void {
    suggestList($this, 'facilities.csv', "name,label\nRHU1,San Jose RHU\n");
    suggestField($this, ['kind' => 'list', 'name' => 'facilities']);
    addFormField($this->form->refresh()->draftVersion, $this->owner, 'notes', FieldType::ShortText, 2);
    app(PublishService::class)->publish($this->form->refresh(), $this->owner);

    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/forms/{$this->form->id}/submissions/create")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('blocks.0.fields.0.suggestions', ['San Jose RHU'])
            ->where('blocks.0.fields.1.suggestions', null));
});

it('accepts any text, whatever the suggestions say', function (): void {
    suggestList($this, 'facilities.csv', "name,label\nRHU1,San Jose RHU\n");
    suggestField($this, ['kind' => 'list', 'name' => 'facilities']);
    app(PublishService::class)->publish($this->form->refresh(), $this->owner);
    $form = suggestGuestForm($this);

    $this->postJson('http://acme.meridian.test/api/v1/public/f/'.shareTokenFor($form).'/submissions', [
        'answers' => ['facility' => 'A clinic nobody listed'],
    ])->assertCreated();

    enterTenant($this->tenant->id);
    expect(data_get(Submission::query()->where('form_id', $form->id)->latest('id')->firstOrFail(), 'answers.answers'))
        ->toBe(['facility' => 'A clinic nobody listed']);
});

it('lists a form that suggests from this one in the source\'s Data sharing section', function (): void {
    suggestField($this, ['kind' => 'form', 'form_id' => $this->source->id, 'field_key' => 'facility_name']);
    app(PublishService::class)->publish($this->form->refresh(), $this->owner);

    $section = app(FormSettingsPresenter::class)->dataSharing($this->source->refresh(), $this->owner);

    expect($section['used_by'])->toBe([['id' => $this->form->id, 'title' => 'Referral']]);
});

it('saves the source through the builder, and refuses a shape it does not know', function (): void {
    test()->withHeaders(builderHeaders($this->form));
    $add = $this->actingAs($this->owner)
        ->postJson("http://acme.meridian.test/forms/{$this->form->id}/fields", ['field_type' => 'short_text'])->assertOk();
    $patch = fn (mixed $source) => $this->actingAs($this->owner)
        ->patchJson("http://acme.meridian.test/forms/{$this->form->id}/fields/{$add->json('id')}", [
            'key' => $add->json('key'), 'label' => 'Facility', 'is_required' => 'optional',
            'config' => ['suggestions_source' => $source], 'validations' => [], 'version' => null,
        ]);

    $patch(['kind' => 'list', 'name' => ''])->assertOk();
    $patch(['kind' => 'form', 'form_id' => $this->source->id, 'field_key' => 'facility_name'])
        ->assertOk()->assertJsonPath('config.suggestions_source.field_key', 'facility_name');
    $patch(null)->assertOk();
    $patch(['kind' => 'everywhere'])->assertUnprocessable();
    $patch(['kind' => 'list', 'name' => 'Not A Name!'])->assertUnprocessable();
});

it('leaves the suggestions behind when a Short text becomes another text type', function (): void {
    $field = suggestField($this, ['kind' => 'form', 'form_id' => $this->source->id, 'field_key' => 'facility_name']);

    $plan = FieldTypeConversion::plan($field, [], FieldType::LongText, [], false);

    expect(array_key_exists('suggestions_source', $plan->config))->toBeFalse()
        ->and($plan->configDropped)->toContain('suggestions_source');
});
