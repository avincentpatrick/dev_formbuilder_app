<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Enums\RequiredMode;
use App\Enums\SubmissionSource;
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
| Increment G8b — the authenticated offline-sync surface (Group B) end-to-end.
|--------------------------------------------------------------------------
| GET /api/v1/sync/manifest (a pinned version's snapshot + checksum) and POST /api/v1/sync/submissions
| (idempotent batch replay, per-item results). Real Sanctum tokens with withToken(), tenant from the
| subdomain, RLS isolation — mirroring ApiV1Test/GuestRuntimeTest.
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

function syncTenant(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => $slug]);

    return $tenant;
}

/** An active member of the current-context tenant (requires enterTenant already called). */
function syncMember(string $role = 'admin'): User
{
    $user = User::factory()->create();
    enterTenant((string) TenantContext::currentTenantId(), $user->id);
    makeActiveMember($user, $role);

    return $user;
}

/** A published form (required full_name + optional integer age). Requires enterTenant already called. */
function syncPublishedForm(Tenant $tenant, User $owner, string $title = 'Intake'): Form
{
    $form = app(FormService::class)->create($tenant, $owner, $title);
    addFormField($form->draftVersion, $owner, 'full_name', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required]);
    addFormField($form->draftVersion, $owner, 'age', FieldType::Integer, 1, [
        'is_queryable' => true,
        'indexed_data_type' => IndexedDataType::Number,
    ]);
    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

// ── GET /api/v1/sync/manifest ────────────────────────────────────────────────────────────────

it('returns the manifest for a pinned published version', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $form = syncPublishedForm($tenant, $admin);
    $versionId = (string) $form->current_published_version_id;
    $token = $admin->createToken('read', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->getJson("http://acme.meridian.test/api/v1/sync/manifest?form_version_id={$versionId}")
        ->assertOk()
        ->assertJsonPath('data.form_version_id', $versionId)
        ->assertJsonStructure([
            'data' => ['form_version_id', 'checksum', 'schema_snapshot' => ['sections', 'fields'], 'choice_lists', 'media_refs', 'manifest_generated_at'],
        ]);
});

it('403s a manifest request without the read:forms ability', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $form = syncPublishedForm($tenant, $admin);
    $versionId = (string) $form->current_published_version_id;
    $token = $admin->createToken('write-only', [ApiAbilities::WRITE_SUBMISSIONS])->plainTextToken;

    $this->withToken($token)
        ->getJson("http://acme.meridian.test/api/v1/sync/manifest?form_version_id={$versionId}")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'insufficient_ability');
});

it('404s an unknown version id', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $token = $admin->createToken('read', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/sync/manifest?form_version_id='.Uuid::uuid7()->toString())
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});

it('404s a draft version that was never published (no manifest)', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Draft only');
    $draftId = (string) $form->draftVersion->id;
    $token = $admin->createToken('read', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->getJson("http://acme.meridian.test/api/v1/sync/manifest?form_version_id={$draftId}")
        ->assertStatus(404);
});

it('hides another tenant\'s version under RLS (404)', function (): void {
    $other = syncTenant('beta');
    enterTenant($other->id);
    $otherOwner = syncMember('admin');
    $otherForm = syncPublishedForm($other, $otherOwner);
    $otherVersionId = (string) $otherForm->current_published_version_id;

    $tenant = syncTenant('acme');
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $token = $admin->createToken('read', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->getJson("http://acme.meridian.test/api/v1/sync/manifest?form_version_id={$otherVersionId}")
        ->assertStatus(404);
});

// ── POST /api/v1/sync/submissions ────────────────────────────────────────────────────────────

it('replays a batch with per-item created / duplicate / invalid / unknown outcomes', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $form = syncPublishedForm($tenant, $admin);
    $versionId = (string) $form->current_published_version_id;
    $token = $admin->createToken('rw', [ApiAbilities::WRITE_SUBMISSIONS])->plainTextToken;

    $u1 = Uuid::uuid7()->toString();
    $u2 = Uuid::uuid7()->toString();
    $u3 = Uuid::uuid7()->toString();
    $answers = ['full_name' => 'Ada Lovelace', 'age' => '30'];

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/sync/submissions', ['submissions' => [
            ['form_version_id' => $versionId, 'client_submission_uuid' => $u1, 'answers' => $answers],
            ['form_version_id' => $versionId, 'client_submission_uuid' => $u1, 'answers' => $answers], // duplicate uuid
            ['form_version_id' => $versionId, 'client_submission_uuid' => $u2, 'answers' => ['full_name' => '', 'age' => '30']], // required
            ['form_version_id' => Uuid::uuid7()->toString(), 'client_submission_uuid' => $u3, 'answers' => $answers], // unknown version
        ]])
        ->assertOk()
        ->assertJsonPath('data.0.status', 'created')
        ->assertJsonPath('data.1.status', 'duplicate')
        ->assertJsonPath('data.2.status', 'invalid')
        ->assertJsonPath('data.2.error.code', 'submission_invalid')
        ->assertJsonPath('data.3.status', 'error')
        ->assertJsonPath('data.3.error.code', 'form_version_not_found');

    enterTenant($tenant->id);
    expect(Submission::query()->count())->toBe(1); // only the one 'created' row persisted
});

