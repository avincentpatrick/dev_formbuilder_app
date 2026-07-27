<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\WebhookDelivery;
use Illuminate\Http\Request;

/**
 * One webhook delivery attempt-record over /api/v1 (H13a) — the per-endpoint delivery observability log
 * (webhook-integration-design.md §5). Deliberately EXCLUDES `payload` (conditional PII — it may embed answer
 * data) and `signature` (a credential-adjacent value); the log surfaces status, timing, and the response
 * excerpt for debugging, not the delivered body.
 *
 * @mixin WebhookDelivery
 */
final class WebhookDeliveryResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Exactly one owner is non-null — the ledger is shared by the webhook and native-connector
            // channels (H15a) and a DB CHECK enforces the exclusivity.
            'webhook_endpoint_id' => $this->webhook_endpoint_id,
            'connection_subscription_id' => $this->connection_subscription_id,
            'event_id' => $this->event_id,
            'event_type' => $this->event_type->value,
            'status' => $this->status->value,
            'attempt_count' => $this->attempt_count,
            'max_attempts' => $this->max_attempts,
            'next_retry_at' => $this->iso($this->next_retry_at),
            'last_attempted_at' => $this->iso($this->last_attempted_at),
            'response_status_code' => $this->response_status_code,
            'response_body_excerpt' => $this->response_body_excerpt,
            'response_time_ms' => $this->response_time_ms,
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
