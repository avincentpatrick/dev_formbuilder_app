#!/usr/bin/env node
/**
 * Builds BHW Connect's development database from the committed copy (Meridian's `bhw-p4-loop`, `D207`):
 *
 *   1. `db/baseline/schema.sql` — the live schema, cleaned (`db/clean-baseline.mjs`);
 *   2. every migration up to `db/baseline/version.txt` recorded as applied without running it, since the
 *      schema already holds it, then every newer migration file run for real — so a pull request's new
 *      migration is applied here exactly as the box's deploy will apply it;
 *   3. `supabase/seed.sql` — the made-up seed (`db/make-seed.mjs`);
 *   4. PostgREST told to reload its schema cache.
 *
 *     BHW_DEV_DATABASE_URL=postgres://postgres:bhw-local@127.0.0.1:5442/bhw_connect node db/load-dev.mjs
 *
 * The database must already carry `db/bootstrap-roles.sql` (the local stack and CI run it first) and must
 * have no tables in `public`: this builds a database, it never overwrites one. Connect as a superuser.
 * The refresh of the copy itself is the "BHW Connect dev copy" workflow (`ops/dev-snapshot.ps1`).
 */
import { readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import pg from "pg";
import { migrate } from "./migrate.mjs";

const APP = resolve(dirname(fileURLToPath(import.meta.url)), "..");
export const BASELINE_SCHEMA = join(APP, "db", "baseline", "schema.sql");
export const BASELINE_VERSION = join(APP, "db", "baseline", "version.txt");
export const SEED = join(APP, "supabase", "seed.sql");

export async function loadDev(client, { log = () => {} } = {}) {
  const { rows } = await client.query(
    "SELECT count(*)::int AS n FROM pg_tables WHERE schemaname = 'public'",
  );
  if (rows[0].n > 0) {
    throw new Error(`public already holds ${rows[0].n} table(s); load-dev builds an empty database only`);
  }

  await client.query(readFileSync(BASELINE_SCHEMA, "utf8"));
  log("schema: db/baseline/schema.sql loaded");

  const version = readFileSync(BASELINE_VERSION, "utf8").trim();
  const { baselined } = await migrate(client, { baseline: version });
  const { applied } = await migrate(client);
  log(`migrations: ${baselined.length} recorded as the baseline (to ${version}), ${applied.length} applied after it`);

  await client.query(readFileSync(SEED, "utf8"));
  const { rows: counted } = await client.query(
    `SELECT count(*) FILTER (WHERE n_live_tup > 0)::int AS filled, count(*)::int AS tables
       FROM pg_stat_user_tables WHERE schemaname = 'public'`,
  );
  log(`seed: supabase/seed.sql loaded`);

  await client.query("NOTIFY pgrst, 'reload schema'");
  return { version, baselined: baselined.length, applied: applied.length, ...counted[0] };
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const connectionString = process.env.BHW_DEV_DATABASE_URL;
  if (!connectionString) {
    process.stderr.write("load-dev: BHW_DEV_DATABASE_URL is not set\n");
    process.exit(2);
  }
  const client = new pg.Client({ connectionString });
  client
    .connect()
    .then(() => loadDev(client, { log: (line) => process.stdout.write(`load-dev: ${line}\n`) }))
    .then((result) => process.stdout.write(`load-dev: done ${JSON.stringify(result)}\n`))
    .catch((error) => {
      process.stderr.write(`load-dev: ${error.message}\n`);
      process.exitCode = 2;
    })
    .finally(() => client.end());
}
