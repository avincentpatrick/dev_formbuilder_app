import pg from "pg";
import { describe, expect, it } from "vitest";

const database = process.env.BHW_TEST_DATABASE_URL;
describe.skipIf(!database)("analysis approval database boundary", () => {
  it("keeps approval records inaccessible to public roles with RLS enabled", async () => {
    const client = new pg.Client({ connectionString: database });
    await client.connect();
    try {
      const { rows } = await client.query(`select
        (select relrowsecurity from pg_class where oid='public.analysis_users'::regclass) as rls,
        has_table_privilege('anon','public.analysis_users','SELECT') as anon_read,
        has_table_privilege('authenticated','public.analysis_users','SELECT') as member_read,
        has_table_privilege('anon','public.analysis_users','INSERT') as anon_write,
        has_table_privilege('authenticated','public.analysis_users','UPDATE') as member_write,
        has_table_privilege('service_role','public.analysis_users','SELECT,INSERT,UPDATE') as service_access`);
      expect(rows[0]).toEqual({
        rls: true,
        anon_read: false,
        member_read: false,
        anon_write: false,
        member_write: false,
        service_access: true,
      });
    } finally {
      await client.end();
    }
  });
});
