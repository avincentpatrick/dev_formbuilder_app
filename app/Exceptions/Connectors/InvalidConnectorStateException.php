<?php

declare(strict_types=1);

namespace App\Exceptions\Connectors;

use App\Support\Connectors\ConnectorOAuthStateService;
use RuntimeException;

/**
 * An OAuth `state` parameter that is structurally malformed, fails its HMAC signature, has expired, or names
 * a different provider than the callback it arrived at (ADR-0009 §D3, H15a).
 *
 * Thrown by {@see ConnectorOAuthStateService::verify()} from the callback middleware BEFORE any tenant
 * context is established, so a forged or tampered state never engages RLS. Deliberately ONE exception for
 * all four causes: the callback is an unauthenticated inbound surface on the central domain, and
 * distinguishing "bad signature" from "expired" for the caller would tell an attacker which half of the
 * token to keep working on. HTTP mapping (→ a redirect to the app URL, disclosing nothing about whether the
 * named tenant exists) is centralised in bootstrap/app.php.
 */
final class InvalidConnectorStateException extends RuntimeException
{
    public static function rejected(): self
    {
        return new self('The connection request could not be verified. Please start again.');
    }
}
