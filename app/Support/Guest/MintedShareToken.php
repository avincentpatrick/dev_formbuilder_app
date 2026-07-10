<?php

declare(strict_types=1);

namespace App\Support\Guest;

/**
 * The result of {@see GuestShareTokenService::mint()} — the opaque token string handed to the guest plus
 * the absolute expiry (unix seconds) so the caller can surface a human-readable "valid until" without
 * re-decoding. Immutable.
 */
final readonly class MintedShareToken
{
    public function __construct(
        public string $token,
        public int $expiresAt,
    ) {}
}
