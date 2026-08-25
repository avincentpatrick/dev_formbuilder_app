<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Enums\RequiredMode;
use App\Enums\ResourceCapacity;
use App\Enums\SubmissionSource;
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

it('reports a content conflict when the same uuid replays with different answers (Increment G8c)', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $form = syncPublishedForm($tenant, $admin);
    $versionId = (string) $form->current_published_version_id;
    $token = $admin->createToken('rw', [ApiAbilities::WRITE_SUBMISSIONS])->plainTextToken;
    $uuid = Uuid::uuid7()->toString();

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/sync/submissions', ['submissions' => [
            ['form_version_id' => $versionId, 'client_submission_uuid' => $uuid, 'answers' => ['full_name' => 'Ada', 'age' => '30']],
        ]])
        ->assertOk()->assertJsonPath('data.0.status', 'created');

    // Same idempotency key, different content → a genuine concurrent-edit conflict (distinct from a version drift).
    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/sync/submissions', ['submissions' => [
            ['form_version_id' => $versionId, 'client_submission_uuid' => $uuid, 'answers' => ['full_name' => 'Grace', 'age' => '40']],
        ]])
        ->assertOk()
        ->assertJsonPath('data.0.status', 'conflict')
        ->assertJsonPath('data.0.error.code', 'submission_conflict');

    enterTenant($tenant->id);
    expect(Submission::query()->count())->toBe(1);
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

/*
|--------------------------------------------------------------------------
| Increment M13 — the per-form authorization neither sync route could express.
|--------------------------------------------------------------------------
| Both routes take their resource from the BODY / QUERY rather than the URL, so `can:` had nothing to bind
| to and neither carried a policy gate: `read:forms` served any form's complete authored schema, and
| `write:submissions` created submissions against any form in the tenant. The gates now live in the two
| controllers, against the same FormPolicy::view / SubmissionPolicy::create the bound-model routes use.
|
| ⚠️ THE CASES ABOVE ARE THE PASSING CONTROL AND MUST STAY GREEN UNEDITED. Every one of them acts as an
| `admin`, who holds `forms.edit.any`, so all of them pass both new gates. That is deliberate: it is what
| proves the gates refuse the unentitled rather than everybody. It is also why NONE of them could have
| caught this — the suite had never once driven either route as a scoped role.
|
| ⚠️ ONE HTTP REQUEST PER TEST, AND EVERY FIXTURE WRITE BEFORE IT — the FormHubGateTest rule. A request
| tears the tenant GUC down on its way out, so a `makeCollaborator()` issued afterwards runs with no tenant
| context and RLS refuses it ("new row violates row-level security policy for table \"resource_grants\"").
|
| ⚠️ WHAT THESE CASES CANNOT SEE. They all run inside RefreshDatabase's single transaction, so the
| partial-batch cases below prove that the surviving item was WRITTEN and its sibling was not — they do not
| prove independent commit durability across connections, which is a property of the pipeline's own
| per-item `DB::transaction` and is asserted where that lives.
|
| ── THE MUTATION MATRIX, READ AS A MATRIX (7 mutations, 0 undefended) ───────────────────────────────────
|   M1  delete the guard (the defect reintroduced)        → 4 red: the three refusals + the synthetic one
|   M2  gate on FormPolicy::view instead of                → 1 red: the SYNTHETIC one, and nothing else
|       SubmissionPolicy::create
|   M3  throw instead of returning a per-item error        → the SAME 4 as M1
|   M4  resolve the form withTrashed()                     → 1 red: the soft-deleted case
|   M5  drop the FormNotAccepting catch arm                → 2 red: closed AND capacity, separately
|   M6  hoist the manifest gate above the status filter    → 1 red: the never-published-draft case
|   M7  control — rename a local                           → survives, as a control must
|
| ⚠️ M2 IS WHY THE SYNTHETIC CASE EXISTS, AND IT REDDENS NOTHING ELSE. Every seeded role gives
| `SubmissionPolicy::create` and `FormPolicy::view` the same answer on this route, so gating on the wrong
| one of the two would have been completely invisible to the other twenty-five cases.
|
| ⚠️ M1 AND M3 SHARE THEIR REDDEN-SET AND THAT IS NOT A GAP IN THE SUITE — it is what the contract is.
| Reordering the mixed batch so the REFUSED item comes first was tried precisely to separate them, and did
| not: from a client's side "an unauthorized item is reported as its own `forbidden` result, and the batch
| continues" is ONE observable, which a missing guard and a throwing guard both violate. Nothing can
| observe a guard's absence without observing a refusal. The reorder stays because it made the case measure
| the forward direction (a refusal does not STOP what follows) rather than the backward one, which no
| implementation was going to get wrong.
*/

