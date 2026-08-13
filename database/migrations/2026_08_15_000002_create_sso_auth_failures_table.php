<?php

declare(strict_types=1);

use App\Enums\SsoFailureReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A bounded, prunable record of refused SAML sign-ins (Phase 4, P1c — ADR-0016 §D26).
 *
 * ADR-0016 §D19 accepted a real cost in writing: *"a real employee whose IdP clock has drifted sees a bare
 * 404, and their admin has no in-app view of why."* The ADR's revisit trigger named what closing it would
 * require — *"a tenant-scoped failures panel fed by something other than `audits` — a bounded, prunable
 * store, because the reason that surface does not exist yet is that the obvious table is append-only and an
 * anonymous endpoint must not be able to fill it."* This is that store.
 *
 * ── ⚠️ WHY NOT `audits`, RESTATED BECAUSE IT IS THE WHOLE DESIGN CONSTRAINT ─────────────────────────
 * `audits` is append-only by RLS policy — no UPDATE and no DELETE policy for any role, under FORCE ROW
 * LEVEL SECURITY — and it is never pruned. The ACS is UNAUTHENTICATED: anyone on the internet who knows a
 * tenant's subdomain can make it write. Those two facts together make an audit row per rejection an
 * amplification primitive: one script, one afternoon, a compliance ledger nobody can read and nobody can
 * clean. This table exists precisely because it may be deleted from.
 *
 * ── THE BOUND IS ON THE WRITE PATH, NOT ON THE SCHEDULER, AND THAT IS DELIBERATE ────────────────────
 * The obvious shape is a nightly prune job. `routes/console.php` says plainly that nothing runs the
 * scheduler on the production box yet (`deploy.ps1` provisions no Task Scheduler task), so a bound that
 * lives there is a bound that does not exist where it matters. {@see SsoAuthFailureRecorder} therefore
 * trims on every insert — both a per-tenant row cap and a retention window, in one statement RLS already
 * scopes. The table cannot grow without limit even if no scheduled work ever runs, and the cost is one
 * cheap DELETE against a table the cap keeps small.
 *
 * ── WHAT IS AND IS NOT RECORDED ─────────────────────────────────────────────────────────────────────
 * `reason` is the machine token, CHECK-constrained to {@see SsoFailureReason::values()} — the
 * `sso_auth_requests_intent_check` precedent, so the vocabulary the panel groups by cannot drift from the
 * one the code throws.
 *
 * ⚠️ `subject_email` IS NULL FOR EVERY PRE-VALIDATION REFUSAL. An email only exists once a signature over
 * the assertion carrying it has verified; recording one from a document that failed validation would let an
 * anonymous caller write attacker-chosen text into a tenant's database and onto an admin's screen. The
 * exception carries the address as a field for exactly this reason rather than having it read back out of
 * its own message.
 *
 * `ip_address` is the member's, and it is why the retention window is data minimisation as well as
 * housekeeping — the `PruneFailedJobsJob` argument, on a table a stranger can write to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_auth_failures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Composite FK below, not foreignUuid()->constrained() — a single-column FK's referential action
            // bypasses RLS and could cross tenants (ADR-0002 §D5). Same shape as `sso_auth_requests`.
            $table->uuid('sso_connection_id');

            $table->string('reason', 40);

            // Post-validation refusals only. See the class docblock.
            $table->string('subject_email', 255)->nullable();

            // The `InResponseTo` the document claimed, when it carried one — so an admin can correlate a
            // panel row with a log line. NOT a foreign key: the commonest reason to be here is that no row
            // matched it, and an FK would make the unmatched case unrecordable.
            $table->char('request_id', 33)->nullable();

            $table->string('ip_address', 45)->nullable();     // 45 = INET6_ADDRSTRLEN
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'sso_connection_id'], 'sso_auth_failures_connection_fk')
                ->references(['tenant_id', 'id'])->on('sso_connections')->cascadeOnDelete();

            // Serves both readers: the panel's "twenty most recent for this tenant" and the trim's
            // "everything past the cap or the window". Leads with tenant_id per ADR-0002 §D1.
            $table->index(['tenant_id', 'occurred_at'], 'sso_auth_failures_tenant_occurred_idx');
        });

        $reasons = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            SsoFailureReason::values()
        ));
        DB::statement(
            'ALTER TABLE sso_auth_failures ADD CONSTRAINT sso_auth_failures_reason_check '
            ."CHECK (reason IN ({$reasons}))"
        );

        // Strict, and note what that means for an UNAUTHENTICATED writer: the ACS has tenant context (the
        // host establishes it before any query) but no `app.current_user_id`, exactly as the
        // `sso_auth_requests` INSERT already does on the same request.
        withTenantIsolation('sso_auth_failures');
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_auth_failures');
    }
};
