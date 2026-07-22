<?php

declare(strict_types=1);

use App\Enums\DomainEventType;
use App\Events\DomainEvent;
use App\Events\FormPublished;
use App\Events\SubmissionCreated;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\User;
use Ramsey\Uuid\Uuid;

// The one event catalog's envelope (H4). A domain event must carry a stable, once-generated event_id and a
// SCALAR-only payload (ADR-0007 §D5) so a queued H13 webhook listener can attach without touching the
// emission site. These are the forward-safety guards for that contract.

function makeVersion(): FormVersion
{
    $version = new FormVersion(['tenant_id' => Uuid::uuid7()->toString(), 'form_id' => Uuid::uuid7()->toString(), 'version_number' => 3]);
    $version->id = Uuid::uuid7()->toString();

    return $version;
}

it('stamps a valid UUID event_id and an occurred_at once, at construction', function (): void {
    $publisher = new User;
    $publisher->id = Uuid::uuid7()->toString();

    $event = FormPublished::for(makeVersion(), $publisher);

    expect(Uuid::isValid($event->eventId))->toBeTrue();
    // Re-reading the same instance returns the SAME id (fixed for the event's life across delivery attempts).
    expect($event->envelope()['event_id'])->toBe($event->eventId);
    expect($event->occurredAt)->not->toBeNull();
});

it('builds the FormPublished envelope with the right type and an all-scalar payload', function (): void {
    $publisher = new User;
    $publisher->id = Uuid::uuid7()->toString();
    $version = makeVersion();

    $env = FormPublished::for($version, $publisher)->envelope();

    expect($env['event_type'])->toBe(DomainEventType::FormPublished->value)->toBe('form.published');
    expect($env['api_version'])->toBe(DomainEvent::API_VERSION);
    expect($env['tenant_id'])->toBe($version->tenant_id);
    expect($env['data']['form_version_id'])->toBe($version->id);

    foreach ($env['data'] as $value) {
        expect(is_scalar($value) || is_null($value))->toBeTrue();
    }
});

it('builds the SubmissionCreated envelope as a scalar payload, never carrying the model', function (): void {
    $submission = new Submission(['tenant_id' => Uuid::uuid7()->toString(), 'form_id' => Uuid::uuid7()->toString(), 'form_version_id' => Uuid::uuid7()->toString()]);
    $submission->id = Uuid::uuid7()->toString();

    $event = SubmissionCreated::for($submission);
    $env = $event->envelope();

    expect($env['event_type'])->toBe('submission.created');
    expect($env['data']['submission_id'])->toBe($submission->id);

    foreach ($env['data'] as $value) {
        expect(is_scalar($value) || is_null($value))->toBeTrue();
    }
});
