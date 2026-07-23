<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Audit;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionDraftService;
use App\Services\Submissions\SubmissionPayload;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment H9b — the authenticated encoder promote route (Group B).
|--------------------------------------------------------------------------
| POST /api/v1/submissions/{submission}/promote finalizes a saved draft to `submitted`, recording the acting
| encoder. ability:write:submissions (token scope) + can:promote,submission (the acting user's real
| permission), RLS-scoped binding. The seam the OCR review-and-confirm flow (H18/H19) reuses.
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

function promoteTenant(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => $slug]);

    return $tenant;
}

function promoteForm(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Intake');
    addFormField($form->draftVersion, $owner, 'full_name', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required]);
    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

/** Stage a draft (via the service seam, as an OCR job would) against a form's published version. */
function stagedDraft(Form $form, array $answers = ['full_name' => 'Ada']): Submission
{
    $version = FormVersion::findOrFail($form->current_published_version_id);

    return app(SubmissionDraftService::class)->saveDraft(new SubmissionPayload(
        version: $version,
        answers: $answers,
        source: SubmissionSource::OcrSingle,
        clientSubmissionUuid: Uuid::uuid7()->toString(),
    ))->submission;
}

it('promotes a draft to submitted, recording the acting encoder', function (): void {
    $tenant = promoteTenant();
    enterTenant($tenant->id);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = promoteForm($tenant, $admin);
    $draft = stagedDraft($form);
    $token = $admin->createToken('write', [ApiAbilities::WRITE_SUBMISSIONS])->plainTextToken;

    $this->withToken($token)
        ->postJson("http://acme.meridian.test/api/v1/submissions/{$draft->id}/promote")
        ->assertOk()
        ->assertJsonPath('data.id', $draft->id)
        ->assertJsonPath('data.status', 'submitted');

    enterTenant($tenant->id);
    expect(Submission::query()->findOrFail($draft->id)->status)->toBe(SubmissionStatus::Submitted);
    // The `created` audit records the acting encoder (not a null system actor).
    expect(Audit::query()->where('auditable_id', $draft->id)->where('event', 'created')->value('user_id'))
        ->toBe($admin->id);
});

it('is an idempotent no-op on an already-promoted submission', function (): void {
    $tenant = promoteTenant();
    enterTenant($tenant->id);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = promoteForm($tenant, $admin);
    $draft = stagedDraft($form);
    $token = $admin->createToken('write', [ApiAbilities::WRITE_SUBMISSIONS])->plainTextToken;
    $url = "http://acme.meridian.test/api/v1/submissions/{$draft->id}/promote";

    $this->withToken($token)->postJson($url)->assertOk();
    $this->withToken($token)->postJson($url)->assertOk()->assertJsonPath('data.status', 'submitted');

    enterTenant($tenant->id);
    expect(Submission::query()->where('status', SubmissionStatus::Submitted)->count())->toBe(1);
});

it('403s a token without the write:submissions ability', function (): void {
    $tenant = promoteTenant();
    enterTenant($tenant->id);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = promoteForm($tenant, $admin);
    $draft = stagedDraft($form);
    $token = $admin->createToken('read', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->postJson("http://acme.meridian.test/api/v1/submissions/{$draft->id}/promote")
        ->assertForbidden();
});

it('403s a member without submission-authoring permission (can:promote)', function (): void {
    $tenant = promoteTenant();
    enterTenant($tenant->id);
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'admin');
    $form = promoteForm($tenant, $owner);
    $draft = stagedDraft($form);

    // A viewer holds no submissions.create → the policy refuses even with a write-scoped token.
    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');
    $token = $viewer->createToken('write', [ApiAbilities::WRITE_SUBMISSIONS])->plainTextToken;

    $this->withToken($token)
        ->postJson("http://acme.meridian.test/api/v1/submissions/{$draft->id}/promote")
        ->assertForbidden();
});
