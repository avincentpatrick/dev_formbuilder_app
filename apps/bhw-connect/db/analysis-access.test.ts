import pg from "pg";
import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

const database = process.env.BHW_TEST_DATABASE_URL;
describe.skipIf(!database)("analysis approval database boundary", () => {
  it("keeps approval records inaccessible to public roles with RLS enabled", async () => {
    const client = new pg.Client({ connectionString: database });
    await client.connect();
    const schema = `analysis_test_${process.pid}`;
    try {
      await client.query("BEGIN");
      await client.query(`CREATE SCHEMA ${schema}`);
      await client.query(
        `ALTER DEFAULT PRIVILEGES IN SCHEMA ${schema} GRANT ALL ON TABLES TO anon, authenticated`,
      );
      const migration = readFileSync(
        new URL("../supabase/migrations/20261112100000_analysis_users.sql", import.meta.url),
        "utf8",
      );
      await client.query(migration.replaceAll("public.analysis_users", `${schema}.analysis_users`));
      const { rows } = await client.query(`select
        (select relrowsecurity from pg_class where oid='${schema}.analysis_users'::regclass) as rls,
        has_table_privilege('anon','${schema}.analysis_users','SELECT') as anon_read,
        has_table_privilege('authenticated','${schema}.analysis_users','SELECT') as member_read,
        has_table_privilege('anon','${schema}.analysis_users','INSERT') as anon_write,
        has_table_privilege('authenticated','${schema}.analysis_users','UPDATE') as member_write,
        has_table_privilege('service_role','${schema}.analysis_users','SELECT,INSERT,UPDATE') as service_access`);
      expect(rows[0]).toEqual({
        rls: true,
        anon_read: false,
        member_read: false,
        anon_write: false,
        member_write: false,
        service_access: true,
      });
    } finally {
      await client.query("ROLLBACK");
      await client.end();
    }
  });
});
