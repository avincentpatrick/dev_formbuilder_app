<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DomainEventType;
use App\Enums\WebhookDeliveryStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Services\Webhooks\WebhookRetrySweeper;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single outbound delivery attempt-record (data-dictionary §15, H13a; generalized in H15a). Mutable
 * tenant-owned record → the plain `strict` RLS shape. `payload` is the full delivered envelope
 * `{event_id, event_type, occurred_at, tenant_id, api_version, data}`.
 *
 * SHARED LEDGER (H15a): a row is owned by EITHER a {@see WebhookEndpoint} (the H13a channel — the tenant
 * runs the receiver) OR a {@see ConnectionSubscription} (the H15a native-connector channel — we hold an
 * OAuth grant and post on their behalf), never both and never neither; a DB CHECK
 * (`webhook_deliveries_owner_check`) enforces it. Idempotency is `(owner, event_id)` on whichever side is
 * populated, as two PARTIAL uniques. One ledger means one retry ladder, one delivery quota and one log
 * shape across both channels — {@see WebhookRetrySweeper} reads the owner columns to
 * decide which delivery job to dispatch.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ?string $webhook_endpoint_id
 * @property ?string $connection_subscription_id
 * @property string $event_id
 * @property DomainEventType $event_type
 * @property array<string, mixed> $payload
 * @property ?string $payload_attachment_id
 * @property WebhookDeliveryStatus $status
 * @property int $attempt_count
 * @property int $max_attempts
 * @property ?Carbon $next_retry_at
 * @property ?Carbon $last_attempted_at
 * @property ?int $response_status_code
 * @property ?string $response_body_excerpt
 * @property ?int $response_time_ms
 * @property ?string $signature
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class WebhookDelivery extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    use HasUuidv7;

    protected $fillable = [
        'tenant_id',
        'webhook_endpoint_id',
        'connection_subscription_id',
        'event_id',
        'event_type',
        'payload',
        'payload_attachment_id',
        'status',
        'attempt_count',
        'max_attempts',
        'next_retry_at',
        'last_attempted_at',
        'response_status_code',
        'response_body_excerpt',
        'response_time_ms',
        'unconfirmed_write_at',
        'signature',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => DomainEventType::class,
            'payload' => 'array',
            'status' => WebhookDeliveryStatus::class,
            'attempt_count' => 'integer',
            'max_attempts' => 'integer',
            'next_retry_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'response_status_code' => 'integer',
            'response_time_ms' => 'integer',
            'unconfirmed_write_at' => 'datetime',
        ];
    }

    /**
     * The webhook-channel owner; null on a connector-owned row.
     *
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /**
     * The connector-channel owner (H15a); null on a webhook-owned row.
     *
     * @return BelongsTo<ConnectionSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ConnectionSubscription::class, 'connection_subscription_id');
    }
}
