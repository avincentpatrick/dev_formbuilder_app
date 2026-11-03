#!/usr/bin/env node
/**
 * Applies `supabase/migrations/*.sql` to a plain PostgreSQL — the testing box's `bhw_connect`, the
 * CI service, or the local docker-compose stack — the way the Supabase CLI did on Supabase.
 *
 *     BHW_DATABASE_URL=postgres://bhw_app:…@127.0.0.1:5432/bhw_connect node db/migrate.mjs
 *
 *   (no flag)            apply every pending file, each in its own transaction, oldest first
 *   --dry-run            list the pending files and change nothing
 *   --check              exit 1 when anything is pending, 0 when nothing is (ops/deploy-bhw.ps1)
 *   --baseline=<version> record every file up to <version> as applied WITHOUT running it — once,
 *                        on a database restored from a dump that already holds that schema
 *   --census             print every `public` table's exact row count as JSON (the deploy compares
 *                        two of these around a migration and refuses on a drop)
 *   --dir=<path>         another migrations directory (the tests use one)
 *
 * History lives in Supabase's own table, `supabase_migrations.schema_migrations(version, statements,
 * name)`, so Supabase's tooling still reads it. A session-level advisory lock keeps two deploys from
 * migrating at once. After anything is applied, `NOTIFY pgrst, 'reload schema'` tells PostgREST,
 * which caches the schema, to read it again.
 *
 * ⚠️ Each file runs inside a transaction, so a statement that refuses one (CREATE INDEX
 * CONCURRENTLY) cannot be in a migration — as with the Supabase CLI. And on the testing box,
 * migrations are additive only: `db/lint-migrations.mjs` refuses the rest in CI.
 *
 * ⚠️ History is keyed on the version, and two pairs of the imported files share one
 * (20260721080000, 20260827180000). So recording a version that is already there does nothing, and
 * is never an error. Before, `--baseline` stopped on the primary key after 41 of 109 versions and
 * left the other 69 pending (measured 2026-11-02, Meridian's `bhw-migrate-duplicate-versions`). A
 * new file must not share a version: `readMigrations` refuses one above the lint floor.
 */
