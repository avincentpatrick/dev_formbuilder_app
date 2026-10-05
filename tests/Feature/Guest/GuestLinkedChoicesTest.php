<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\SubmissionStatus;
use App\Enums\TenantUserStatus;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\LinkedChoiceService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M133 — GET /api/v1/public/linked-choices/{shareToken}/{version}: the choices a form takes from another form's answers.
|--------------------------------------------------------------------------
| `D60` = A: beside the schema, with its own stamp. What it must hold:
|   - only the lists the TOKEN'S OWN version links, and only for that version — guest access is tenant-wide under
|     row security, so this route's own checks are the isolation;
|   - distinct, trimmed, non-blank answers of finished responses (never a draft, a screened-out or archived one,
|     or a deleted one), sorted ignoring case, capped at 1,000; a choice question's answers as its labels;
|   - both consent keys read live: sharing off, the question unshared, or the owner losing access empties the list.
|
| ⚠️ Helpers are prefixed `linkedGuest*`: Pest loads every test file into one process.
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

    $this->source = linkedGuestSource($this->tenant, $this->owner);
    $this->destination = linkedGuestDestination($this->tenant, $this->owner, $this->source, 'referral');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A published, sharing facility register: a text question and a dropdown. */
function linkedGuestSource(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Facility Register');
    addFormField($form->draftVersion, $owner, 'facility_name', FieldType::ShortText, 1);
    addFormField($form->draftVersion, $owner, 'district', FieldType::Dropdown, 2, ['config' => ['options' => [
        ['value' => 'north', 'label' => 'North'], ['value' => 'south', 'label' => 'South'],
    ]]]);
    app(PublishService::class)->publish($form->refresh(), $owner);

    return app(FormService::class)->setDataSharing($form->refresh(), true, null, $owner);
}

/** A published guest form whose `facility` takes its choices from `$source`'s `$key`, and whose `area` from `district`. */
function linkedGuestDestination(Tenant $tenant, User $owner, Form $source, string $slug, string $key = 'facility_name'): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Referral');
    addFormField($form->draftVersion, $owner, 'facility', FieldType::Dropdown, 1, ['config' => [
        'options' => [], 'options_source' => ['form_id' => $source->id, 'field_key' => $key],
    ]]);
    addFormField($form->draftVersion, $owner, 'area', FieldType::SingleSelect, 2, ['config' => [
        'options' => [], 'options_source' => ['form_id' => $source->id, 'field_key' => 'district'],
    ]]);
    app(PublishService::class)->publish($form->refresh(), $owner);
    $form->refresh()->update(['public_slug' => $slug, 'allow_guest_submissions' => true]);

    return $form->refresh();
}

function linkedGuestUrl(Form $form, ?string $version = null, ?string $token = null): string
{
    $token ??= shareTokenFor($form);
    $version ??= (string) $form->current_published_version_id;

    return "http://acme.meridian.test/api/v1/public/linked-choices/{$token}/{$version}";
}

/** @param array<string, mixed> $answers */
function linkedGuestResponse(Form $source, array $answers, SubmissionStatus $status = SubmissionStatus::Submitted): Submission
{
    // Re-entered every time: an HTTP request leaves the test's connection without the tenant GUC row security reads.
    enterTenant($source->tenant_id);

    return seedInboxSubmission($source, null, $status, $answers);
}

/** @return list<string> */
function linkedGuestValues(array $list): array
{
    return array_map(static fn (array $o): string => $o['value'], $list['options']);
}

it('serves the distinct, trimmed answers of finished responses, sorted ignoring case, with the text as the value (D85)', function (): void {
    linkedGuestResponse($this->source, ['facility_name' => 'San Jose RHU', 'district' => 'north']);
    linkedGuestResponse($this->source, ['facility_name' => '  San Jose RHU ', 'district' => 'north']);
    linkedGuestResponse($this->source, ['facility_name' => 'mabini clinic', 'district' => 'south'], SubmissionStatus::Approved);
    linkedGuestResponse($this->source, ['facility_name' => 'Bayan Health'], SubmissionStatus::Returned);
    linkedGuestResponse($this->source, ['facility_name' => '   ']);
    linkedGuestResponse($this->source, ['facility_name' => 'Draft Only'], SubmissionStatus::Draft);
    linkedGuestResponse($this->source, ['facility_name' => 'Screened Out'], SubmissionStatus::ScreenedOut);
    linkedGuestResponse($this->source, ['facility_name' => 'Archived One'], SubmissionStatus::Archived);
    linkedGuestResponse($this->source, ['facility_name' => 'Deleted One'])->delete();

    $response = $this->getJson(linkedGuestUrl($this->destination))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-cache');
    $facility = $response->json('data.lists.facility');
    expect(linkedGuestValues($facility))->toBe(['Bayan Health', 'mabini clinic', 'San Jose RHU'])
        ->and($facility['options'][0])->toBe(['value' => 'Bayan Health', 'label' => 'Bayan Health'])
        ->and($facility['available'])->toBeTrue()
        ->and($facility['truncated'])->toBeFalse()
        ->and($facility['stamp'])->toBe(hash('sha256', (string) json_encode($facility['options'], JSON_UNESCAPED_UNICODE)));

    // A choice question's answers arrive as its LABELS, and the label is what the answer saves.
    expect(linkedGuestValues($response->json('data.lists.area')))->toBe(['North', 'South']);
});

