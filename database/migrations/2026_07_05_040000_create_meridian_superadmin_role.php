<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Provision the `meridian_superadmin` elevated role (Increment B2c) — a NON-superuser, NOBYPASSRLS
 * role used ONLY by App\Services\Admin\SuperAdminService (the `pgsql_superadmin` connection) for the
 * rare cross-tenant reads a platform operator must perform. It sees an RLS-protected table's rows only
 * through the `superadmin_bypass` carve-out policy (scoped `TO` this role, gated on the
 * `app.is_superadmin_context` GUC the service sets transaction-locally) — RLS still applies to it, so
 * a query with the GUC unset fails closed. This mirrors the B1 `meridian_auth` role exactly.
 *
 * Deliberately NOT a superuser and NOT BYPASSRLS: either would sidestep RLS wholesale, reintroducing
 * the single-point-of-failure ADR-0002 §D3 exists to avoid. The whole point is that the elevated role
 * is still constrained by RLS and only reads via an explicit, audited, context-gated policy.
 *
 * Runs on the privileged (superuser) connection — the default `meridian_app` role cannot CREATE ROLE.
 * Idempotent (IF NOT EXISTS): safe under migrate:fresh (roles survive) and on a production host where
 * a DBA created the role by hand first. Fresh Docker clusters also create it via docker/postgres/init.
 */
return new class extends Migration
{
    public function up(): void
    {
        $password = (string) config('database.connections.pgsql_superadmin.password', 'secret');
        $quoted = "'".str_replace("'", "''", $password)."'";

        DB::connection('pgsql_privileged')->statement(
            'DO $$ BEGIN '
            ."IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'meridian_superadmin') THEN "
            .'CREATE ROLE meridian_superadmin LOGIN PASSWORD '.$quoted.' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE; '
            .'END IF; END $$;'
        );
    }

    public function down(): void
    {
        // The role is shared infrastructure (it holds grants/policies on tables, dropped by the
        // apply_superadmin_bypass migrations' down()). Leaving it in place is intentional — dropping a
        // login role that may back live elevated sessions is not something a schema rollback should do.
    }
};
