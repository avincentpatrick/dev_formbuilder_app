<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Models\Notification as NotificationRecord;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\EventNotification;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * The delivery rules: §23's absence-means-default, the two entry points, and the one asymmetry
 * ({@see NotificationType::honorsEmailPreference()}) that is surprising enough to need pinning.
 */
beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = inboxTenant('acme');
    $this->user = User::factory()->create(['email' => 'member@acme.test']);
    enterTenant($this->tenant->id, $this->user->id);
    Notification::fake();
});

function dispatcher(): NotificationDispatcher
{
    return app(NotificationDispatcher::class);
}

it('writes an in-app row and no email for the high-volume type when nobody has expressed a preference', function (): void {
    dispatcher()->dispatch(NotificationType::SubmissionReceived, [$this->user->id], ['form_title' => 'Intake']);

    expect(NotificationRecord::query()->count())->toBe(1)
        ->and(NotificationRecord::query()->first()?->emailed_at)->toBeNull();

    // The PRD's "a lead-gen owner isn't emailed per response unless they opt in", enforced.
    Notification::assertNothingSent();
});

it('emails an actionable type by default, and stamps emailed_at on the same row', function (): void {
    dispatcher()->dispatch(NotificationType::SubmissionReturned, [$this->user->id], ['form_title' => 'Intake']);

    Notification::assertSentOnDemand(EventNotification::class);

    expect(NotificationRecord::query()->count())->toBe(1)
        // §22 models an emailed notification as the SAME row with a timestamp, never a second row.
        ->and(NotificationRecord::query()->first()?->emailed_at)->not->toBeNull();
});

it('honours an explicit opt-in on the high-volume type', function (): void {
    NotificationPreference::factory()->create([
        'user_id' => $this->user->id,
        'notification_type' => NotificationType::SubmissionReceived,
    ]);

    dispatcher()->dispatch(NotificationType::SubmissionReceived, [$this->user->id], []);

    Notification::assertSentOnDemand(EventNotification::class);
});

it('honours an explicit silence on both channels', function (): void {
    NotificationPreference::factory()->silenced()->create([
        'user_id' => $this->user->id,
        'notification_type' => NotificationType::SubmissionReturned,
    ]);

    dispatcher()->dispatch(NotificationType::SubmissionReturned, [$this->user->id], []);

    expect(NotificationRecord::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('sends the email but writes no row when only the bell is silenced', function (): void {
    NotificationPreference::factory()->create([
        'user_id' => $this->user->id,
        'notification_type' => NotificationType::SubmissionApproved,
        'in_app_enabled' => false,
        'email_enabled' => true,
    ]);

    dispatcher()->dispatch(NotificationType::SubmissionApproved, [$this->user->id], []);

    // The inherent consequence of §22's one-row-two-channels model, pinned so it reads as a decision:
    // `emailed_at` is NOT a complete log of what was emailed, and nothing may count it as one.
    expect(NotificationRecord::query()->count())->toBe(0);
    Notification::assertSentOnDemand(EventNotification::class);
});

it('carries a resolved brand palette into the queued email', function (): void {
    dispatcher()->dispatch(NotificationType::SubmissionApproved, [$this->user->id], []);

    // H23a4's trap: the palette must be resolved at DISPATCH, in-request. A worker has no tenant GUC, so a
    // notification that forgets this renders unbranded — silently, with a green suite.
    Notification::assertSentOnDemand(
        EventNotification::class,
        static fn (EventNotification $notification): bool => $notification->brand !== [],
    );
});

it('does nothing at all, and issues no query, for an empty recipient set', function (): void {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    // The guest-submission path arrives here with no respondent to tell. It must be a clean no-op, not a
    // `whereIn (…)` against an empty list.
    dispatcher()->dispatch(NotificationType::SubmissionApproved, [], ['submission_id' => 'x']);

    expect($queries)->toBe(0);
    Notification::assertNothingSent();
});

it('records a site-owned type as an in-app row with emailed_at, sending nothing itself', function (): void {
    dispatcher()->record(NotificationType::ExportReady, $this->user->id, ['submission_id' => 's1'], emailed: true);

    expect(NotificationRecord::query()->count())->toBe(1)
        ->and(NotificationRecord::query()->first()?->emailed_at)->not->toBeNull();

    // GeneratePdfJob's own branded email already went out; the dispatcher must not mint a second copy.
    Notification::assertNothingSent();
});

it('still records the in-app row when the caller could not resolve an address', function (): void {
    dispatcher()->record(NotificationType::WebhookFailed, $this->user->id, ['webhook_endpoint_id' => 'w1'], emailed: false);

    expect(NotificationRecord::query()->count())->toBe(1)
        ->and(NotificationRecord::query()->first()?->emailed_at)->toBeNull();
});

it('lets a recipient silence the bell for a site-owned type without silencing its operational email', function (): void {
    NotificationPreference::factory()->silenced()->create([
        'user_id' => $this->user->id,
        'notification_type' => NotificationType::ExportReady,
    ]);

    dispatcher()->record(NotificationType::ExportReady, $this->user->id, [], emailed: true);

    // `in_app_enabled: false` is honoured…
    expect(NotificationRecord::query()->count())->toBe(0);

    // …while `email_enabled: false` on this type is not, and deliberately: the email answers a file the
    // recipient asked for by hand. `honorsEmailPreference()` is where that is argued, and I4 renders the
    // toggle as unavailable rather than offering a switch wired to nothing.
    expect(NotificationType::ExportReady->honorsEmailPreference())->toBeFalse();
});
