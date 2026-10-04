<?php

declare(strict_types=1);

use App\Enums\FormAutomationAction;
use App\Enums\FormAutomationRunStatus;
use App\Jobs\Automations\SendFormAutomationEmailJob;
use App\Models\FormAutomationRun;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\Automations\FormAutomationNotification;
use App\Services\Automations\FormAutomationService;
use App\Support\Submissions\SubmissionReference;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantUrl;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M132 — an email automation sends a notice and a link, never an answer (R-b7bc5149, D82).
|--------------------------------------------------------------------------
| The job is run directly, under the tenant context its base establishes, with notifications faked; the message's
| own text is rendered from the faked notification, so what is asserted is what a recipient would read.
|
| ⚠️ Helpers are prefixed `automationMail*`: Pest loads every test file into one process.
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

    Queue::fake();
    app(FormAutomationService::class)->create($this->form, 'Tell the team', FormAutomationAction::Email, ['nurse@example.org', 'lead@example.org'], null, $this->owner);
    $this->submissionId = (string) $this->postJson('http://acme.meridian.test/api/v1/public/f/'.shareTokenFor($this->form).'/submissions', [
        'answers' => ['full_name' => 'Ada Lovelace', 'age' => '36'],
    ])->assertCreated()->json('data.id');
    enterTenant($this->tenant->id, $this->owner->id);
    $this->run = FormAutomationRun::query()->sole();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('sends each address its own notice, and marks the run sent', function (): void {
    Notification::fake();

    (new SendFormAutomationEmailJob($this->tenant->id, $this->run->id))->handle();

    $sentTo = [];
    Notification::assertSentOnDemand(FormAutomationNotification::class, function (FormAutomationNotification $n, array $channels, AnonymousNotifiable $to) use (&$sentTo): bool {
        $sentTo[] = $to->routes['mail'];

        return true;
    });
    Notification::assertSentOnDemandTimes(FormAutomationNotification::class, 2);
    expect($sentTo)->toBe(['nurse@example.org', 'lead@example.org']);

    enterTenant($this->tenant->id, $this->owner->id);
    expect($this->run->refresh()->status)->toBe(FormAutomationRunStatus::Succeeded)->and($this->run->attempt_count)->toBe(1);
});

it('says which form, which response and when, links to the response in the app, and never carries an answer', function (): void {
    Notification::fake();

    (new SendFormAutomationEmailJob($this->tenant->id, $this->run->id))->handle();

    $mail = null;
    Notification::assertSentOnDemand(FormAutomationNotification::class, function (FormAutomationNotification $n, array $channels, AnonymousNotifiable $to) use (&$mail): bool {
        $mail = $n->toMail($to);

        return true;
    });

    enterTenant($this->tenant->id, $this->owner->id);
    $submission = Submission::query()->findOrFail($this->submissionId);
    $text = implode("\n", [$mail->subject, ...$mail->introLines, ...$mail->outroLines, (string) $mail->actionUrl]);

    expect($mail->subject)->toBe('New response to Intake')
        ->and($text)->toContain('Reference: '.SubmissionReference::format($submission->reference))
        ->and($mail->actionUrl)->toBe(TenantUrl::to($this->tenant, 'submissions/'.$submission->id))
        // The answers stay behind the link: the respondent's name appears nowhere in the message. (The age is not
        // checked: a two-digit number can occur in the time or the reference by chance.)
        ->and(str_contains($text, 'Ada Lovelace'))->toBeFalse();
});

it('sends nothing for an automation switched off before its turn came, and says so', function (): void {
    Notification::fake();
    $this->run->automation->forceFill(['enabled' => false])->save();

    (new SendFormAutomationEmailJob($this->tenant->id, $this->run->id))->handle();

    Notification::assertNothingSent();
    enterTenant($this->tenant->id, $this->owner->id);
    expect($this->run->refresh()->status)->toBe(FormAutomationRunStatus::Skipped)->and($this->run->error_code)->toBe('disabled');
});
