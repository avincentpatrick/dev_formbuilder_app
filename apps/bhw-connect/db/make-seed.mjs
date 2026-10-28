#!/usr/bin/env node
/**
 * Writes `supabase/seed.sql`: a small, safe-to-publish database for the local stack and CI
 * (decision D182), generated from a full one — the testing box's `bhw_connect`, or a restore of it.
 *
 *     BHW_SEED_SOURCE_URL=postgres://…/bhw_connect node db/make-seed.mjs [--rows=1000] [--fact-rows=200] [--out=supabase/seed.sql]
 *
 * ⛔ THIS REPOSITORY IS PUBLIC, AND `fact_bhw_raw` HOLDS 270,917 PEOPLE. So every rule below denies
 * by default, decided by the table's name:
 *
 *   dim_*    every row — the lookups (geography, programmes, facilities), already public on the site
 *   agg_*    the first --rows rows by primary key — published aggregates
 *   fact_*   SYNTHETIC rows only, never a real record. A column takes a real value only when it is
 *            categorical — at most CATEGORICAL_MAX distinct values, each shared by at least
 *            SHARED_MIN rows on average (sex, status, a region code; a 30-row table of names is not
 *            categorical) — or a foreign key into a table already in the seed; every other column —
 *            a name, an id number, a phone, a free-text field — gets a made-up value of its type
 *            (numbers and dates inside the column's real range, text "synthetic <column> <n>").
 *   anything else   no rows (feedback, usage events, admin users, caches: personal or irrelevant)
 *
 * A new table therefore enters the seed empty until a rule here names it. Review the output before
 * committing it anyway.
 *
 * The output is one transaction of INSERTs in foreign-key order, each value written by PostgreSQL's
 * own `quote_nullable` (so arrays, JSON and enums round-trip), then every serial and identity
 * sequence moved past the rows inserted.
 */