// ── POST /api/v1/sync/submissions — the per-item create gate ──────────────────────────────────

it('lets a Form Editor replay onto a form they hold an editor grant on', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $form = syncPublishedForm($tenant, $admin, 'Alpha');
    $versionId = (string) $form->current_published_version_id;

    $editor = syncMember('form_editor');
    makeCollaborator($form, $editor, ResourceCapacity::Editor);
    $token = $editor->createToken('rw', [ApiAbilities::WRITE_SUBMISSIONS])->plainTextToken;

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/sync/submissions', ['submissions' => [
            ['form_version_id' => $versionId, 'client_submission_uuid' => Uuid::uuid7()->toString(), 'answers' => ['full_name' => 'Ada', 'age' => '30']],
        ]])
        ->assertOk()
        ->assertJsonPath('data.0.status', 'created');

    enterTenant($tenant->id);
    expect(Submission::query()->where('form_id', $form->id)->count())->toBe(1);
});

it('refuses a Form Editor replaying onto a form they hold no grant on, and writes nothing', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $granted = syncPublishedForm($tenant, $admin, 'Alpha');
    $other = syncPublishedForm($tenant, $admin, 'Beta');
    $otherVersionId = (string) $other->current_published_version_id;

    $editor = syncMember('form_editor');
    makeCollaborator($granted, $editor, ResourceCapacity::Editor); // NOT on $other
    $token = $editor->createToken('rw', [ApiAbilities::WRITE_SUBMISSIONS])->plainTextToken;

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/sync/submissions', ['submissions' => [
            ['form_version_id' => $otherVersionId, 'client_submission_uuid' => Uuid::uuid7()->toString(), 'answers' => ['full_name' => 'Ada', 'age' => '30']],
        ]])
        ->assertOk() // the BATCH still succeeds; the ITEM is refused
        ->assertJsonPath('data.0.status', 'error')
        ->assertJsonPath('data.0.error.code', 'forbidden')
        ->assertJsonPath('data.0.error.message', 'You are not authorized to create submissions on this form.')
        ->assertJsonPath('data.0.submission', null);

    enterTenant($tenant->id);
    expect(Submission::query()->count())->toBe(0);
});

/*
| The Reviewer is the role the backlog row does not name and the sharpest instance of the gap. The row said
| "a Viewer with a write:submissions token", which is IMPOSSIBLE — that ability maps to `submissions.create`
| and a viewer does not hold it, so ApiAbilities::intersect() drops it at mint time. A reviewer DOES hold
| `submissions.create` and can mint the token; SubmissionPolicy::create() then additionally requires
| `forms.edit.any` or EDITOR capacity, and a reviewer's grant is reviewer capacity. So a Reviewer is
| authorized to encode on ZERO forms through the web app and reached EVERY form through this route.
*/
it('refuses a Reviewer replaying onto a form they hold only reviewer capacity on', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $form = syncPublishedForm($tenant, $admin, 'Alpha');
    $versionId = (string) $form->current_published_version_id;

    $reviewer = syncMember('reviewer');
    makeCollaborator($form, $reviewer, ResourceCapacity::Reviewer);
    $token = $reviewer->createToken('rw', [ApiAbilities::WRITE_SUBMISSIONS])->plainTextToken;

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/sync/submissions', ['submissions' => [
            ['form_version_id' => $versionId, 'client_submission_uuid' => Uuid::uuid7()->toString(), 'answers' => ['full_name' => 'Ada', 'age' => '30']],
        ]])
        ->assertOk()
        ->assertJsonPath('data.0.status', 'error')
        ->assertJsonPath('data.0.error.code', 'forbidden');

    enterTenant($tenant->id);
    expect(Submission::query()->count())->toBe(0);
});

