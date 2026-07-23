<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\DomainEventType;
use App\Models\Submission;

/**
 * Raised once a submission has been persisted and its transaction has COMMITTED (technical-architecture.md
 * §4.1 — events are dispatched post-commit only, never inside the transaction). Not fired on an idempotent
 * replay no-op (nothing new was created).
 *
 * Folded into the {@see DomainEvent} catalog in H4: it now carries the stable envelope (a once-generated
 * `event_id`) and a SCALAR payload rather than the live {@see Submission} model, so H13 can attach a queued
 * webhook listener with an ADR-0007 §D5-safe payload without touching this emission site. The
 * submission's own audit trail (`AuditEvent::Created`) is written separately and in-transaction by the
 * pipeline — this event is the post-commit announcement, not the ledger row.
 */
final class SubmissionCreated extends DomainEvent
{
    private function __construct(
        public readonly string $tenantId,
        public readonly string $submissionId,
        public readonly string $formId,
        public readonly string $formVersionId,
    ) {
        parent::__construct();
    }

    public static function for(Submission $submission): self
    {
        return new self(
            tenantId: (string) $submission->tenant_id,
            submissionId: (string) $submission->id,
            formId: (string) $submission->form_id,
            formVersionId: (string) $submission->form_version_id,
        );
    }

    public function eventType(): DomainEventType
    {
        return DomainEventType::SubmissionCreated;
    }

    /** A submission always belongs to a tenant — narrower than the base's nullable contract (covariant). */
    public function tenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function data(): array
    {
        return [
            'submission_id' => $this->submissionId,
            'form_id' => $this->formId,
            'form_version_id' => $this->formVersionId,
        ];
    }
}
