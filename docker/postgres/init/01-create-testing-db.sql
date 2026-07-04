-- Runs once on first Postgres init (as the POSTGRES_USER superuser).
-- Creates the isolated database the Pest suite migrates into (phpunit.xml: DB_DATABASE=meridian_testing),
-- so tests never touch dev data.
CREATE DATABASE meridian_testing;
GRANT ALL PRIVILEGES ON DATABASE meridian_testing TO meridian;

-- NOTE (Increment A): the app must connect as a NON-superuser, non-BYPASSRLS role for Row-Level
-- Security to actually apply (a superuser ignores RLS even with FORCE). A dedicated `meridian_app`
-- role is introduced when RLS lands; the skeleton has no tenant tables yet, so it connects as
-- the default owner for now.