it('persists source=offline_sync + the token user as respondent + device provenance', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $form = syncPublishedForm($tenant, $admin);
    $versionId = (string) $form->current_published_version_id;
    $token = $admin->createToken('rw', [ApiAbilities::WRITE_SUBMISSIONS])->plainTextToken;

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/sync/submissions', ['submissions' => [
            [
                'form_version_id' => $versionId,
                'client_submission_uuid' => Uuid::uuid7()->toString(),
                'answers' => ['full_name' => 'Ada', 'age' => '30'],
                'device_id' => 'dev-xyz',
                'app_version' => 'g8b-1',
            ],
        ]])
        ->assertOk()
        ->assertJsonPath('data.0.status', 'created');

    enterTenant($tenant->id);
    $submission = Submission::query()->firstOrFail();
    expect($submission->source)->toBe(SubmissionSource::OfflineSync)
        ->and($submission->respondent_user_id)->toBe($admin->id)
        ->and($submission->device_id)->toBe('dev-xyz')
        ->and($submission->app_version)->toBe('g8b-1');
});

it('is idempotent across separate requests (same uuid replays as duplicate)', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $form = syncPublishedForm($tenant, $admin);
    $versionId = (string) $form->current_published_version_id;
    $token = $admin->createToken('rw', [ApiAbilities::WRITE_SUBMISSIONS])->plainTextToken;
    $uuid = Uuid::uuid7()->toString();
    $item = ['form_version_id' => $versionId, 'client_submission_uuid' => $uuid, 'answers' => ['full_name' => 'Ada', 'age' => '30']];

    $this->withToken($token)->postJson('http://acme.meridian.test/api/v1/sync/submissions', ['submissions' => [$item]])
        ->assertOk()->assertJsonPath('data.0.status', 'created');
    $this->withToken($token)->postJson('http://acme.meridian.test/api/v1/sync/submissions', ['submissions' => [$item]])
        ->assertOk()->assertJsonPath('data.0.status', 'duplicate');

    enterTenant($tenant->id);
    expect(Submission::query()->count())->toBe(1);
});

it('reports a conflict when replaying against a superseded version', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $form = syncPublishedForm($tenant, $admin);
    $oldVersionId = (string) $form->current_published_version_id;
    app(PublishService::class)->publish($form->refresh(), $admin); // old version → superseded
    $token = $admin->createToken('rw', [ApiAbilities::WRITE_SUBMISSIONS])->plainTextToken;

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/sync/submissions', ['submissions' => [
            ['form_version_id' => $oldVersionId, 'client_submission_uuid' => Uuid::uuid7()->toString(), 'answers' => ['full_name' => 'Ada', 'age' => '30']],
        ]])
        ->assertOk()
        ->assertJsonPath('data.0.status', 'conflict')
        ->assertJsonPath('data.0.error.code', 'submission_version_superseded');
});

it('403s a batch replay without the write:submissions ability', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $token = $admin->createToken('read-only', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/sync/submissions', ['submissions' => []])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'insufficient_ability');
});
