<?php

declare(strict_types=1);

use App\Enums\DomainEventType;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Events\DomainEvent;
use App\Events\FormPublished;
use App\Events\MemberInvited;
use App\Events\SubmissionApproved;
use App\Events\SubmissionCreated;
use App\Events\SubmissionReturned;
use App\Events\SubmissionUpdated;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\Tenant;
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
    $submission = new Submission([
        'tenant_id' => Uuid::uuid7()->toString(),
        'form_id' => Uuid::uuid7()->toString(),
        'form_version_id' => Uuid::uuid7()->toString(),
        'status' => SubmissionStatus::Submitted,
        'source' => SubmissionSource::Guest,
    ]);
    $submission->id = Uuid::uuid7()->toString();

    $event = SubmissionCreated::for($submission);
    $env = $event->envelope();

    expect($env['event_type'])->toBe('submission.created');
    expect($env['data']['submission_id'])->toBe($submission->id);
    // H13a enriched the payload to the design-doc §3 shape (identifiers + metadata, never the answers).
    expect($env['data']['status'])->toBe('submitted');
    expect($env['data']['source'])->toBe('guest');
    expect($env['data'])->not->toHaveKey('answers');

    foreach ($env['data'] as $value) {
        expect(is_scalar($value) || is_null($value))->toBeTrue();
    }
});

it('builds the SubmissionApproved envelope to the pre-specified four-key shape', function (): void {
    $submission = new Submission([
        'tenant_id' => Uuid::uuid7()->toString(),
        'form_id' => Uuid::uuid7()->toString(),
        'status' => SubmissionStatus::Approved,
        'source' => SubmissionSource::Manual,
    ]);
    $submission->id = Uuid::uuid7()->toString();

    $reviewer = new User;
    $reviewer->id = Uuid::uuid7()->toString();

    $env = SubmissionApproved::for($submission, $reviewer)->envelope();

    expect($env['event_type'])->toBe('submission.approved');
    // docs/webhook-integration-design.md §3 pre-specified this shape years before anything emitted it.
    // Asserted as an EXACT key set, so a later "just one more field" widening reddens here rather than
    // silently pushing something new to every subscribed third-party endpoint.
    expect(array_keys($env['data']))->toBe(['submission_id', 'form_id', 'validated_by', 'validated_at']);
    expect($env['data']['validated_by'])->toBe($reviewer->id);
});

it('keeps the reviewer note out of the SubmissionReturned envelope', function (): void {
    $submission = new Submission([
        'tenant_id' => Uuid::uuid7()->toString(),
        'form_id' => Uuid::uuid7()->toString(),
        'status' => SubmissionStatus::Returned,
        'source' => SubmissionSource::Manual,
        'returned_reason' => 'The date of birth does not match the attached ID.',
        'remarks' => 'Third time this week.',
    ]);
    $submission->id = Uuid::uuid7()->toString();

    $reviewer = new User;
    $reviewer->id = Uuid::uuid7()->toString();

    $env = SubmissionReturned::for($submission, $reviewer, SubmissionStatus::UnderReview->value)->envelope();

    expect($env['event_type'])->toBe('submission.returned');
    expect(array_keys($env['data']))
        ->toBe(['submission_id', 'form_id', 'previous_status', 'validated_by', 'validated_at']);
    expect($env['data']['previous_status'])->toBe('under_review');

    // §3's default-exclude-sensitive-content principle: a webhook endpoint is a third-party destination the
    // tenant configures and the platform cannot vet, so reviewer prose about someone's answers does not go
    // there. The respondent reads it on the submission, where it can actually be acted on.
    $encoded = json_encode($env, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('date of birth')
        ->and($encoded)->not->toContain('Third time');
});

it('never carries an invite token or accept URL in the MemberInvited envelope', function (): void {
    $tenant = new Tenant(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->id = Uuid::uuid7()->toString();

    $actor = new User;
    $actor->id = Uuid::uuid7()->toString();

    $env = MemberInvited::for($tenant, 'newcomer@example.test', 'reviewer', $actor, '2026-08-13T00:00:00+00:00')
        ->envelope();

    expect($env['event_type'])->toBe('member.invited');
    expect(array_keys($env['data']))->toBe(['email', 'role', 'invited_by', 'expires_at']);

    // The whole argument of the MemberInvited docblock, made mechanical: DomainEventType is the webhook
    // SUBSCRIPTION vocabulary, so anything in here reaches whatever endpoint a tenant has configured. A
    // working accept link there would be a privilege escalation dressed as an integration.
    $encoded = json_encode($env, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('token')
        ->and($encoded)->not->toContain('invitations/')
        ->and($encoded)->not->toContain('http');
});

it('builds the SubmissionUpdated envelope to a metadata-only shape that cannot widen unnoticed (I9c)', function (): void {
    // The pin every other submission event already had. Without it this is the one whose payload could grow
    // an `answers` key in a later increment and reach every subscribed endpoint and Slack channel silently.
    $submission = new Submission([
        'tenant_id' => Uuid::uuid7()->toString(),
        'form_id' => Uuid::uuid7()->toString(),
        'status' => SubmissionStatus::UnderReview,
        'source' => SubmissionSource::Manual,
    ]);
    $submission->id = Uuid::uuid7()->toString();

    $editor = new User;
    $editor->id = Uuid::uuid7()->toString();

    $env = SubmissionUpdated::for($submission, $editor, true)->envelope();

    expect($env['event_type'])->toBe('submission.updated')
        ->and(array_keys($env['data']))
        ->toBe(['submission_id', 'form_id', 'edited_by', 'status', 'approval_withdrawn'])
        ->and($env['data']['approval_withdrawn'])->toBeTrue()
        ->and($env['data']['status'])->toBe('under_review')
        // A webhook body and a Slack message are not places for respondent data — nor for the KEY NAMES,
        // which are schema information a form author may treat as sensitive on their own.
        ->and($env['data'])->not->toHaveKey('answers');

    foreach ($env['data'] as $value) {
        expect(is_scalar($value) || is_null($value))->toBeTrue();
    }
});
