<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Audit;
use Illuminate\Http\Request;

/**
 * One audit-ledger row (H4). `auditable_type` is the stable spec §1 alias string (`form_version`,
 * `resource_grants`, `users`, `tenant`, …) — surfaced opaque, not eager-resolved, since the target may be
 * hard-deleted. `old_values`/`new_values` are already redacted at write time (audit-compliance-logging-spec
 * §2); `redacted_fields` names what was withheld.
 *
 * @mixin Audit
 */
final class AuditResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'event' => $this->event->value,
            'old_values' => $this->old_values !== null ? $this->jsonObject($this->old_values) : null,
            'new_values' => $this->new_values !== null ? $this->jsonObject($this->new_values) : null,
            'redacted_fields' => $this->redacted_fields,
            'user_id' => $this->user_id,
            'is_system_action' => $this->is_system_action,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
