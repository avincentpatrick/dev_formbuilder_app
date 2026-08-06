<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\DomainEventType;
use App\Models\Submission;
use App\Models\User;
use App\Services\Submissions\SubmissionReviewService;
use App\Support\Audit\AuditRedactor;

/**
 * A reviewer returned a submission to its respondent for correction (Increment I3). Raised post-commit by
 * {@see SubmissionReviewService::returnToRespondent()}, by call-site ordering.
 *
 * The payload follows §3's two neighbouring shapes: `submission.approved`'s reviewer/timestamp pair plus
 * `submission.updated`'s `previous_status`, which is the part a consumer needs to tell "returned from the
 * queue" apart from "returned after someone had claimed it".
 *
 * ── `returned_reason` is NOT in the payload ────────────────────────────────────────────────────────────
 * It is reviewer-authored prose about someone's answers, and §3's stated default is identifiers and
 * metadata rather than record content, "specifically because a webhook endpoint is a third-party
 * destination the tenant configures and this platform cannot vet". Nothing is lost: the person who needs
 * the reason is the respondent, and their in-app notification deep-links to the submission where it is
 * shown verbatim. (Note this is the opposite call from `remarks`, which never leaves the platform at all —
 * {@see AuditRedactor::PII} placeholders it even in the audit ledger. The asymmetry is the same one I2
 * recorded: `returned_reason` is written to be read by the respondent, `remarks` is not.)
 *
 * `respondentUserId` is in-process only — see {@see SubmissionApproved} for the reasoning.
 */
final class SubmissionReturned extends DomainEvent
{
    private function __construct(
        public readonly string $tenantId,
        public readonly string $submissionId,
        public readonly string $formId,
        public readonly ?string $respondentUserId,
        public readonly string $previousStatus,
        public readonly ?string $validatedByUserId,
        public readonly ?string $validatedAt,
    ) {
        parent::__construct();
    }

    public static function for(Submission $submission, User $reviewer, string $previousStatus): self
    {
        return new self(
            tenantId: (string) $submission->tenant_id,
            submissionId: (string) $submission->id,
            formId: (string) $submission->form_id,
            respondentUserId: $submission->respondent_user_id === null
                ? null
                : (string) $submission->respondent_user_id,
            previousStatus: $previousStatus,
            validatedByUserId: (string) $reviewer->getKey(),
            validatedAt: $submission->validated_at?->toIso8601String(),
        );
    }

    public function eventType(): DomainEventType
    {
        return DomainEventType::SubmissionReturned;
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
            'previous_status' => $this->previousStatus,
            'validated_by' => $this->validatedByUserId,
            'validated_at' => $this->validatedAt,
        ];
    }
}
