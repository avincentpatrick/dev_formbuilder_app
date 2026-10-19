<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\FeedbackParty;
use App\Enums\FeedbackSource;
use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;
use App\Enums\NotificationType;
use App\Models\Audit;
use App\Models\FeedbackComment;
use App\Models\FeedbackReport;
use App\Models\Notification as NotificationRecord;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\EventNotification;
use App\Services\Admin\SuperAdminService;
use App\Services\Feedback\FeedbackLifecycle;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M179 (`R-129c44c3`, `D151`) — who is told what about a feedback report's thread.
|--------------------------------------------------------------------------
| - **Resolved or Declined** (`feedback_closed`) now reaches the reporter AND every member who wrote in the
|   thread — a tester who said "still a problem" wants to know it was fixed again. Each person once, current
|   members only, the actor never.
| - **A support reply** (`feedback_reply`, new) reaches the same people.
| - **A tester's own move** — It works, Still a problem, a comment — tells nobody: it reaches the support team
|   through the next session's pull.
| The reply's words travel in the email only, never in `data`, which every member can read at the database
| level (the M175 rule for `feedback_closed`, kept for both).
*/

beforeEach(function (): void {
    Notification::fake();
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-thread-notify']);
    $this->reporter = User::factory()->create();
    $this->tester = User::factory()->create();
    $this->leaver = User::factory()->create();
    $this->bystander = User::factory()->create();
    enterTenant($this->tenant->id, $this->reporter->id);
    makeActiveMember($this->reporter, 'owner');
    makeActiveMember($this->tester, 'viewer');
    makeActiveMember($this->leaver, 'viewer');
    makeActiveMember($this->bystander, 'viewer');

    $this->support = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $this->report = FeedbackReport::create([
        'user_id' => $this->reporter->id,
        'type' => FeedbackType::Bug,
        'route' => '/forms',
        'remarks' => 'The label box is too small.',
        'status' => FeedbackStatus::Ongoing,
    ]);
    $this->report->forceFill(['source' => FeedbackSource::Relayed, 'reference' => 'U3', 'title' => 'Larger label box'])->save();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A thread message from `$author` (a member), written the way the lifecycle writes one. */
function notifyThreadComment(FeedbackReport $report, User $author, string $body): void
{
    app(FeedbackLifecycle::class)->comment($report, $author, $body);
}

/** @return list<string> */
function notifyRecipients(NotificationType $type): array
{
    return NotificationRecord::query()->where('type', $type->value)->pluck('user_id')->map(fn ($id): string => (string) $id)->sort()->values()->all();
}

it('tells the reporter and everyone who wrote in the thread when a report is resolved — each once, members only', function (): void {
    notifyThreadComment($this->report, $this->tester, 'Still cut off on the tablet.');
    notifyThreadComment($this->report, $this->tester, 'And on the phone.');
    notifyThreadComment($this->report, $this->reporter, 'Same here.');
    notifyThreadComment($this->report, $this->leaver, 'Me too.');
    TenantUser::query()->where('user_id', $this->leaver->id)->delete();

    app(SuperAdminService::class)->transitionFeedback($this->report->fresh(), FeedbackStatus::Resolved, $this->support, 'Fixed in version abc1234, live on staging.');

    $expected = collect([(string) $this->reporter->id, (string) $this->tester->id])->sort()->values()->all();
    expect(notifyRecipients(NotificationType::FeedbackClosed))->toBe($expected);

    foreach (NotificationRecord::query()->where('type', NotificationType::FeedbackClosed->value)->get() as $bell) {
        expect(json_encode($bell->data))->not->toContain('Fixed in version abc1234')
            ->and($bell->data['source'] ?? null)->toBe('relayed');
    }

    Notification::assertSentOnDemand(
        EventNotification::class,
        fn (EventNotification $mail, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === $this->tester->email
            && $mail->type === NotificationType::FeedbackClosed
            && str_contains($mail->body, 'Fixed in version abc1234, live on staging.'),
    );
});

it('sends a support reply to the same people, writes it to the thread as the support team, and audits it', function (): void {
    notifyThreadComment($this->report, $this->tester, 'Still cut off on the tablet.');

    app(SuperAdminService::class)->replyToFeedback($this->report->fresh(), $this->support, 'Which tablet, please?');

    /** @var FeedbackComment $reply */
    $reply = FeedbackComment::query()->where('author_kind', FeedbackParty::Support->value)->sole();
    expect($reply->user_id)->toBeNull()
        ->and($reply->body)->toBe('Which tablet, please?')
        ->and($this->report->fresh()->last_comment_at)->not->toBeNull()
        ->and($this->report->fresh()->status)->toBe(FeedbackStatus::Ongoing);

    $expected = collect([(string) $this->reporter->id, (string) $this->tester->id])->sort()->values()->all();
    expect(notifyRecipients(NotificationType::FeedbackReply))->toBe($expected);

    foreach (NotificationRecord::query()->where('type', NotificationType::FeedbackReply->value)->get() as $bell) {
        expect(json_encode($bell->data))->not->toContain('Which tablet');
    }

    /** @var Audit $audit */
    $audit = Audit::withoutTenantScope()->where('auditable_type', 'feedback_comments')->where('event', AuditEvent::Created->value)->sole();
    expect((string) $audit->tenant_id)->toBe((string) $this->tenant->id)
        ->and(json_encode($audit->new_values))->not->toContain('Which tablet');
});

it('tells nobody about a tester\'s own move — the support team reads it in the pull', function (): void {
    $this->report->forceFill(['status' => FeedbackStatus::Resolved])->save();

    app(FeedbackLifecycle::class)->confirm($this->report, $this->tester);
    app(FeedbackLifecycle::class)->reopen($this->report->fresh(), $this->tester, 'It broke again.');
    notifyThreadComment($this->report, $this->tester, 'A note.');

    expect(NotificationRecord::query()->count())->toBe(0);
});
