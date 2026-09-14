<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\TenantUserStatus;
use App\Models\User;
use App\Services\Auth\OperatorAccounts;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The decisions and the writes behind `platform:super-admin` (M95). The command is input and output only.
 *
 * ── WHY `pgsql_privileged`, AND WHY NOT A METHOD ON {@see SuperAdminService} ─────────────────────────────
 * `users` carries FORCE row-level security. A console process sets no `app.current_user_id` and no tenant, so
 * the app connection cannot see ANY account from here — an UPDATE over it affects zero rows and reports
 * success, which `CentralHostLoginTest`'s promotion case pins. `SuperAdminService` elevates through
 * `pgsql_superadmin`, whose role holds SELECT only on `users`, so it cannot write the flag at all. `pgsql_auth`
 * technically could (its SELECT policy is `USING (true)` and it holds an UPDATE grant), and it must not: it is
 * the pre-authentication role, and multi-tenancy-rbac-design.md §9 bounds it by rule rather than privilege.
 * It is used here only for the independent read-back after commit, as a different role on a different session.
 *
 * ── THE PRECONDITION IS ASKED OF `pg_roles`, NOT INFERRED FROM A ROW COUNT ───────────────────────────────
 * Both seeders guard their own promotion with "zero rows affected AND the row exists". That guard is blind in
 * exactly the case it claims to cover: a privileged role that is merely the table owner, under FORCE, sees
 * zero users (measured), so the existence re-check is false and the throw never fires. So the role must be
 * SUPERUSER or BYPASSRLS before anything is read — the shape `SubmissionReferenceBackfill::assertPrivileged()`
 * already uses — and then every write can require exactly one affected row unconditionally, because the read
 * and the write share one session.
 *
 * ── A PLATFORM OPERATOR HOLDS NO WORKSPACE MEMBERSHIP (C5) ───────────────────────────────────────────────
 * Both seeders create their operator membership-less by design, `ImpersonationService` never impersonates a
 * super-admin, and the console's two-factor gate covers only the console. So an account with any
 * non-removed `tenant_users` row is refused, and the membership count is re-read under the same `FOR UPDATE`
 * as the account: a concurrent membership insert takes a key-share lock on the `users` row through its foreign
 * key, so it either committed before the re-read or waits for this transaction.
 *
 * ── ⚠️ NO AUDIT ROW, AND THAT IS A DELIBERATE, RECORDED GAP (M95, C3) ───────────────────────────────────
 * The audit spec lists `users`/`updated` for every `is_super_admin` change. This is the first writer of that
 * flag in `app/`, and it writes no audit row. The reason is the one the spec's `domain` row already gives for
 * artisan-only acts: `AuditLogger::record()` hard-codes `is_system_action = false` and falls back to
 * `Auth::id()` for the actor, so a console row would carry a NULL actor beside `is_system_action = false` —
 * malformed by the logger's own contract — plus the console's synthetic request address and user agent. A
 * system-actor audit shape covering this command, `tenants:create`, `domains:activate` and `sso:domains` is
 * filed as a before-launch row in `docs/feature-backlog.md`. Until it lands, the command's output is the
 * provisioning record. `PlatformSuperAdminCommandTest` pins the absence, so adding a partial audit later is a
 * deliberate change rather than a silent one.
 */
final class SuperAdminProvisioner
{
    public const string CONNECTION = 'pgsql_privileged';

    public const string READ_BACK_CONNECTION = 'pgsql_auth';

    /** No account exists for the address. */
    public const string NEW = 'new';

    /** An account exists, is not a super-admin, and holds no membership — it may be granted the flag. */
    public const string EXISTING = 'existing';

    /** The account is already a platform super-admin. Nothing to write. */
    public const string ALREADY = 'already';

    /** The account exists but is soft-deleted. Refused. */
    public const string TRASHED = 'trashed';

    /** The account holds a non-removed workspace membership. Refused (C5). */
    public const string MEMBER = 'member';

    /** The state seen under the row lock differs from the state the command classified. Nothing was written. */
    public const string CHANGED = 'changed';

    /** `::int` so the answer is an integer on every driver setting, never a `'t'`/`'f'` string. */
    private const string PRIVILEGE_SQL = 'select (rolsuper or rolbypassrls)::int as ok, current_user as role '
        .'from pg_roles where rolname = current_user';

    public function __construct(private readonly OperatorAccounts $accounts) {}

    /**
     * Whether `pgsql_privileged` connects as a role that bypasses row-level security, and which role it is.
     *
     * @return array{bypasses: bool, role: string}
     */
    public function privilegedRole(): array
    {
        $row = DB::connection(self::CONNECTION)->selectOne(self::PRIVILEGE_SQL);

        if (! is_object($row)) {
            return ['bypasses' => false, 'role' => 'unknown'];
        }

        return [
            'bypasses' => (int) ($row->ok ?? 0) === 1,
            'role' => (string) ($row->role ?? 'unknown'),
        ];
    }

