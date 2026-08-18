<?php

declare(strict_types=1);

namespace App\Enums;

use App\Events\DomainEvent;
use App\Services\Connectors\ConnectionPresenter;
use App\Services\Webhooks\WebhookEndpointPresenter;
use App\Support\Connectors\ConnectorEventContextResolver;
use App\Support\Connectors\Providers\SlackMessageFormatter;

/**
 * THE one domain-event catalog (technical-architecture.md §4.1/§7.4, data-dictionary §22 design note).
 * A single vocabulary that every post-commit consumer shares — webhook dispatch (H13), in-app + email
 * notifications, and the realtime Reverb push — so a submission notification and a submission webhook fire
 * from ONE event, never two divergent in-controller write paths. Each {@see DomainEvent} declares exactly
 * one of these as its `event_type`; it becomes the envelope's `event_type` field and, in H13, the string a
 * `webhook_endpoints.event_types` subscription matches against.
 *
 * Dotted `noun.verb` naming, matching the `WebhookEventType` starter catalog earmarked in data-dictionary
 * §14–15. When H13 lands it should REFERENCE these cases rather than mint a second enum — the webhook
 * subscription vocabulary and the domain-event vocabulary are the same catalog by design.
 *
 * H4 ships only the two events it actually raises. Later increments extend the catalog as they add
 * emission sites (`submission.updated`/`submission.approved` with the review + edit work, `form.archived`
 * with archival, `subscription.updated` with billing). **I3 added three** with the notifications substrate,
 * and **I9c added `submission.updated`** with post-submission answer editing — the case this docblock had
 * named as a future addition since H4.
 *
 * ── Adding a case is a four-file act, not a one-line one ────────────────────────────────────────────────
 * ⚠️ THIS PARAGRAPH SAID "FIVE-FILE" AND NAMED TWO METHODS THAT NO LONGER EXIST — corrected in I9c, which
 * is the first increment to actually follow it. **I3 single-sourced the label into {@see self::label()}
 * below**, deleting `WebhookEndpointPresenter::eventTypeLabel()` and `ConnectionPresenter::eventTypeLabel()`
 * in the process, but this list was never updated. A maintainer following it would have gone looking for two
 * private matches that are not there and could reasonably have concluded the note was stale in the OTHER
 * direction — that no label work was needed at all — and shipped the one omission that actually 500s a page.
 * (Same failure mode as `docs/ux/exceptions-log.md` #6, which I8 planned a work item against after its
 * subject had already been fixed: a note is trusted precisely because it looks like diligence, so it must be
 * closed in the commit that closes its subject.)
 *
 * This enum is ALSO the webhook (`webhook_endpoints.event_types`) and Slack-connector
 * (`connection_subscriptions.event_types`) subscription vocabulary, and it is CHECK-constrained at
 * `webhook_deliveries_event_type_check`. A new case therefore needs, in the same commit:
 *
 *   1. a migration recreating that CHECK (else a tenant subscribes to the new event and the fan-out INSERT
 *      dies with 23514) — copy the shape of `2026_08_06_000003_recreate_webhook_deliveries_event_type_check`;
 *   2. an arm in {@see self::label()} — the ONE exhaustive match, and an unhandled case 500s BOTH
 *      `/webhooks` and `/integrations`; the two presenters need no edit;
 *   3. copy + a deep link in {@see SlackMessageFormatter} and {@see ConnectorEventContextResolver}
 *      (both defaulted, so the failure is a silent "*Update* — a form" rather than a crash);
 *   4. a regenerated `openapi.json`.
 *
 * The corollary: a case with NO emission site is worse than a missing one — it is subscribable and can
 * never fire. That is why I3's `review_requested` notification is a second fan-out of `submission.created`
 * rather than a `review.requested` case; see {@see NotificationType}.
 *
 * NOT the audit vocabulary: an audit row's {@see AuditEvent} records what changed on a model for the
 * compliance ledger; a domain event announces that something happened to post-commit consumers.
 */
enum DomainEventType: string
{
    case SubmissionCreated = 'submission.created';
    case SubmissionApproved = 'submission.approved';
    case SubmissionReturned = 'submission.returned';
    // I9c — a finalized submission's ANSWERS were corrected ({@see SubmissionAnswerEditService}). Sits with
    // its siblings because case order is a wire contract: `values()` feeds openapi.json and the subscription
    // checkbox groups, so moving it later is a spec diff for no behavioural gain.
    case SubmissionUpdated = 'submission.updated';
    case FormPublished = 'form.published';
    case FormOpened = 'form.opened';
    case FormClosed = 'form.closed';
    case MemberInvited = 'member.invited';

    /**
     * The human label for the subscribable-event checkbox groups on `/webhooks` and `/integrations`.
     *
     * It lives HERE rather than in the two presenters, which until I3 carried byte-identical private
     * `match` statements under docblocks promising "one catalog, one set of words, whichever channel a
     * tenant is configuring" — a promise nothing enforced, in two exhaustive matches that would each 500
     * their page the moment this enum grew. Same posture as {@see AuditEvent::label()}: the label is the
     * part that must not diverge, so it is single-sourced; colour and iconography stay with the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::SubmissionCreated => 'Submission created',
            self::SubmissionApproved => 'Submission approved',
            self::SubmissionReturned => 'Submission returned',
            self::SubmissionUpdated => 'Submission answers edited',
            self::FormPublished => 'Form published',
            self::FormOpened => 'Form opened',
            self::FormClosed => 'Form closed',
            self::MemberInvited => 'Member invited',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