it('moves the stamp when the list changes, and only then', function (): void {
    linkedGuestResponse($this->source, ['facility_name' => 'San Jose RHU']);
    $first = $this->getJson(linkedGuestUrl($this->destination))->json('data.lists.facility.stamp');
    expect($this->getJson(linkedGuestUrl($this->destination))->json('data.lists.facility.stamp'))->toBe($first);

    linkedGuestResponse($this->source, ['facility_name' => 'Mabini Clinic']);
    expect($this->getJson(linkedGuestUrl($this->destination))->json('data.lists.facility.stamp'))->not->toBe($first);
});

it('empties the list when the source stops sharing, stops sharing that question, or the owner loses access', function (): void {
    linkedGuestResponse($this->source, ['facility_name' => 'San Jose RHU']);
    expect($this->getJson(linkedGuestUrl($this->destination))->json('data.lists.facility.available'))->toBeTrue();

    enterTenant($this->tenant->id, $this->owner->id);
    app(FormService::class)->setDataSharing($this->source, true, ['district'], $this->owner);
    $only = $this->getJson(linkedGuestUrl($this->destination))->json('data.lists');
    expect($only['facility'])->toMatchArray(['available' => false, 'options' => []])
        ->and($only['area']['available'])->toBeTrue();

    enterTenant($this->tenant->id, $this->owner->id);
    app(FormService::class)->setDataSharing($this->source->refresh(), false, null, $this->owner);
    expect($this->getJson(linkedGuestUrl($this->destination))->json('data.lists.area.available'))->toBeFalse();

    // Sharing back on, but the destination's owner stripped of every role. (A demotion to Form editor would not do:
    // this owner created the source too, and its creator's editor grant reads its responses.)
    enterTenant($this->tenant->id, $this->owner->id);
    app(FormService::class)->setDataSharing($this->source->refresh(), true, null, $this->owner);
    expect($this->getJson(linkedGuestUrl($this->destination))->json('data.lists.facility.available'))->toBeTrue();
    enterTenant($this->tenant->id, $this->owner->id);
    $this->owner->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($this->getJson(linkedGuestUrl($this->destination))->json('data.lists.facility'))
        ->toMatchArray(['available' => false, 'options' => []]);
});

it('reads the owner\'s roles in a guest request, which starts with no permissions team', function (): void {
    // ⚠️ IN A FEATURE TEST THE REQUEST SHARES THIS PROCESS, so the team id `enterTenant()` set would leak into it and
    // hide the production path: a guest request is a fresh process, and only `EstablishTenantDatabaseContext` (never on
    // the guest group) sets a team. Cleared here, as production has it; without `ResponseReadAccess` setting one, every
    // role lookup answers from no roles and an owner who can read reads as one who cannot.
    linkedGuestResponse($this->source, ['facility_name' => 'San Jose RHU']);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    expect($this->getJson(linkedGuestUrl($this->destination))->json('data.lists.facility'))
        ->toMatchArray(['available' => true, 'options' => [['value' => 'San Jose RHU', 'label' => 'San Jose RHU']]]);
});

it('stops serving once the owner is no longer an active member, though their role remains', function (): void {
    // A guest request has no acting user, so the `users` row-security join that hides a non-active co-member from an
    // AUTHOR does not apply here: the active-membership check is what refuses.
    linkedGuestResponse($this->source, ['facility_name' => 'San Jose RHU']);
    expect($this->getJson(linkedGuestUrl($this->destination))->json('data.lists.facility.available'))->toBeTrue();

    enterTenant($this->tenant->id, $this->owner->id);
    TenantUser::query()->where('user_id', $this->owner->id)->update(['status' => TenantUserStatus::Suspended]);

    expect($this->getJson(linkedGuestUrl($this->destination))->json('data.lists.facility'))
        ->toMatchArray(['available' => false, 'options' => []]);
});

