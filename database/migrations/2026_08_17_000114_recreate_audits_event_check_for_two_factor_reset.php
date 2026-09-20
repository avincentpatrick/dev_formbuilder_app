<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen `audits_event_check` for the administrative two-factor reset — Increment M107 (`D37`).
 *
 * ── THIS MIGRATION IS NOT OPTIONAL, AND ITS ABSENCE FAILS AT THE WORST MOMENT ───────────────────────────
 * `create_audits_table.php` generates the CHECK from {@see AuditEvent::values()} so the enum and the
 * constraint cannot drift. That generation runs ONCE, at the migration that created it — adding a case to
 * the PHP enum does nothing to a database that already exists. Without this file,
 * `AuditEvent::TwoFactorReset` reaches PostgreSQL as `SQLSTATE 23514` from inside `AuditLogger::record()`,
 * which runs inside the caller's business transaction: **the reset would roll back at the moment it was
 * being recorded**, and a person already locked out of their account would be told it had failed.
 *
 * That failure mode is worse here than it was for impersonation. An operator whose impersonation grant
 * rolls back tries again; the audience for this one is somebody who cannot sign in at all.
 *
 * It is also invisible to a green local test run on a tree that never dropped its database, which is why it
 * is written first and not last. The predecessor this file copies — `2026_08_10_000002` — says the same,
 * and saying it twice is cheaper than a third increment rediscovering it.
 *
 * ── REGENERATED FROM THE ENUM, NOT HAND-LISTED ─────────────────────────────────────────────────────────
 * The new constraint is built from `AuditEvent::values()` exactly as both predecessors were, so a TWELFTH
 * case needs a migration but never an edit to a literal list here. Dropping by `IF EXISTS` keeps this
 * runnable against a database that predates the constraint.
 *
 * ── `down()` NARROWS RATHER THAN DROPPING ──────────────────────────────────────────────────────────────
 * Restoring the ten-value list is the honest inverse, but it will FAIL if a reset has already been
 * recorded — PostgreSQL validates a new CHECK against the whole table. That is the correct outcome: a
 * rollback that silently left the ledger unconstrained would be worse than one that stops and says the data
 * no longer fits. Delete the rows first if that is genuinely what is wanted — and note that deleting an
 * audit row is itself refused by the append-only policy, which is the honest shape of "this is hard to
 * undo" rather than an oversight.
 *
 * ── ALTER-ONLY, NO RLS RE-EMIT ─────────────────────────────────────────────────────────────────────────
 * `audits` already carries its append-only isolation shape and those policies are ROW-scoped, not
 * column-scoped — a constraint change does not touch them. (`scripts/migration-lint.php` only requires
 * isolation on migrations that CREATE a tenant-scoped table.)
 */
return new class extends Migration
{
    /** The vocabulary as it stood before M107 — the exact list `2026_08_10_000002` left behind. */
    private const PRE_M107_EVENTS = [
        'created', 'updated', 'deleted', 'restored',
        'published', 'archived', 'exported', 'permission_changed',
        'impersonation_started', 'impersonation_ended',
    ];

    public function up(): void
    {
        $this->recreate(AuditEvent::values());
    }

    public function down(): void
    {
        $this->recreate(self::PRE_M107_EVENTS);
    }

    /** @param  list<string>  $events */
    private function recreate(array $events): void
    {
        $list = implode(', ', array_map(static fn (string $value): string => "'".$value."'", $events));

        DB::statement('ALTER TABLE audits DROP CONSTRAINT IF EXISTS audits_event_check');
        DB::statement("ALTER TABLE audits ADD CONSTRAINT audits_event_check CHECK (event IN ({$list}))");
    }
};
