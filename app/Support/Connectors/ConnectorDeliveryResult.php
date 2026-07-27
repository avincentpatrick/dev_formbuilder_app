<?php

declare(strict_types=1);

namespace App\Support\Connectors;

use App\Jobs\Webhooks\DeliverWebhookJob;

/**
 * The outcome of one attempt to push an event to a provider (ADR-0009, H15a) — the connector analogue of an
 * HTTP response in {@see DeliverWebhookJob}, normalized so the delivery job records the
 * same ledger columns for every provider.
 *
 * `credentialRejected` is the load-bearing flag: it means the provider told us the GRANT is dead
 * (`invalid_auth`, `token_revoked`, `account_inactive`), not that this message failed. The delivery job
 * treats it as terminal — revoke the connection, dead-letter the delivery, notify the owner once — rather
 * than scheduling a retry that can only fail the same way for the next seven days (ADR-0009 §D6/§D7).
 *
 * Note that a provider can report failure with HTTP 200: Slack returns `{"ok": false, "error": "..."}`.
 * `delivered` is the adapter's verdict, never the transport status code.
 */
final readonly class ConnectorDeliveryResult
{
    private function __construct(
        public bool $delivered,
        public ?int $responseStatus,
        public string $responseExcerpt,
        public bool $credentialRejected,
    ) {}

    public static function delivered(?int $status, string $excerpt): self
    {
        return new self(true, $status, $excerpt, false);
    }

    /** A message-level failure: worth retrying on the ladder (rate limit, transient provider error). */
    public static function failed(?int $status, string $excerpt): self
    {
        return new self(false, $status, $excerpt, false);
    }

    /** The grant itself is dead — terminal, and the connection must be revoked. */
    public static function credentialRejected(?int $status, string $excerpt): self
    {
        return new self(false, $status, $excerpt, true);
    }
}
