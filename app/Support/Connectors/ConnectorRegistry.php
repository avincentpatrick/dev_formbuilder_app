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

    /**
     * The provider's OPTIONAL destination lister (H15b), or null when it offers no picker.
     *
     * Config-driven exactly like `adapter`, and null rather than throwing: a missing channel_lister is not a
     * misconfiguration, it is a provider that has no such concept (H16's Sheets enumerates spreadsheets, not
     * channels). The caller degrades the picker; nothing 404s, because the CONNECTION is still perfectly valid.
     */
    public function channelListerFor(ConnectorProviderKey $provider): ?ListsChannels
    {
        $class = config("connectors.providers.{$provider->value}.channel_lister");

        if (! is_string($class) || ! is_a($class, ListsChannels::class, true)) {
            return null;
        }

        $lister = $this->container->make($class);

        return $lister instanceof ListsChannels ? $lister : null;
    }
}
