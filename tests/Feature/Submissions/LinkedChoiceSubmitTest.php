<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M133 — an answer to a question that takes its choices from another form is ACCEPTED, whatever the list says now.
|--------------------------------------------------------------------------
| ⛔ The row's one hard rule: nothing in Connect project v1 may break offline submission. A device answers from the
| list it fetched — perhaps yesterday — and the source may have changed since. So the server never checks a linked
| answer against the live list: the snapshot holds no typed choices, both engines skip membership, and these cases
| prove it end to end on the guest route and on the sync replay, with the answer stored as the text chosen (`D85`).
| The encode page, where a keyer is online, shows the live list.
|
| ⚠️ Helpers are prefixed `linkedSubmit*`: Pest loads every test file into one process.
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

    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Referral');
    addFormField($form->draftVersion, $this->owner, 'facility', FieldType::Dropdown, 1, ['config' => [
        'options' => [], 'options_source' => ['form_id' => $this->source->id, 'field_key' => 'facility_name'],
    ]]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);
    $form->refresh()->update(['public_slug' => 'referral', 'allow_guest_submissions' => true]);
    $this->destination = $form->refresh();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function linkedSubmitStored(Tenant $tenant, Form $form): ?array
{
    enterTenant($tenant->id);
    $submission = Submission::query()->where('form_id', $form->id)->latest('id')->first();

    return $submission === null ? null : data_get($submission, 'answers.answers');
}

it('accepts a guest answer from the list, stored as the text chosen', function (): void {
    $this->postJson('http://acme.meridian.test/api/v1/public/f/'.shareTokenFor($this->destination).'/submissions', [
        'answers' => ['facility' => 'San Jose RHU'],
    ])->assertCreated();

    expect(linkedSubmitStored($this->tenant, $this->destination))->toBe(['facility' => 'San Jose RHU']);
});

it('accepts a guest answer the list no longer holds, because the device answered from yesterday\'s list', function (): void {
    $this->postJson('http://acme.meridian.test/api/v1/public/f/'.shareTokenFor($this->destination).'/submissions', [
        'answers' => ['facility' => 'Old Bayan Clinic'],
    ])->assertCreated();

    expect(linkedSubmitStored($this->tenant, $this->destination))->toBe(['facility' => 'Old Bayan Clinic']);
});

it('accepts it after the source stops sharing too — consent governs what is SHOWN, never what was answered', function (): void {
    enterTenant($this->tenant->id, $this->owner->id);
    app(FormService::class)->setDataSharing($this->source->refresh(), false, null, $this->owner);

    $this->postJson('http://acme.meridian.test/api/v1/public/f/'.shareTokenFor($this->destination).'/submissions', [
        'answers' => ['facility' => 'San Jose RHU'],
    ])->assertCreated();
});

it('accepts a stale answer on the offline sync replay, per item', function (): void {
    enterTenant($this->tenant->id, $this->owner->id);
    $token = $this->owner->createToken('rw', [ApiAbilities::WRITE_SUBMISSIONS])->plainTextToken;

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/sync/submissions', ['submissions' => [[
            'form_version_id' => (string) $this->destination->current_published_version_id,
            'client_submission_uuid' => Uuid::uuid7()->toString(),
            'answers' => ['facility' => 'Old Bayan Clinic'],
        ]]])
        ->assertOk()
        ->assertJsonPath('data.0.status', 'created');

    expect(linkedSubmitStored($this->tenant, $this->destination))->toBe(['facility' => 'Old Bayan Clinic']);
});

it('shows a keyer the live list on the encode page', function (): void {
    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/forms/{$this->destination->id}/submissions/create")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('submissions/Encode', false)
            ->where('blocks.0.fields.0.key', 'facility')
            ->where('blocks.0.fields.0.options', [['value' => 'San Jose RHU', 'label' => 'San Jose RHU']]));
});