import { createHash } from "node:crypto";
import { writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import pg from "pg";

export const CATEGORICAL_MAX = 50;
export const SHARED_MIN = 10;
export const DEFAULT_OUT = join(
  resolve(dirname(fileURLToPath(import.meta.url)), ".."),
  "supabase",
  "seed.sql",
);

/** "all" | "sample" | "synthetic" | "none" — by table name only. */
export function seedRule(table) {
  if (table.startsWith("dim_")) return "all";
  if (table.startsWith("agg_")) return "sample";
  if (table.startsWith("fact_")) return "synthetic";
  return "none";
}

export function quoteIdent(name) {
  return `"${name.replaceAll('"', '""')}"`;
}

export function quoteLiteral(value) {
  if (value === null || value === undefined) return "NULL";
  return `'${String(value).replaceAll("'", "''")}'`;
}

/** Few distinct values, each shared by many rows: a category, not an identifier. */
export function isCategorical(distinct, total) {
  return distinct <= CATEGORICAL_MAX && total >= SHARED_MIN * Math.max(distinct, 1);
}

/** Tables ordered so that every table comes after the tables its foreign keys point at. */
export function dependencyOrder(tables, foreignKeys) {
  const ordered = [];
  const state = new Map();
  const visit = (table) => {
    if (state.get(table) === "done") return;
    if (state.get(table) === "visiting") return; // a cycle: break it here
    state.set(table, "visiting");
    for (const fk of foreignKeys.filter((f) => f.table === table && f.refTable !== table)) {
      if (tables.includes(fk.refTable)) visit(fk.refTable);
    }
    state.set(table, "done");
    ordered.push(table);
  };
  [...tables].sort().forEach(visit);
  return ordered;
}

function uuidFrom(text) {
  const h = createHash("md5").update(text).digest("hex");
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-4${h.slice(13, 16)}-8${h.slice(17, 20)}-${h.slice(20, 32)}`;
}

/**
 * A made-up value for one column of one synthetic row. `stats` carries what the source says about the
 * column: `categories` (its distinct values, when few), `refValues` (values present in the seeded
 * table a foreign key points at), and `min`/`max` for numbers and dates. Returns the SQL literal.
 */
export function syntheticLiteral(table, column, index, stats = {}) {
  if (stats.refValues) {
    if (stats.refValues.length)
      return quoteLiteral(stats.refValues[index % stats.refValues.length]);
    if (column.is_nullable === "YES") return "NULL";
    throw new Error(
      `make-seed: ${table}.${column.column_name} must point at a row, and the table it points at has none in the seed`,
    );
  }
  if (stats.categories) {
    return stats.categories.length
      ? quoteLiteral(stats.categories[index % stats.categories.length])
      : "NULL";
  }
  const type = column.udt_name;
  if (/^(int2|int4|int8|numeric|float4|float8)$/.test(type)) {
    const min = Number(stats.min ?? 0);
    const max = Number(stats.max ?? 100);
    const span = Math.max(max - min, 0);
    const value = span === 0 ? min : min + ((index * 7919) % (Math.floor(span) + 1));
    return quoteLiteral(/^int/.test(type) ? Math.round(value) : value);
  }
  if (type === "bool") return quoteLiteral(index % 2 === 0);
  if (type === "uuid") return quoteLiteral(uuidFrom(`${table}:${column.column_name}:${index}`));
  if (/^(date|timestamp|timestamptz)$/.test(type)) {
    const min = Date.parse(stats.min ?? "2025-01-01T00:00:00Z");
    const max = Date.parse(stats.max ?? "2025-12-31T00:00:00Z");
    const day = 24 * 60 * 60 * 1000;
    const days = Math.max(Math.floor((max - min) / day), 0);
    const iso = new Date(min + ((index * 31) % (days + 1)) * day).toISOString();
    return quoteLiteral(type === "date" ? iso.slice(0, 10) : iso);
  }
  if (/^(text|varchar|bpchar|citext)$/.test(type)) {
    const text = `synthetic ${column.column_name} ${index + 1}`;
    const limit = column.character_maximum_length;
    return quoteLiteral(limit ? text.slice(0, limit) : text);
  }
  if (type === "json" || type === "jsonb") return quoteLiteral("{}");
  if (type.startsWith("_")) return quoteLiteral("{}");
  if (column.is_nullable === "YES") return "NULL";
  throw new Error(
    `make-seed cannot invent a value for ${table}.${column.column_name} (${type}, NOT NULL); add a rule for it`,
  );
}

async function describe(client, schema) {
  const { rows: columns } = await client.query(
    `SELECT c.table_name, c.column_name, c.udt_name, c.is_nullable, c.character_maximum_length,
            c.column_default, c.is_identity, c.identity_generation, c.is_generated
       FROM information_schema.columns c
       JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name
      WHERE c.table_schema = $1 AND t.table_type = 'BASE TABLE'
      ORDER BY c.table_name, c.ordinal_position`,
    [schema],
  );
  const { rows: keys } = await client.query(
    `SELECT tc.table_name, kcu.column_name, kcu.ordinal_position
       FROM information_schema.table_constraints tc
       JOIN information_schema.key_column_usage kcu
         ON kcu.constraint_schema = tc.constraint_schema AND kcu.constraint_name = tc.constraint_name
      WHERE tc.table_schema = $1 AND tc.constraint_type = 'PRIMARY KEY'
      ORDER BY tc.table_name, kcu.ordinal_position`,
    [schema],
  );
  const { rows: foreignKeys } = await client.query(
    `SELECT cl.relname AS "table", a.attname AS "column", rcl.relname AS "refTable", ra.attname AS "refColumn"
       FROM pg_constraint con
       JOIN pg_class cl ON cl.oid = con.conrelid
       JOIN pg_namespace ns ON ns.oid = cl.relnamespace
       JOIN pg_class rcl ON rcl.oid = con.confrelid
       JOIN LATERAL unnest(con.conkey, con.confkey) AS k(attnum, refattnum) ON true
       JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum
       JOIN pg_attribute ra ON ra.attrelid = con.confrelid AND ra.attnum = k.refattnum
      WHERE con.contype = 'f' AND ns.nspname = $1`,
    [schema],
  );
  const tables = [...new Set(columns.map((c) => c.table_name))];
  const byTable = new Map(tables.map((t) => [t, columns.filter((c) => c.table_name === t)]));
  const primaryKey = new Map(
    tables.map((t) => [t, keys.filter((k) => k.table_name === t).map((k) => k.column_name)]),
  );
  return { tables, byTable, primaryKey, foreignKeys };
}

/** The seed, as SQL text. `targetSchema` lets a test load it somewhere other than where it came from. */
export async function makeSeed(
  client,
  { schema = "public", targetSchema = schema, rows = 1000, factRows = 200 } = {},
) {
  const { tables, byTable, primaryKey, foreignKeys } = await describe(client, schema);
  const seeded = new Map(); // table -> Map(column -> values written)
  const out = [
    "-- Generated by db/make-seed.mjs. dim_* copied, agg_* sampled, fact_* SYNTHETIC — never a real record.",
    "-- Review before committing: this repository is public.",
    "BEGIN;",
  ];
  const sequences = [];

  for (const table of dependencyOrder(tables, foreignKeys)) {
    const rule = seedRule(table);
    if (rule === "none") continue;
    const columns = byTable.get(table).filter((c) => c.is_generated !== "ALWAYS");
    const names = columns.map((c) => c.column_name);
    const target = `${quoteIdent(targetSchema)}.${quoteIdent(table)}`;
    const overriding = columns.some(
      (c) => c.is_identity === "YES" && c.identity_generation === "ALWAYS",
    )
      ? " OVERRIDING SYSTEM VALUE"
      : "";
    const insert = `INSERT INTO ${target} (${names.map(quoteIdent).join(", ")})${overriding} VALUES `;
    const written = new Map(names.map((n) => [n, []]));
    const valueRows = [];

    if (rule === "all" || rule === "sample") {
      const order = primaryKey.get(table).length ? primaryKey.get(table) : names;
      const select = names
        .map((n) => `quote_nullable(${quoteIdent(n)}::text)`)
        .join(" || ', ' || ");
      const raw = names.map((n) => `${quoteIdent(n)}::text AS ${quoteIdent(n)}`).join(", ");
      const { rows: source } = await client.query(
        `SELECT ${select} AS literal, ${raw} FROM ${quoteIdent(schema)}.${quoteIdent(table)}
          ORDER BY ${order.map(quoteIdent).join(", ")}${rule === "sample" ? ` LIMIT ${Number(rows)}` : ""}`,
      );
      for (const row of source) {
        valueRows.push(`(${row.literal})`);
        for (const n of names) written.get(n).push(row[n]);
      }
    } else {
      const stats = new Map();
      for (const column of columns) {
        const fk = foreignKeys.find((f) => f.table === table && f.column === column.column_name);
        if (fk) {
          const values = seeded.get(fk.refTable)?.get(fk.refColumn) ?? [];
          stats.set(column.column_name, { refValues: values.filter((v) => v !== null) });
          continue;
        }
        const col = `${quoteIdent(column.column_name)}`;
        const from = `${quoteIdent(schema)}.${quoteIdent(table)}`;
        const { rows: counted } = await client.query(
          `SELECT count(DISTINCT ${col})::int AS n, count(*)::int AS total FROM ${from}`,
        );
        const { n, total } = counted[0];
        if (isCategorical(n, total) && column.udt_name !== "uuid") {
          const { rows: values } = await client.query(
            `SELECT DISTINCT ${col}::text AS v FROM ${from} WHERE ${col} IS NOT NULL ORDER BY 1`,
          );
          stats.set(column.column_name, { categories: values.map((v) => v.v) });
        } else if (
          /^(int2|int4|int8|numeric|float4|float8|date|timestamp|timestamptz)$/.test(
            column.udt_name,
          )
        ) {
          const { rows: range } = await client.query(
            `SELECT min(${col})::text AS min, max(${col})::text AS max FROM ${from}`,
          );
          stats.set(column.column_name, range[0]);
        }
      }
      for (let i = 0; i < factRows; i += 1) {
        const literals = columns.map((c) =>
          syntheticLiteral(table, c, i, stats.get(c.column_name)),
        );
        valueRows.push(`(${literals.join(", ")})`);
      }
    }

    seeded.set(table, written);
    if (valueRows.length) out.push(`${insert}\n  ${valueRows.join(",\n  ")};`);
    for (const c of columns) {
      if (c.is_identity === "YES" || /^nextval\(/.test(c.column_default ?? ""))
        sequences.push([table, c.column_name]);
    }
  }

  for (const [table, column] of sequences) {
    const target = `${quoteIdent(targetSchema)}.${quoteIdent(table)}`;
    out.push(
      `SELECT setval(pg_get_serial_sequence(${quoteLiteral(`${quoteIdent(targetSchema)}.${quoteIdent(table)}`)}, ${quoteLiteral(column)}), ` +
        `GREATEST(COALESCE((SELECT max(${quoteIdent(column)}) FROM ${target}), 0), 1));`,
    );
  }
  out.push("COMMIT;", "");
  return out.join("\n");
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const option = (name, fallback) => {
    const arg = process.argv.slice(2).find((a) => a.startsWith(`--${name}=`));
    return arg ? arg.slice(name.length + 3) : fallback;
  };
  const connectionString = process.env.BHW_SEED_SOURCE_URL;
  if (!connectionString) {
    process.stderr.write("make-seed: BHW_SEED_SOURCE_URL is not set\n");
    process.exit(2);
  }
  const client = new pg.Client({ connectionString });
  client
    .connect()
    .then(() =>
      makeSeed(client, {
        rows: Number(option("rows", 1000)),
        factRows: Number(option("fact-rows", 200)),
      }),
    )
    .then((sql) => {
      const out = resolve(option("out", DEFAULT_OUT));
      writeFileSync(out, sql);
      process.stdout.write(`make-seed: wrote ${out}\n`);
    })
    .catch((error) => {
      process.stderr.write(`make-seed: ${error.message}\n`);
      process.exitCode = 2;
    })
    .finally(() => client.end());
}
