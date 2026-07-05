<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Provision the `meridian_auth` login role — a NON-superuser, NOBYPASSRLS role used ONLY by the
 * pre-authentication path (App\Auth\RlsAwareUserProvider on the `pgsql_auth` connection) to resolve
 * and update `users` before any tenant/user context exists. It is granted access to `users` alone
 * (see apply_users_rls), so a bug on this connection can never reach tenant data.
 *
 * Runs on the privileged (superuser) connection — the default `meridian_app` role cannot CREATE ROLE.
 * Idempotent: guarded by IF NOT EXISTS so it is safe under migrate:fresh (roles survive) and on a
 * production host where a DBA created the role by hand first (the runbook path). For fresh Docker
 * clusters the role is also created by docker/postgres/init.
 */
return new class extends Migration
{
    public function up(): void
    {
        $password = (string) config('database.connections.pgsql_auth.password', 'secret');
        $quoted = "'".str_replace("'", "''", $password)."'";

        DB::connection('pgsql_privileged')->statement(
            'DO $$ BEGIN '
            ."IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'meridian_auth') THEN "
            .'CREATE ROLE meridian_auth LOGIN PASSWORD '.$quoted.' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE; '
            .'END IF; END $$;'
        );
    }

    public function down(): void
    {
        // The role is shared infrastructure (it owns grants/policies on `users`, dropped by
        // apply_users_rls's down()). Leaving it in place is intentional — dropping a login role that
        // may back live sessions is not something a schema rollback should do.
    }
};
