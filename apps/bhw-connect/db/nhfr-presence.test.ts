import pg from "pg";
import { readFileSync } from "node:fs";
import { expect, it } from "vitest";
const database = process.env.BHW_TEST_DATABASE_URL;
it.skipIf(!database)(
  "counts distinct union presence, excludes unusable attribution and other snapshots, and keeps zero areas",
  async () => {
    const db = new pg.Client({ connectionString: database });
    await db.connect();
    const schema = `nhfr_presence_test_${process.pid}`;
    try {
      await db.query("BEGIN");
      await db.query(
        `create schema ${schema}; create type ${schema}.geo_level_enum as enum ('national','region','province','citymun','barangay'); create table ${schema}.dim_geo(geo_code text primary key,geo_level ${schema}.geo_level_enum,parent_code text); create table ${schema}.fact_nhfr_facility(dataset_id bigint,geo_code text,barangay_geo_code text,facility_type text); grant usage on schema ${schema} to anon; grant select on all tables in schema ${schema} to anon;`,
      );
      const sql = readFileSync(
        new URL("../supabase/migrations/20261124180000_nhfr_presence_summary.sql", import.meta.url),
        "utf8",
      )
        .replaceAll("public.nhfr_presence_summary", `${schema}.nhfr_presence_summary`)
        .replace("set search_path = public", `set search_path = ${schema}`);
      await db.query(sql);
      await db.query(
        `insert into ${schema}.dim_geo values ('r1','region',null),('r2','region',null),('p1','province','r1'),('p2','province','r1'),('c1','citymun','p1'),('b1','barangay','c1'),('b2','barangay','c1'),('b3','barangay','c1'),('c2','citymun','p2'),('b4','barangay','c2'); insert into ${schema}.fact_nhfr_facility values(1,'c1','b1','Hospital'),(1,'c1','b1','Station'),(1,'c1','b2','Station'),(1,'c1',null,'Hospital'),(1,'c1','b4','Hospital'),(2,'c1','b3','Other');`,
      );
      await db.query(
        `alter table ${schema}.fact_nhfr_facility enable row level security; create policy visible_snapshot on ${schema}.fact_nhfr_facility for select to anon using(dataset_id=1)`,
      );
      await db.query("set local role anon");
      const read = async (root: string, types: string[] = []) =>
        (await db.query(`select ${schema}.nhfr_presence_summary(1,$1,$2) as value`, [root, types]))
          .rows[0].value;
      const all = await read("PH");
      expect(all.types).toEqual(["Hospital", "Station"]);
      expect(all.rows).toEqual([
        { code: "r1", total: 5, allPresent: 2, present: 2, barangays: 4 },
        { code: "r2", total: 0, allPresent: 0, present: 0, barangays: 0 },
      ]);
      expect((await read("r1", ["Hospital", "Station", "Station"])).rows).toEqual([
        { code: "p1", total: 5, allPresent: 2, present: 2, barangays: 3 },
        { code: "p2", total: 0, allPresent: 0, present: 0, barangays: 1 },
      ]);
      expect((await read("p1", ["Hospital"])).rows).toEqual([
        { code: "c1", total: 5, allPresent: 2, present: 1, barangays: 3 },
      ]);
      expect((await read("r1", ["Station"])).rows[0].present).toBe(2);
      expect((await read("r1", ["Unknown"])).rows[0].present).toBe(0);
      const hidden = (
        await db.query(`select ${schema}.nhfr_presence_summary(2,'PH','{}') as value`)
      ).rows[0].value;
      expect(hidden.types).toEqual([]);
      expect(
        hidden.rows.every(
          (r: { total: number; present: number }) => r.total === 0 && r.present === 0,
        ),
      ).toBe(true);
    } finally {
      await db.query("ROLLBACK");
      await db.end();
    }
  },
);
