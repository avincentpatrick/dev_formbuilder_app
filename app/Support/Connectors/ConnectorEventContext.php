<?php

declare(strict_types=1);

namespace App\Support\Connectors;

use App\Events\DomainEvent;

/**
 * The human-facing detail a delivered connector message needs but a {@see DomainEvent} envelope
 * deliberately does not carry (ADR-0009, H15a): the envelope is ids + metadata by design
 * (webhook-integration-design.md §3), which is right for a machine consumer and useless in a chat message
 * that a person reads.
 *
 * Resolved once per delivery attempt by {@see ConnectorEventContextResolver} (which does the RLS-scoped
 * reads) and passed to a pure formatter, so message-building stays testable without a database.
 */
final readonly class ConnectorEventContext
{
    public function __construct(
        /** The form's title, or null when the event names no form / the form has since been hard-deleted. */
        public ?string $formName,
        /** An absolute link into the tenant's own app for this event, or null when none applies. */
        public ?string $deepLink,
    ) {}

    public static function empty(): self
    {
        return new self(null, null);
    }
}
