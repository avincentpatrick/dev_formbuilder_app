<?php

declare(strict_types=1);

namespace App\Enums;

use App\Events\DomainEvent;

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
 * with archival, `subscription.updated` with billing).
 *
 * NOT the audit vocabulary: an audit row's {@see AuditEvent} records what changed on a model for the
 * compliance ledger; a domain event announces that something happened to post-commit consumers.
 */
enum DomainEventType: string
{
    case SubmissionCreated = 'submission.created';
    case FormPublished = 'form.published';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
