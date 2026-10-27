import { createHmac } from "node:crypto";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import pg from "pg";
import { afterAll, beforeAll, describe, expect, it } from "vitest";
import {
  destructiveRules,
  executedSql,
  FLOOR,
  isMarkedDestructive,
  lintMigrations,
} from "./lint-migrations.mjs";
import { census, migrate, pendingMigrations, readMigrations } from "./migrate.mjs";
import { mintRoleToken, signJwt } from "./mint-jwt.mjs";
import {
  dependencyOrder,
  isCategorical,
  makeSeed,
  seedRule,
  syntheticLiteral,
} from "./make-seed.mjs";

function tempDir(files: Record<string, string>): string {
  const dir = mkdtempSync(join(tmpdir(), "bhw-db-"));
  for (const [name, body] of Object.entries(files)) writeFileSync(join(dir, name), body);
  return dir;
}

describe("lint-migrations", () => {
  it("refuses each statement that can remove data", () => {
    const cases: [string, string][] = [
      ["DROP TABLE public.feedback;", "DROP TABLE"],
      ["DROP SCHEMA old CASCADE;", "DROP SCHEMA"],
      ["ALTER TABLE t DROP COLUMN c;", "DROP COLUMN"],
      ["ALTER TABLE t DROP c;", "DROP COLUMN"],
      ["ALTER TABLE t ALTER COLUMN c TYPE bigint;", "ALTER COLUMN … TYPE"],
      ["ALTER TABLE t ALTER c SET DATA TYPE text;", "ALTER COLUMN … TYPE"],
      ["TRUNCATE usage_events;", "TRUNCATE"],
      ["DELETE FROM feedback WHERE true;", "DELETE FROM"],
      ["ALTER TABLE t RENAME TO u;", "RENAME"],
    ];
    for (const [sql, rule] of cases) expect(destructiveRules(sql), sql).toContain(rule);
  });

  it("lets additive statements through", () => {
    const sql = `
      CREATE TABLE t (id bigint PRIMARY KEY, c text);
      ALTER TABLE t ADD COLUMN d int;
      ALTER TABLE t DROP CONSTRAINT t_c_check;
      ALTER TABLE t ALTER COLUMN c DROP DEFAULT;
      ALTER TABLE t ALTER COLUMN c DROP NOT NULL;
      CREATE INDEX t_c ON t (c);
      INSERT INTO t VALUES (1, 'x');`;
    expect(destructiveRules(sql)).toEqual([]);
  });

  it("ignores what is never executed: comments, strings and function bodies", () => {
    const sql = `
      -- we used to DROP TABLE here
      /* TRUNCATE nothing */
      INSERT INTO notes VALUES ('please DELETE FROM my mind');
      CREATE FUNCTION prune() RETURNS void LANGUAGE sql AS $$ DELETE FROM cache WHERE stale $$;`;
    expect(destructiveRules(sql)).toEqual([]);
  });

  it("reads a DO block, because it runs during the migration", () => {
    expect(destructiveRules("DO $$ BEGIN DELETE FROM feedback; END $$;")).toContain("DELETE FROM");
  });

  it("does not let a string hide the statement after it, or a comment open a string", () => {
    expect(destructiveRules("INSERT INTO t VALUES ('a--b'); DROP TABLE t;")).toContain(
      "DROP TABLE",
    );
    expect(destructiveRules("-- don't\nDROP TABLE t;")).toContain("DROP TABLE");
    expect(executedSql("SELECT E'it''s \\' quoted'; TRUNCATE t;")).toContain("TRUNCATE");
  });

  it("lets a file through when its first line says it means it", () => {
    expect(isMarkedDestructive("-- bhw:destructive\nDROP TABLE t;")).toBe(true);
    expect(isMarkedDestructive("SELECT 1;\n-- bhw:destructive")).toBe(false);
  });

  it("reads only files above the floor, and names each offender", () => {
    const dir = tempDir({
      [`${FLOOR}_old_history.sql`]: "DROP TABLE everything;",
      "29990101000000_new_additive.sql": "CREATE TABLE t (id int);",
      "29990101000001_new_destructive.sql": "TRUNCATE t;",
      "29990101000002_new_marked.sql": "-- bhw:destructive\nTRUNCATE t;",
    });
    try {
      expect(lintMigrations(dir)).toEqual([
        { file: "29990101000001_new_destructive.sql", rules: ["TRUNCATE"] },
      ]);
    } finally {
      rmSync(dir, { recursive: true });
    }
  });

  it("passes on the migrations committed today", () => {
    expect(lintMigrations()).toEqual([]);
  });
});

