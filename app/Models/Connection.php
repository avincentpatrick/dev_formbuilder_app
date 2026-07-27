<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConnectionStatus;
use App\Enums\ConnectorProviderKey;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Database\Factories\ConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The platform's OAuth grant on a tenant's third-party workspace (data-dictionary §24, ADR-0009, H15a).
 * Mutable tenant-owned record → the plain `strict` RLS shape.
 *
 * Both tokens are `encrypted` at rest and — unlike {@see WebhookEndpoint::$secret}, which is revealed once
 * so the tenant can configure their receiver — are returned by NO api resource or presenter in any form,
 * masked or otherwise (ADR-0009 §D1): the platform is their only consumer, so displaying one is pure
 * downside. They are also registered in `AuditRedactor` under the `connection` alias (§D10).
 *
 * Credentials live here; ROUTING lives on {@see ConnectionSubscription}, so one grant can feed many
 * destinations without duplicating (or re-refreshing) a token per rule.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ConnectorProviderKey $provider
 * @property string $external_account_id
 * @property string $external_account_label
 * @property list<string> $scopes
 * @property string $access_token
 * @property ?string $refresh_token
 * @property ?Carbon $token_expires_at
 * @property ConnectionStatus $status
 * @property ?Carbon $last_refreshed_at
 * @property ?Carbon $last_error_at
 * @property ?string $last_error
 * @property ?string $connected_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
class Connection extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<ConnectionFactory> */
    use HasFactory;

    use HasUuidv7;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'provider',
        'external_account_id',
        'external_account_label',
        'scopes',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'status',
        'last_refreshed_at',
        'last_error_at',
        'last_error',
        'connected_by',
    ];

    /**
     * Neither token is ever in the array/JSON form of the model, so one cannot reach a log, an Inertia prop,
     * or a default resource by omission (ADR-0009 §D1).
     *
     * @var list<string>
     */
    protected $hidden = ['access_token', 'refresh_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => ConnectorProviderKey::class,
            'scopes' => 'array',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'status' => ConnectionStatus::class,
            'last_refreshed_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    /**
     * Whether the access token is due for a proactive refresh (ADR-0009 §D6). A grant with no expiry (Slack's
     * default bot token) or no refresh token can never be refreshed, so it is never due — the sweep skips it
     * rather than failing on it.
     */
    public function needsRefresh(Carbon $now, int $leadSeconds): bool
    {
        if ($this->refresh_token === null || $this->token_expires_at === null) {
            return false;
        }

        return $this->token_expires_at->lessThanOrEqualTo($now->copy()->addSeconds($leadSeconds));
    }

    /** @return HasMany<ConnectionSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(ConnectionSubscription::class);
    }

    /** @return BelongsTo<User, $this> */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }
}
