<?php

declare(strict_types=1);

namespace App\Support\Auth;

use RuntimeException;

/**
 * The `state` on a Google sign-in callback was not one we minted, or is no longer usable (J3c2).
 *
 * ⚠️ ONE EXCEPTION FOR EVERY CAUSE, AND THE ABSENCE OF DETAIL IS THE POINT. Structure, signature, claim
 * shape and expiry all arrive here identically. This is an unauthenticated inbound surface: telling the
 * caller which half of the token to keep working on is a gift, and ADR-0019 §D9 gives every refusal in
 * this flow one indistinguishable outcome.
 */
final class InvalidGoogleAuthStateException extends RuntimeException
{
    public static function rejected(): self
    {
        return new self('The Google sign-in state parameter was rejected.');
    }
}
