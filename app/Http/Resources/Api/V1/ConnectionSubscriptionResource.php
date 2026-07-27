<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\ConnectionSubscription;
use Illuminate\Http\Request;

/**
 * One "send these events to this destination" rule on a connection, over /api/v1 (ADR-0009, H15a).
 *
 * `config` is returned in full — unlike a connection's tokens it holds no credential, only the provider's
 * destination handle (a Slack channel id and its display name), which the tenant chose and needs to see.
 *
 * @mixin ConnectionSubscription
 */
final class ConnectionSubscriptionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'connection_id' => $this->connection_id,
            'form_id' => $this->form_id,
            'name' => $this->name,
            'event_types' => $this->event_types,
            'config' => $this->config,
            'status' => $this->status->value,
            'consecutive_failure_count' => $this->consecutive_failure_count,
            'last_success_at' => $this->iso($this->last_success_at),
            'last_failure_at' => $this->iso($this->last_failure_at),
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
