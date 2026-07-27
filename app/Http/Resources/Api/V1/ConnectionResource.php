<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Connection;
use Illuminate\Http\Request;

/**
 * A native-connector OAuth grant over /api/v1 (ADR-0009, H15a).
 *
 * NEITHER TOKEN APPEARS HERE IN ANY FORM — not revealed once, not masked, not a suffix. That is a deliberate
 * divergence from {@see WebhookEndpointResource}, which reveals a webhook secret at creation because the
 * tenant must paste it into their own receiver. A connector token has no user-facing consumer: the platform
 * is the only thing that ever sends it, so displaying it would be pure downside (ADR-0009 §D1). What the
 * tenant needs to see is which workspace is connected, with what scopes, and whether it still works.
 *
 * @mixin Connection
 */
final class ConnectionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider->value,
            'external_account_id' => $this->external_account_id,
            'external_account_label' => $this->external_account_label,
            'scopes' => $this->scopes,
            'status' => $this->status->value,
            // Null for a non-expiring grant (Slack's default bot token) — not "unknown", but "never expires".
            'token_expires_at' => $this->iso($this->token_expires_at),
            'last_refreshed_at' => $this->iso($this->last_refreshed_at),
            // The provider's own error code from the last failure (e.g. `invalid_grant`), never a body.
            'last_error' => $this->last_error,
            'last_error_at' => $this->iso($this->last_error_at),
            'connected_by' => $this->connected_by,
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