describe("mint-jwt", () => {
  const secret = "a".repeat(40);

  it("signs HS256 tokens PostgREST can verify with the shared secret", () => {
    const token = mintRoleToken("anon", secret, { now: Date.UTC(2026, 9, 27) });
    const [header, payload, signature] = token.split(".");
    expect(JSON.parse(Buffer.from(header, "base64url").toString())).toEqual({
      alg: "HS256",
      typ: "JWT",
    });
    const claims = JSON.parse(Buffer.from(payload, "base64url").toString());
    expect(claims.role).toBe("anon");
    expect(claims.exp - claims.iat).toBe(10 * 365 * 24 * 60 * 60);
    const expected = createHmac("sha256", secret)
      .update(`${header}.${payload}`)
      .digest("base64url");
    expect(signature).toBe(expected);
  });

  it("mints only the two roles PostgREST switches to, with a secret PostgREST accepts", () => {
    expect(() => mintRoleToken("authenticated", secret)).toThrow(/role must be/);
    expect(() => mintRoleToken("postgres", secret)).toThrow(/role must be/);
    expect(() => mintRoleToken("anon", "short")).toThrow(/32 characters/);
    expect(signJwt({ role: "x" }, secret).split(".")).toHaveLength(3);
  });
});

describe("migrate (no database)", () => {
  it("reads <version>_<name>.sql oldest first and refuses a misnamed file", () => {
    const dir = tempDir({ "2_b.sql": "SELECT 2;", "1_a.sql": "SELECT 1;", "notes.txt": "" });
    try {
      expect(readMigrations(dir).map((m) => [m.version, m.name])).toEqual([
        ["1", "a"],
        ["2", "b"],
      ]);
      writeFileSync(join(dir, "oops.sql"), "");
      expect(() => readMigrations(dir)).toThrow(/<version>_<name>\.sql/);
    } finally {
      rmSync(dir, { recursive: true });
    }
  });

  it("finds pending files by version", () => {
    const files = [{ version: "1" }, { version: "2" }, { version: "3" }] as { version: string }[];
    expect(
      pendingMigrations(files, new Set(["1", "3"])).map((m: { version: string }) => m.version),
    ).toEqual(["2"]);
  });
});

describe("make-seed (no database)", () => {
  it("denies by default: only dim_, agg_ and fact_ tables enter the seed, and fact_ only as synthetic rows", () => {
    expect(seedRule("dim_geo")).toBe("all");
    expect(seedRule("agg_demographics")).toBe("sample");
    expect(seedRule("fact_bhw_raw")).toBe("synthetic");
    for (const table of [
      "feedback",
      "usage_events",
      "admin_users",
      "district_correction",
      "ai_narrative_cache",
    ]) {
      expect(seedRule(table)).toBe("none");
    }
  });

  it("calls a column categorical only when its values are few and shared by many rows", () => {
    expect(isCategorical(2, 270_917)).toBe(true);
    expect(isCategorical(30, 30)).toBe(false); // a small table of names
    expect(isCategorical(51, 1_000_000)).toBe(false);
  });

  it("orders tables after the tables they point at", () => {
    const order = dependencyOrder(
      ["fact_x", "dim_geo", "dim_region"],
      [
        { table: "fact_x", column: "geo", refTable: "dim_geo", refColumn: "code" },
        { table: "dim_geo", column: "region", refTable: "dim_region", refColumn: "code" },
      ],
    );
    expect(order).toEqual(["dim_region", "dim_geo", "fact_x"]);
  });

  it("invents free-text values and never reuses one", () => {
    const column = {
      column_name: "full_name",
      udt_name: "text",
      is_nullable: "NO",
      character_maximum_length: null,
    };
    expect(syntheticLiteral("fact_bhw_raw", column, 0)).toBe("'synthetic full_name 1'");
    expect(syntheticLiteral("fact_bhw_raw", column, 1)).toBe("'synthetic full_name 2'");
  });

  it("keeps numbers and dates inside the column's real range", () => {
    const age = {
      column_name: "age",
      udt_name: "int4",
      is_nullable: "NO",
      character_maximum_length: null,
    };
    for (let i = 0; i < 50; i += 1) {
      const value = Number(
        syntheticLiteral("fact_bhw_raw", age, i, { min: "18", max: "70" }).replaceAll("'", ""),
      );
      expect(value).toBeGreaterThanOrEqual(18);
      expect(value).toBeLessThanOrEqual(70);
    }
  });

  it("refuses a NOT NULL foreign key it cannot satisfy, rather than writing a seed that will not load", () => {
    const geo = {
      column_name: "geo_code",
      udt_name: "text",
      is_nullable: "NO",
      character_maximum_length: null,
    };
    expect(() => syntheticLiteral("fact_x", geo, 0, { refValues: [] })).toThrow(
      /points at has none/,
    );
  });
});

// The database half runs wherever a disposable PostgreSQL is configured: CI's service container, or
// the local stack (docker compose up, then BHW_TEST_DATABASE_URL=postgres://postgres:bhw-local@127.0.0.1:5442/bhw_connect).
const DATABASE = process.env.BHW_TEST_DATABASE_URL;

