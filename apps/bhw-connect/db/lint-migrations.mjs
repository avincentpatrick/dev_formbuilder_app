#!/usr/bin/env node
/**
 * Refuses a migration that can remove data, the way Meridian refuses its own (staging data is never
 * removed — the testers' records live there).
 *
 *     node db/lint-migrations.mjs            # CI: exit 1 and name each file and statement
 *
 * WHAT IT REFUSES, outside comments, string literals and function bodies: DROP TABLE, DROP SCHEMA,
 * a dropped column (`DROP COLUMN`, or `ALTER TABLE … DROP <name>`), a changed column type, TRUNCATE,
 * DELETE FROM, and RENAME (a renamed table or column breaks the running release's queries before
 * the new one starts). A `DO $$ … $$` block runs at migration time, so its body IS read; a
 * function's body (`AS $$ … $$`) runs only when called, so it is not.
 *
 * THE ESCAPE. A file whose first line is exactly `-- bhw:destructive` is let through here. The
 * boundary check (Meridian's scripts/boundary-check.php) then treats that file as Meridian's too,
 * so it cannot merge without the Meridian owner's approval label. The marker says "I mean it"; the
 * label says "the other owner agrees".
 *
 * THE FLOOR. Only files newer than FLOOR are read: the 111 files imported with the app are history
 * — they do not even match the 170 migrations Supabase recorded — and the database's baseline at
 * the move replaces them. FLOOR is the newest of them.
 */
import { readdirSync, readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

export const FLOOR = "20260905235804";
export const DESTRUCTIVE_MARKER = "-- bhw:destructive";
export const DEFAULT_DIR = join(
  resolve(dirname(fileURLToPath(import.meta.url)), ".."),
  "supabase",
  "migrations",
);

const RULES = [
  ["DROP TABLE", /\bDROP\s+TABLE\b/i],
  ["DROP SCHEMA", /\bDROP\s+SCHEMA\b/i],
  ["DROP COLUMN", /\bDROP\s+COLUMN\b/i],
  [
    "DROP COLUMN",
    /\bALTER\s+TABLE\b[^;]*?\bDROP\s+(?!COLUMN\b|CONSTRAINT\b|DEFAULT\b|NOT\s+NULL\b|EXPRESSION\b|IDENTITY\b)(?:IF\s+EXISTS\s+)?["a-z_]/i,
  ],
  ["ALTER COLUMN … TYPE", /\bALTER\s+(?:COLUMN\s+)?["a-z_][\w"]*\s+(?:SET\s+DATA\s+)?TYPE\b/i],
  ["TRUNCATE", /\bTRUNCATE\b/i],
  ["DELETE FROM", /\bDELETE\s+FROM\b/i],
  ["RENAME", /\bRENAME\b/i],
];

/**
 * The SQL a migration actually executes, read in one pass so that each construct hides only what it
 * should: a comment (`--` to the end of the line, `/* … *\/`), a string literal (`'…'` with `''`
 * inside it, or `E'…'` with backslash escapes) and a function body (`$tag$ … $tag$`) become blanks;
 * the body of a `DO` block is kept, because it runs now. Order matters and a chain of regexes gets
 * it wrong both ways: `'a--b'` would hide the rest of its line, and `-- don't` would open a string.
 */
export function executedSql(sql) {
  let out = "";
  let i = 0;
  while (i < sql.length) {
    const rest = sql.slice(i);
    if (rest.startsWith("--")) {
      const end = sql.indexOf("\n", i);
      i = end === -1 ? sql.length : end;
      out += " ";
    } else if (rest.startsWith("/*")) {
      const end = sql.indexOf("*/", i + 2);
      i = end === -1 ? sql.length : end + 2;
      out += " ";
    } else if ((/^[Ee]'/.test(rest) && !/\w$/.test(out)) || rest[0] === "'") {
      const escapes = rest[0] !== "'";
      let j = i + (escapes ? 2 : 1);
      while (j < sql.length) {
        if (escapes && sql[j] === "\\") j += 2;
        else if (sql[j] === "'" && sql[j + 1] === "'") j += 2;
        else if (sql[j] === "'") break;
        else j += 1;
      }
      i = j + 1;
      out += " '' ";
    } else if (/^\$[A-Za-z0-9_]*\$/.test(rest) && !/[\w$]$/.test(out)) {
      const tag = /^\$[A-Za-z0-9_]*\$/.exec(rest)[0];
      const end = sql.indexOf(tag, i + tag.length);
      const body = sql.slice(i + tag.length, end === -1 ? sql.length : end);
      i = end === -1 ? sql.length : end + tag.length;
      out += /\bDO\s*$/i.test(out) ? ` ${executedSql(body)} ` : " ";
    } else {
      out += sql[i];
      i += 1;
    }
  }
  return out;
}

export function destructiveRules(sql) {
  const executed = executedSql(sql);
  return [...new Set(RULES.filter(([, pattern]) => pattern.test(executed)).map(([name]) => name))];
}

export function isMarkedDestructive(sql) {
  return sql.split(/\r?\n/u, 1)[0].trim() === DESTRUCTIVE_MARKER;
}

/** Every file above the floor that would remove data and does not say it means to. */
export function lintMigrations(dir = DEFAULT_DIR, floor = FLOOR) {
  const findings = [];
  for (const file of readdirSync(dir)
    .filter((f) => f.endsWith(".sql"))
    .sort()) {
    const version = file.split("_", 1)[0];
    if (!(version > floor)) continue;
    const sql = readFileSync(join(dir, file), "utf8");
    if (isMarkedDestructive(sql)) continue;
    const rules = destructiveRules(sql);
    if (rules.length > 0) findings.push({ file, rules });
  }
  return findings;
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const findings = lintMigrations();
  for (const { file, rules } of findings) {
    process.stderr.write(`lint-migrations: ${file} — ${rules.join(", ")}\n`);
  }
  if (findings.length > 0) {
    process.stderr.write(
      `lint-migrations: ${findings.length} file(s) can remove data. Migrations here are additive; if this one ` +
        `must not be, make its first line "${DESTRUCTIVE_MARKER}" and get the Meridian owner's approval label.\n`,
    );
    process.exit(1);
  }
  process.stdout.write(`lint-migrations: passed (files above ${FLOOR} read)\n`);
}