it('caps a list at 1,000 choices and says it was cut', function (): void {
    enterTenant($this->tenant->id, $this->owner->id);
    $versionId = (string) $this->source->current_published_version_id;
    $over = LinkedChoiceService::MAX_CHOICES + 5;
    DB::statement(
        "INSERT INTO submissions (id, tenant_id, form_id, form_version_id, status, source, reference, submitted_at, created_at, updated_at)
         SELECT gen_random_uuid(), ?, ?, ?, 'submitted', 'manual', 'L' || lpad(g::text, 7, '0'), now(), now(), now()
         FROM generate_series(1, {$over}) AS g",
        [$this->tenant->id, $this->source->id, $versionId],
    );
    DB::statement(
        "INSERT INTO submission_answers (submission_id, tenant_id, form_version_id, answers, attachment_refs, created_at, updated_at)
         SELECT s.id, s.tenant_id, s.form_version_id,
                jsonb_build_object('facility_name', 'Clinic ' || lpad((row_number() OVER (ORDER BY s.id))::text, 5, '0')),
                '[]'::jsonb, now(), now()
         FROM submissions s WHERE s.form_id = ?",
        [$this->source->id],
    );

    $facility = $this->getJson(linkedGuestUrl($this->destination))->json('data.lists.facility');

    expect($facility['options'])->toHaveCount(LinkedChoiceService::MAX_CHOICES)
        ->and($facility['truncated'])->toBeTrue()
        ->and($facility['options'][0]['value'])->toBe('Clinic 00001');
});

it('answers only for the token\'s own version, and a 404 for any other', function (): void {
    $other = Str::uuid7()->toString();

    $this->getJson(linkedGuestUrl($this->destination, $other))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'linked_choices_not_found');
});

it('gives another form\'s token only that form\'s lists, never this one\'s', function (): void {
    linkedGuestResponse($this->source, ['facility_name' => 'San Jose RHU']);
    enterTenant($this->tenant->id, $this->owner->id);
    $plain = guestForm($this->tenant, $this->owner, 'plain-intake');

    // Its own version: no linked question, so no list — not the destination's.
    $this->getJson(linkedGuestUrl($plain))->assertOk()->assertExactJson([
        'data' => ['version_id' => (string) $plain->current_published_version_id, 'lists' => []],
    ]);
    // The destination's version under the plain form's token: refused.
    $this->getJson(linkedGuestUrl($this->destination, token: shareTokenFor($plain)))->assertNotFound();
});

it('refuses a form whose guest access is off', function (): void {
    enterTenant($this->tenant->id, $this->owner->id);
    $token = shareTokenFor($this->destination);
    $this->destination->update(['allow_guest_submissions' => false]);

    $this->getJson(linkedGuestUrl($this->destination, token: $token))->assertNotFound();
});

it('never reads another workspace\'s form, even when a link names it', function (): void {
    linkedGuestResponse($this->source, ['facility_name' => 'San Jose RHU']);

    $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'default_locale' => 'en']);
    $other->domains()->create(['domain' => 'other']);
    $stranger = User::factory()->create();
    enterTenant($other->id, $stranger->id);
    makeActiveMember($stranger, 'admin');
    $poacher = app(FormService::class)->create($other, $stranger, 'Poacher');

    // Publish would refuse this link (the source is invisible here), and a published snapshot cannot be rewritten,
    // so the serve-time check is asked directly, of a version whose snapshot names the first workspace's form.
    $version = new FormVersion([
        'form_id' => $poacher->id,
        'schema_snapshot' => ['sections' => [], 'fields' => [['key' => 'facility', 'field_type' => 'dropdown', 'config' => [
            'options' => [], 'options_source' => ['form_id' => $this->source->id, 'field_key' => 'facility_name'],
        ]]]],
    ]);

    expect(app(LinkedChoiceService::class)->listsFor($poacher, $version)['facility'])
        ->toMatchArray(['available' => false, 'options' => []]);

    // The control: the same question asked inside the source's own workspace serves the list.
    enterTenant($this->tenant->id, $this->owner->id);
    $own = new FormVersion(['form_id' => $this->destination->id, 'schema_snapshot' => $version->schema_snapshot]);
    expect(app(LinkedChoiceService::class)->listsFor($this->destination, $own)['facility']['options'])
        ->toBe([['value' => 'San Jose RHU', 'label' => 'San Jose RHU']]);
});
