import pg from "pg";
import { readFileSync } from "node:fs";
import { expect, it, vi } from "vitest";
import { CONTRACT } from "../ingestion/prepare-poverty-official.mjs";
import { validateBundle, loadBundle } from "./load-poverty-official.mjs";
function bundle() {
  return {
    format: "bhw-poverty-official-v1",
    dataset_slug: CONTRACT.dataset_slug,
    source_url: CONTRACT.source_url,
    workbook_sha256: CONTRACT.workbook_sha256,
    rows: CONTRACT.mapping.map((m: Record<string, unknown>) => ({
      ...m,
      reference_year: 2023,
      poverty_incidence: 0,
      ci_low: -0.1,
      ci_high: 3,
      cv: 12,
      is_suppressed: false,
      value_status: "available",
      workbook_sha256: CONTRACT.workbook_sha256,
      publication_note: "Synthetic test observation, not a PSA value.",
    })),
  };
}
it("rejects changed geography, incomplete coverage and contradictory state", () => {
  const b = bundle();
  expect(() => validateBundle(b)).not.toThrow();
  expect(() => validateBundle({ ...b, rows: b.rows.slice(1) })).toThrow("Incomplete");
  b.rows[0].geo_code = "wrong";
  expect(() => validateBundle(b)).toThrow("geography");
  const c = bundle();
  c.rows[0].value_status = "not_absolute_zero";
  expect(() => validateBundle(c)).toThrow("estimate");
});
it("rolls back a successful dry run and never overwrites a registered release", async () => {
  const b = bundle();
  const query = vi.fn(async (sql: string) => ({
    rows: sql.startsWith("SELECT geo_code")
      ? b.rows
      : sql.startsWith("INSERT INTO public.dim_dataset")
        ? [{ dataset_id: 99 }]
        : [],
  }));
  expect(await loadBundle({ query }, b)).toEqual({ applied: false, rows: b.rows.length });
  expect(query.mock.calls.at(-1)?.[0]).toBe("ROLLBACK");
  query.mockImplementation(async (sql: string) => ({
    rows: sql.startsWith("SELECT geo_code") ? b.rows : [{ dataset_id: 99 }],
  }));
  await expect(loadBundle({ query }, b, { apply: true })).rejects.toThrow("already registered");
  expect(query.mock.calls.at(-1)?.[0]).toBe("ROLLBACK");
});

it.skipIf(!process.env.BHW_TEST_DATABASE_URL)(
  "loads synthetic records against the real migration, rolls back, and grants public read only",
  async () => {
    const client = new pg.Client({ connectionString: process.env.BHW_TEST_DATABASE_URL });
    await client.connect();
    const schema = `poverty_test_${process.pid}`;
    try {
      await client.query(`CREATE SCHEMA ${schema}`);
      await client.query(
        `ALTER DEFAULT PRIVILEGES IN SCHEMA ${schema} GRANT ALL ON TABLES TO anon, authenticated`,
      );
      await client.query(
        `ALTER DEFAULT PRIVILEGES IN SCHEMA ${schema} GRANT ALL ON SEQUENCES TO anon, authenticated`,
      );
      await client.query(
        `CREATE TYPE ${schema}.geo_level_enum AS ENUM ('national','region','province','citymun','barangay')`,
      );
      await client.query(
        `CREATE TABLE ${schema}.dim_dataset (dataset_id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, slug text UNIQUE, name text, source_name text, source_url text, license text, methodology_md text, geo_join_level text, as_of_date date, version text, status text)`,
      );
      await client.query(
        `CREATE TABLE ${schema}.dim_geo (geo_code text PRIMARY KEY, geo_level text, geo_name text)`,
      );
      const b = bundle();
      for (const row of b.rows)
        await client.query(`INSERT INTO ${schema}.dim_geo VALUES ($1,$2,$3)`, [
          row.geo_code,
          row.geo_level,
          row.geo_name,
        ]);
      const migration = readFileSync(
        new URL("../supabase/migrations/20261120120000_official_poverty.sql", import.meta.url),
        "utf8",
      );
      await client.query(migration.replaceAll("public.", `${schema}.`));
      const scoped = {
        query: (sql: string, values?: unknown[]) =>
          client.query(sql.replaceAll("public.", `${schema}.`), values),
      };
      expect(await loadBundle(scoped, b)).toEqual({ applied: false, rows: 103 });
      expect(
        (await client.query(`SELECT count(*) FROM ${schema}.agg_poverty_official`)).rows[0].count,
      ).toBe("0");
      const permissions = await client.query(`SELECT relrowsecurity AS rls,
      has_table_privilege('anon', '${schema}.agg_poverty_official', 'SELECT') AS readable,
      has_table_privilege('anon', '${schema}.agg_poverty_official', 'TRUNCATE') AS truncatable,
      has_table_privilege('authenticated', '${schema}.agg_poverty_official', 'INSERT') AS writable
      FROM pg_class WHERE oid = '${schema}.agg_poverty_official'::regclass`);
      expect(permissions.rows[0]).toEqual({
        rls: true,
        readable: true,
        truncatable: false,
        writable: false,
      });
    } finally {
      await client.query("ROLLBACK");
      await client.query(`DROP SCHEMA IF EXISTS ${schema} CASCADE`);
      await client.end();
    }
  },
);
