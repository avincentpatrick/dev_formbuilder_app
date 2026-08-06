<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Enums\ResourceCapacity;
use App\Enums\TenantUserStatus;
use App\Models\Form;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Authorization\ResourceGrantService;
use App\Services\Forms\FormService;
use App\Services\Notifications\NotificationRecipientResolver;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Who gets told, and — the half that matters — who does not.
 *
 * The rule this class must reproduce is `SubmissionPolicy::view()`: org-wide roles see every submission,
 * collaborator-scoped roles see only forms they hold a grant on. If the two ever disagree the bell announces
 * a form title and a submission to someone the inbox would 403, which is a leak rather than a cosmetic bug.
 * So the last test here asserts agreement with the policy itself rather than with a re-stated expectation.
 */
beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();

    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    $this->resolver = app(NotificationRecipientResolver::class);
});

/** A member of the tenant under test with the given role. */
function member(string $role): User
{
    $user = User::factory()->create();
    enterTenant(test()->tenant->id, $user->id);
    makeActiveMember($user, $role);
    enterTenant(test()->tenant->id, test()->owner->id);

    return $user;
}

/** A form nobody holds a creator grant on, so each test adds exactly the reach it means to test. */
function ungrantedForm(string $title = 'Intake'): Form
{
    $form = app(FormService::class)->create(test()->tenant, test()->owner, $title);
    DB::table('resource_grants')->where('scopeable_id', $form->id)->delete();

    return $form->refresh();
}

it('tells the org-wide roles about a submission without needing any grant', function (): void {
    $admin = member('admin');
    $form = ungrantedForm();

    $recipients = $this->resolver->forType(NotificationType::SubmissionReceived, $form);

    expect($recipients)->toContain($this->owner->id)->toContain($admin->id);
});

it('does not tell a form editor about a form they hold no grant on', function (): void {
    $editor = member('form_editor');
    $form = ungrantedForm();

    expect($this->resolver->forType(NotificationType::SubmissionReceived, $form))
        ->not->toContain($editor->id);
});

it('tells a form editor once they hold a grant on that form', function (): void {
    $editor = member('form_editor');
    $form = ungrantedForm();

    app(ResourceGrantService::class)->grant($this->owner, $editor, $form, ResourceCapacity::Editor);

    expect($this->resolver->forType(NotificationType::SubmissionReceived, $form))
        ->toContain($editor->id);
});

it('does not tell a viewer, who is org-wide but not on the received list', function (): void {
    $viewer = member('viewer');
    $form = ungrantedForm();

    // Viewer holds `dashboard.org.view`, so the visibility half would admit them. They are excluded by the
    // ROLE set instead: a read-only observer is not who "your data arrived" is addressed to.
    expect($this->resolver->forType(NotificationType::SubmissionReceived, $form))
        ->not->toContain($viewer->id);
});

it('routes review_requested to reviewers and submission_received to editors', function (): void {
    $editor = member('form_editor');
    $reviewer = member('reviewer');
    $form = ungrantedForm();

    app(ResourceGrantService::class)->grant($this->owner, $editor, $form, ResourceCapacity::Editor);
    app(ResourceGrantService::class)->grant($this->owner, $reviewer, $form, ResourceCapacity::Reviewer);

    expect($this->resolver->forType(NotificationType::SubmissionReceived, $form))
        ->toContain($editor->id)->not->toContain($reviewer->id)
        ->and($this->resolver->forType(NotificationType::ReviewRequested, $form))
        ->toContain($reviewer->id)->not->toContain($editor->id);
});

it('never notifies the actor about their own act', function (): void {
    $admin = member('admin');
    $form = ungrantedForm();

    expect($this->resolver->forType(NotificationType::SubmissionReceived, $form, $admin->id))
        ->not->toContain($admin->id)
        ->and($this->resolver->forType(NotificationType::SubmissionReceived, $form, $admin->id))
        ->toContain($this->owner->id);
});

it('excludes a removed member who still carries a grant', function (): void {
    $editor = member('form_editor');
    $form = ungrantedForm();
    app(ResourceGrantService::class)->grant($this->owner, $editor, $form, ResourceCapacity::Editor);

    TenantUser::query()->where('user_id', $editor->id)->update(['status' => TenantUserStatus::Removed]);

    expect($this->resolver->forType(NotificationType::SubmissionReceived, $form))
        ->not->toContain($editor->id);
});

it('returns nobody for a type whose recipient the emitting site names', function (): void {
    member('admin');

    // The four addressed types must fall straight through — guessing a role set for "your submission was
    // approved" would tell the whole tenant about one person's response.
    foreach ([
        NotificationType::SubmissionApproved,
        NotificationType::SubmissionReturned,
        NotificationType::ExportReady,
        NotificationType::WebhookFailed,
    ] as $type) {
        expect($this->resolver->forType($type, null))->toBe([]);
    }
});

it('resolves a whole tenant in a bounded number of queries', function (): void {
    $form = ungrantedForm();

    // Twelve collaborator-scoped members: the shape that turns a per-candidate grant lookup into twelve
    // queries inside a public guest POST. ResourceGrantResolver::primeFor() is what keeps this flat, and
    // without this bound a regression to per-user holdsAny() would pass every other test in the file.
    for ($i = 0; $i < 12; $i++) {
        $editor = member('form_editor');
        app(ResourceGrantService::class)->grant($this->owner, $editor, $form, ResourceCapacity::Editor);
    }

    app(ResourceGrantResolver::class)->forget();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $recipients = $this->resolver->forType(NotificationType::SubmissionReceived, $form);

    expect($recipients)->toHaveCount(13) // twelve editors + the owner
        ->and($queries)->toBeLessThanOrEqual(6);
});

it('agrees with SubmissionPolicy about every candidate it keeps and every one it drops', function (): void {
    $granted = member('form_editor');
    $ungranted = member('form_editor');
    $reviewer = member('reviewer');
    $form = ungrantedForm();

    app(ResourceGrantService::class)->grant($this->owner, $granted, $form, ResourceCapacity::Editor);

    $submission = Submission::factory()->create(['form_id' => $form->id]);
    $recipients = $this->resolver->forType(NotificationType::SubmissionReceived, $form);

    // The anti-drift assertion: everyone told can open what they were told about, and everyone dropped
    // could not have. Anything else is the bell describing a submission the inbox denies.
    foreach ([$this->owner, $granted] as $included) {
        expect($recipients)->toContain($included->id)
            ->and(Gate::forUser($included)->allows('view', $submission))->toBeTrue();
    }

    foreach ([$ungranted, $reviewer] as $excluded) {
        expect($recipients)->not->toContain($excluded->id);
    }

    expect(Gate::forUser($ungranted)->allows('view', $submission))->toBeFalse();
});
