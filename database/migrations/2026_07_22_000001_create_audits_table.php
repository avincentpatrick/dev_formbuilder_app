<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only audit ledger (H4, data-dictionary §13, audit-compliance-logging-spec.md). Carried
 * forward from legacy's `Auditable` mechanism, but the table's *write posture* is enforced at the
 * database this time, not merely promised in prose:
 *
 *  - **Append-only RLS** (`append_only` shape): strict tenant SELECT + INSERT and NO update/delete policy,
 *    so FORCE ROW LEVEL SECURITY denies mutation and deletion to every role — the "immutable, never
 *    deleted" guarantee (§13 Design Notes) becomes a Postgres invariant.
 *  - **Nullable `tenant_id` with a NULL-on-delete FK, not cascade**: referential actions BYPASS RLS, so a
 *    `cascadeOnDelete` on a tenant hard-delete would silently wipe "never-deleted" audit rows through the
 *    exact hole the form_version guard exists to close. `nullOnDelete` preserves the ledger (the row lives
 *    on as a platform-level record of a tenant that is gone).
 *
 * A super-admin action against a tenant (suspend/reactivate) is audited into THAT tenant's own log
 * (RBAC §9 transparency) by writing under the AFFECTED tenant's context on the app connection, atomically
 * with the status change (see SuperAdminService) — so the strict INSERT policy passes with no carve-out. A
 * super-admin INSERT bypass would only be needed for a future NULL-tenant / cross-tenant PLATFORM audit
 * action, which H4 does not have; it is deferred to that action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Nullable: a rare platform-level action with no single-tenant context (§13). Declared as a
            // manual ->foreign (not foreignUuid()->constrained(), which isn't nullable-friendly) so the
            // migration linter's declares_tenant_column rule still sees the literal `tenant_id`. nullOnDelete
            // NOT cascade — see the class docblock (FK actions bypass RLS; cascade would delete the ledger).
            $table->uuid('tenant_id')->nullable();

            // Explicit morph columns rather than uuidMorphs(): uuidMorphs() emits a (type, id) index whose
            // leading column is not tenant_id (ADR-0002 §D1). NO DB FK — the target table varies by type, and
            // the auditable may itself be hard-deleted while its audit trail must remain. Aliases are the
            // audit-compliance-logging-spec §1 strings (`form_version`, `submission`, `users`, `tenant`,
            // `resource_grants`, …), stored as literals — deliberately NOT the global Relation::morphMap,
            // which would split Sanctum/Spatie morph rows if User/Tenant were aliased there.
            $table->string('auditable_type', 100);
            $table->uuid('auditable_id');

            $table->string('event', 30); // App\Enums\AuditEvent

            // Redacted per audit-compliance-logging-spec §2 BEFORE write. redacted_fields is the transparency
            // record of what was stripped (names only, never values).
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->jsonb('redacted_fields')->nullable();

            $table->uuid('user_id')->nullable();                 // NULL for automated/system actions
            $table->boolean('is_system_action')->default(false);
            $table->ipAddress('ip_address')->nullable();         // Postgres inet
            $table->text('user_agent')->nullable();

            // Append-only: created_at only, no updated_at/deleted_at (§13). The Audit model sets
            // const UPDATED_AT = null so Eloquent never writes an updated_at.
            $table->timestampTz('created_at')->useCurrent();

            // FKs preserve the ledger past the referenced row's deletion (see docblock).
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            // EXPLICIT SHORT NAMES (PostgreSQL NAMEDATALEN = 63): the auto-generated names for these column
            // lists would be truncated and risk a collision. All lead with tenant_id (ADR-0002 §D1).
            $table->index(['tenant_id', 'id'], 'audits_tenant_recent_idx');                         // browse newest-first (cursor on id)
            $table->index(['tenant_id', 'auditable_type', 'auditable_id'], 'audits_tenant_auditable_idx'); // one resource's history
            $table->index(['tenant_id', 'user_id'], 'audits_tenant_user_idx');                      // one actor's actions
        });

        // Pin the event vocabulary at the database, generated from the enum so the two cannot drift.
        $events = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            AuditEvent::values()
        ));
        DB::statement(
            'ALTER TABLE audits ADD CONSTRAINT audits_event_check '
            ."CHECK (event IN ({$events}))"
        );

        // Append-only isolation shape — strict tenant SELECT + INSERT, no update/delete policy (FORCE RLS
        // denies mutation). Satisfies the migration linter (literal 'tenant_id' above + this call).
        withTenantIsolation('audits', 'append_only');
    }

    public function down(): void
    {
        // Dropping the table removes its policies with it.
        Schema::dropIfExists('audits');
    }
};