/*
| The refusal is PER ITEM, not per request. A batch legitimately names several forms — that is the whole
| shape of a device outbox — so refusing the request as a whole would discard the authorized items with it.
|
| ⚠️ THE REFUSED ITEM COMES FIRST, AND THE ORDER IS THE MEASUREMENT. With it second, the case only shows
| that a refusal does not UNDO what preceded it, which no implementation was ever going to get wrong. With
| it first, the case shows that a refusal does not STOP WHAT FOLLOWS — which is the property that separates
| returning a per-item error from throwing, and the whole reason the batch survives a partial failure.
*/
it('refuses the unauthorized item without stopping the authorized one behind it', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $granted = syncPublishedForm($tenant, $admin, 'Alpha');
    $other = syncPublishedForm($tenant, $admin, 'Beta');

    $editor = syncMember('form_editor');
    makeCollaborator($granted, $editor, ResourceCapacity::Editor);
    $token = $editor->createToken('rw', [ApiAbilities::WRITE_SUBMISSIONS])->plainTextToken;

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/sync/submissions', ['submissions' => [
            ['form_version_id' => (string) $other->current_published_version_id, 'client_submission_uuid' => Uuid::uuid7()->toString(), 'answers' => ['full_name' => 'Grace', 'age' => '40']],
            ['form_version_id' => (string) $granted->current_published_version_id, 'client_submission_uuid' => Uuid::uuid7()->toString(), 'answers' => ['full_name' => 'Ada', 'age' => '30']],
        ]])
        ->assertOk()
        ->assertJsonPath('data.0.status', 'error')
        ->assertJsonPath('data.0.error.code', 'forbidden')
        ->assertJsonPath('data.1.status', 'created');

    enterTenant($tenant->id);
    expect(Submission::query()->where('form_id', $other->id)->count())->toBe(0)
        ->and(Submission::query()->where('form_id', $granted->id)->count())->toBe(1);
});

/*
| ⚠️ THE ABILITY NAME IS LOAD-BEARING AND NO SHIPPED ROLE CAN SHOW IT, WHICH IS WHY THIS CASE IS SYNTHETIC.
| `SubmissionPolicy::create` and `FormPolicy::view` — the obvious alternative, and the one the manifest
| route next door correctly uses — admit the IDENTICAL set across all five seeded roles on this route, so
| every case above passes for either. They diverge on one principal: somebody who may EDIT the form but may
| not CREATE a submission. Nothing in the product mints that member (all five roles that hold
| `forms.edit.*` also hold `submissions.create`, and `PATCH /members/{user}/role` only ever assigns one of
| the five), so this is a fail-closed structural pin rather than a live rule — the `FormHubGateTest` idiom
| and, deliberately, its exact construction. Without it, gating this route on the wrong policy is invisible.
*/
it('refuses a member who may edit the form but holds no submissions.create', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $form = syncPublishedForm($tenant, $admin, 'Alpha');
    $versionId = (string) $form->current_published_version_id;

    $stranger = syncMember('form_editor');
    $stranger->syncRoles([]);
    $stranger->syncPermissions(['forms.edit.own']); // FormPolicy::view yes, SubmissionPolicy::create no
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    makeCollaborator($form, $stranger, ResourceCapacity::Editor);
    $token = $stranger->createToken('rw', [ApiAbilities::WRITE_SUBMISSIONS])->plainTextToken;

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/sync/submissions', ['submissions' => [
            ['form_version_id' => $versionId, 'client_submission_uuid' => Uuid::uuid7()->toString(), 'answers' => ['full_name' => 'Ada', 'age' => '30']],
        ]])
        ->assertOk()
        ->assertJsonPath('data.0.status', 'error')
        ->assertJsonPath('data.0.error.code', 'forbidden');

    enterTenant($tenant->id);
    expect(Submission::query()->count())->toBe(0);
});

// ── POST /api/v1/sync/submissions — the two outcomes that used to abort the whole batch ───────

