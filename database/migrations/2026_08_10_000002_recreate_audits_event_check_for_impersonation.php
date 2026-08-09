<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen `audits_event_check` for the two impersonation boundaries — Increment I11b.
 *
 * ── THIS MIGRATION IS NOT OPTIONAL, AND ITS ABSENCE FAILS AT THE WORST MOMENT ───────────────────────────
 * `create_audits_table.php` generates the CHECK from {@see AuditEvent::values()} so the enum and the
 * constraint cannot drift. That generation runs ONCE, at the migration that created it — adding a case to
 * the PHP enum does nothing to a database that already exists. Without this file, `AuditEvent::
 * ImpersonationStarted` reaches PostgreSQL as `SQLSTATE 23514` from inside `AuditLogger::record()`, which
 * runs inside the caller's business transaction: the impersonation grant would roll back at the moment it
 * was being recorded, and the operator would see a 500 with no ledger row explaining it.
 *
 * It is also invisible to a green local test run on a tree that never dropped its database, which is why it
 * is written first and not last.
 *
 * ── REGENERATED FROM THE ENUM, NOT HAND-LISTED ─────────────────────────────────────────────────────────
 * The new constraint is built from `AuditEvent::values()` exactly as the original was, so a NINTH case
 * needs a migration but never an edit to a literal list here. Dropping by `IF EXISTS` keeps this runnable
 * against a database that predates the constraint.
 *
 * ── `down()` NARROWS RATHER THAN DROPPING ──────────────────────────────────────────────────────────────
 * Restoring the eight-value list is the honest inverse, but it will FAIL if impersonation rows already
 * exist — PostgreSQL validates a new CHECK against the whole table. That is the correct outcome: a rollback
 * that silently left the ledger unconstrained would be worse than one that stops and says the data no
 * longer fits. Delete the rows first if that is genuinely what is wanted.
 *
 * ── ALTER-ONLY, NO RLS RE-EMIT ─────────────────────────────────────────────────────────────────────────
 * `audits` already carries its append-only isolation shape and those policies are ROW-scoped, not
 * column-scoped — a constraint change does not touch them. (`scripts/migration-lint.php` only requires
 * isolation on migrations that CREATE a tenant-scoped table.)
 */
return new class extends Migration
{
    /** The vocabulary as it stood before I11b — the exact list `create_audits_table.php` generated. */
    private const PRE_I11B_EVENTS = [
        'created', 'updated', 'deleted', 'restored',
        'published', 'archived', 'exported', 'permission_changed',
    ];

    public function up(): void
    {
        $this->recreate(AuditEvent::values());
    }

    public function down(): void
    {
        $this->recreate(self::PRE_I11B_EVENTS);
    }

    /** @param  list<string>  $events */
    private function recreate(array $events): void
    {
        $list = implode(', ', array_map(static fn (string $value): string => "'".$value."'", $events));

        DB::statement('ALTER TABLE audits DROP CONSTRAINT IF EXISTS audits_event_check');
        DB::statement("ALTER TABLE audits ADD CONSTRAINT audits_event_check CHECK (event IN ({$list}))");
    }
};
