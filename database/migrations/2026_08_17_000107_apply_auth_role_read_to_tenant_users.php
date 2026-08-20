<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The pre-auth role's read carve-out on `tenant_users` (Increment M8 — ADR-0002 §D3,
 * multi-tenancy-rbac-design.md §7 and §9).
 *
 * ── WHY A PRE-AUTH PATH NEEDS TO SEE MEMBERSHIPS AT ALL ─────────────────────────────────────────────
 * `/invitations/{token}` is served with tenant context but WITHOUT `auth` — the invitee is not yet a
 * member, which is the whole point. To decide whether the person holding that token may set a password on
 * the account the invite points at, the controller has to ask one question about a GLOBAL identity:
 * **"has this identity ever actually been a member of any workspace?"** A `joined_at` on somebody else's
 * `tenant_users` row is the only positive record of that, and it is by definition in another tenant.
 *
 * Without this migration the question cannot be asked at all, and not in a way that fails loudly:
 *   - `tenant_users` carries the STRICT shape plus `unique(tenant_id, user_id)`, so the query returns
 *     **zero rows by construction** inside the invited tenant — the invite row IS the only row there; and
 *   - `meridian_auth` was granted `SELECT, UPDATE ON users` and nothing else (`apply_users_rls.php`), so
 *     the cross-RLS hop that resolves the invitee's identity could not reach memberships either.
 *
 * A predicate that silently answers "no" is exactly the failure mode this schema keeps paying for, so the
 * carve-out is explicit rather than a query written hopefully against the app connection.
 *
 * ── WHAT IT COSTS, STATED RATHER THAN BURIED ────────────────────────────────────────────────────────
 * The pre-auth role gains a deployment-wide **membership** read on top of the deployment-wide **identity**
 * read it already holds (`users_auth_select ... TO meridian_auth USING (true)`). That is a real widening and
 * it is bounded by exactly one rule, which lives in RBAC §9 rather than here: **no user-supplied predicate
 * may ever run on `pgsql_auth`.** The single consumer — `TenantMembershipService::identityIsEstablished()` —
 * matches a server-derived uuid with exact equality and reads one boolean. A LIKE, an `orWhere`, or a
 * caller-chosen column against this connection would turn an authorization check into a cross-tenant
 * directory of who works where.
 *
 * SELECT only, and the absence of the other three commands is the write refusal itself: under FORCE RLS a
 * command with no matching policy is denied. The pre-auth role reads to make a decision; it must never
 * author a membership. See {@see TenantIsolation::authRoleReadSql()} for why that absence is deliberate.
 *
 * ── THE GRANT IS NOT DECORATION ─────────────────────────────────────────────────────────────────────
 * Table privileges are checked BEFORE RLS: without the GRANT the query fails "permission denied" before any
 * policy is consulted, and a test meant to prove the POLICY would pass for the wrong reason.
 * `TenantUsersAuthReadRlsTest` asserts the two halves separately, and additionally asserts the negative that
 * matters most — the policy is scoped `TO meridian_auth`, so an ordinary tenant connection is completely
 * unaffected by this file and still cannot read another tenant's memberships.
 *
 * `down()` drops the policy BY NAME and revokes the grant. Deliberately not a `LIKE` sweep: this schema has
 * already recorded what a prefix sweep costs — a policy dropped while its GRANT survived, leaving a page
 * rendering zero rows with no error.
 *
 * Runs on the default `meridian_app` connection: it owns `tenant_users`, so it is who can GRANT + CREATE
 * POLICY. Creates no table, so the migration linter does not require withTenantIsolation() here.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantIsolation::authRoleRead('tenant_users');

        DB::statement('GRANT SELECT ON tenant_users TO '.$this->authRole());
    }

    public function down(): void
    {
        DB::statement('REVOKE SELECT ON tenant_users FROM '.$this->authRole());

        DB::statement('DROP POLICY IF EXISTS tenant_users_auth_select ON tenant_users');
    }

    /**
     * The pre-auth role name, validated rather than interpolated on trust — it reaches DDL that takes no
     * bindings. Same guard `apply_users_rls.php` and every bypass migration use.
     */
    private function authRole(): string
    {
        $role = (string) config('database.connections.pgsql_auth.username', 'meridian_auth');

        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException("Unsafe pgsql_auth role name: {$role}");
        }

        return $role;
    }
};
