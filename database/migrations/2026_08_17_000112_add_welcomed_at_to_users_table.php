<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The positive record that this person has already been welcomed (Increment M103, closing `R-4f23d9c7`).
 *
 * ── THE DEFECT THIS COLUMN EXISTS TO CLOSE ────────────────────────────────────────────────────────────
 * `App\Listeners\Auth\SendWelcomeEmail` handles Fortify's `Verified` event and its docblock opens *"Say
 * hello, once"*. It has no guard of any kind, and `Verified` is not a once-per-person event:
 * `UpdateUserProfileInformation::updateVerifiedUser()` nulls `email_verified_at` and re-sends verification
 * on EVERY address change, the tenant Settings page puts that field in front of every member, and
 * Fortify's `VerifyEmailController` fires `Verified` again when the new link is clicked. A tester who
 * corrects a typo in their own address is welcomed to the product a second time.
 *
 * ⛔ `email_verified_at` CANNOT BE THE GUARD, WHICH IS THE WHOLE REASON THIS IS A NEW COLUMN. It is
 * NULLED by the very action that starts the second welcome, so at the moment the listener runs it says
 * exactly what it says for a brand-new account. The question "has this person been greeted before?" is
 * unanswerable from the old schema.
 *
 * ── THE BACKFILL IS DELIBERATE, AND IT DEVIATES FROM THIS FILE'S OWN PRECEDENT ─────────────────────────
 * `2026_08_17_000111_add_password_set_at_to_users_table.php` shipped with NO backfill and argued
 * monotonicity: no honest backfill existed for it, because nothing in the old schema recorded that a human
 * had chosen a password. Here one does. `email_verified_at IS NOT NULL` **is** the record that the welcome
 * already fired, because that listener is the only thing that sends `WelcomeNotification` and it fires on
 * exactly that transition.
 *
 * ⚠️ IT IS ALSO RIGHT FOR INVITEES, WHICH IS THE CASE THAT DECIDED IT. `InvitationController` force-fills
 * `email_verified_at` and fires no event, so an invitee is verified and was DELIBERATELY never welcomed —
 * the listener's docblock argues at length that a third email after the invitation would be the least
 * useful one. Without this backfill their first address change would send them precisely that email. So
 * stamping every verified account is not merely convenient; skipping it would reintroduce the defect for
 * the one population the design most wanted to protect.
 *
 * ⚠️ THE BACKFILL IS A SEPARATE MIGRATION, AND THIS ONE MEASURED WHY THE HARD WAY. Written as one file —
 * `Schema::table()` on the default connection, then the UPDATE on `pgsql_privileged` — it SELF-DEADLOCKS
 * and hangs forever. Laravel wraps each migration in a transaction on `pgsql`, so the ALTER holds
 * ACCESS EXCLUSIVE on `users` while the separate privileged SESSION waits for that same lock; measured
 * with `pg_blocking_pids()`, which named the migration's own two backends. The backfill therefore lives
 * in `..._000113`, exactly as `2026_07_20_000004_backfill_resource_grants_from_form_collaborators.php`
 * already prescribes for this repository — its docblock calls the split "the binding constraint", not
 * operator convenience, and it was right.
 *
 * ⚠️ NO `withTenantIsolation()`, correct rather than omitted: this creates no table, and `users` is not
 * tenant-scoped. `scripts/migration-lint.php` requires the call only for a CREATE carrying a literal
 * `tenant_id`.
 *
 * ⚠️ NO NEW GRANT OR POLICY IS NEEDED. A column on `users` rides the existing permissive
 * `users_app_update` policy and the existing table privileges — the same argument
 * `2026_08_16_000002_add_google_id_to_users_table.php` and `..._000111` both make.
 *
 * ⚠️ THE APPLICATION WRITES THIS COLUMN UNDER A BORROWED USER GUC, NOT PRIVILEGED AND NOT PRE-AUTH.
 * `SendWelcomeEmail::claimWelcome()` sets `app.current_user_id` to the person's own id for one
 * transaction, satisfying `users_users_visibility`'s `id = app.current_user_id` arm so the row is
 * visible to its own UPDATE. That is the narrowest of the three options and the only one correct on
 * BOTH doors — the ambient GUC is absent on the Google sign-in path, and `pgsql_auth` is a separate
 * session that cannot see an uncommitted test fixture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // `after('email_verified_at')` because this column is the answer to a question that one
            // raises — a reader running `\d users` should meet the two together.
            $table->timestampTz('welcomed_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('welcomed_at');
        });
    }
};
