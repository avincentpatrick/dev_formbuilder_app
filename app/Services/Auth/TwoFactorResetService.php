<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AuditEvent;
use App\Enums\TenantUserStatus;
use App\Exceptions\Tenancy\MembershipException;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\SuperAdminContext;
use Illuminate\Support\Facades\DB;

/**
 * Clear somebody's two-factor enrolment on their behalf — Increment M107, answering `D37`.
 *
 * ── WHAT THIS EXISTS TO RESCUE ─────────────────────────────────────────────────────────────────────────
 * Every Fortify route that can turn two-step sign-in OFF sits behind `auth` + `password.confirm`, and both
 * are PAST the challenge. Someone who has lost their device and has no usable recovery code therefore has
 * no exit at all: `docs/security-threat-model.md` §9 item 12 records it, and until this class the only
 * remedy was an operator editing the database by hand. `D37` answered it — an admin reset, recorded in the
 * audit log, performed by a workspace owner OR the platform operator — and both surfaces land here so
 * there is exactly one place where an enrolment is cleared.
 *
 * ── ⛔ THE CONNECTION IS THE WHOLE DESIGN, AND IT WAS MEASURED RATHER THAN REASONED ────────────────────
 * `users` carries `users_users_visibility`, a SELECT policy PostgreSQL also applies to an UPDATE whose
 * WHERE reads a column. Measured against a live stack with a no-op `UPDATE`, counting affected rows:
 *
 *   tenant context set, actor ≠ target, target an ACTIVE member  ·  app connection  ·  1 row
 *   NO tenant context — the console's situation                  ·  app connection  ·  0 rows, SILENTLY
 *   either                                                       ·  `pgsql_auth`    ·  1 row
 *   either                                                       ·  `pgsql_superadmin` · SQLSTATE 42501
 *
 * Two facts fall out of that table and neither is guessable from the schema:
 *
 *   · The policy's MEMBERSHIP arm means the workspace path would have worked on the app connection. It is
 *     not used, because the console path cannot, and one write path that is always right beats two that
 *     are each right half the time.
 *   · ⛔ `pgsql_superadmin` holds `SELECT` ONLY on `users` — confirmed from `information_schema.
 *     role_table_grants` — so the console's usual `SuperAdminService::elevated()` shape is STRUCTURALLY
 *     UNAVAILABLE here. It refuses loudly rather than silently, which is the one mercy in it.
 *
 * So the write runs on `pgsql_auth`, switch-call-restore in a `finally`, exactly the shape
 * {@see User::replaceRecoveryCode()} already uses for the same table and the same reason.
 *
 * ⚠️ AND THE ELEVATED WINDOW IS NARROW ON PURPOSE. `meridian_auth` holds grants on `users` plus a
 * read-only `SELECT` on `tenant_users` and NOTHING ELSE, so the audit write must happen OUTSIDE it. Every
 * method here clears first and records second for that reason, not for readability.
 *
 * ── THE ZERO-ROW GUARD IS NOT BELT-AND-BRACES ─────────────────────────────────────────────────────────
 * Eligibility already proves the row is visible, so today the guard cannot fire. It ships anyway: this
 * repository has now met the silent-zero-row family on six Fortify endpoints, one recovery-code rotation
 * and one `users` backfill, and in every case the symptom was a success message over a write that never
 * happened. The audience for THIS write is somebody who is already locked out; telling them it worked
 * when it did not is the worst available outcome.
 *
 * ── ⚠️ THE CROSS-TENANT CONSEQUENCE, STATED WHERE THE CODE IS ─────────────────────────────────────────
 * `two_factor_secret`, `two_factor_recovery_codes` and `two_factor_confirmed_at` live on the GLOBAL `users`
 * table. A workspace owner's reset therefore clears that person's second factor in EVERY workspace they
 * belong to, not only the acting one. `D37` did not consider this — it is recorded in
 * `docs/security-threat-model.md` §9 and filed as its own open decision for narrowing.
 */
final class TwoFactorResetService
{
    /**
     * The connection that can actually see another person's `users` row — see the class docblock.
     *
     * Spelled as a literal rather than borrowed from the auth provider's constant, matching the five other
     * call sites in `app/` that do the same ({@see User::replaceRecoveryCode()} records why).
     */
    private const WRITE_CONNECTION = 'pgsql_auth';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Clear an active member's enrolment from the WORKSPACE roster.
     *
     * Reached behind `can:tenant.members.two_factor_reset` (Owner only) and `step-up`, alongside the three
     * other member mutations. The refusals below are DOMAIN invariants rather than permission checks: an
     * Owner legitimately reaches the route and is then told no.
     *
     * @throws MembershipException
     */
    public function resetForMember(Tenant $tenant, User $member, User $actor): void
    {
        if ($actor->id === $member->id) {
            // Not a courtesy. An owner who can reach this page can sign in, and Fortify's own
            // `DELETE /user/two-factor-authentication` already serves them — behind `password.confirm`.
            // Allowing a self-reset here would be a documented way to step AROUND that confirmation.
            throw MembershipException::cannotResetOwnTwoFactor();
        }

        // ⛔ FAIL-CLOSED, and asked as an existence question rather than by reading a flag: a row this
        // connection cannot see returns false from `exists()` and REFUSES, where `$member->is_super_admin`
        // on an unreadable model would read null and permit. Same shape as ImpersonationService::isEligible().
        $targetIsResettable = User::on(self::WRITE_CONNECTION)
            ->whereKey($member->id)
            ->where('is_super_admin', false)
            ->exists();

        if (! $targetIsResettable) {
            throw MembershipException::cannotResetSuperAdminTwoFactor();
        }

        // RLS already scopes this to the acting tenant, exactly as TenantMembershipService::changeRole()
        // relies on. The explicit `tenant_id` is belt to that brace and is deliberate: this is the check
        // that stops one workspace's Owner reaching an identity they merely share a ROW with, and a
        // boundary that holds only because an ambient GUC is set is a boundary one missing middleware wide.
        $membership = TenantUser::query()
            ->where('user_id', $member->id)
            ->where('tenant_id', $tenant->id)
            ->first();
        if ($membership === null || $membership->status !== TenantUserStatus::Active) {
            throw MembershipException::notAMember();
        }

        $this->clearEnrolmentAndRecord($member, $actor, connection: null);
    }

