<?php

declare(strict_types=1);

namespace App\Exceptions\Guest;

use RuntimeException;

/**
 * A guest share token whose signature is valid but whose embedded expiry has passed (Increment F5).
 * Distinct from {@see InvalidShareTokenException} so the surface can tell "this link is fake" from "this
 * link has aged out" (→ 401 `share_token_expired` on the /api/v1 surface, mapped in bootstrap/app.php).
 */
final class ExpiredShareTokenException extends RuntimeException
{
    public static function atExpiry(): self
    {
        return new self('The share token has expired.');
    }
}
