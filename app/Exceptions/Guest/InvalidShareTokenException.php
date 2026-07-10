<?php

declare(strict_types=1);

namespace App\Exceptions\Guest;

use App\Support\Guest\GuestShareTokenService;
use RuntimeException;

/**
 * A guest share token that is structurally malformed or fails its HMAC signature check (Increment F5).
 * Thrown by {@see GuestShareTokenService::verify()} BEFORE any tenant context is
 * established, so a forged/tampered token never engages RLS. No `render()`: HTTP mapping (→ 401 on the
 * /api/v1 surface) is centralised in bootstrap/app.php, mirroring app/Exceptions/Forms/*.
 */
final class InvalidShareTokenException extends RuntimeException
{
    public static function malformed(): self
    {
        return new self('The share token is malformed.');
    }

    public static function badSignature(): self
    {
        return new self('The share token signature is invalid.');
    }
}
