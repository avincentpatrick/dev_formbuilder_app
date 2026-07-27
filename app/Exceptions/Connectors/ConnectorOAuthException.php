<?php

declare(strict_types=1);

namespace App\Exceptions\Connectors;

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
 */
final class ConnectorOAuthException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function exchangeFailed(string $errorCode): self
    {
        return new self($errorCode, "The provider refused the authorization exchange ({$errorCode}).");
    }

    public static function refreshFailed(string $errorCode): self
    {
        return new self($errorCode, "The provider refused the token refresh ({$errorCode}).");
    }
}
