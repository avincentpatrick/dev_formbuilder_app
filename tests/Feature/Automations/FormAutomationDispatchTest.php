<?php

declare(strict_types=1);

use App\Enums\FormAutomationAction;
use App\Enums\FormAutomationRunStatus;
use App\Enums\PlanTier;
use App\Events\SubmissionCreated;
use App\Jobs\Automations\DeliverFormAutomationWebhookJob;
use App\Jobs\Automations\SendFormAutomationEmailJob;
use App\Models\Form;
use App\Models\FormAutomation;
use App\Models\FormAutomationRun;
use App\Models\Submission;
use App\Models\User;
use App\Services\Automations\FormAutomationDispatcher;
use App\Services\Automations\FormAutomationService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M132 — a submitted response fires its form's automations (R-b7bc5149, D62 = A: on the queue only).
|--------------------------------------------------------------------------
| Through the REAL guest submit, so the event is the one `SubmissionPipeline` raises after commit and the listener is
| the auto-discovered one. The jobs themselves are faked here; what they do is the job tests'.
|
| ⚠️ Helpers are prefixed `automationDispatch*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = guestTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    $this->form = guestForm($this->tenant, $this->owner);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function automationDispatchAdd(Form $form, User $owner, FormAutomationAction $action, string $name): FormAutomation
{
    [$automation] = app(FormAutomationService::class)->create(
        $form,
        $name,
        $action,
        $action === FormAutomationAction::Email ? ['team@example.org'] : null,
        $action === FormAutomationAction::Webhook ? 'https://8.8.8.8/hook' : null,
        $owner,
    );

    return $automation;
}

function automationDispatchSubmit(Form $form): string
{
    return (string) test()->postJson('http://acme.meridian.test/api/v1/public/f/'.shareTokenFor($form).'/submissions', [
        'answers' => ['full_name' => 'Ada Lovelace', 'age' => '36'],
    ])->assertCreated()->json('data.id');
}

it('queues one run per enabled automation of the form a response was submitted to, and none for any other', function (): void {
    Queue::fake([SendFormAutomationEmailJob::class, DeliverFormAutomationWebhookJob::class]);
    $email = automationDispatchAdd($this->form, $this->owner, FormAutomationAction::Email, 'Tell the team');
    $hook = automationDispatchAdd($this->form, $this->owner, FormAutomationAction::Webhook, 'To the registry');
    $off = automationDispatchAdd($this->form, $this->owner, FormAutomationAction::Email, 'Switched off');
    $off->forceFill(['enabled' => false])->save();
    $elsewhere = automationDispatchAdd(guestForm($this->tenant, $this->owner, 'other'), $this->owner, FormAutomationAction::Email, 'Another form');

    $submissionId = automationDispatchSubmit($this->form);

    enterTenant($this->tenant->id, $this->owner->id);
    $runs = FormAutomationRun::query()->get()->keyBy('form_automation_id');
    expect($runs->keys()->sort()->values()->all())->toBe(collect([$email->id, $hook->id])->sort()->values()->all())
        ->and($runs[$email->id]->submission_id)->toBe($submissionId)
        ->and($runs[$email->id]->status)->toBe(FormAutomationRunStatus::Pending);

    Queue::assertPushed(SendFormAutomationEmailJob::class, 1);
    Queue::assertPushed(DeliverFormAutomationWebhookJob::class, 1);
    Queue::assertPushed(SendFormAutomationEmailJob::class, fn ($job) => $job->runId === $runs[$email->id]->id);
    expect($off->runs()->count())->toBe(0)->and($elsewhere->runs()->count())->toBe(0);
});

it('runs each automation at most once per event: the same event raised again queues nothing new', function (): void {
    Queue::fake([SendFormAutomationEmailJob::class]);
    automationDispatchAdd($this->form, $this->owner, FormAutomationAction::Email, 'Tell the team');
    $submissionId = automationDispatchSubmit($this->form);

    enterTenant($this->tenant->id, $this->owner->id);
    $event = SubmissionCreated::for(Submission::query()->findOrFail($submissionId));
    app(FormAutomationDispatcher::class)->dispatchFor($event);
    app(FormAutomationDispatcher::class)->dispatchFor($event);

    // One run from the real submit, one from the event raised here — and the second raise added nothing.
    expect(FormAutomationRun::query()->count())->toBe(2);
    Queue::assertPushed(SendFormAutomationEmailJob::class, 2);
});

it('records a web-address run as skipped when the plan no longer includes webhooks, and still queues the email', function (): void {
    Queue::fake([SendFormAutomationEmailJob::class, DeliverFormAutomationWebhookJob::class]);
    automationDispatchAdd($this->form, $this->owner, FormAutomationAction::Email, 'Tell the team');
    $hook = automationDispatchAdd($this->form, $this->owner, FormAutomationAction::Webhook, 'To the registry');
    assignPlanTier(PlanTier::Free);

    automationDispatchSubmit($this->form);

    enterTenant($this->tenant->id, $this->owner->id);
    $run = $hook->runs()->sole();
    expect($run->status)->toBe(FormAutomationRunStatus::Skipped)->and($run->error_code)->toBe('plan_feature');
    Queue::assertPushed(SendFormAutomationEmailJob::class, 1);
    Queue::assertNotPushed(DeliverFormAutomationWebhookJob::class);
});

it('never fails the respondent: an automation that cannot be read still leaves the response accepted', function (): void {
    Queue::fake([SendFormAutomationEmailJob::class]);
    automationDispatchAdd($this->form, $this->owner, FormAutomationAction::Email, 'Tell the team');
    // The positive control: this submit queues the automation.
    automationDispatchSubmit($this->form);
    Queue::assertPushed(SendFormAutomationEmailJob::class, 1);

    // A real failure inside the listener, rolled back with the test: the table it reads is gone.
    DB::statement('ALTER TABLE form_automations RENAME TO form_automations_unreadable');

    $submissionId = automationDispatchSubmit($this->form);

    enterTenant($this->tenant->id, $this->owner->id);
    expect(Submission::query()->whereKey($submissionId)->exists())->toBeTrue();
    Queue::assertPushed(SendFormAutomationEmailJob::class, 1);
});
