<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Enums\SubmissionStatus;
use App\Events\SubmissionCreated;
use App\Models\Notification as NotificationRecord;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Submissions\SubmissionReviewService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * The listeners, end to end: one domain event in, the right rows for the right people out.
 */
beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    Notification::fake();

    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    $this->form = app(FormService::class)->create($this->tenant, $this->owner, 'Intake');
});

/** A member of the tenant under test, leaving the owner as the acting user. */
function tenantMember(string $role): User
{
    $user = User::factory()->create();
    enterTenant(test()->tenant->id, $user->id);
    makeActiveMember($user, $role);
    enterTenant(test()->tenant->id, test()->owner->id);

    return $user;
}

function rowsOfType(NotificationType $type): int
{
    return NotificationRecord::query()->where('type', $type->value)->count();
}

it('tells the owner once about a new submission, not twice', function (): void {
    // The owner is in BOTH role sets (submission_received and review_requested). NotifyOnSubmissionCreated
    // subtracts the first set from the second precisely so one submission is one row for one person.
    $submission = Submission::factory()->create([
        'form_id' => $this->form->id,
        'status' => SubmissionStatus::Submitted,
    ]);

    event(SubmissionCreated::for($submission));

    enterTenant($this->tenant->id, $this->owner->id);

    expect(NotificationRecord::query()->forUser($this->owner)->count())->toBe(1)
        ->and(NotificationRecord::query()->forUser($this->owner)->first()?->type)
        ->toBe(NotificationType::SubmissionReceived);
});

it('asks a reviewer to review while telling the owner a submission arrived', function (): void {
    $reviewer = tenantMember('reviewer');
    // A reviewer with org-wide visibility is the simple case; grant-scoped reviewers are covered by
    // NotificationRecipientResolverTest. Here the point is that ONE event produces TWO different types.
    DB::table('resource_grants')->insert([
        'id' => Str::uuid7()->toString(),
        'tenant_id' => $this->tenant->id,
        'user_id' => $reviewer->id,
        'scopeable_type' => 'form',
        'scopeable_id' => $this->form->id,
        'capacity' => 'reviewer',
        'includes_descendants' => false,
        'granted_by' => $this->owner->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $submission = Submission::factory()->create([
        'form_id' => $this->form->id,
        'status' => SubmissionStatus::Submitted,
    ]);

    event(SubmissionCreated::for($submission));

    enterTenant($this->tenant->id, $this->owner->id);

    expect(rowsOfType(NotificationType::SubmissionReceived))->toBe(1)
        ->and(rowsOfType(NotificationType::ReviewRequested))->toBe(1)
        ->and(NotificationRecord::query()->forUser($reviewer)->first()?->type)
        ->toBe(NotificationType::ReviewRequested);
});

it('does not tell the encoder about their own submission', function (): void {
    $editor = tenantMember('form_editor');

    $submission = Submission::factory()->create([
        'form_id' => $this->form->id,
        'status' => SubmissionStatus::Submitted,
        'respondent_user_id' => $editor->id,
    ]);

    event(SubmissionCreated::for($submission));

    enterTenant($this->tenant->id, $this->owner->id);

    expect(NotificationRecord::query()->forUser($editor)->count())->toBe(0)
        ->and(NotificationRecord::query()->forUser($this->owner)->count())->toBe(1);
});

it('carries the form title into the payload so the bell can render without a join', function (): void {
    $submission = Submission::factory()->create([
        'form_id' => $this->form->id,
        'status' => SubmissionStatus::Submitted,
    ]);

    event(SubmissionCreated::for($submission));

    enterTenant($this->tenant->id, $this->owner->id);
    $row = NotificationRecord::query()->forUser($this->owner)->first();

    expect($row?->data['form_title'])->toBe('Intake')
        ->and($row?->data['submission_id'])->toBe($submission->id)
        ->and($row?->type->pathFor($row->data))->toBe("submissions/{$submission->id}");
});

it('notifies the respondent when their submission is approved', function (): void {
    $respondent = tenantMember('form_editor');
    $submission = Submission::factory()->create([
        'form_id' => $this->form->id,
        'status' => SubmissionStatus::Submitted,
        'respondent_user_id' => $respondent->id,
    ]);

    app(SubmissionReviewService::class)->approve($submission, $this->owner);

    enterTenant($this->tenant->id, $this->owner->id);

    expect(NotificationRecord::query()->forUser($respondent)->where('type', NotificationType::SubmissionApproved->value)->count())
        ->toBe(1);
});

it('keeps the reviewer note out of the returned notification payload', function (): void {
    $respondent = tenantMember('form_editor');
    $submission = Submission::factory()->create([
        'form_id' => $this->form->id,
        'status' => SubmissionStatus::Submitted,
        'respondent_user_id' => $respondent->id,
    ]);

    app(SubmissionReviewService::class)
        ->returnToRespondent($submission, $this->owner, 'The date of birth does not match the attached ID.');

    enterTenant($this->tenant->id, $this->owner->id);
    $row = NotificationRecord::query()->forUser($respondent)->first();

    // `data` holds identifiers and display labels; the reason lives on the submission the row links to,
    // which is the only place it can be acted on.
    expect($row?->type)->toBe(NotificationType::SubmissionReturned)
        ->and(json_encode($row?->data, JSON_THROW_ON_ERROR))->not->toContain('date of birth');
});

it('is a clean no-op when a guest submission is approved', function (): void {
    // A guest has no `respondent_user_id`, so there is nobody to tell. The listener must return quietly
    // rather than dispatch to an empty set or fail resolving a null recipient.
    $submission = Submission::factory()->create([
        'form_id' => $this->form->id,
        'status' => SubmissionStatus::Submitted,
        'respondent_user_id' => null,
    ]);

    app(SubmissionReviewService::class)->approve($submission, $this->owner);

    enterTenant($this->tenant->id, $this->owner->id);

    expect(rowsOfType(NotificationType::SubmissionApproved))->toBe(0);
    Notification::assertNothingSent();
});
