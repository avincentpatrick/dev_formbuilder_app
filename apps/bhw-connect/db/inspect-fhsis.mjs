#!/usr/bin/env node
/** Read-only FHSIS diagnosis. Prints counts, keys and access metadata; never credentials or records.
 * On the server, from apps/bhw-connect:
 * node --env-file=C:\\meridian\\bhw\\.env db/inspect-fhsis.mjs
 */
import pg from "pg";

const url = process.env.BHW_DATABASE_URL;
if (!url) {
  console.error(
    "BHW_DATABASE_URL is not set. Use Node's --env-file with the server's BHW .env file.",
  );
  process.exit(2);
}
const client = new pg.Client({ connectionString: url, connectionTimeoutMillis: 10000 });
let connected = false;
async function check(label, sql) {
  await client.query("SAVEPOINT inspection");
  try {
    const { rows } = await client.query(sql);
    console.log(JSON.stringify({ check: label, rows }));
    await client.query("RELEASE SAVEPOINT inspection");
  } catch (error) {
    await client.query("ROLLBACK TO SAVEPOINT inspection");
    // Codes identify permission/missing-table errors without dumping connection details.
    console.log(JSON.stringify({ check: label, error_code: error.code ?? "unknown" }));
  }
}
const datasets = `SELECT dataset_id, slug, status FROM public.dim_dataset WHERE slug ILIKE '%fhsis%' ORDER BY dataset_id`;
const countByDataset = `SELECT 'indicator' AS source, f.dataset_id, d.slug, count(*)::int AS rows,
 count(*) FILTER (WHERE f.breakdown = 'total')::int AS total_breakdown_rows,
 count(*) FILTER (WHERE f.breakdown = 'total' AND f.geo_code = 'PH' AND f.geo_level = 'national')::int AS dashboard_national_rows
 FROM public.fact_fhsis_indicator f LEFT JOIN public.dim_dataset d USING (dataset_id)
 GROUP BY f.dataset_id, d.slug
 UNION ALL SELECT 'workforce', f.dataset_id, d.slug, count(*)::int, NULL,
 count(*) FILTER (WHERE f.geo_code = 'PH' AND f.geo_level = 'national' AND f.cadre <> 'bhw')::int
 FROM public.fact_fhsis_workforce f LEFT JOIN public.dim_dataset d USING (dataset_id)
 GROUP BY f.dataset_id, d.slug ORDER BY source, dataset_id`;
try {
  await client.connect();
  connected = true;
  await client.query("BEGIN TRANSACTION READ ONLY");
  await client.query("SET LOCAL statement_timeout = '10s'");
  await check(
    "connection_role",
    `SELECT current_user AS role, current_database() AS database, current_setting('transaction_read_only') AS read_only`,
  );
  await check("datasets", datasets);
  await check(
    "table_totals",
    `SELECT (SELECT count(*) FROM public.fact_fhsis_indicator)::int AS indicator_rows, (SELECT count(*) FROM public.fact_fhsis_workforce)::int AS workforce_rows, (SELECT count(*) FROM public.ref_fhsis_indicator)::int AS definitions`,
  );
  await check("owner_counts_by_dataset", countByDataset);
  await check(
    "indicator_keys",
    `SELECT dataset_id, geo_level, breakdown, count(*)::int AS rows FROM public.fact_fhsis_indicator GROUP BY dataset_id, geo_level, breakdown ORDER BY dataset_id, geo_level, breakdown LIMIT 100`,
  );
  await check(
    "national_geo_keys",
    `SELECT geo_code, geo_level, count(*)::int AS rows FROM public.fact_fhsis_indicator WHERE geo_level = 'national' GROUP BY geo_code, geo_level ORDER BY geo_code LIMIT 20`,
  );
  await check(
    "geography_mismatches",
    `SELECT count(*)::int AS rows FROM public.fact_fhsis_indicator f JOIN public.dim_geo g USING (geo_code) WHERE f.geo_level <> g.geo_level`,
  );
  await check(
    "relation_access",
    `SELECT c.relname AS relation, pg_get_userbyid(c.relowner) AS owner,
     c.relrowsecurity AS rls_enabled, c.relforcerowsecurity AS rls_forced,
     has_table_privilege('anon', c.oid, 'SELECT') AS anon_select
     FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
     WHERE n.nspname = 'public' AND c.relname IN
     ('fact_fhsis_indicator', 'fact_fhsis_workforce', 'ref_fhsis_indicator')
     ORDER BY c.relname`,
  );
  await check(
    "policies",
    `SELECT tablename, roles, cmd, qual FROM pg_policies WHERE schemaname = 'public' AND tablename IN ('fact_fhsis_indicator', 'fact_fhsis_workforce', 'ref_fhsis_indicator') ORDER BY tablename, policyname`,
  );
  await client.query("SAVEPOINT anon_probe");
  try {
    await client.query("SET LOCAL ROLE anon");
    await check("anon_datasets", datasets);
    await check("anon_counts_by_dataset", countByDataset);
  } catch (error) {
    console.log(
      JSON.stringify({
        check: "anon_role_switch",
        error_code: error.code ?? "unknown",
        note: "If role switching is denied, rerun as the database operator; do not change grants for this check.",
      }),
    );
  } finally {
    await client.query("ROLLBACK TO SAVEPOINT anon_probe");
  }
  await client.query("ROLLBACK");
} catch (error) {
  console.error(
    JSON.stringify({
      error_code: error.code ?? "unknown",
      note: "Inspection could not complete. No data was changed.",
    }),
  );
  process.exitCode = 1;
} finally {
  if (connected) await client.end();
}
