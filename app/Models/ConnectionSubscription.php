<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConnectorSubscriptionStatus;
use App\Enums\DomainEventType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Database\Factories\ConnectionSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One "send these events to this destination" rule on a {@see Connection} (data-dictionary §25, ADR-0009,
 * H15a) — the {@see WebhookEndpoint} analogue for native connectors. Mutable tenant-owned record → the
 * plain `strict` RLS shape.
 *
 * `form_id` NULL = tenant-wide, non-null = scoped to one form. `event_types` is a list of
 * {@see DomainEventType} string values. `config` is the provider-specific destination payload (Slack:
 * `{channel_id, channel_name}`) and never holds a credential — those live on the connection.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $connection_id
 * @property ?string $form_id
 * @property string $name
 * @property list<string> $event_types
 * @property array<string, mixed> $config
 * @property ConnectorSubscriptionStatus $status
 * @property int $consecutive_failure_count
 * @property ?Carbon $last_success_at
 * @property ?Carbon $last_failure_at
 * @property ?string $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
class ConnectionSubscription extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<ConnectionSubscriptionFactory> */
    use HasFactory;

    use HasUuidv7;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'connection_id',
        'form_id',
        'name',
        'event_types',
        'config',
        'status',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_types' => 'array',
            'config' => 'array',
            'status' => ConnectorSubscriptionStatus::class,
            'consecutive_failure_count' => 'integer',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    /**
     * The OAuth grant this rule delivers through.
     *
     * NAMED `grant()`, NOT `connection()`, deliberately: Eloquent's base Model declares a protected
     * `$connection` property (the database connection name), so a relation of that name would resolve
     * through `__get` at runtime while every static analyser — and every reader — sees the DB-connection
     * property instead. The domain word for what this is, per ADR-0009, is a grant.
     *
     * @return BelongsTo<Connection, $this>
     */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * This subscription's rows in the shared outbound ledger (H15a — `webhook_deliveries` is owned by either
     * a webhook endpoint or a connection subscription, never both).
     *
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'connection_subscription_id');
    }
}
