-- Runs once on first Postgres init (as the POSTGRES_USER superuser, `meridian`).
--
-- Two jobs:
--   1. Create the isolated database the Pest suite migrates into (phpunit.xml: DB_DATABASE=meridian_testing),
--      so tests never touch dev data.
--   2. Create the NON-superuser `meridian_app` role and make it own both databases.
--
-- Why `meridian_app` is mandatory (ADR-0002 §D2, Increment A): Row-Level Security is ignored for
-- superusers and for a `BYPASSRLS` role, and a table's owner bypasses RLS unless FORCE is set. The
-- application (and the RLS-enforced tests) therefore connect as `meridian_app` — NOSUPERUSER,
-- NOBYPASSRLS — and it OWNS the tables so that `FORCE ROW LEVEL SECURITY` is what makes the owner
-- obey the tenant policies. That is exactly why the cross-tenant fuzz pack proving "Tenant A cannot
-- see Tenant B" doubles as the proof that FORCE is active: drop FORCE and the owner sees everything.
--
-- The superuser `meridian` is reserved for role/db creation here and, from Increment B, for seeding
-- the platform-global (tenant_id IS NULL) rows the strict INSERT policy forbids `meridian_app` to write.

DO
$$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'meridian_app') THEN
        CREATE ROLE meridian_app LOGIN PASSWORD 'secret' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
    END IF;

    -- Increment B1: the pre-authentication role. NON-superuser, granted access to `users` ONLY (see
    -- the apply_users_rls migration), used by RlsAwareUserProvider to resolve/update a user during
    -- login/password-reset before any tenant context exists. Also created by a guarded migration so
    -- CI and existing (non-fresh-volume) databases get it too.
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'meridian_auth') THEN
        CREATE ROLE meridian_auth LOGIN PASSWORD 'secret' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
    END IF;
END
$$;

CREATE DATABASE meridian_testing OWNER meridian_app;

-- Hand the dev database to `meridian_app` too, so migrations run and tables are owned by the same
-- non-superuser role the app connects as (in PG15+ owning the database confers control of its public
-- schema via pg_database_owner, so meridian_app can create tables there).
ALTER DATABASE meridian OWNER TO meridian_app;
GRANT ALL PRIVILEGES ON DATABASE meridian TO meridian_app;
GRANT ALL PRIVILEGES ON DATABASE meridian_testing TO meridian_app;
