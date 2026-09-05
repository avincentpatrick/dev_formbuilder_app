<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The positive record that a HUMAN CHOSE THIS PASSWORD (Increment M76, closing residual 30).
 *
 * ── THE DEFECT THIS COLUMN EXISTS TO CLOSE ────────────────────────────────────────────────────────────
 * `TenantMembershipService::identityIsEstablished()` decides whether a holder of an invitation token may
 * set a password on the invited account. Every one of its five arms reads FALSE for an account created by
 * central-host self-registration and never used: `CreateNewUser` stamps no `email_verified_at`, that door
 * creates no membership, and `google_id` / `two_factor_confirmed_at` are NULL. The invitation routes carry
 * neither `auth` nor `verified`, so that predicate is the ENTIRE gate — and a token holder was handed
 * `registerInvitedPlaceholder()`, which force-fills a chosen password, stamps `email_verified_at` and logs
 * in as them. The account is real and someone else's; the attacker needs only the emailed token.
 *
 * ⛔ `password` CANNOT BE THE SIGNAL AND THIS REPOSITORY HAS PAID FOR THAT TWICE. `resolveOrCreateUser()`
 * writes `Hash::make(Str::random(48))` into a NOT NULL column, so a placeholder's hash is byte-for-byte
 * as real as anyone's. *"Has a usable password"* is unanswerable from the old schema — which is the whole
 * reason this is a new column rather than a cleverer query. ADR-0016 §D22 records the same
 * indistinguishability for its own fork, and this retires it for the repository.
 *
 * ⛔ `tos_accepted_at` IS THE TRAP AND IS DELIBERATELY NOT REUSED. Its only writer is
 * `InvitationController` itself, so a self-registered member has it NULL and SSO JIT provisioning leaves
 * it NULL by design — it would refuse precisely the people it appears to admit.
 *
 * ── NULLABLE, WITH NO BACKFILL, AND THAT IS THE DESIGN RATHER THAN A DEFERRAL ──────────────────────────
 * The row that filed this concluded the backfill was the hard part, and that the only honest one
 * *"re-derives `identityIsEstablished()` in SQL, i.e. a fourth copy of the predicate"*. It does not, because
 * the new arm is **monotonic**: the predicate becomes `password_set_at IS NOT NULL` **OR** the five arms it
 * already had. Adding a disjunct can only ever move an account from *not established* to *established* —
 * never the reverse — so:
 *
 *   · Stamping NOBODY is SAFE. Every existing account is judged exactly as it is judged today, by the five
 *     arms that already exist. Nothing regresses and no live invitation placeholder is locked out, which
 *     is the lockout the row correctly feared from a stamp-everyone backfill.
 *   · Every account created or re-passworded FROM NOW ON carries the positive signal, so the population
 *     that is exposed is closed at the top and drains as people reset their passwords.
 *
 * ⚠️ SO THE RESIDUAL IS NARROWED RATHER THAN ERASED, AND SAYING SO IS THE POINT: an account that
 * self-registered BEFORE this migration and has still never verified, enrolled 2FA, linked Google or
 * joined a workspace remains in the old state until it next sets a password. That is strictly better than
 * today and strictly safer than either naive backfill, and it is stated here so nobody reads the closed
 * row as *"nobody is exposed"*.
 *
 * ⚠️ NO `withTenantIsolation()`, correct rather than omitted: this creates no table, and `users` is not
 * tenant-scoped — its visibility is the join-shape policy from `2026_07_05_000102_apply_users_rls.php`.
 * `scripts/migration-lint.php` requires the call only for a CREATE carrying a literal `tenant_id`.
 *
 * ⚠️ NO NEW GRANT OR POLICY IS NEEDED. A column on `users` rides the existing table-level
 * `GRANT SELECT, UPDATE ON users TO meridian_auth` and the existing `TO meridian_auth` write policy, which
 * is the same argument `2026_08_16_000002_add_google_id_to_users_table.php` makes for itself — and it
 * matters here because `identityIsEstablished()` reads this account on the `pgsql_auth` connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // `after('password')` because this column is a fact ABOUT that column, and a reader running
            // `\d users` should meet the two together.
            $table->timestampTz('password_set_at')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('password_set_at');
        });
    }
};