    /**
     * Clear an account's enrolment from the PLATFORM CONSOLE.
     *
     * Reached behind `auth` + `superadmin` + `superadmin.mfa` + `step-up` on the central host. This is the
     * path that exists for the case the workspace one cannot serve: an account that belongs to no workspace
     * at all, or one whose only owner is the person who is locked out.
     *
     * ⚠️ A super-admin target is permitted HERE and refused on the workspace surface. Clearing a
     * super-admin's enrolment grants nobody anything: `EnsureSuperAdminMfa` redirects an unenrolled
     * super-admin straight to `admin.mfa.setup`, so the reset forces re-enrolment rather than bypassing it.
     * What must not happen is a WORKSPACE owner reaching platform staff, and that is where it is refused.
     *
     * @throws MembershipException
     */
    public function resetForOperator(User $target, User $operator): void
    {
        if ($operator->id === $target->id) {
            throw MembershipException::cannotResetOwnTwoFactor();
        }

        // The console runs with no tenant context, so the ledger row belongs to no tenant either — the
        // `tenant_id = NULL` shape SuperAdminService::updatePlatformSettings() already writes, through the
        // `audits_superadmin_insert` bypass. A tenant-scoped caller must never pass this.
        $this->clearEnrolmentAndRecord($target, $operator, connection: SuperAdminContext::CONNECTION);
    }

    /**
     * The one write path, plus its ledger row.
     *
     * @throws MembershipException
     */
    private function clearEnrolmentAndRecord(User $target, User $actor, ?string $connection): void
    {
        $wasEnrolled = User::on(self::WRITE_CONNECTION)
            ->whereKey($target->id)
            ->where(function ($query): void {
                $query->whereNotNull('two_factor_secret')
                    ->orWhereNotNull('two_factor_confirmed_at');
            })
            ->exists();

        if (! $wasEnrolled) {
            // A success toast over a no-op would read as "their access is restored" to the one person who
            // most needs that sentence to be true.
            throw MembershipException::twoFactorNotEnrolled();
        }

        // ⚠️ ALL THREE COLUMNS, NOT JUST THE TIMESTAMP. `EnsureSuperAdminMfa` and TwoFactorEnforcementGate
        // read only `two_factor_confirmed_at`, but Fortify's `hasEnabledTwoFactorAuthentication()` reads
        // the SECRET (`confirm => true` requires both). Clearing one and leaving the other makes the
        // enforcement middleware and the settings panel disagree about whether the person is enrolled.
        $affected = DB::connection(self::WRITE_CONNECTION)
            ->table('users')
            ->where('id', (string) $target->id)
            ->update([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ]);

        if ($affected === 0) {
            throw MembershipException::twoFactorResetAffectedNoRows();
        }

        // OUTSIDE the elevated window — `meridian_auth` cannot reach `audits`. See the class docblock.
        //
        // No `old`/`new`: the only values that changed are a TOTP secret and a recovery-code list, and a
        // compliance ledger is the last place either belongs. The same posture the two impersonation
        // boundaries take, and for a sharper reason.
        $write = fn (): mixed => $this->audit->record(
            AuditEvent::TwoFactorReset,
            'users',
            (string) $target->id,
            actorId: (string) $actor->getKey(),
            connection: $connection,
        );

        if ($connection === null) {
            $write();

            return;
        }

        // ⛔ THE CONNECTION ALONE IS NOT ENOUGH, AND THIS WAS MEASURED RATHER THAN REASONED. `audits`
        // carries a strict append-only INSERT policy (`tenant_id = ctx`) plus the `audits_superadmin_insert`
        // bypass, and the bypass is gated on the `app.is_superadmin_context` GUC — not on the role. Writing
        // a `tenant_id = NULL` row over `pgsql_superadmin` WITHOUT opening that context fails with
        // `SQLSTATE 42501 — new row violates row-level security policy for table "audits"`, which is what
        // the first run of `TwoFactorResetTest`'s three console cases did.
        //
        // `applyLocal()` is `SET LOCAL`, so it only has effect inside a transaction ON THAT CONNECTION —
        // hence the wrapper rather than a bare call. This is `SuperAdminService::elevated()`'s shape,
        // reproduced rather than reused because that method is private and this is not a SuperAdminService
        // concern; if a third caller ever needs it, that is the moment to lift it somewhere shared.
        DB::connection($connection)->transaction(function () use ($write): void {
            SuperAdminContext::applyLocal();

            $write();
        });

        // The in-memory model is now stale in a way that matters: a caller that re-renders the roster from
        // it would show the person still enrolled.
        $target->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->syncOriginal();
    }
}
