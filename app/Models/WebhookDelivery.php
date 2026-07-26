<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DomainEventType;
use App\Enums\WebhookDeliveryStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single webhook delivery attempt-record (data-dictionary §15, H13a). Mutable tenant-owned record → the
 * plain `strict` RLS shape. `(webhook_endpoint_id, event_id)` is unique — the idempotency key. `payload`
 * is the full delivered envelope `{event_id, event_type, occurred_at, tenant_id, api_version, data}`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $webhook_endpoint_id
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
        ];
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
