<?php

declare(strict_types=1);

namespace App\Exceptions\Connectors;

use App\Services\Connectors\ConnectionTokenRefresher;
use App\Support\Connectors\ConnectorProvider;
use RuntimeException;

/**
 * A provider refused an OAuth code exchange or token refresh (ADR-0009, H15a). Thrown by a
 * {@see ConnectorProvider} adapter; carries the provider's own error code (`invalid_grant`,
 * `bad_verification_code`, …) so the callback can hand it back as a `?connect_error=` parameter and the
 * refresh sweep can store it in `connections.last_error`.
 *
 * The message never contains a token: the adapter passes the provider's error code, not its request body.
 * No `render()` — the callback controller handles it inline, since a failed exchange must still redirect
 * the human back to their own tenant host rather than surface an error page on the central domain.
 *
 * ── `$terminal` (H16a) — WHY A REFUSAL IS NOT ALWAYS A DEATH ─────────────────────────────────────────────
 *
 * {@see ConnectionTokenRefresher} responds to this exception by marking the grant
 * DEAD: it clears both tokens, pauses every rule on the connection and emails the owner, and only a human
 * re-running the OAuth flow can undo it. That is right for `invalid_grant` — the tenant removed our app, and
 * hammering it changes nothing — and badly wrong for a timeout, which says nothing about the credential at all.
 *
 * It went unnoticed through H15a because Slack's bot tokens do not expire, so the refresh path was effectively
 * unreachable. H16a makes it reachable twice over: Google's tokens expire hourly, and the delivery job now
 * pre-flights a refresh. Without this flag, one blip at Google's token endpoint would permanently destroy a
 * working connection — a failure that is invisible until a tenant notices their spreadsheet stopped filling.
 *
 * Defaults to `true` so every pre-H16a call site keeps its exact behaviour; an adapter opts a code out by
 * passing `terminal: false`, which it can only do because it is the one thing that knows its provider's
 * vocabulary.
 */
final class ConnectorOAuthException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        public readonly bool $terminal,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function exchangeFailed(string $errorCode, bool $terminal = true): self
    {
        return new self($errorCode, $terminal, "The provider refused the authorization exchange ({$errorCode}).");
    }

    public static function refreshFailed(string $errorCode, bool $terminal = true): self
    {
        return new self($errorCode, $terminal, "The provider refused the token refresh ({$errorCode}).");
    }
}
