<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Form;
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
| Increment F4b — manual-encoding HTTP wiring through the real subdomain pipeline.
|--------------------------------------------------------------------------
| Proves SubmissionPolicy::create on the encode routes end-to-end (permission + per-form collaborator
| scope + published), and that `store` funnels answers into the SubmissionPipeline and maps its per-field
| SubmissionValidationException to a redirect-back-with-errors. Tenants resolve on a subdomain of the
| central domain (config/tenancy.php → meridian.test).
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

/** The demo tenant reachable at acme.meridian.test. */
function encodeTenant(): Tenant
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);

    return $tenant;
}

/** A published form (required `full_name` + optional `age`) created + collaborated by $owner. */
function publishedEncodeForm(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Intake');
    addFormField($form->draftVersion, $owner, 'full_name', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required]);
    addFormField($form->draftVersion, $owner, 'age', FieldType::Integer, 1);
    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

it('forbids a Viewer from the manual-encode page', function (): void {
    $tenant = encodeTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = publishedEncodeForm($tenant, $owner);

    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer'); // no submissions.create

    $this->actingAs($viewer)
        ->get("http://acme.meridian.test/forms/{$form->id}/submissions/create")
        ->assertForbidden();
});

it('cannot encode a draft-only (unpublished) form', function (): void {
    $tenant = encodeTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Draft only'); // never published

    $this->actingAs($admin)
        ->get("http://acme.meridian.test/forms/{$form->id}/submissions/create")
        ->assertForbidden();
});

it('renders the encode page and records a submission for an Admin', function (): void {
    $this->withoutVite(); // the GET renders the Inertia root view; CI tests job builds no assets
    $tenant = encodeTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = publishedEncodeForm($tenant, $admin);

    $this->actingAs($admin)
        ->get("http://acme.meridian.test/forms/{$form->id}/submissions/create")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('submissions/Encode', false)->has('blocks'));

    $this->actingAs($admin)
        ->post("http://acme.meridian.test/forms/{$form->id}/submissions", [
            'answers' => ['full_name' => 'Ada Lovelace', 'age' => '36'],
        ])
        ->assertRedirect()
        ->assertSessionHas('toast');

    enterTenant($tenant->id, $admin->id);
    expect(Submission::query()->count())->toBe(1);
    $submission = Submission::query()->firstOrFail();
    expect($submission->status)->toBe(SubmissionStatus::Submitted)
        ->and($submission->source)->toBe(SubmissionSource::Manual)
        ->and($submission->respondent_user_id)->toBe($admin->id)
        ->and(SubmissionAnswer::query()->findOrFail($submission->id)->answers)
        ->toEqual(['full_name' => 'Ada Lovelace', 'age' => '36']);
});

it('bounces a missing required answer back with a per-field error and persists nothing', function (): void {
    $tenant = encodeTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = publishedEncodeForm($tenant, $admin);

    $this->actingAs($admin)
        ->post("http://acme.meridian.test/forms/{$form->id}/submissions", [
            'answers' => ['full_name' => '', 'age' => '36'],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('answers.full_name');

    enterTenant($tenant->id, $admin->id);
    expect(Submission::query()->count())->toBe(0)
        ->and(SubmissionAnswer::query()->count())->toBe(0);
});

it('confines encoding to a form the editor collaborates on (.own)', function (): void {
    $tenant = encodeTenant();
    $editor = User::factory()->create();
    $other = User::factory()->create();

    enterTenant($tenant->id, $editor->id);
    $mine = publishedEncodeForm($tenant, $editor); // editor is the creator → an editor collaborator
    makeActiveMember($editor, 'form_editor');

    enterTenant($tenant->id, $other->id);
    $theirs = publishedEncodeForm($tenant, $other);

    // The editor can encode their own published form …
    $this->actingAs($editor)
        ->post("http://acme.meridian.test/forms/{$mine->id}/submissions", [
            'answers' => ['full_name' => 'Grace Hopper', 'age' => '45'],
        ])
        ->assertRedirect();

    // … but not a form they don't collaborate on (no form_collaborators row → policy denies → 403).
    $this->actingAs($editor)
        ->get("http://acme.meridian.test/forms/{$theirs->id}/submissions/create")
        ->assertForbidden();
});
