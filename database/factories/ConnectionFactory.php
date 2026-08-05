<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ConnectionStatus;
use App\Enums\ConnectorProviderKey;
use App\Models\Connection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Connection>
 *
 * `tenant_id` is auto-filled by BelongsToTenant's creating hook, so the active TenantContext decides
 * ownership. The default is a live Slack grant with a NON-EXPIRING token (Slack's default bot token —
 * `refresh_token`/`token_expires_at` null), which the refresh sweep correctly skips; use
 * {@see expiringIn()} for a grant the sweep should pick up.
 */
class ConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => ConnectorProviderKey::Slack,
            'external_account_id' => 'T'.strtoupper(Str::random(10)),
            'external_account_label' => fake()->company().' Workspace',
            'scopes' => ['chat:write', 'channels:read'],
            'access_token' => 'xoxb-'.Str::random(40),
            'refresh_token' => null,
            'token_expires_at' => null,
            'status' => ConnectionStatus::Active,
            'connected_by' => null,
        ];
    }

    /** A rotating grant whose access token expires in $seconds — the refresh sweep's target. */
    public function expiringIn(int $seconds): static
    {
        return $this->state(fn (): array => [
            'refresh_token' => 'xoxe-'.Str::random(40),
            'token_expires_at' => Carbon::now()->addSeconds($seconds),
        ]);
    }

    /**
     * A Google Sheets grant (H16a) — and unlike the Slack default it is REFRESHABLE and EXPIRING, because
     * that is what a real one is: Google issues an access token that lives about an hour plus a refresh token
     * on the first authorization. A Google grant with a null expiry does not occur in production, so a factory
     * that produced one would let a test pass over a code path the sweep and the pre-flight both skip.
     */
    public function googleSheets(int $expiresInSeconds = 3600): static
    {
        return $this->state(fn (): array => [
            'provider' => ConnectorProviderKey::GoogleSheets,
            'external_account_id' => 'google-drive',
            'external_account_label' => 'Google Sheets',
            'scopes' => ['https://www.googleapis.com/auth/drive.file'],
            'access_token' => 'ya29.'.Str::random(40),
            'refresh_token' => '1//'.Str::random(40),
            'token_expires_at' => Carbon::now()->addSeconds($expiresInSeconds),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['status' => ConnectionStatus::Revoked]);
    }

    public function refreshFailed(): static
    {
        return $this->state(fn (): array => [
            'status' => ConnectionStatus::RefreshFailed,
            'last_error' => 'invalid_grant',
            'last_error_at' => Carbon::now(),
        ]);
    }
}