describe.skipIf(!DATABASE)("against a real PostgreSQL", () => {
  const client = new pg.Client({ connectionString: DATABASE });
  const prefix = `m192t_${process.pid}`;

  beforeAll(async () => {
    await client.connect();
  });

  afterAll(async () => {
    await client.query(`DROP TABLE IF EXISTS ${prefix}_a, ${prefix}_b`);
    await client.query(
      "DELETE FROM supabase_migrations.schema_migrations WHERE version LIKE '2999%'",
    );
    await client.query(`DROP SCHEMA IF EXISTS ${prefix}_src CASCADE`);
    await client.query(`DROP SCHEMA IF EXISTS ${prefix}_dst CASCADE`);
    await client.end();
  });

  it("applies pending files once, in order, records them, and reports the rest", async () => {
    const dir = tempDir({
      "29990101000001_a.sql": `CREATE TABLE ${prefix}_a (id int PRIMARY KEY); INSERT INTO ${prefix}_a VALUES (1), (2);`,
      "29990101000002_b.sql": `CREATE TABLE ${prefix}_b (id int PRIMARY KEY);`,
    });
    try {
      const first = await migrate(client, { dir });
      expect(first.applied.map((m: { file: string }) => m.file)).toEqual([
        "29990101000001_a.sql",
        "29990101000002_b.sql",
      ]);
      const again = await migrate(client, { dir });
      expect(again.applied).toEqual([]);

      writeFileSync(join(dir, "29990101000003_c.sql"), "SELECT 1;");
      const check = await migrate(client, { dir, dryRun: true });
      expect(check.pending.map((m: { file: string }) => m.file)).toEqual(["29990101000003_c.sql"]);

      expect(((await census(client)) as Record<string, number>)[`${prefix}_a`]).toBe(2);
    } finally {
      rmSync(dir, { recursive: true });
    }
  });

  it("rolls a failing file back and leaves it pending", async () => {
    const dir = tempDir({
      "29990101000009_broken.sql": `CREATE TABLE ${prefix}_x (id int); SELECT no_such_column FROM ${prefix}_x;`,
    });
    try {
      await expect(migrate(client, { dir })).rejects.toThrow(/rolled back/);
      const { rows } = await client.query("SELECT to_regclass($1) AS t", [`${prefix}_x`]);
      expect(rows[0].t).toBeNull();
      expect((await migrate(client, { dir, dryRun: true })).pending).toHaveLength(1);
    } finally {
      rmSync(dir, { recursive: true });
    }
  });

  it("writes a seed that loads, copies lookups, and carries no real person", async () => {
    const src = `${prefix}_src`;
    const dst = `${prefix}_dst`;
    const ddl = (schema: string) => `
      CREATE SCHEMA ${schema};
      CREATE TABLE ${schema}.dim_geo (code text PRIMARY KEY, name text NOT NULL);
      CREATE TABLE ${schema}.agg_counts (code text PRIMARY KEY REFERENCES ${schema}.dim_geo, n int NOT NULL);
      CREATE TABLE ${schema}.fact_people (
        id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
        geo_code text NOT NULL REFERENCES ${schema}.dim_geo,
        full_name text NOT NULL,
        sex text NOT NULL CHECK (sex IN ('F', 'M')),
        age int NOT NULL CHECK (age BETWEEN 18 AND 99)
      );
      CREATE TABLE ${schema}.feedback (id int PRIMARY KEY, email text);`;
    await client.query(ddl(src));
    await client.query(ddl(dst));
    await client.query(`INSERT INTO ${src}.dim_geo VALUES ('01', 'Ilocos'), ('02', 'Cagayan')`);
    await client.query(`INSERT INTO ${src}.agg_counts VALUES ('01', 5), ('02', 7)`);
    await client.query(`INSERT INTO ${src}.feedback VALUES (1, 'someone@example.org')`);
    await client.query(
      `INSERT INTO ${src}.fact_people (geo_code, full_name, sex, age)
       SELECT CASE WHEN g % 2 = 0 THEN '01' ELSE '02' END, 'Real Person ' || g, CASE WHEN g % 3 = 0 THEN 'F' ELSE 'M' END, 20 + g % 40
         FROM generate_series(1, 300) AS g`,
    );

    const sql = await makeSeed(client, { schema: src, targetSchema: dst, rows: 1, factRows: 25 });
    expect(sql).not.toContain("Real Person");
    expect(sql).not.toContain("someone@example.org");
    await client.query(sql);

    const count = async (table: string) =>
      Number((await client.query(`SELECT count(*) AS n FROM ${dst}.${table}`)).rows[0].n);
    expect(await count("dim_geo")).toBe(2);
    expect(await count("agg_counts")).toBe(1);
    expect(await count("fact_people")).toBe(25);
    expect(await count("feedback")).toBe(0);
    const { rows } = await client.query(`SELECT DISTINCT sex FROM ${dst}.fact_people ORDER BY 1`);
    expect(rows.map((r) => r.sex)).toEqual(["F", "M"]);
  });
});
