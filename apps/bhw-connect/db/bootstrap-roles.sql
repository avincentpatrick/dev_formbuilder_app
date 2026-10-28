-- BHW Connect's database contract, outside Supabase: the roles PostgREST switches between, the
-- `extensions` schema the functions expect, and Supabase's migration-history table.
--
-- Run once per database, as a superuser, INSIDE the database (`bhw_connect` on the testing box,
-- the CI service, or the local docker-compose stack):
--
--     psql -v ON_ERROR_STOP=1 -d bhw_connect -f db/bootstrap-roles.sql
--
-- It is idempotent: every statement checks before it creates, so a second run changes nothing.
-- Passwords are NOT set here. The box's operator sets `bhw_app` and `authenticator` by hand, and
-- the local stack sets throw-away ones (db/local-dev.sql).
--
-- WHY THESE ROLES. supabase-js sends each request with a JWT whose `role` claim names one of
-- `anon`, `authenticated` or `service_role`; PostgREST logs in as `authenticator` and switches to
-- that role per request. Row-level security does the filtering for `anon` and `authenticated`;
-- `service_role` must BYPASSRLS, or the 19 tables with RLS on and no policy go dark for the admin
-- pages, the AI layer and the cron jobs, exactly as they would on Supabase.

-- ── Roles (cluster-wide, so create only when missing) ─────────────────────────────────────────

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'bhw_app') THEN
    CREATE ROLE bhw_app LOGIN;              -- owns the schema; db/migrate.mjs connects as it
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'anon') THEN
    CREATE ROLE anon NOLOGIN NOINHERIT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
    CREATE ROLE authenticated NOLOGIN NOINHERIT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'service_role') THEN
    CREATE ROLE service_role NOLOGIN NOINHERIT BYPASSRLS;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticator') THEN
    CREATE ROLE authenticator LOGIN NOINHERIT;
  END IF;
END
$$;

-- Supabase's request timeouts: a public request is cut off sooner than a signed-in one.
ALTER ROLE anon SET statement_timeout = '3s';
ALTER ROLE authenticated SET statement_timeout = '8s';

GRANT anon, authenticated, service_role TO authenticator;

-- ── Schemas ───────────────────────────────────────────────────────────────────────────────────

-- Six functions pin `search_path = public, extensions` and one calls `extensions.similarity`, as on
-- Supabase: the extensions live in their own schema, and the functions resolve them unchanged.
CREATE SCHEMA IF NOT EXISTS extensions;
CREATE EXTENSION IF NOT EXISTS pg_trgm SCHEMA extensions;
CREATE EXTENSION IF NOT EXISTS pgcrypto SCHEMA extensions;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp" SCHEMA extensions;

-- pgvector is built off the box and copied in (decision D180). Where it is not installed — the
-- stock postgres:15 image, CI — it is skipped with a notice rather than failing, and the one
-- column that needs it (doc_chunk_embedding.embedding) is absent from that database.
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_available_extensions WHERE name = 'vector') THEN
    CREATE EXTENSION IF NOT EXISTS vector SCHEMA extensions;
  ELSE
    RAISE NOTICE 'pgvector is not installed here; the vector extension was skipped.';
  END IF;
END
$$;

ALTER SCHEMA public OWNER TO bhw_app;
GRANT USAGE ON SCHEMA public TO anon, authenticated, service_role;
GRANT USAGE ON SCHEMA extensions TO anon, authenticated, service_role, bhw_app;

-- ── Privileges, as Supabase grants them (policies do the filtering) ─────────────────────────

GRANT ALL ON ALL TABLES IN SCHEMA public TO anon, authenticated, service_role;
GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO anon, authenticated, service_role;
GRANT ALL ON ALL FUNCTIONS IN SCHEMA public TO anon, authenticated, service_role;

-- A table a later migration creates inherits the same grants. Without this, a plain-SQL restore
-- taken with --no-privileges, or the first new table, would be invisible to PostgREST.
ALTER DEFAULT PRIVILEGES FOR ROLE bhw_app IN SCHEMA public
  GRANT ALL ON TABLES TO anon, authenticated, service_role;
ALTER DEFAULT PRIVILEGES FOR ROLE bhw_app IN SCHEMA public
  GRANT ALL ON SEQUENCES TO anon, authenticated, service_role;
ALTER DEFAULT PRIVILEGES FOR ROLE bhw_app IN SCHEMA public
  GRANT ALL ON FUNCTIONS TO anon, authenticated, service_role;

-- ── Migration history, in Supabase's own shape (so its tooling still reads it) ──────────────

CREATE SCHEMA IF NOT EXISTS supabase_migrations AUTHORIZATION bhw_app;
CREATE TABLE IF NOT EXISTS supabase_migrations.schema_migrations (
  version    text PRIMARY KEY,
  statements text[],
  name       text
);
ALTER TABLE supabase_migrations.schema_migrations OWNER TO bhw_app;
