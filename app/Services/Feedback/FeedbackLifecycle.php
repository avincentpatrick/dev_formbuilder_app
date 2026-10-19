<?php

declare(strict_types=1);

namespace App\Services\Feedback;

use App\Enums\AuditEvent;
use App\Enums\FeedbackCommentAction;
use App\Enums\FeedbackParty;
use App\Enums\FeedbackStatus;
use App\Enums\NotificationType;
use App\Exceptions\Feedback\FeedbackLifecycleException;
use App\Models\FeedbackComment;
use App\Models\FeedbackReport;
use App\Models\User;
use App\Services\Admin\SuperAdminService;
use App\Services\Attachments\AttachmentStorageService;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Every move a feedback report makes, and every message in its thread — one lifecycle with two doors (M179,
 * `R-129c44c3`, `D151`; data-dictionary §21, §21a).
 *
 * - **The support door** is {@see SuperAdminService::transitionFeedback()} (the console and the platform support
 *   API): it opens a transaction on the ordinary connection, ADOPTS the report's tenant context, and calls
 *   {@see self::support()} inside it — so the write and its audit pass the strict policies and the audit lands in
 *   that workspace's own ledger (RBAC §9).
 * - **The testers' door** is the tenant app (`FeedbackThreadController`): {@see self::confirm()},
 *   {@see self::reopen()} and {@see self::comment()} run under the request's own session context. They never
 *   call `applyLocal()` — the context is already the member's, and replacing it would overwrite
 *   `app.current_user_id` for the rest of the request. Who may act is the policy's question
 *   (`FeedbackReportPolicy`), asked before this class is reached.
 *
 * ── THE ROW IS READ AS IT IS, UNDER A LOCK ──────────────────────────────────────────────────────────────────
 * Every move re-reads the report `FOR UPDATE` inside its transaction and judges the move against THAT row — never
 * the model the caller was holding, which another request may have moved since. Until M179 the support door
 * guarded with the caller's model and re-read without a lock, so two moves at once could both pass a guard
 * against a status that no longer held, and the audit recorded the stale status as the old one. The lock is
 * taken after the tenant context is in place: PostgreSQL applies a table's UPDATE policy to a locking SELECT as
 * a FILTER, so a lock taken with no context would match nothing, silently. Never lock through the elevated
 * connection either — `meridian_superadmin` holds SELECT only, and FOR UPDATE needs UPDATE.
 *
 * ── WHAT EACH MOVE STAMPS ────────────────────────────────────────────────────────────────────────────────────
 * Reaching Resolved or Declined stamps `resolved_*` and tells the reporter ({@see FeedbackStatus::stampsResolution()}).
 * A tester's **It works** stamps `confirmed_*` and keeps `resolved_*` — who fixed it stays on record — and tells
 * no one. ANY move back to Ongoing clears both stamps, on either door: they mean "when, and by whom, was this
 * closed / confirmed", and a re-opened report is neither. The history survives in the audit and, for a tester's
 * move, in the thread, where the move writes a message carrying its `action`.
 */
