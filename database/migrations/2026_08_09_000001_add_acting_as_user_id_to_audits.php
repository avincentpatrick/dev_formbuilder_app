<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `audits.acting_as_user_id` — Increment I11a, closing the only requirement
 * `docs/multi-tenancy-rbac-design.md` §9 states about impersonation.
 *
 * That entry (line 433) is a DEFERRAL, not a specification, and its single substantive sentence is the
 * obligation this column exists to meet: impersonation *"would need an `acting_as_user_id`-style `audits`
 * column (and the `audits` table itself, which does not exist until Phase 1) to keep a super-admin's own
 * actions distinguishable from actions taken while impersonating"*. `docs/security-threat-model.md:144`
 * states the consequence of not having it: **"impersonation without a distinguishing audit column would
 * make a compromised super-admin session indistinguishable from the impersonated user's own actions in
 * the audit trail"**. So the column lands BEFORE the feature (I11b), not alongside it — a ledger that
 * gained the ability to be lied to and the ability to record the lie in the same release would have a
 * window in between where neither was true.
 *
 * ── WHAT THE TWO ACTOR COLUMNS MEAN ─────────────────────────────────────────────────────────────────────
 * `user_id` stays the EFFECTIVE actor — whose authority the action ran under, i.e. the impersonated user.
 * `acting_as_user_id` is the REAL human at the keyboard, and is NULL on every ordinary row. That direction
 * is deliberate and is the only one that keeps existing readers correct: every current consumer
 * (`audits_tenant_user_idx`, the tenant viewer's actor filter, `AuditPolicy`, the CSV export's Actor column,
 * `AuditResource.user_id`) already means "the account this happened under", and swapping the sense would
 * silently re-point all of them at platform staff. A reader that does not know about impersonation
 * therefore keeps telling the truth, just less of it.
 *
 * ── NULLABLE, AND NO CHECK CONSTRAINT PAIRING IT WITH `user_id` ─────────────────────────────────────────
 * A `CHECK (acting_as_user_id IS NULL OR user_id IS NOT NULL)` was considered and rejected. `user_id` is
 * already nullable for system actions, and the FK is `nullOnDelete` — so deleting the impersonated user
 * legitimately produces a row with a NULL `user_id` and a non-NULL `acting_as_user_id`, which the constraint
 * would then refuse to have existed. Enforcing a shape the retention policy itself breaks turns an
 * append-only ledger into one that cannot honour a deletion.
 *
 * ── `nullOnDelete`, MATCHING `user_id` ──────────────────────────────────────────────────────────────────
 * Never cascade. The table's own docblock argues it: FK actions bypass RLS, and a cascade here would let
 * deleting one operator delete the ledger of everything they ever did — the precise opposite of the point.
 * The trade is the same one `user_id` already makes and `AuditLogPresenter::actorLabel()` already renders:
 * a null actor whose row is gone is displayed as unknown, not as absent.
 *
 * ── THE INDEX IS PARTIAL, AND THAT IS THE WHOLE REASON IT IS AFFORDABLE ─────────────────────────────────
 * The only query shape is "rows written during an impersonation" — a compliance question asked rarely and
 * answered over a table whose every OTHER row has this column NULL. A plain btree would index one entry per
 * audit row forever to serve a predicate that excludes ~100% of them. `WHERE acting_as_user_id IS NOT NULL`
 * indexes only the rows that can ever match. It leads with `tenant_id` per ADR-0002 §D1 like the table's
 * other three, and carries an explicit short name because PostgreSQL truncates at NAMEDATALEN = 63.
 *
 * ── NO RLS RE-EMIT ──────────────────────────────────────────────────────────────────────────────────────
 * Alter-only. `audits` already carries its append-only isolation shape and those policies are ROW
 * predicates, not column lists, so a new column is covered the moment it exists (the 2026_07_23_000010 /
 * 2026_07_25_000001 / 2026_08_08_000001 precedent). ⚠️ `composer migration-lint` therefore SKIPS this file
 * — its green here is vacuous, not evidence. And the standing prohibition is untouched: `audits` has no
 * UPDATE and no DELETE policy for any role, which under FORCE ROW LEVEL SECURITY *is* the append-only
 * guarantee. Do not add one to backfill this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audits', function (Blueprint $table): void {
            $table->foreignUuid('acting_as_user_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        // Partial — see the docblock. Not expressible through Blueprint, which has no `->where()`.
        DB::statement(
            'CREATE INDEX audits_tenant_acting_as_idx ON audits (tenant_id, acting_as_user_id) '
            .'WHERE acting_as_user_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS audits_tenant_acting_as_idx');

        Schema::table('audits', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('acting_as_user_id');
        });
    }
};
