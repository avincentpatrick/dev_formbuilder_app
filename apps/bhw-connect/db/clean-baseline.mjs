#!/usr/bin/env node
/**
 * Cleans a schema-only `pg_dump` of BHW Connect's `public` schema into `db/baseline/schema.sql`, the
 * schema the local stack and CI build from (Meridian's `bhw-p4-loop`, `D207`).
 *
 *     node db/clean-baseline.mjs <raw dump> <out file>
 *
 * WHY A BASELINE AND NOT THE MIGRATIONS. The migration files cannot build the database: they assume
 * Supabase's `auth` and `storage` schemas, and the live database holds tables no file creates (Meridian's
 * M206 measured both). The live schema itself loads into PostgreSQL 15 with pgvector after
 * `db/bootstrap-roles.sql`, once the lines below are taken out. Each is matched by its content, never by its
 * position, and the run refuses (exit 2) if anything that names Supabase's schemas or roles survives.
 *
 *   - `\restrict` / `\unrestrict`: psql-only lines a current `pg_dump` (15.14 on) writes around the dump,
 *     each with a random key, so the baseline is plain SQL any client runs and its bytes change only when
 *     the schema does;
 *   - `SET transaction_timeout`: a PostgreSQL 17 setting a newer `pg_dump` writes and 15 rejects;
 *   - `CREATE SCHEMA public` / `COMMENT ON SCHEMA public`: the schema already exists;
 *   - a foreign key into `auth.` or `storage.` (two lines: `ALTER TABLE ONLY …` and `ADD CONSTRAINT …`);
 *   - a grant or default privilege for a Supabase-only role (`supabase_*`, `dashboard_user`, `pgbouncer`).
 *
 * Plain JavaScript, no dependency, so the box's runner runs it as it is.
 */
import { readFileSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";

const SUPABASE_ROLE = /\b(supabase_\w+|dashboard_user|pgbouncer)\b/;
const SUPABASE_SCHEMA = /\b(auth|storage)\.\w/;

/** Returns `{ text, stripped }`, where `stripped` counts what each rule removed. Throws if anything is left. */
export function cleanBaseline(raw) {
  const lines = raw.split("\n");
  const kept = [];
  const stripped = { psqlOnly: 0, setting: 0, publicSchema: 0, supabaseForeignKey: 0, supabaseRole: 0 };
  for (let i = 0; i < lines.length; i += 1) {
    const line = lines[i];
    if (/^\\(un)?restrict\b/.test(line)) {
      stripped.psqlOnly += 1;
      continue;
    }
    if (/^SET transaction_timeout\b/.test(line)) {
      stripped.setting += 1;
      continue;
    }
    if (/^(CREATE SCHEMA public;|COMMENT ON SCHEMA public IS\b)/.test(line)) {
      stripped.publicSchema += 1;
      continue;
    }
    if (/^\s+ADD CONSTRAINT .* REFERENCES (auth|storage)\./.test(line)) {
      if (kept.length && /^ALTER TABLE ONLY /.test(kept[kept.length - 1])) kept.pop();
      stripped.supabaseForeignKey += 1;
      continue;
    }
    if (/^(GRANT|REVOKE|ALTER DEFAULT PRIVILEGES)\b/.test(line) && SUPABASE_ROLE.test(line)) {
      stripped.supabaseRole += 1;
      continue;
    }
    kept.push(line);
  }
  const text = kept.join("\n");
  const left = kept.filter(
    (line) => !line.startsWith("--") && (SUPABASE_ROLE.test(line) || SUPABASE_SCHEMA.test(line)),
  );
  if (left.length) {
    throw new Error(
      `clean-baseline: ${left.length} line(s) still name Supabase's schemas or roles, e.g. ${left[0].trim().slice(0, 120)}`,
    );
  }
  return { text, stripped };
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const [input, output] = process.argv.slice(2);
  if (!input || !output) {
    process.stderr.write("usage: node db/clean-baseline.mjs <raw dump> <out file>\n");
    process.exit(2);
  }
  try {
    const { text, stripped } = cleanBaseline(readFileSync(input, "utf8"));
    writeFileSync(output, text);
    process.stdout.write(`clean-baseline: wrote ${output} (stripped ${JSON.stringify(stripped)})\n`);
  } catch (error) {
    process.stderr.write(`${error.message}\n`);
    process.exit(2);
  }
}