import { readdirSync, readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import pg from "pg";
import { FLOOR } from "./lint-migrations.mjs";

export const LOCK_KEY = 7_319_204_192;
export const APP_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
export const DEFAULT_DIR = join(APP_ROOT, "supabase", "migrations");

const FILE_NAME = /^(\d+)_(.+)\.sql$/;

const RECORD =
  "INSERT INTO supabase_migrations.schema_migrations (version, statements, name) " +
  "VALUES ($1, $2, $3) ON CONFLICT (version) DO NOTHING";

/**
 * Every `<version>_<name>.sql` in the directory, oldest first. A misnamed .sql file is an error, and
 * so are two files sharing a version above `floor`. The two pairs below it are history.
 */
export function readMigrations(dir, floor = FLOOR) {
  const migrations = readdirSync(dir)
    .filter((file) => file.endsWith(".sql"))
    .sort()
    .map((file) => {
      const match = FILE_NAME.exec(file);
      if (!match) throw new Error(`${file}: a migration is named <version>_<name>.sql`);
      return {
        version: match[1],
        name: match[2],
        file,
        sql: readFileSync(join(dir, file), "utf8"),
      };
    });
  const seen = new Map();
  for (const m of migrations) {
    if (seen.has(m.version) && m.version > floor) {
      throw new Error(
        `${seen.get(m.version)} and ${m.file} share version ${m.version}: give the new one its own`,
      );
    }
    seen.set(m.version, m.file);
  }
  return migrations;
}

export function pendingMigrations(migrations, applied) {
  return migrations.filter((m) => !applied.has(m.version));
}

export async function ensureHistory(client) {
  await client.query("CREATE SCHEMA IF NOT EXISTS supabase_migrations");
  await client.query(
    "CREATE TABLE IF NOT EXISTS supabase_migrations.schema_migrations " +
      "(version text PRIMARY KEY, statements text[], name text)",
  );
}

export async function appliedVersions(client) {
  const { rows } = await client.query("SELECT version FROM supabase_migrations.schema_migrations");
  return new Set(rows.map((row) => row.version));
}

/** Exact row counts of every base table in `public`, keyed by table name. */
export async function census(client) {
  const { rows: tables } = await client.query(
    "SELECT table_name FROM information_schema.tables " +
      "WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name",
  );
  const counts = {};
  for (const { table_name: table } of tables) {
    const { rows } = await client.query(
      `SELECT count(*)::bigint AS n FROM public."${table.replaceAll('"', '""')}"`,
    );
    counts[table] = Number(rows[0].n);
  }
  return counts;
}

/**
 * The runner. Returns what it found and what it did; throws on a failed file after rolling it back,
 * so the files before it stay applied and the files after it stay pending.
 *
 * @param {pg.Client} client
 * @param {{ dir?: string, dryRun?: boolean, baseline?: string | null, log?: (line: string) => void }} [options]
 */
export async function migrate(
  client,
  { dir = DEFAULT_DIR, dryRun = false, baseline = null, log = () => {} } = {},
) {
  const migrations = readMigrations(dir);
  await client.query("SELECT pg_advisory_lock($1)", [LOCK_KEY]);
  try {
    await ensureHistory(client);
    const pending = pendingMigrations(migrations, await appliedVersions(client));

    if (baseline !== null) {
      const covered = pending.filter((m) => m.version <= baseline);
      if (!migrations.some((m) => m.version === baseline)) {
        throw new Error(`--baseline=${baseline} names no migration file in ${dir}`);
      }
      if (!dryRun) {
        // Recorded whole or not at all: a baseline left half-done reads as pending history.
        await client.query("BEGIN");
        try {
          for (const m of covered) {
            const { rowCount } = await client.query(RECORD, [m.version, [], m.name]);
            log(
              rowCount === 1
                ? `baselined ${m.file} (recorded, not run)`
                : `baselined ${m.file} (its version is already recorded)`,
            );
          }
          await client.query("COMMIT");
        } catch (error) {
          await client.query("ROLLBACK");
          throw error;
        }
      }
      return { pending, applied: [], baselined: covered };
    }

    if (dryRun) return { pending, applied: [], baselined: [] };

    const applied = [];
    for (const m of pending) {
      await client.query("BEGIN");
      try {
        await client.query(m.sql);
        await client.query(RECORD, [m.version, [m.sql], m.name]);
        await client.query("COMMIT");
      } catch (error) {
        await client.query("ROLLBACK");
        throw new Error(`${m.file} failed and was rolled back: ${error.message}`);
      }
      applied.push(m);
      log(`applied ${m.file}`);
    }
    if (applied.length > 0) await client.query("NOTIFY pgrst, 'reload schema'");
    return { pending, applied, baselined: [] };
  } finally {
    await client.query("SELECT pg_advisory_unlock($1)", [LOCK_KEY]);
  }
}

function parseArgs(argv) {
  const options = { dryRun: false, check: false, census: false, baseline: null, dir: DEFAULT_DIR };
  for (const arg of argv) {
    if (arg === "--dry-run") options.dryRun = true;
    else if (arg === "--check") options.check = true;
    else if (arg === "--census") options.census = true;
    else if (arg.startsWith("--baseline=")) options.baseline = arg.slice("--baseline=".length);
    else if (arg.startsWith("--dir=")) options.dir = resolve(arg.slice("--dir=".length));
    else throw new Error(`unknown argument ${arg}`);
  }
  return options;
}

async function main() {
  const options = parseArgs(process.argv.slice(2));
  const connectionString = process.env.BHW_DATABASE_URL;
  if (!connectionString) throw new Error("BHW_DATABASE_URL is not set");

  const client = new pg.Client({ connectionString });
  await client.connect();
  try {
    if (options.census) {
      process.stdout.write(`${JSON.stringify(await census(client))}\n`);
      return 0;
    }
    const log = (line) => process.stdout.write(`${line}\n`);
    const checkOnly = options.check || options.dryRun;
    const result = await migrate(client, { ...options, dryRun: checkOnly || options.dryRun, log });
    if (options.baseline !== null) {
      log(`${result.baselined.length} file(s) recorded as applied up to ${options.baseline}`);
      return 0;
    }
    if (checkOnly) {
      for (const m of result.pending) log(`pending ${m.file}`);
      log(`${result.pending.length} pending`);
      return options.check && result.pending.length > 0 ? 1 : 0;
    }
    log(`${result.applied.length} applied`);
    return 0;
  } finally {
    await client.end();
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  main().then(
    (code) => process.exit(code),
    (error) => {
      process.stderr.write(`migrate: ${error.message}\n`);
      process.exit(2);
    },
  );
}
