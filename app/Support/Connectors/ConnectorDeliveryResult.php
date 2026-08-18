<?php

declare(strict_types=1);

namespace App\Support\Connectors;

use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Services\Connectors\ConnectorChannelDirectory;

/**
 * The outcome of one attempt to push an event to a provider (ADR-0009, H15a) — the connector analogue of an
 * HTTP response in {@see DeliverWebhookJob}, normalized so the delivery job records the
 * same ledger columns for every provider.
 *
 * FOUR OUTCOMES, AND THE THIRD AND FOURTH DIFFER IN *WHOSE* FAULT IT IS — which is what decides what gets
 * switched off:
 *
 *   - {@see delivered()}          — it worked.
 *   - {@see failed()}             — this attempt failed; retry on the ladder (rate limit, 5xx, timeout).
 *   - {@see credentialRejected()} — the GRANT is dead (`invalid_auth`, `token_revoked`, `invalid_grant`).
 *     Terminal: revoke the CONNECTION, dead-letter, notify the owner once (ADR-0009 §D6/§D7). Every rule on
 *     that connection stops, because none of them can work.
 *   - {@see blocked()}            — H16a. The grant is FINE and the destination is not: a Google Sheet whose
 *     columns no longer match the stored mapping, or one this grant can no longer reach. Terminal for THIS
 *     RULE only — pause the subscription, dead-letter, notify the owner once — and the connection, plus every
 *     other rule on it, keeps working.
 *
 * THE FOURTH CASE EXISTS BECAUSE THE OTHER THREE ARE ALL WRONG FOR IT, and each in a way that matters.
 * `failed` would retry a condition only a human can fix, spending seven days of ladder to reach a dead letter
 * nobody is told about. `credentialRejected` would revoke a perfectly good OAuth grant — and with it every
 * OTHER rule on that connection — because one spreadsheet got a new column. `delivered` would be a lie.
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
        public bool $blocked = false,
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

    /**
     * The destination is unusable in a way retrying cannot fix, but the credential is healthy (H16a): column
     * drift, a deleted spreadsheet, a file this grant was never granted access to.
     *
     * `$excerpt` is shown to the tenant, so it must say what to DO. It is written by us — a drift summary or
     * our own copy — never echoed from the provider, which is the same rule
     * {@see ConnectorChannelDirectory} follows for the same reason: a provider's
     * error string is unreviewed third-party text and this one reaches an email.
     */
    public static function blocked(?int $status, string $excerpt): self
    {
        return new self(false, $status, $excerpt, false, true);
    }
}
