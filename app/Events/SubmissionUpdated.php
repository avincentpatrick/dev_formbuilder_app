<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\DomainEventType;
use App\Models\Submission;
use App\Models\User;
use App\Services\Submissions\SubmissionAnswerEditService;
use App\Support\Audit\AuditRedactor;

/**
 * A finalized submission's ANSWERS were corrected (Increment I9c). Raised post-commit by
 * {@see SubmissionAnswerEditService::edit()} — by call-site ordering, after the transaction that rewrote the
 * answer document returns, never inside it.
 *
 * ── ⚠️ IT CARRIES NO ANSWERS, AND THAT IS THE POINT OF THE EVENT RATHER THAN A LIMITATION ────────────────
 * `data()` is metadata only, matching {@see SubmissionCreated::data()}'s recorded reason for the same
 * choice: a domain event is an announcement delivered to third-party endpoints and Slack channels, and the
 * answer document is the tenant's respondent data. Shipping a diff of it would put PII into every subscribed
 * webhook body and every Slack message — including the very fields
 * {@see AuditRedactor} exists to keep OUT of the tenant's own audit ledger, which is a
 * far more private destination than a chat room. A subscriber that needs the new answers reads them back
 * through the API with its own credentials, where the usual authorization applies.
 *
 * Not even the changed KEY NAMES ride along, which was considered and rejected: a key name is schema
 * information a form author may treat as sensitive on its own (`hiv_status_confirmed` tells a reader what
 * the form asks without answering it), and a consumer that wants to know what moved is re-fetching anyway.
 *
 * ── The demotion is reported, because a consumer's state depends on it ──────────────────────────────────
 * Editing an `approved` submission returns it to `under_review` (the user decision this increment
 * implements), so a consumer that acted on `submission.approved` — pushed a row to a warehouse, paid an
 * enumerator, closed a case — needs to know the approval was withdrawn. `status` is therefore the row's
 * status AFTER the edit, and the payload's `approval_withdrawn` flag (carried on this object as
 * `$wasDemoted`) says plainly that this event is the reason it changed, rather than leaving the consumer
 * to diff against state it may not have kept. The two names are deliberate: the WIRE name describes the
 * consequence a subscriber acts on, the property name describes the transition that caused it.
 *
 * ── `respondentUserId` is in-process only ───────────────────────────────────────────────────────────────
 * Same posture as {@see SubmissionApproved}: it rides on the event object for in-process listeners and is
 * absent from `data()`. I9c registers no NotificationType arm — an internal correction by a privileged
 * editor is not something to page the respondent about — but the field is present so a later increment
 * that decides otherwise does not have to change the event's shape to do it. (An earlier draft said the
 * argument was 'recorded in docs/notifications-realtime-design.md'. There is no such file anywhere in the
 * repo; THIS docblock is the record.)
 */
final class SubmissionUpdated extends DomainEvent
{
    private function __construct(
        public readonly string $tenantId,
        public readonly string $submissionId,
        public readonly string $formId,
        public readonly ?string $respondentUserId,
        public readonly string $editedByUserId,
        public readonly string $status,
        public readonly bool $wasDemoted,
    ) {
        parent::__construct();
    }

    /**
     * `$submission` is the row AFTER the edit — the service passes the locked-and-saved instance, so
     * `status` already reflects any demotion.
     *
     * `$wasDemoted` is passed rather than inferred: `status === under_review` is also true for an edit to a
     * submission that was ALREADY under review, and the two are different facts. Inferring it would report a
     * withdrawn approval on every edit to a submission in the review queue.
     */
    public static function for(Submission $submission, User $editor, bool $wasDemoted): self
    {
        return new self(
            tenantId: (string) $submission->tenant_id,
            submissionId: (string) $submission->id,
            formId: (string) $submission->form_id,
            respondentUserId: $submission->respondent_user_id === null
                ? null
                : (string) $submission->respondent_user_id,
            editedByUserId: (string) $editor->getKey(),
            status: $submission->status->value,
            wasDemoted: $wasDemoted,
        );
    }

    public function eventType(): DomainEventType
    {
        return DomainEventType::SubmissionUpdated;
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
            'edited_by' => $this->editedByUserId,
            'status' => $this->status,
            'approval_withdrawn' => $this->wasDemoted,
        ];
    }
}