final class FeedbackLifecycle
{
    /** The longest message a thread accepts — the request validates it too; this is the one rule both doors share. */
    public const BODY_MAX = 2000;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
        private readonly AttachmentStorageService $attachments,
    ) {}

    /**
     * The support door's move. ⚠️ Call it INSIDE the transaction that adopted the report's tenant context —
     * {@see SuperAdminService::transitionFeedback()} is that caller — so the lock, the write, the audit and the
     * notification all run under that context.
     *
     * @throws FeedbackLifecycleException on a move the support graph does not offer from the row's real status
     */
    public function support(string $reportId, FeedbackStatus $to, User $actor, ?string $note): FeedbackReport
    {
        $row = $this->lockedRow($reportId);
        $from = $row->status;

        if (! $from->canTransitionTo($to, FeedbackParty::Support)) {
            throw FeedbackLifecycleException::refused($from, $to);
        }

        $old = $this->stamps($row);

        // Re-opening CLEARS the resolution stamp rather than preserving it: those two columns mean "when, and by
        // whom, was this closed", and a re-opened report is not closed. The reply is kept unless a new one is
        // written: it is what the reporter last read, and re-opening says nothing new to them. Support never
        // reaches Confirmed, so every support move also clears a tester's confirmation — which only a re-open of
        // a Confirmed report can have left behind.
        $resolvedAt = $to->stampsResolution() ? now() : null;
        $resolvedBy = $to->stampsResolution() ? (string) $actor->getKey() : null;
        $resolutionNote = $note ?? $row->resolution_note;

        $row->forceFill([
            'status' => $to,
            'resolved_at' => $resolvedAt,
            'resolved_by' => $resolvedBy,
            'resolution_note' => $resolutionNote,
            'confirmed_at' => null,
            'confirmed_by' => null,
        ])->save();

        $this->audit->record(
            AuditEvent::Updated,
            'feedback_reports',
            (string) $row->getKey(),
            old: $old,
            new: $this->stamps($row),
            actorId: (string) $actor->getKey(),
        );

        // Resolved or Declined: the reporter is told — by bell and, unless they switched it off, by email. Ids and
        // labels only in `data`; the reply rides as mail-only context.
        if ($to->stampsResolution()) {
            $this->notifications->dispatch(
                NotificationType::FeedbackClosed,
                [(string) $row->user_id],
                array_filter([
                    'feedback_report_id' => (string) $row->getKey(),
                    'status' => $to->value,
                    'page_title' => $row->page_title,
                ], static fn (?string $value): bool => $value !== null),
                $resolutionNote === null ? [] : ['note' => $resolutionNote],
            );
        }

        return $row;
    }

    /**
     * **It works** — a member confirms a Resolved report's fix (`D151`). Stamps `confirmed_*`, keeps `resolved_*`,
     * writes a `confirmed` message to the thread (its words optional), and tells no one.
     *
     * @throws FeedbackLifecycleException when the row is not Resolved any more (another tester confirmed it, or it
     *                                    was re-opened)
     */
    public function confirm(FeedbackReport $report, User $member, ?string $body = null): FeedbackReport
    {
        $body = $this->optionalBody($body);

        /** @var FeedbackReport $written */
        $written = DB::transaction(function () use ($report, $member, $body): FeedbackReport {
            $row = $this->lockedRow((string) $report->getKey());
            $this->guardMember($row, FeedbackStatus::Confirmed);

            $old = $this->stamps($row);
            $now = now();

            $row->forceFill([
                'status' => FeedbackStatus::Confirmed,
                'confirmed_at' => $now,
                'confirmed_by' => (string) $member->getKey(),
                'last_comment_at' => $now,
            ])->save();

            $this->writeComment($row, $member, FeedbackCommentAction::Confirmed, $body, null);
            $this->audit->record(AuditEvent::Updated, 'feedback_reports', (string) $row->getKey(), old: $old, new: $this->stamps($row), actorId: (string) $member->getKey());

            return $row;
        });

        return $written;
    }

    /**
     * **Still a problem** — a member sends a Resolved or Confirmed report back to Ongoing (`D151`). The comment is
     * REQUIRED (what is still wrong is the whole point, and it is what the next session's pull shows as REOPENED);
     * a screenshot is optional. Clears `resolved_*` and `confirmed_*`.
     *
     * @throws ValidationException when there is no comment
     * @throws FeedbackLifecycleException when the row is not Resolved or Confirmed any more
     */
    public function reopen(FeedbackReport $report, User $member, ?string $body, ?UploadedFile $screenshot = null): FeedbackReport
    {
        $body = $this->requiredBody($body, 'Say what is still a problem, so the team knows what to look at.');

        /** @var FeedbackReport $written */
        $written = DB::transaction(function () use ($report, $member, $body, $screenshot): FeedbackReport {
            $row = $this->lockedRow((string) $report->getKey());
            $this->guardMember($row, FeedbackStatus::Ongoing);

            $old = $this->stamps($row);

            $row->forceFill([
                'status' => FeedbackStatus::Ongoing,
                'resolved_at' => null,
                'resolved_by' => null,
                'confirmed_at' => null,
                'confirmed_by' => null,
                'last_comment_at' => now(),
            ])->save();

            $this->writeComment($row, $member, FeedbackCommentAction::Reopened, $body, $screenshot);
            $this->audit->record(AuditEvent::Updated, 'feedback_reports', (string) $row->getKey(), old: $old, new: $this->stamps($row), actorId: (string) $member->getKey());

            return $row;
        });

        return $written;
    }

    /**
     * A member's comment — on any report they may see, in any status (`D151`). Moves nothing, so it is not
     * audited (the report's own creation is not either, `FeedbackService`); it stamps `last_comment_at`.
     *
     * @throws ValidationException when the comment is empty
     */
    public function comment(FeedbackReport $report, User $member, ?string $body, ?UploadedFile $screenshot = null): FeedbackComment
    {
        $body = $this->requiredBody($body, 'Write a comment first.');

        /** @var FeedbackComment $comment */
        $comment = DB::transaction(function () use ($report, $member, $body, $screenshot): FeedbackComment {
            $row = $this->lockedRow((string) $report->getKey());
            $row->forceFill(['last_comment_at' => now()])->save();

            return $this->writeComment($row, $member, null, $body, $screenshot);
        });

        return $comment;
    }

    /** The report, read as it is now, locked until the transaction ends. */
    private function lockedRow(string $reportId): FeedbackReport
    {
        /** @var FeedbackReport $row */
        $row = FeedbackReport::query()->whereKey($reportId)->lockForUpdate()->firstOrFail();

        return $row;
    }

    private function guardMember(FeedbackReport $row, FeedbackStatus $to): void
    {
        if (! $row->status->canTransitionTo($to, FeedbackParty::Member)) {
            throw FeedbackLifecycleException::refused($row->status, $to);
        }
    }

    /**
     * The columns a move changes, as the audit records them on both sides. `resolution_note` is redacted by
     * `AuditRedactor` (a reply quotes the report); the stamps are not.
     *
     * @return array<string, string|null>
     */
    private function stamps(FeedbackReport $row): array
    {
        return [
            'status' => $row->status->value,
            'resolved_at' => $row->resolved_at?->toIso8601String(),
            'resolved_by' => $row->resolved_by,
            'resolution_note' => $row->resolution_note,
            'confirmed_at' => $row->confirmed_at?->toIso8601String(),
            'confirmed_by' => $row->confirmed_by,
        ];
    }

    private function writeComment(FeedbackReport $row, User $member, ?FeedbackCommentAction $action, ?string $body, ?UploadedFile $screenshot): FeedbackComment
    {
        $attachment = $screenshot === null
            ? null
            : $this->attachments->storeFeedbackScreenshot($screenshot, (string) $row->tenant_id, (string) $row->getKey(), (string) $member->getKey());

        $comment = new FeedbackComment;
        $comment->forceFill([
            'id' => Uuid::uuid7()->toString(),
            'feedback_report_id' => (string) $row->getKey(),
            'user_id' => (string) $member->getKey(),
            'author_kind' => FeedbackParty::Member,
            'action' => $action,
            'body' => $body,
            'screenshot_attachment_id' => $attachment?->getKey(),
        ])->save();

        return $comment;
    }

    private function optionalBody(?string $body): ?string
    {
        $body = $body === null ? '' : trim($body);

        if (mb_strlen($body) > self::BODY_MAX) {
            throw ValidationException::withMessages(['body' => 'Keep it to '.number_format(self::BODY_MAX).' characters.']);
        }

        return $body === '' ? null : $body;
    }

    private function requiredBody(?string $body, string $whenEmpty): string
    {
        $body = $this->optionalBody($body);

        if ($body === null) {
            throw ValidationException::withMessages(['body' => $whenEmpty]);
        }

        return $body;
    }
}
