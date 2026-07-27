<?php

declare(strict_types=1);

namespace App\Support\Connectors;

use App\Services\Connectors\ConnectionService;
use Illuminate\Support\Carbon;

/**
 * The normalized result of an OAuth code exchange or token refresh (ADR-0009, H15a) — the one shape every
 * provider adapter reduces its wire response to, so {@see ConnectionService} never
 * learns a provider's field names.
 *
 * `expiresAt` is null for a non-expiring grant (Slack's default bot token), which is what makes the refresh
 * sweep skip it rather than fail on it. `refreshToken` is null when the provider issues none, and MAY be
 * null on a refresh response — some providers return only a new access token and expect the existing
 * refresh token to be kept, so the caller preserves the stored one rather than nulling it.
 */
final readonly class ConnectorGrant
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public ?Carbon $expiresAt,
        public array $scopes,
        public string $externalAccountId,
        public string $externalAccountLabel,
    ) {}
}
