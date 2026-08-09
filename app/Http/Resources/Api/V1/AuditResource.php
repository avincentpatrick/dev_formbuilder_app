<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Audit;
use App\Services\Audit\AuditLogPresenter;
use Illuminate\Http\Request;

/**
 * One audit-ledger row (H4). `auditable_type` is the stable spec §1 alias string (`form_version`,
 * `resource_grants`, `users`, `tenant`, …) — surfaced opaque, not eager-resolved, since the target may be
 * hard-deleted. `old_values`/`new_values` are already redacted at write time (audit-compliance-logging-spec
 * §2); `redacted_fields` names what was withheld.
 *
 * ⚠️ SCRAMBLE PUBLISHES THE COMMENT ABOVE EACH KEY VERBATIM INTO `openapi.json`, so anything written there
 * is public API documentation, not a note to the next maintainer. I11a's first draft shipped an internal
 * argument about integration joins into the spec before the regen diff caught it. Internal reasoning goes
 * HERE; the per-key comments stay short and consumer-facing.
 *
 * On `acting_as_user_id` (I11a): an ID, not a name, matching `user_id` beside it — and the disclosure that
 * choice implies was weighed rather than inherited. The tenant WEB viewer deliberately renders only
 * "Platform operator" ({@see AuditLogPresenter::actingAsLabel()}), so exposing a raw
 * uuid here is strictly more than the page shows. It is still the right call: the id is OPAQUE to the
 * caller (platform staff hold no membership, so `/users` cannot resolve it), it discloses no more than the
 * page already does about the FACT of an operator acting, and it lets a tenant correlate several actions to
 * one operator — which serves RBAC §9's transparency posture rather than working against it. A display name
 * would leak staff identity to every API consumer and be unjoinable besides.
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
            // The platform operator who performed this action while impersonating `user_id`. Null unless
            // the action was taken during an impersonated session.
            'acting_as_user_id' => $this->acting_as_user_id,
            'is_system_action' => $this->is_system_action,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