/*
| `forms` soft-deletes and `form_versions` does not, and the RLS policies on both are tenant-only with no
| deleted_at predicate — so a deleted form's versions stay fully resolvable and outlive it. The pipeline's
| own `Form::findOrFail()` therefore raised a ModelNotFoundException that replayOne() did not catch, and
| bootstrap/app.php rendered a top-level 404 for the WHOLE request after its earlier items had already
| committed: the client learned nothing about which of its rows landed, and the offending item re-raised on
| every retry, so one row stalled a device's outbox permanently.
*/
it('reports a soft-deleted form per item and still processes its sibling', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $live = syncPublishedForm($tenant, $admin, 'Alpha');
    $doomed = syncPublishedForm($tenant, $admin, 'Beta');
    $doomedVersionId = (string) $doomed->current_published_version_id; // captured BEFORE the delete
    $doomed->delete();
    $token = $admin->createToken('rw', [ApiAbilities::WRITE_SUBMISSIONS])->plainTextToken;

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/sync/submissions', ['submissions' => [
            ['form_version_id' => $doomedVersionId, 'client_submission_uuid' => Uuid::uuid7()->toString(), 'answers' => ['full_name' => 'Ada', 'age' => '30']],
            ['form_version_id' => (string) $live->current_published_version_id, 'client_submission_uuid' => Uuid::uuid7()->toString(), 'answers' => ['full_name' => 'Grace', 'age' => '40']],
        ]])
        ->assertOk()
        ->assertJsonPath('data.0.status', 'error')
        ->assertJsonPath('data.0.error.code', 'form_not_found')
        ->assertJsonPath('data.1.status', 'created');

    enterTenant($tenant->id);
    expect(Submission::query()->where('form_id', $live->id)->count())->toBe(1);
});

/*
| FormNotAcceptingSubmissionException is a SIBLING of SubmissionException rather than a subclass — every
| exception in app/Exceptions/Submissions is `final class ... extends RuntimeException` — so the
| `SubmissionException` arm never caught it and a closed or full form 403'd the whole batch. That is the
| opposite of the never-block posture the offline design is built on: a device that collected for a month
| and replayed after the form closed lost every item's result.
*/
it('reports a closed form per item, with its schedule detail, and still processes its sibling', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $open = syncPublishedForm($tenant, $admin, 'Alpha');
    $closed = syncPublishedForm($tenant, $admin, 'Beta');
    $closesAt = now()->subDay();
    $closed->forceFill(['closes_at' => $closesAt])->save();
    $token = $admin->createToken('rw', [ApiAbilities::WRITE_SUBMISSIONS])->plainTextToken;

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/sync/submissions', ['submissions' => [
            ['form_version_id' => (string) $closed->current_published_version_id, 'client_submission_uuid' => Uuid::uuid7()->toString(), 'answers' => ['full_name' => 'Ada', 'age' => '30']],
            ['form_version_id' => (string) $open->current_published_version_id, 'client_submission_uuid' => Uuid::uuid7()->toString(), 'answers' => ['full_name' => 'Grace', 'age' => '40']],
        ]])
        ->assertOk()
        ->assertJsonPath('data.0.status', 'error')
        ->assertJsonPath('data.0.error.code', 'form_closed')
        ->assertJsonPath('data.0.error.details.closes_at', $closesAt->toIso8601String())
        ->assertJsonPath('data.1.status', 'created');

    enterTenant($tenant->id);
    expect(Submission::query()->where('form_id', $open->id)->count())->toBe(1)
        ->and(Submission::query()->where('form_id', $closed->id)->count())->toBe(0);
});

