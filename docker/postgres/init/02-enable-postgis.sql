-- Runs once on first Postgres init (as the POSTGRES_USER superuser, `meridian`), AFTER
-- 01-create-testing-db.sql in filename sort order (so `meridian_testing` already exists).
--
-- Increment G5b1 / ADR-0006: enable PostGIS so the geospatial field types (geopoint/geotrace/geoshape)
-- can project a canonical GeoJSON answer into a geometry(Geometry, 4326) + GiST column in
-- `submission_geo_index`. The extension MUST be created by a superuser — the NON-superuser `meridian_app`
-- role the app connects as is denied ("Must be superuser to create this extension"), which is exactly
-- why this lives in the privileged init path (and in a guarded pgsql_privileged migration for
-- migrate:fresh / existing non-fresh-volume databases).
--
-- The extension is per-database, so it must be enabled in BOTH the dev DB (`meridian`, the current
-- connection here) and the isolated test DB (`meridian_testing`). Idempotent via IF NOT EXISTS.

CREATE EXTENSION IF NOT EXISTS postgis;

\connect meridian_testing
CREATE EXTENSION IF NOT EXISTS postgis;