    /**
     * Classify the address before anything is prompted for or written.
     *
     * @return array{state: string, user: User|null, memberships: int}
     */
    public function inspect(string $email): array
    {
        $user = $this->accounts->find(self::CONNECTION, $email);

        if ($user === null) {
            return ['state' => self::NEW, 'user' => null, 'memberships' => 0];
        }

        if ($user->trashed()) {
            return ['state' => self::TRASHED, 'user' => $user, 'memberships' => 0];
        }

        if ($user->is_super_admin) {
            return ['state' => self::ALREADY, 'user' => $user, 'memberships' => 0];
        }

        $memberships = $this->membershipCount((string) $user->getKey());

        return $memberships > 0
            ? ['state' => self::MEMBER, 'user' => $user, 'memberships' => $memberships]
            : ['state' => self::EXISTING, 'user' => $user, 'memberships' => 0];
    }

    /**
     * Create a new super-admin: one INSERT inside one `pgsql_privileged` transaction.
     *
     * The re-read under `FOR UPDATE` catches an account that appeared since {@see inspect()}. A concurrent
     * INSERT of the same address that has not committed yet is invisible to it, and arrives instead as a
     * `UniqueConstraintViolationException` from the insert, which the caller reports as the same refusal.
     *
     * @return array{outcome: string, user: User|null}
     */
    public function create(string $email, string $name, string $password): array
    {
        /** @var array{outcome: string, user: User|null} $result */
        $result = DB::connection(self::CONNECTION)->transaction(function () use ($email, $name, $password): array {
            if ($this->accounts->find(self::CONNECTION, $email, lockForUpdate: true) !== null) {
                return ['outcome' => self::CHANGED, 'user' => null];
            }

            $user = $this->accounts->createVerifiedAccount(self::CONNECTION, $name, $email, $password, superAdmin: true);

            return ['outcome' => self::NEW, 'user' => $user];
        });

        return $result;
    }

    /**
     * Grant the flag to an existing, membership-less account: exactly one UPDATE in one transaction.
     *
     * The password and every two-factor column are left untouched. Clearing an enrolled operator's secret would
     * be a silent two-factor reset, which is a separate question in the threat model rather than a side effect
     * of a grant. `email_verified_at` is set only when it is empty, as the operator's attestation of the address.
     *
     * The update goes through the query builder, never Eloquent `save()`: `performUpdate()` discards the
     * affected-row count, which is exactly how both seeders once failed in silence.
     *
     * @return array{outcome: string, user: User|null, verifiedNow: bool}
     */
    public function promote(string $email): array
    {
        /** @var array{outcome: string, user: User|null, verifiedNow: bool} $result */
        $result = DB::connection(self::CONNECTION)->transaction(function () use ($email): array {
            $user = $this->accounts->find(self::CONNECTION, $email, lockForUpdate: true);

            if ($user === null
                || $user->trashed()
                || $user->is_super_admin
                || $this->membershipCount((string) $user->getKey()) > 0) {
                return ['outcome' => self::CHANGED, 'user' => $user, 'verifiedNow' => false];
            }

            $verifiedNow = $user->email_verified_at === null;

            $affected = DB::connection(self::CONNECTION)->table('users')
                ->where('id', $user->getKey())
                ->where('is_super_admin', false)
                ->update([
                    'is_super_admin' => true,
                    'email_verified_at' => DB::raw('coalesce(email_verified_at, now())'),
                    'updated_at' => now(),
                ]);

            self::assertExactlyOne($affected);

            return ['outcome' => self::EXISTING, 'user' => $user, 'verifiedNow' => $verifiedNow];
        });

        return $result;
    }

    /**
     * The postcondition: a DIFFERENT role, on a different session, must see the committed grant.
     *
     * An in-memory model cannot satisfy this, and neither can a read inside the writing transaction.
     */
    public function readBack(string $email): ?User
    {
        return User::on(self::READ_BACK_CONNECTION)->where('email', $email)->first();
    }

    /**
     * Every other live platform super-admin, by address. Printed on every run, so an operator created by a
     * demo seed on this database — whose password is published in the repository — is seen on day one.
     *
     * @return list<string>
     */
    public function otherSuperAdmins(string $email): array
    {
        return array_values(
            DB::connection(self::CONNECTION)->table('users')
                ->where('is_super_admin', true)
                ->whereNull('deleted_at')
                ->where('email', '<>', $email)
                ->orderBy('email')
                ->pluck('email')
                ->map(static fn (mixed $address): string => (string) $address)
                ->all()
        );
    }

    /**
     * Anything other than exactly one affected row is a defect, and throwing rolls the transaction back.
     *
     * Split out and static so the branch no live fixture can reach under a bypassing role is assertable with
     * scalars. Returns the count so a caller can assert on the accepted value too.
     */
    public static function assertExactlyOne(int $affected): int
    {
        if ($affected !== 1) {
            throw new RuntimeException(
                "The grant affected {$affected} row(s) on ".self::CONNECTION.'; exactly one was expected. The '
                .'transaction was rolled back, so nothing was committed.'
            );
        }

        return $affected;
    }

    /** Non-removed memberships of any status — an outstanding invitation is a membership row too. */
    private function membershipCount(string $userId): int
    {
        return DB::connection(self::CONNECTION)->table('tenant_users')
            ->where('user_id', $userId)
            ->where('status', '<>', TenantUserStatus::Removed->value)
            ->count();
    }
}
