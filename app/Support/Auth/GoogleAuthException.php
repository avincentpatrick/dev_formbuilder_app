<?php

declare(strict_types=1);

namespace App\Support\Auth;

use RuntimeException;
use Throwable;

/**
 * Google could not be asked, or answered in a way this flow cannot use (Increment J3c2).
 *
 * ⚠️ THE MESSAGE IS FOR THE LOG AND NEVER FOR THE RESPONSE. ADR-0019 §D9 gives every refusal in this flow
 * one indistinguishable outcome — `?google=failed` — so nothing here may reach a rendered page. The
 * previous exception is kept because an operator reading `auth.google.callback.rejected` needs to tell a
 * revoked client secret from a DNS failure, and neither is visible from the bounce.
 */
final class GoogleAuthException extends RuntimeException
{
    public static function exchangeFailed(?Throwable $previous = null): self
    {
        return new self('The Google authorization code could not be exchanged.', previous: $previous);
    }

    public static function identityUnusable(string $why, ?Throwable $previous = null): self
    {
        return new self("Google returned an identity this flow cannot use: {$why}.", previous: $previous);
    }
}
