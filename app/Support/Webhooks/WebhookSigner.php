<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use App\Models\WebhookEndpoint;
use App\Support\Guest\GuestShareTokenService;

/**
 * Computes the HMAC-SHA256 signature for an outbound webhook delivery (technical-architecture.md §7.2/§7.4,
 * webhook-integration-design.md §2.1). The signature is over `"{timestamp}.{raw_body}"` — Stripe's own
 * webhook-security convention — not the body alone, so a captured delivery cannot be replayed with a
 * different timestamp; the `timestamp` is the envelope's `occurred_at`, stable across retries.
 *
 * Mirrors {@see GuestShareTokenService::sign()}'s `hash_hmac` mechanics, but the key is
 * per-endpoint tenant data (the `webhook_endpoints.secret`), not an APP_KEY-derived process secret. Emits a
 * hex digest (the header-friendly form receivers verify with their language's `hmac_sha256(...).hexdigest()`).
 */
final class WebhookSigner
{
    /** The header carrying the signature (`sha256=<hex>`). */
    public const string SIGNATURE_HEADER = 'X-Webhook-Signature';

    /** The header carrying the signed timestamp (== the envelope `occurred_at`, ISO-8601). */
    public const string TIMESTAMP_HEADER = 'X-Webhook-Timestamp';

    /** The header carrying the delivery's event id (idempotency key for the receiver). */
    public const string EVENT_ID_HEADER = 'X-Webhook-Event-Id';

    /** The raw hex HMAC-SHA256 of `"{timestamp}.{rawBody}"` under the endpoint secret. */
    public function sign(string $secret, string $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /** The `X-Webhook-Signature` header value: the versioned `sha256=<hex>` form. */
    public function signatureHeader(string $secret, string $timestamp, string $rawBody): string
    {
        return 'sha256='.$this->sign($secret, $timestamp, $rawBody);
    }

    /**
     * The `X-Webhook-Signature` value for an endpoint (H13b) — the single builder both {@see DeliverWebhookJob}
     * and the "send test event" path use, so the two can never drift. Signs with the current secret and, while
     * a rotation grace window is open ({@see WebhookEndpoint::activePreviousSecret()}), ALSO with the previous
     * secret, comma-joining the two `sha256=<hex>` values (Stripe-style) so a receiver mid-rotation verifies
     * with EITHER. The current secret is always first.
     */
    public function signatureHeaderFor(WebhookEndpoint $endpoint, string $timestamp, string $rawBody): string
    {
        $header = $this->signatureHeader($endpoint->secret, $timestamp, $rawBody);

        $previous = $endpoint->activePreviousSecret();

        if ($previous !== null) {
            $header .= ','.$this->signatureHeader($previous, $timestamp, $rawBody);
        }

        return $header;
    }
}
