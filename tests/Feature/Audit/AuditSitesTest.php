<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\ResourceCapacity;
use App\Enums\SubmissionSource;
use App\Events\FormPublished;
use App\Models\Audit;
use App\Models\FormVersion;
use App\Models\ResourceGrant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\ResourceGrantService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The six retired TODO(audits) sites (H4) — each emits the right ledger row, IN the same transaction as the
| change it records. This is the increment's payoff: grant/revoke, ownership transfer, publish, and the
| submission pipeline now leave an audit trail. The super-admin suspend/reactivate sites are proven
| separately in AuditSuperAdminSitesTest.
|
| ⚠️ THIS FILE'S IDENTITY IS "H4's SIX SITES". DO NOT GROW IT.
| H4 counted six `TODO(audit)` markers and retired them; a SEVENTH survived uncounted at
| SubmissionReviewService::apply(), because the four review verbs funnel through one private method its
| inventory never walked. Increment I2 retired that one and closed the rest of the coverage — form
| create/metadata/scope/save-resume/confirmation/schedule/archive, version restore, tenant draft settings,
| branding, and the custom-domain lifecycle — and those live in their own files:
|
|   tests/Feature/Audit/AuditCoverageTest.php     — the I2 sites, including the 7th
|   tests/Feature/Audit/AuditDomainSitesTest.php  — the domain sites and their two RLS hazards
|
| Note there is no numeric assertion here that "six" holds; the only count in the file is the pair of
| permission_changed rows an ownership transfer emits, which I2 does not touch. The six is prose, and a
| future increment that adds coverage should add a file rather than edit this sentence.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();

    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
    $this->actingAs($this->admin); // so AuditLogger's Auth::id() fallback resolves the acting admin

    $this->member = User::factory()->create();
    makeActiveMember($this->member, 'form_editor');

    $this->form = app(FormService::class)->create($this->tenant, $this->admin, 'Survey');
});

/** Publish a one-field form and return the published version. */
function publishAuditForm(Tenant $tenant, User $user): FormVersion
{
    $form = app(FormService::class)->create($tenant, $user, 'Audited Survey');
    addFormField($form->draftVersion, $user, 'q1');

    return app(PublishService::class)->publish($form->refresh(), $user);
}

it('audits a grant as `created` on resource_grants, with the target and reach', function (): void {
    $grant = app(ResourceGrantService::class)->grant($this->admin, $this->member, $this->form, ResourceCapacity::Reviewer);

    $audit = Audit::query()
        ->where('auditable_type', 'resource_grants')
        ->where('auditable_id', $grant->id)
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->event)->toBe(AuditEvent::Created);
    expect($audit->new_values['capacity'])->toBe('reviewer');
    expect($audit->new_values['scopeable_type'])->toBe('form');
    expect($audit->new_values['user_id'])->toBe($this->member->id);
    expect($audit->user_id)->toBe($this->admin->id);
});

it('audits a revoke as `deleted`, capturing the grant BEFORE the hard-delete', function (): void {
    $grant = app(ResourceGrantService::class)->grant($this->admin, $this->member, $this->form, ResourceCapacity::Editor);
    $grantId = $grant->id;

    app(ResourceGrantService::class)->revoke($grant);

    $audit = Audit::query()
        ->where('auditable_type', 'resource_grants')
        ->where('auditable_id', $grantId)
        ->where('event', AuditEvent::Deleted->value)
        ->first();

    // The grant is gone, but the audit is the only remaining record it ever existed (spec §1).
    expect(ResourceGrant::query()->whereKey($grantId)->exists())->toBeFalse();
    expect($audit)->not->toBeNull();
    expect($audit->old_values['capacity'])->toBe('editor');
    expect($audit->old_values['user_id'])->toBe($this->member->id);
});

it('audits an ownership transfer as two permission_changed rows plus a tenant update', function (): void {
    // The tenant must already HAVE an owner so the transfer both promotes the successor and demotes the
    // incumbent — that is what produces the two permission_changed rows.
    $owner = User::factory()->create();
    makeActiveMember($owner, 'owner');
    $this->tenant->forceFill(['owner_user_id' => $owner->id])->save();

    $successor = User::factory()->create();
    makeActiveMember($successor, 'admin');

    app(TenantMembershipService::class)->transferOwnership($this->tenant, $successor, $owner);

    $permissionChanges = Audit::query()->where('event', AuditEvent::PermissionChanged->value)->get();
    expect($permissionChanges)->toHaveCount(2);
    // Both are recorded against the affected USER row, not the composite-key model_has_roles pivot.
    expect($permissionChanges->pluck('auditable_type')->unique()->all())->toBe(['users']);
    expect($permissionChanges->firstWhere('auditable_id', $successor->id)->new_values['role'])->toBe('owner');
    expect($permissionChanges->firstWhere('auditable_id', $owner->id)->new_values['role'])->toBe('admin');

    $tenantUpdate = Audit::query()
        ->where('auditable_type', 'tenant')
        ->where('event', AuditEvent::Updated->value)
        ->first();
    expect($tenantUpdate)->not->toBeNull();
    expect($tenantUpdate->new_values['owner_user_id'])->toBe($successor->id);
});

it('audits a publish as `published` on the form_version AND dispatches FormPublished post-commit', function (): void {
    Event::fake([FormPublished::class]);

    $version = publishAuditForm($this->tenant, $this->admin);

    $audit = Audit::query()
        ->where('auditable_type', 'form_version')
        ->where('auditable_id', $version->id)
        ->where('event', AuditEvent::Published->value)
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->new_values['status'])->toBe('published');
    expect($audit->old_values['status'])->toBe('draft');

    Event::assertDispatched(FormPublished::class, fn (FormPublished $e): bool => $e->formVersionId === $version->id
        && $e->publishedByUserId === $this->admin->id);
});

it('audits a submission as `created`, with head-row attributes and NO guest PII', function (): void {
    $version = publishAuditForm($this->tenant, $this->admin);

    $result = app(SubmissionPipeline::class)->submit(new SubmissionPayload(
        version: $version,
        answers: ['q1' => 'hello'],
        source: SubmissionSource::Manual,
        respondentUserId: $this->admin->id,
    ));

    $audit = Audit::query()
        ->where('auditable_type', 'submission')
        ->where('auditable_id', $result->submission->id)
        ->where('event', AuditEvent::Created->value)
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->new_values['form_id'])->toBe($version->form_id);
    expect($audit->new_values['status'])->toBe('submitted');
    // The ledger must never carry guest PII (spec §2) — the pipeline records head attributes only.
    expect($audit->new_values)->not->toHaveKey('guest_ip');
    expect($audit->new_values)->not->toHaveKey('guest_contact_email');
});
