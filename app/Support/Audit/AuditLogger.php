<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Enums\AuditEvent;
use App\Models\Audit;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;

/**
 * The single write path into the append-only audit ledger (H4, audit-compliance-logging-spec.md,
 * data-dictionary §13). Every audit row in the system is written through {@see record()} — there is no
 * `Auditable` model observer in H4 (that sugar is deferred to the first increment that broadly audits model
 * lifecycle); routing every emission through one logger keeps redaction and the row shape impossible to
 * diverge.
 *
 * The write runs inside the caller's transaction on the tenant connection; `tenant_id` auto-fills from the
 * active {@see TenantContext} via BelongsToTenant, and the strict INSERT policy checks
 * `tenant_id = ctx`. It is atomic with the change it records — if the caller's transaction rolls back, so
 * does the audit (technical-architecture.md §4.1: the audit INSERT is inside the transaction, before COMMIT).
 * A super-admin action against a tenant is recorded the same way, under the AFFECTED tenant's context (see
 * SuperAdminService), so it needs no elevated connection or INSERT bypass.
 *
 * Redaction (§2) is applied here, at write time, so stored `old_values`/`new_values` are already safe and
 * the read API returns them verbatim.
 */
final class AuditLogger
{
    public function __construct(private readonly AuditRedactor $redactor) {}

    /**
     * Record a tenant-scoped audit row (the tenant is the active context's). `$actorId` defaults to the
     * authenticated user; pass it explicitly (or null for a guest/system-less action) when auth() is not
     * the actor.
     *
     * @param  ?array<string, mixed>  $old
     * @param  ?array<string, mixed>  $new
     * @param  list<string>  $erasedKeys  keys erased by a GDPR erasure (§2.1 — both sides placeholdered)
     * @param  list<string>  $piiAnswerKeys  answer keys whose field is is_pii/is_sensitive
     */
    public function record(
        AuditEvent $event,
        string $auditableType,
        string $auditableId,
        ?array $old = null,
        ?array $new = null,
        ?string $actorId = null,
        array $erasedKeys = [],
        array $piiAnswerKeys = [],
    ): Audit {
        $redacted = $this->redactor->redact($auditableType, $old, $new, $erasedKeys, $piiAnswerKeys);

        $audit = new Audit;
        $audit->forceFill([
            // tenant_id intentionally omitted — BelongsToTenant fills it from the active context on create.
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'event' => $event,
            'old_values' => $redacted['old'],
            'new_values' => $redacted['new'],
            'redacted_fields' => $redacted['redacted_fields'],
            'user_id' => $actorId ?? Auth::id(),
            'is_system_action' => false,
            'ip_address' => $this->requestIp(),
            'user_agent' => $this->requestUserAgent(),
        ]);
        $audit->save();

        return $audit;
    }

    /** Best-effort request metadata — null outside an HTTP context (console/queue). */
    private function requestIp(): ?string
    {
        return app()->bound('request') ? request()->ip() : null;
    }

    private function requestUserAgent(): ?string
    {
        return app()->bound('request') ? request()->userAgent() : null;
    }
}
