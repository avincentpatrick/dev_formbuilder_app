<?php

declare(strict_types=1);

namespace App\Support\Connectors;

use App\Enums\ConnectorProviderKey;
use App\Exceptions\Connectors\UnknownConnectorProviderException;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves a {@see ConnectorProviderKey} to its {@see ConnectorProvider} adapter (ADR-0009, H15a) — the one
 * place that knows which class serves which provider, so nothing else needs a `match` over providers.
 *
 * The map lives in `config('connectors.providers')` rather than in code so a provider can be disabled
 * operationally (an outage, a revoked app registration) without a deploy: an absent or misconfigured entry
 * makes every route for that provider 404 rather than half-work.
 */
final class ConnectorRegistry
{
    public function __construct(private readonly Container $container) {}

    /**
     * @throws UnknownConnectorProviderException the key has no configured, buildable adapter
     */
    public function for(ConnectorProviderKey $provider): ConnectorProvider
    {
        $class = config("connectors.providers.{$provider->value}.adapter");

        if (! is_string($class) || ! is_a($class, ConnectorProvider::class, true)) {
            throw UnknownConnectorProviderException::forKey($provider->value);
        }

        return $this->container->make($class);
    }

    /**
     * Resolve a raw route segment (`slack`) to an adapter. Rejects both an unknown string and a known enum
     * case with no adapter behind it, so a route parameter can never reach a half-configured provider.
     *
     * @throws UnknownConnectorProviderException
     */
    public function fromKey(string $key): ConnectorProvider
    {
        $provider = ConnectorProviderKey::tryFrom($key);

        if ($provider === null) {
            throw UnknownConnectorProviderException::forKey($key);
        }

        return $this->for($provider);
    }

    /** The enum case for a raw route segment, refusing anything without a configured adapter. */
    public function keyFor(string $key): ConnectorProviderKey
    {
        return $this->fromKey($key)->key();
    }
}
