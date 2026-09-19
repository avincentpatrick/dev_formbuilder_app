<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stamp `users.welcomed_at` for everyone already verified (Increment M103, closing `R-4f23d9c7`).
 *
 * ── WHY THIS IS ITS OWN FILE, MEASURED RATHER THAN COPIED FROM THE NEIGHBOUR ───────────────────────────
 * ⛔ Written inside `..._000112` beside its own `Schema::table()`, this **self-deadlocks and hangs
 * forever.** Laravel wraps each migration in a transaction on `pgsql`, so the `ALTER TABLE users` holds
 * `ACCESS EXCLUSIVE` while the separate `pgsql_privileged` SESSION below waits for that same lock — one
 * process, two connections, each waiting on the other. `pg_blocking_pids()` named the migration's own two
 * backends, which is how this was diagnosed rather than guessed at.
 *
 * `2026_07_20_000004_backfill_resource_grants_from_form_collaborators.php` had already written the rule
 * down: the split is *"the binding constraint, not operator convenience"*. This file exists because that
 * sentence is true and was rediscovered the expensive way.
 *
 * ── WHY IT CANNOT RUN ON THE APP CONNECTION ────────────────────────────────────────────────────────────
 * ⛔ `users` carries FORCE ROW LEVEL SECURITY, so the migration/app role does **not** bypass it — and
 * PostgreSQL applies SELECT policies to an UPDATE whose WHERE reads a column. With no
 * `app.current_user_id` set, `users_users_visibility` matches nothing, the UPDATE affects **zero rows**,
 * and **nothing is raised**. The migration would report success having changed no data.
 * `App\Support\Tenancy\TenantIsolation` records this as the false premise that cost six Fortify endpoints
 * the whole of Phase 0. Hence `pgsql_privileged`, as both existing data backfills in this directory use.
 *
 * ── WHY A BACKFILL AT ALL, WHERE `..._000111` DELIBERATELY HAD NONE ───────────────────────────────────
 * `add_password_set_at_to_users_table` shipped with no backfill and argued monotonicity, because nothing
 * in the old schema recorded that a human had chosen a password. Here something does:
 * `email_verified_at IS NOT NULL` **is** the record that the welcome already fired, since
 * `SendWelcomeEmail` is the only sender of `WelcomeNotification` and it fires on exactly that transition.
 *
 * ⚠️ AND IT IS RIGHT FOR INVITEES, THE CASE THAT DECIDED IT. `InvitationController` force-fills
 * `email_verified_at` and fires no event, so an invitee is verified and was DELIBERATELY never welcomed —
 * the listener argues at length that a third email after the invitation would be the least useful one.
 * Without this backfill their first address change would send them precisely that email. Skipping the
 * backfill would reintroduce the defect for the one population the design most wanted to protect.
 *
 * ── THE GUARD, BECAUSE THE FAILURE MODE ABOVE IS SILENT ────────────────────────────────────────────────
 * A wrong connection here produces a green migration and a column full of nulls, discoverable only when
 * somebody is welcomed twice months later. The post-condition re-counts on the same privileged connection
 * and throws if any verified account is still unstamped. On a fresh database the count is zero and it
 * passes, so it cannot fire spuriously — only when the write genuinely did not land.
 *
 * ── ROLLBACK ───────────────────────────────────────────────────────────────────────────────────────────
 * `down()` clears what `up()` stamped and is deliberately NOT selective: it cannot tell a backfilled
 * stamp from one the listener wrote afterwards, and guessing would be worse than saying so. Re-running
 * `up()` restores the backfilled half exactly; the listener's own stamps are lost, and the cost of that
 * is at most one repeated welcome per affected person. `..._000112`'s `down()` drops the column outright,
 * so a full unwind discards the same information by a shorter route.
 */
return new class extends Migration
{
    /**
     * The write goes to `pgsql_privileged` in AUTOCOMMIT, so a transaction Laravel opened on the DEFAULT
     * connection would roll back NOTHING here — leaving it on would only create the illusion of
     * atomicity, and it is what deadlocks against the preceding migration's lock. The same reasoning,
     * and the same flag, as `2026_07_20_000004`. Recovery is fail-FORWARD (re-run), which is safe
     * because the UPDATE is idempotent: `welcomed_at IS NULL` makes a second run a no-op.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $privileged = DB::connection('pgsql_privileged');

        $privileged->statement(
            'UPDATE users SET welcomed_at = email_verified_at
             WHERE email_verified_at IS NOT NULL AND welcomed_at IS NULL'
        );

        $unstamped = $privileged->table('users')
            ->whereNotNull('email_verified_at')
            ->whereNull('welcomed_at')
            ->count();

        if ($unstamped > 0) {
            throw new RuntimeException(
                "welcomed_at backfill landed nothing: {$unstamped} verified account(s) are still "
                .'unstamped. The UPDATE was almost certainly refused by row-level security — see this '
                ."migration's docblock."
            );
        }
    }

    public function down(): void
    {
        DB::connection('pgsql_privileged')->statement('UPDATE users SET welcomed_at = NULL');
    }
};
