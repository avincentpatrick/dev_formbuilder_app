import { afterAll, beforeAll, describe, expect, it } from "vitest";
import pg from "pg";
import { readFileSync } from "node:fs";
import { randomUUID } from "node:crypto";
import { loadDev } from "./load-dev.mjs";
import { migrate } from "./migrate.mjs";

const database = process.env.BHW_TEST_DATABASE_URL;
const tables = [
  "document_catalog",
  "document_file",
  "document_version",
  "document_extraction",
  "document_job",
  "document_upload_attempt",
  "document_audit_event",
];

// Run only on the disposable development database, never the deployment.
describe.skipIf(!database)("document library PostgreSQL contract", () => {
  let client: pg.Client;
  const admin = new pg.Client({ connectionString: database });
  const testDatabase = `bhw_docs_${randomUUID().replaceAll("-", "")}`;
  beforeAll(async () => {
    await admin.connect();
    await admin.query(`CREATE DATABASE ${testDatabase}`);
    const connection = new URL(database!);
    connection.pathname = `/${testDatabase}`;
    client = new pg.Client({ connectionString: connection.toString() });
    await client.connect();
    await client.query(readFileSync(new URL("./bootstrap-roles.sql", import.meta.url), "utf8"));
    await loadDev(client);
  }, 30000);
  afterAll(async () => {
    await client?.end();
    await admin.query(`DROP DATABASE IF EXISTS ${testDatabase}`);
    await admin.end();
  });

  it("has applied the migration once and keeps existing citation records resolvable", async () => {
    await client.query("begin");
    try {
      const source = (
        await client.query(`insert into doc_source
        (key,title,source_path,source_sha256,page_count,char_count,extractor,status)
        values ('synthetic-legacy-citation','Synthetic legacy source','synthetic.pdf',repeat('b',64),1,4,'test','approved') returning doc_id`)
      ).rows[0].doc_id;
      const chunk = (
        await client.query(
          `insert into doc_chunk
        (doc_id,chunk_index,page_from,page_to,char_start,char_end,content,content_sha256)
        values ($1,0,1,1,0,5,'café🌿',repeat('c',64)) returning chunk_id`,
          [source],
        )
      ).rows[0].chunk_id;
      expect((await migrate(client)).applied).toEqual([]);
      const citation = (
        await client.query(
          `select s.key,c.content,c.char_start,c.char_end
        from doc_chunk c join doc_source s using(doc_id) where c.chunk_id=$1`,
          [chunk],
        )
      ).rows[0];
      expect(Array.from(citation.content).length).toBe(citation.char_end - citation.char_start);
      expect(citation).toEqual({
        key: "synthetic-legacy-citation",
        content: "café🌿",
        char_start: 0,
        char_end: 5,
      });
    } finally {
      await client.query("rollback");
    }
  });

  it("enables RLS without public policies on every library table", async () => {
    const { rows } = await client.query(
      "select relname, relrowsecurity from pg_class where relnamespace = 'public'::regnamespace and relname = any($1)",
      [tables],
    );
    expect(rows).toHaveLength(tables.length);
    expect(rows.every((row) => row.relrowsecurity)).toBe(true);
    expect(
      (
        await client.query(
          "select * from pg_policies where schemaname='public' and tablename=any($1)",
          [tables],
        )
      ).rows,
    ).toEqual([]);
  });

  for (const role of ["anon", "authenticated"]) {
    it(`denies direct reads and writes for ${role}`, async () => {
      await client.query("begin");
      try {
        await client.query(`set local role ${role}`);
        for (const table of tables) {
          for (const sql of [
            `select * from ${table}`,
            `insert into ${table} default values`,
            `update ${table} set created_at=created_at`,
            `delete from ${table}`,
          ]) {
            const query =
              table === "document_version" ? sql.replaceAll("created_at", "uploaded_at") : sql;
            await client.query("savepoint denied_request");
            await expect(client.query(query)).rejects.toMatchObject({ code: "42501" });
            await client.query("rollback to savepoint denied_request");
          }
        }
      } finally {
        await client.query("rollback");
      }
    });
  }

  it("keeps rows hidden by RLS even if SELECT is accidentally granted", async () => {
    await client.query("begin");
    try {
      await client.query(
        "insert into document_catalog(title,created_by) values ('Synthetic private policy',gen_random_uuid())",
      );
      await client.query(`grant select on ${tables.join(",")} to anon, authenticated`);
      for (const role of ["anon", "authenticated"]) {
        await client.query(`set local role ${role}`);
        expect((await client.query("select * from document_catalog")).rows).toEqual([]);
        await client.query("reset role");
      }
    } finally {
      await client.query("rollback");
    }
  });

  it("allows service writes, duplicate tracking, and separate extraction revisions", async () => {
    await client.query("begin");
    try {
      await client.query("set local role service_role");
      const actor = "11111111-1111-4111-8111-111111111111";
      const catalog = (
        await client.query(
          "insert into document_catalog(title,created_by) values ('Synthetic policy',$1) returning id",
          [actor],
        )
      ).rows[0].id;
      const hash = "a".repeat(64);
      await client.query(
        "insert into document_file values ($1,'11111111-1111-4111-8111-111111111111.pdf',100,now())",
        [hash],
      );
      const version = (
        await client.query(
          "insert into document_version(document_id,file_sha256,uploaded_by) values ($1,$2,$3) returning id",
          [catalog, hash, actor],
        )
      ).rows[0].id;
      for (const revision of [1, 2]) {
        await client.query(
          "insert into document_extraction(version_id,revision,canonical_text,page_map,page_count,parser_version) values ($1,$2,'Synthetic café policy','[]',1,'test')",
          [version, revision],
        );
      }
      expect(
        (
          await client.query(
            "select count(*)::int as n from document_extraction where version_id=$1",
            [version],
          )
        ).rows[0].n,
      ).toBe(2);
      await client.query(
        "insert into document_upload_attempt(uploaded_by,original_filename,outcome,duplicate_version_id) values ($1,'synthetic.pdf','duplicate',$2)",
        [actor, version],
      );
      await client.query(
        "insert into document_audit_event(actor_id,document_id,event) values ($1,$2,'synthetic_test')",
        [actor, catalog],
      );
      const state = (
        await client.query(
          "select availability,embedding_status from document_version where id=$1",
          [version],
        )
      ).rows[0];
      expect(state).toEqual({ availability: "unavailable", embedding_status: "pending" });
      await client.query("savepoint invalid_extraction");
      await expect(
        client.query(
          "insert into document_extraction(version_id,revision,canonical_text,page_map,page_count,parser_version) values ($1,3,'','[]',301,'test')",
          [version],
        ),
      ).rejects.toMatchObject({ code: "23514" });
      await client.query("rollback to savepoint invalid_extraction");
    } finally {
      await client.query("rollback");
    }
  });
});