/*
| The response cap is the consequence the backlog row itself cites, and it was the one that escaped: it is
| raised from INSIDE SubmissionFinalizer, so it travels the same uncaught path as the schedule refusal.
| Staged as a real overflow (cap 1, one prior finalized row) rather than a cap of zero, so the count in
| `details` is the figure a tenant would actually read.
*/
it('reports a full form per item, with its cap figures, and still processes its sibling', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $uncapped = syncPublishedForm($tenant, $admin, 'Alpha');
    $capped = syncPublishedForm($tenant, $admin, 'Beta');
    $capped->forceFill(['max_responses' => 1])->save();
    seedInboxSubmission($capped, $admin, SubmissionStatus::Submitted, ['full_name' => 'Prior']);
    $token = $admin->createToken('rw', [ApiAbilities::WRITE_SUBMISSIONS])->plainTextToken;

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/sync/submissions', ['submissions' => [
            ['form_version_id' => (string) $capped->current_published_version_id, 'client_submission_uuid' => Uuid::uuid7()->toString(), 'answers' => ['full_name' => 'Ada', 'age' => '30']],
            ['form_version_id' => (string) $uncapped->current_published_version_id, 'client_submission_uuid' => Uuid::uuid7()->toString(), 'answers' => ['full_name' => 'Grace', 'age' => '40']],
        ]])
        ->assertOk()
        ->assertJsonPath('data.0.status', 'error')
        ->assertJsonPath('data.0.error.code', 'max_responses_reached')
        ->assertJsonPath('data.0.error.details.max_responses', 1)
        ->assertJsonPath('data.0.error.details.current_count', 2)
        ->assertJsonPath('data.1.status', 'created');

    enterTenant($tenant->id);
    // The refused item rolled its own transaction back: exactly the prior row survives on the capped form.
    expect(Submission::query()->where('form_id', $capped->id)->count())->toBe(1)
        ->and(Submission::query()->where('form_id', $uncapped->id)->count())->toBe(1);
});

// ── GET /api/v1/sync/manifest — the read twin ─────────────────────────────────────────────────

it('403s a Form Editor fetching the manifest of a form they hold no grant on', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $granted = syncPublishedForm($tenant, $admin, 'Alpha');
    $other = syncPublishedForm($tenant, $admin, 'Beta');
    $otherVersionId = (string) $other->current_published_version_id;

    $editor = syncMember('form_editor');
    makeCollaborator($granted, $editor, ResourceCapacity::Editor); // NOT on $other
    $token = $editor->createToken('read', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->getJson("http://acme.meridian.test/api/v1/sync/manifest?form_version_id={$otherVersionId}")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');
});

it('serves the manifest to a Form Editor holding an editor grant on that form', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $form = syncPublishedForm($tenant, $admin, 'Alpha');
    $versionId = (string) $form->current_published_version_id;

    $editor = syncMember('form_editor');
    makeCollaborator($form, $editor, ResourceCapacity::Editor);
    $token = $editor->createToken('read', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->getJson("http://acme.meridian.test/api/v1/sync/manifest?form_version_id={$versionId}")
        ->assertOk()
        ->assertJsonPath('data.form_version_id', $versionId);
});

/*
| The gate runs AFTER the version has been resolved AND status-filtered, on purpose — hoisting it above the
| filter would turn the 404 a never-published draft already answers into a 403, confirming to someone who
| may not read the form that the version exists.
|
| ⚠️ AN UNKNOWN ID WOULD NOT MEASURE THIS AND THE FIRST DRAFT OF THIS CASE USED ONE. There is no form to
| gate on when nothing resolves, so every ordering answers 404 and the case passes vacuously. A DRAFT
| version is the one input that exists, belongs to a real form, and is still withheld — so it is the only
| shape where the two orderings differ.
*/
it('404s a never-published draft version for an unentitled caller rather than 403ing it', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Draft only');
    $draftId = (string) $form->draftVersion->id;

    $editor = syncMember('form_editor'); // no grant on that form, so the gate would refuse it
    $token = $editor->createToken('read', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->getJson("http://acme.meridian.test/api/v1/sync/manifest?form_version_id={$draftId}")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});

/*
| A BEHAVIOUR CHANGE M13 INTRODUCED, PINNED RATHER THAN LEFT IMPLICIT. Resolving the form to gate on it
| means resolving it at all, and `Form::query()` carries the SoftDeletes scope — so a deleted form's
| manifest, which used to be served happily because only the version was ever read, is now a 404. That is
| the answer `GET /forms/{form}/versions/{version}` already gives (its `{form}` binding excludes trashed)
| and the one the replay route gives per item, so all three doors now agree about a deleted form.
*/
it('404s the manifest of a soft-deleted form', function (): void {
    $tenant = syncTenant();
    enterTenant($tenant->id);
    $admin = syncMember('admin');
    $form = syncPublishedForm($tenant, $admin, 'Doomed');
    $versionId = (string) $form->current_published_version_id; // captured BEFORE the delete
    $form->delete();
    $token = $admin->createToken('read', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->getJson("http://acme.meridian.test/api/v1/sync/manifest?form_version_id={$versionId}")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});
