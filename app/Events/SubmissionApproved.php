<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\DomainEventType;
use App\Models\Submission;
use App\Models\User;
use App\Services\Submissions\SubmissionReviewService;

/**
 * A reviewer approved a submission (Increment I3). Raised post-commit by
 * {@see SubmissionReviewService::approve()} — by call-site ordering, after the transaction that flipped the
 * status returns, never inside it.
 *
 * The `data()` shape is not invented here: `docs/webhook-integration-design.md` §3 pre-specified
 * `submission.approved` as `{submission_id, form_id, validated_by, validated_at}` long before anything
 * emitted it, and this matches it exactly.
 *
 * ── `respondentUserId` is in-process only, deliberately ─────────────────────────────────────────────────
 * The notification listener needs to know WHO to tell, and the answer — the person who submitted — is not
 * part of the pre-specified webhook payload. It rides on the event object, which only synchronous in-process
 * listeners ever see; {@see self::data()} is what reaches a third-party endpoint, and it stays as specified.
 * It is null for a guest submission, which has no user to notify.
 *
 * As with every event in this catalog, the audit row for the same act is written separately and
 * IN-transaction: the ledger must be atomic with the change, this announcement must not fire for a
 * transition that rolled back.
 */
final class SubmissionApproved extends DomainEvent
{
    private function __construct(
        public readonly string $tenantId,
        public readonly string $submissionId,
        public readonly string $formId,
        public readonly ?string $respondentUserId,
        public readonly ?string $validatedByUserId,
        public readonly ?string $validatedAt,
    ) {
        parent::__construct();
    }

    public static function for(Submission $submission, User $reviewer): self
    {
        return new self(
            tenantId: (string) $submission->tenant_id,
            submissionId: (string) $submission->id,
            formId: (string) $submission->form_id,
            respondentUserId: $submission->respondent_user_id === null
                ? null
                : (string) $submission->respondent_user_id,
            validatedByUserId: (string) $reviewer->getKey(),
            validatedAt: $submission->validated_at?->toIso8601String(),
        );
    }

    public function eventType(): DomainEventType
    {
        return DomainEventType::SubmissionApproved;
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
            'validated_by' => $this->validatedByUserId,
            'validated_at' => $this->validatedAt,
        ];
    }
}
