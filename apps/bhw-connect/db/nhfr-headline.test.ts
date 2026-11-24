import pg from "pg";
import { readFileSync } from "node:fs";
import { expect, it } from "vitest";
const database = process.env.BHW_TEST_DATABASE_URL;
it.skipIf(!database)(
  "counts union presence once, preserves unassigned municipal facilities and respects split roots",
  async () => {
    const client = new pg.Client({ connectionString: database });
    await client.connect();
    const schema = `nhfr_headline_test_${process.pid}`;
    try {
      await client.query("BEGIN");
      await client.query(
        `create schema ${schema}; create table ${schema}.dim_geo(geo_code text primary key, geo_level text, parent_code text); create table ${schema}.fact_nhfr_facility(dataset_id bigint, facility_type text, ownership_major text, bed_capacity integer, geo_code text, barangay_geo_code text); grant usage on schema ${schema} to anon; grant select on all tables in schema ${schema} to anon;`,
      );
      const migration = readFileSync(
        new URL("../supabase/migrations/20261124140000_nhfr_headline_summary.sql", import.meta.url),
        "utf8",
      )
        .replaceAll("public.nhfr_headline_summary", `${schema}.nhfr_headline_summary`)
        .replace("set search_path = public", `set search_path = ${schema}`);
      await client.query(migration);
      await client.query(
        `insert into ${schema}.dim_geo values ('PH','national',null),('city','citymun','PH'),('b1','barangay','city'),('b2','barangay','city'),('b3','barangay','city'),('other','citymun','PH'),('foreign','barangay','other'); insert into ${schema}.fact_nhfr_facility values (1,'Hospital','Government',10,'city','b1'),(1,'Station','Government',0,'city','b1'),(1,'Station','Private',0,'city','b2'),(1,'Hospital','Private',20,'city',null),(1,'Hospital','Government',2,'city','foreign'),(2,'Hospital','Government',100,'city','b3'),(1,'Laboratory','Private',3,'other','foreign');`,
      );
      await client.query("set local role anon");
      const read = async (roots: string[], types: string[]) =>
        (
          await client.query(`select ${schema}.nhfr_headline_summary(1,$1,$2) as summary`, [
            roots,
            types,
          ])
        ).rows[0].summary;
      expect(await read(["city"], ["Hospital", "Station"])).toMatchObject({
        total: 5,
        count: 5,
        present: 2,
        barangays: 3,
        beds: 32,
        government: 3,
        private: 2,
        unattributed: 2,
      });
      expect(await read(["city"], ["Station"])).toMatchObject({
        count: 2,
        present: 2,
        barangays: 3,
        beds: 0,
      });
      expect(await read(["city"], ["Hospital"])).toMatchObject({
        count: 3,
        present: 1,
        unattributed: 2,
      });
      expect(await read(["b1", "b1"], ["Hospital", "Station"])).toMatchObject({
        total: 2,
        count: 2,
        present: 1,
        barangays: 1,
      });
      expect(await read(["city"], ["Laboratory"])).toMatchObject({
        total: 5,
        count: 0,
        present: 0,
        barangays: 3,
        beds: 0,
        types: ["Hospital", "Laboratory", "Station"],
      });
      expect(await read(["b3"], [])).toMatchObject({
        total: 0,
        count: 0,
        present: 0,
        barangays: 1,
        beds: 0,
      });
    } finally {
      await client.query("ROLLBACK");
      await client.end();
    }
  },
);
