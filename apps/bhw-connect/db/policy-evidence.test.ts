import { afterAll, beforeAll, describe, expect, it } from "vitest";
import pg from "pg";
import { readFileSync } from "node:fs";
import { randomUUID } from "node:crypto";
import { loadDev } from "./load-dev.mjs";
const database = process.env.BHW_TEST_DATABASE_URL;
describe.skipIf(!database)("immutable policy evidence in PostgreSQL", () => {
  const admin = new pg.Client({ connectionString: database });
  let db: pg.Client;
  const name = `bhw_policy_${randomUUID().replaceAll("-", "")}`,
    actor = randomUUID(),
    editor = randomUUID();
  const a = { version: randomUUID(), extraction: randomUUID(), chunk: 0, key: "" },
    b = { version: randomUUID(), extraction: randomUUID(), chunk: 0, key: "" },
    old = { version: randomUUID(), extraction: randomUUID(), chunk: 0, key: "" };
  beforeAll(async () => {
    await admin.connect();
    await admin.query(`create database ${name}`);
    const url = new URL(database!);
    url.pathname = `/${name}`;
    db = new pg.Client({ connectionString: url.toString() });
    await db.connect();
    // Roles are cluster-wide even though each suite has its own database.
    await admin.query("select pg_advisory_lock(761004)");
    try {
      await db.query(readFileSync(new URL("./bootstrap-roles.sql", import.meta.url), "utf8"));
    } finally {
      await admin.query("select pg_advisory_unlock(761004)");
    }
    await loadDev(db);
    await db.query("insert into admin_users values($1,'admin'),($2,'editor')", [actor, editor]);
    for (const [index, fixture] of [a, b, old].entries()) {
      const doc = randomUUID(),
        hash = String(index + 1).repeat(64);
      fixture.key = `upload:${fixture.extraction}`;
      const text =
        index === 0
          ? "Clinics must keep referral records for 20 days. " +
            "Synthetic appendix text. ".repeat(80) +
            "Decisive annex: preserve signed referrals."
          : index === 1
            ? "Clinics must keep referral records for 30 days."
            : "Archived policy: keep referral records for 10 days.";
      await db.query(
        "insert into document_catalog(id,title,issuer,collection_key,created_by) values($1,$2,'Synthetic agency','test-policies',$3)",
        [
          doc,
          index === 0
            ? "Referral policy Alpha"
            : index === 1
              ? "Referral policy Beta"
              : "Historical policy",
          actor,
        ],
      );
      await db.query("insert into document_file(sha256,storage_key,byte_count) values($1,$2,100)", [
        hash,
        `${randomUUID()}.pdf`,
      ]);
      await db.query(
        "insert into document_version(id,document_id,file_sha256,uploaded_by,availability,archived,publication_date) values($1,$2,$3,$4,'available',$5,'2025-01-01')",
        [fixture.version, doc, hash, actor, index === 2],
      );
      const source = (
        await db.query(
          "insert into doc_source(key,title,source_path,source_sha256,page_count,char_count,extractor,status) values($1,$2,'synthetic.pdf',$3,1,$4,'synthetic',$5) returning doc_id",
          [
            fixture.key,
            index === 0
              ? "Referral policy Alpha"
              : index === 1
                ? "Referral policy Beta"
                : "Historical policy",
            hash,
            Array.from(text).length,
            index === 2 ? "rejected" : "approved",
          ],
        )
      ).rows[0].doc_id;
      await db.query(
        "insert into document_extraction(id,version_id,revision,canonical_text,page_map,page_count,parser_version,source_id,activation_origin) values($1,$2,1,$3,'[]',1,'synthetic',$4,'successful_upload')",
        [fixture.extraction, fixture.version, text, source],
      );
      await db.query("update document_version set current_extraction_id=$1 where id=$2", [
        fixture.extraction,
        fixture.version,
      ]);
      fixture.chunk = (
        await db.query(
          "insert into doc_chunk(doc_id,chunk_index,page_from,page_to,char_start,char_end,content,content_sha256) values($1,0,1,1,0,$2,$3,$4) returning chunk_id",
          [source, Array.from(text).length, text, hash],
        )
      ).rows[0].chunk_id;
    }
  }, 30000);
  afterAll(async () => {
    await db?.end();
    await admin.query(`drop database if exists ${name}`);
    await admin.end();
  });
  const call = async (operation: string, payload: Record<string, unknown>, who = actor) =>
    (
      await db.query("select policy_evidence_api($1,$2,$3) as data", [
        who,
        operation,
        JSON.stringify(payload),
      ])
    ).rows[0].data;
  it("excludes archived versions by default and permits only explicit historical selections", async () => {
    const active = await call("select", { mode: "active" });
    expect(active.map((r: { version_id: string }) => r.version_id).sort()).toEqual(
      [a.version, b.version].sort(),
    );
    await expect(call("select", { mode: "selected", versions: [old.version] })).rejects.toThrow();
    expect(
      (await call("select", { mode: "selected", versions: [old.version], historical: true }))[0]
        .extraction_id,
    ).toBe(old.extraction);
    expect(
      (await call("select", { mode: "selected", revisions: [a.extraction] }))[0].version_id,
    ).toBe(a.version);
  });
  it("binds search and full passages to the selected identity even with colliding page numbers", async () => {
    const rows = await call("search", { keys: [a.key], query: "referral" });
    expect(rows).toHaveLength(1);
    expect(rows[0].version_id).toBe(a.version);
    expect(rows[0].page).toBe(1);
    const full = await call("read", { keys: [a.key], chunk: a.chunk, neighbors: 2 });
    expect(full[0].content).toContain("Decisive annex");
    expect(full[0].content.length).toBeGreaterThan(1200);
    await expect(call("read", { keys: [a.key], chunk: b.chunk })).rejects.toThrow(
      "outside selected",
    );
  });
  it("honors archive changes at retrieval time and still reads explicit historical evidence", async () => {
    await db.query("begin");
    try {
      await db.query("update document_version set archived=true where id=$1", [a.version]);
      expect(await call("search", { keys: [a.key], query: "referral" })).toEqual([]);
      expect(
        (await call("read", { keys: [a.key], chunk: a.chunk, historical: true }))[0].version_id,
      ).toBe(a.version);
    } finally {
      await db.query("rollback");
    }
  });
  it("denies editors and anonymous execution without exposing policy text", async () => {
    await expect(call("catalog", {}, editor)).rejects.toMatchObject({ code: "42501" });
    await db.query("begin");
    try {
      await db.query("set local role anon");
      await expect(call("catalog", {})).rejects.toMatchObject({ code: "42501" });
    } finally {
      await db.query("rollback");
    }
  });
});
