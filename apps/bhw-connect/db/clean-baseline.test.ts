import { describe, expect, it } from "vitest";
import { cleanBaseline } from "./clean-baseline.mjs";

// The shape a schema-only pg_dump of Supabase's public schema takes, cut to one of each line the
// cleaner removes and a few it must keep.
const RAW = [
  "\\restrict AbC123",
  "SET statement_timeout = 0;",
  "SET transaction_timeout = 0;",
  "CREATE SCHEMA public;",
  "COMMENT ON SCHEMA public IS 'standard public schema';",
  "CREATE TABLE public.admin_users (user_id uuid NOT NULL, role public.admin_role_enum NOT NULL);",
  "CREATE TABLE public.dim_geo (geo_code text NOT NULL);",
  "ALTER TABLE ONLY public.admin_users",
  "    ADD CONSTRAINT admin_users_pkey PRIMARY KEY (user_id);",
  "ALTER TABLE ONLY public.admin_users",
  "    ADD CONSTRAINT admin_users_user_id_fkey FOREIGN KEY (user_id) REFERENCES auth.users(id);",
  "ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA public GRANT ALL ON TABLES TO anon;",
  "GRANT ALL ON TABLE public.dim_geo TO supabase_admin;",
  "GRANT SELECT ON TABLE public.dim_geo TO anon;",
  "\\unrestrict AbC123",
].join("\n");

describe("clean-baseline", () => {
  it("takes out exactly the lines a plain PostgreSQL 15 rejects, and keeps the rest", () => {
    const { text, stripped } = cleanBaseline(RAW);

    expect(stripped).toEqual({ psqlOnly: 2, setting: 1, publicSchema: 2, supabaseForeignKey: 1, supabaseRole: 2 });
    expect(text).not.toMatch(/transaction_timeout|CREATE SCHEMA public|supabase_admin|auth\.users|restrict/);
    // The primary key's two lines stay; only the foreign key into auth. and its ALTER line go.
    expect(text).toContain("ADD CONSTRAINT admin_users_pkey PRIMARY KEY (user_id);");
    expect(text.match(/^ALTER TABLE ONLY public\.admin_users$/gm)).toHaveLength(1);
    expect(text).toContain("SET statement_timeout = 0;");
    expect(text).toContain("GRANT SELECT ON TABLE public.dim_geo TO anon;");
  });

  it("refuses a dump that would still name Supabase's schemas or roles", () => {
    const policy = `${RAW}\nCREATE POLICY own ON public.admin_users USING (user_id = auth.uid());`;
    expect(() => cleanBaseline(policy)).toThrow(/still name Supabase/);

    const owner = `${RAW}\nALTER TABLE public.dim_geo OWNER TO supabase_admin;`;
    expect(() => cleanBaseline(owner)).toThrow(/still name Supabase/);
  });
});
