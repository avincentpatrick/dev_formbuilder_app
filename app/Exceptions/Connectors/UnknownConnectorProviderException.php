<?php

declare(strict_types=1);

namespace App\Exceptions\Connectors;

use App\Enums\ConnectorProviderKey;
use App\Support\Connectors\ConnectorRegistry;
use RuntimeException;

/**
 * A provider key with no adapter behind it (ADR-0009, H15a) — either a route parameter that is not a
 * {@see ConnectorProviderKey} case, or a case whose `config('connectors.providers')` entry is
 * missing or unbuildable.
 *
 * Thrown by {@see ConnectorRegistry}. Mapped to a 404 in bootstrap/app.php: an unknown provider is an
 * unknown URL, not a server fault, and the non-disclosure posture matches the rest of the surface.
 */
final class UnknownConnectorProviderException extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self("No connector adapter is registered for provider [{$key}].");
    }
}
