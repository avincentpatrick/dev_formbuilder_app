<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Enums\SubmissionPdfOutcome;
use App\Support\Notifications\NotificationCopy;

/*
|--------------------------------------------------------------------------
| The words in a notification, both renderings (Increments I3 + I4).
|
| `NotificationCopy` shipped in I3 with NO test anywhere in the suite; I4 both adds a second public method
| and corrects a live defect in the first, so this is the moment to cover it.
|
| Pure: no container, no database, no morph map. Nothing here may touch a model or a config — a Unit test
| runs with an EMPTY container, and the first Eloquent cast would die on "connection resolver".
*/

it('gives every type a non-empty email headline and body', function (NotificationType $type): void {
    $copy = NotificationCopy::for($type, 'Acme Research', []);

    expect($copy['headline'])->not->toBe('')->and($copy['body'])->not->toBe('');
})->with(NotificationType::cases());

it('gives every type a non-empty in-app title and description', function (NotificationType $type): void {
    $copy = NotificationCopy::inApp($type, []);

    expect($copy['title'])->not->toBe('')->and($copy['description'])->not->toBe('');
})->with(NotificationType::cases());

it('degrades gracefully on a payload missing every optional key', function (NotificationType $type): void {
    // `data` is a six-arm union: `form_title` is nullable on two types and absent on two others,
    // `form_id` is absent on a third. An empty payload is the worst case of all of them at once, and it
    // must produce a sentence rather than "Your response to  was approved."
    $copy = NotificationCopy::inApp($type, []);

    expect($copy['description'])->not->toContain('  ')
        ->and($copy['description'])->toEndWith('.');
})->with(NotificationType::cases());

it('uses the type label as the in-app title, except for a failed export', function (NotificationType $type): void {
    expect(NotificationCopy::inApp($type, [])['title'])->toBe($type->label());
})->with(array_filter(NotificationType::cases(), fn (NotificationType $t): bool => $t !== NotificationType::ExportReady));

it('never names the tenant in the in-app copy', function (NotificationType $type): void {
    // The email stands alone in an inbox weeks later, so it names the workspace. A bell row is read INSIDE
    // that workspace, three seconds after the fact. `inApp()` takes no $tenantName and must never grow one.
    $copy = NotificationCopy::inApp($type, ['form_title' => 'Clinic Intake', 'email' => 'a@b.test']);

    expect($copy['title'].' '.$copy['description'])->not->toContain('Acme Research');
})->with(NotificationType::cases());

it('falls back when form_title is null on the two review outcomes', function (NotificationType $type): void {
    $copy = NotificationCopy::inApp($type, ['submission_id' => 'x', 'form_title' => null]);

    expect($copy['description'])->toContain('one of your forms');
})->with([NotificationType::SubmissionApproved, NotificationType::SubmissionReturned]);

it('keeps the reviewer\'s reason out of a returned notification', function (): void {
    $copy = NotificationCopy::inApp(NotificationType::SubmissionReturned, [
        'form_title' => 'Clinic Intake',
        // Not a key the listener writes — this asserts the copy could not surface it even if it appeared.
        'returned_reason' => 'Please confirm the date of birth.',
    ]);

    expect($copy['description'])->not->toContain('date of birth');
});

it('says an export is READY only when it actually is', function (SubmissionPdfOutcome $outcome): void {
    // GeneratePdfJob records an export_ready row for EVERY terminal outcome, so a quota-blocked or failed
    // export previously produced a bell row headed "Export ready" over a submission with no artifact.
    $copy = NotificationCopy::inApp(NotificationType::ExportReady, [
        'form_title' => 'Clinic Intake',
        'outcome' => $outcome->value,
    ]);

    expect($copy['title'])->toBe('Export failed')
        ->and($copy['description'])->toContain('could not be generated');
})->with([SubmissionPdfOutcome::QuotaExceeded, SubmissionPdfOutcome::Failed]);

it('still says ready for a successful export, and for a payload with no outcome at all', function (): void {
    // A missing `outcome` reads as success rather than as failure: the key has been on the payload since
    // the row's first day, so its absence means a hand-built or future payload, and calling that "failed"
    // is the worse of the two wrong answers.
    foreach ([['outcome' => SubmissionPdfOutcome::Ready->value], []] as $extra) {
        $copy = NotificationCopy::inApp(NotificationType::ExportReady, ['form_title' => 'Clinic Intake', ...$extra]);

        expect($copy['title'])->toBe('Export ready')
            ->and($copy['description'])->toContain('finished generating');
    }
});

it('names the invitee and their role', function (): void {
    $copy = NotificationCopy::inApp(NotificationType::MemberInvited, [
        'email' => 'new@example.test',
        'role' => 'form_editor',
    ]);

    // The underscore is a database value, not a word anyone says out loud.
    expect($copy['description'])->toBe('new@example.test was invited as form editor.');
});

it('names the paused endpoint without repeating its failure count', function (): void {
    $copy = NotificationCopy::inApp(NotificationType::WebhookFailed, [
        'endpoint_name' => 'CRM sync',
        'failure_count' => 20,
    ]);

    // The count is on /webhooks/{id}, which is where the row links — a bell row is not a dashboard.
    expect($copy['description'])->toContain('CRM sync')->and($copy['description'])->not->toContain('20');
});
