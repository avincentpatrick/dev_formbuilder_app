import { afterAll, beforeAll, describe, it, expect } from "vitest";
import { randomUUID, createHash } from "node:crypto";
import { readFileSync } from "node:fs";
import pg from "pg";
import { loadDev } from "./load-dev.mjs";
const database = process.env.BHW_TEST_DATABASE_URL;
describe.skipIf(!database)("full policy review PostgreSQL contracts", () => {
  const admin = new pg.Client({ connectionString: database });
  let db: pg.Client;
  const name = `bhw_review_${randomUUID().replaceAll("-", "")}`,
    actor = randomUUID(),
    other = randomUUID(),
    editor = randomUUID();
  const a = { version: randomUUID(), extraction: randomUUID() },
    b = { version: randomUUID(), extraction: randomUUID() },
    empty = { version: randomUUID(), extraction: randomUUID() };
  const decisive = "Any exception requires the original signed referral record.";
  const longText = "General administration appendix. ".repeat(160) + decisive;
  const hash = (s: string) => createHash("sha256").update(s).digest("hex");
  async function fixture(f: typeof a, text: string, warnings: unknown[] = []) {
    const doc = randomUUID(),
      sha = hash(f.version);
    await db.query(
      "insert into document_catalog(id,title,issuer,collection_key,created_by) values($1,$2,'Synthetic agency','review-test',$3)",
      [doc, `Synthetic ${f.version}`, actor],
    );
    await db.query("insert into document_file(sha256,storage_key,byte_count) values($1,$2,100)", [
      sha,
      `${randomUUID()}.pdf`,
    ]);
    await db.query(
      "insert into document_version(id,document_id,file_sha256,uploaded_by,availability) values($1,$2,$3,$4,'available')",
      [f.version, doc, sha, actor],
    );
    const source = (
      await db.query(
        "insert into doc_source(key,title,source_path,source_sha256,page_count,char_count,extractor,status) values($1,'Synthetic policy','synthetic.pdf',$2,1,$3,'synthetic','approved') returning doc_id",
        [`upload:${f.extraction}`, sha, Array.from(text).length],
      )
    ).rows[0].doc_id;
    await db.query(
      "insert into document_extraction(id,version_id,revision,canonical_text,page_map,page_count,parser_version,source_id,activation_origin,warnings) values($1,$2,1,$3,$4,1,'synthetic',$5,'successful_upload',$6)",
      [
        f.extraction,
        f.version,
        text,
        JSON.stringify([{ page: 1, char_start: 0, char_end: Array.from(text).length }]),
        text ? source : null,
        JSON.stringify(warnings),
      ],
    );
    await db.query("update document_version set current_extraction_id=$1 where id=$2", [
      f.extraction,
      f.version,
    ]);
  }
  beforeAll(async () => {
    await admin.connect();
    await admin.query(`create database ${name}`);
    const url = new URL(database!);
    url.pathname = `/${name}`;
    db = new pg.Client({ connectionString: url.toString() });
    await db.connect();
    await admin.query("select pg_advisory_lock(761004)");
    try {
      await db.query(readFileSync(new URL("./bootstrap-roles.sql", import.meta.url), "utf8"));
    } finally {
      await admin.query("select pg_advisory_unlock(761004)");
    }
    await loadDev(db);
    await db.query("insert into admin_users values($1,'admin'),($2,'admin'),($3,'editor')", [
      actor,
      other,
      editor,
    ]);
    await db.query(
      "insert into analysis_users(user_id,approved,updated_by) values($1,true,$1),($2,true,$2),($3,true,$3)",
      [actor, other, editor],
    );
    await fixture(a, longText);
    await fixture(b, "Clinics must retain records for 30 days.");
    await fixture(empty, "", [{ page: 1, message: "unreadable" }]);
  }, 30000);
  afterAll(async () => {
    await db?.end();
    await admin.query(`drop database if exists ${name}`);
    await admin.end();
  });
  const api = async (op: string, payload: Record<string, unknown> = {}, who = actor) =>
    (
      await db.query("select policy_review_api($1,$2,$3) as data", [
        who,
        op,
        JSON.stringify(payload),
      ])
    ).rows[0].data;
  const worker = async (
    op: string,
    s?: { id: number; lease: string },
    payload: Record<string, unknown> = {},
    run?: string,
  ) =>
    (
      await db.query("select policy_review_worker($1,$2,$3,$4,$5) as data", [
        op,
        s?.id,
        s?.lease,
        run,
        JSON.stringify(payload),
      ])
    ).rows[0].data;
  const create = async (
    versions = [a.version, b.version],
    budget = 200,
    extra: Record<string, unknown> = {},
  ) =>
    (
      await api("create", {
        requestKey: randomUUID(),
        question: "What must clinics retain?",
        sources: { mode: "selected", versions, revisions: [], historical: false, online: false },
        datasets: { definitions: [], exactRowsFrozen: false },
        budget,
        ...extra,
      })
    ).id as string;
  const finish = (s: { id: number; lease: string }, findings: unknown[] = []) =>
    worker("finish", s, {
      outcome: findings.length ? "examined" : "no_finding",
      result: { relevant: !!findings.length, findings, limitations: [] },
      evidence: { datasets: [] },
    });
  it("freezes all canonical sections, including a decisive low-similarity appendix outside ordinary top hits", async () => {
    const run = await create([a.version]);
    const report = await api("get", { run });
    expect(report.coverage.total).toBe(Math.ceil(Array.from(longText).length / 2400));
    const read = [];
    let decisiveSeen = false;
    for (let i = 0; i < report.coverage.total; i++) {
      const c = await worker("claim", undefined, {}, run);
      expect(c.integrity_ok).toBe(true);
      read.push(c.text);
      if (c.text.includes(decisive)) decisiveSeen = true;
      await finish(c.section);
    }
    expect(read.join("")).toBe(longText);
    expect(decisiveSeen).toBe(true);
    expect((await api("get", { run })).complete).toBe(true);
  });
  it("retains comparison candidates across every version, never certifies legal conflict", async () => {
    const run = await create([a.version, b.version]);
    for (let i = 0; i < 4; i++) {
      const c = await worker("claim", undefined, {}, run);
      if (!c) break;
      await finish(c.section, [
        {
          kind: "policy_requirement",
          topic: "retention",
          text: "Synthetic requirement",
          quote: c.text.slice(0, 30),
          comparisonValue: c.document.version_id === a.version ? "20 days" : "30 days",
        },
      ]);
    }
    const report = await api("get", { run });
    expect(report.groups[0]).toMatchObject({
      topic: "retention",
      versions: 2,
      comparison_needed: true,
    });
    expect(report.complete).toBe(true);
  });
  it("stops on budget and resumes only unexamined sections", async () => {
    const run = await create([a.version, b.version], 1);
    const c = await worker("claim", undefined, {}, run);
    // A concurrent dispatch cannot invalidate the in-flight attempt at the budget boundary.
    expect(await worker("claim", undefined, {}, run)).toBeNull();
    expect((await api("get", { run })).run.status).toBe("running");
    await finish(c.section);
    let r = await api("get", { run });
    expect(r.run.status).toBe("partial");
    expect(r.coverage.no_finding).toBe(1);
    await api("resume", { run, extraBudget: 20, retryFailed: true });
    const next = await worker("claim", undefined, {}, run);
    expect(next.section.id).not.toBe(c.section.id);
    await finish(next.section);
    await api("cancel", { run });
    r = await api("get", { run });
    expect(r.coverage.no_finding).toBe(2);
    expect(r.complete).toBe(false);
  });
  it("cancels and rejects stale completions without repeating completed work", async () => {
    const run = await create([a.version]);
    const first = await worker("claim", undefined, {}, run);
    await finish(first.section);
    const second = await worker("claim", undefined, {}, run);
    await api("cancel", { run });
    expect(await finish(second.section)).toEqual({ active: false });
    await api("resume", { run, extraBudget: 5 });
    const next = await worker("claim", undefined, {}, run);
    expect(next.section.id).toBe(second.section.id);
    expect(next.section.lease).not.toBe(second.section.lease);
    await api("cancel", { run });
  });
  it("recovers expired leases and rejects former-worker writes", async () => {
    const run = await create([b.version]);
    const old = await worker("claim", undefined, {}, run);
    await db.query(
      "update policy_review_section set lease_until=now()-interval '1 second' where id=$1",
      [old.section.id],
    );
    const fresh = await worker("claim", undefined, {}, run);
    expect(fresh.section.attempts).toBe(2);
    expect(await finish(old.section)).toEqual({ active: false });
    await finish(fresh.section);
    expect((await api("get", { run })).complete).toBe(true);
  });
  it("distinguishes no-finding, unreadable and excluded inventories", async () => {
    const run = await create([b.version, empty.version]);
    const c = await worker("claim", undefined, {}, run);
    await finish(c.section);
    const r = await api("get", { run });
    expect(r.coverage).toMatchObject({ no_finding: 1, unreadable: 0 });
    expect(r.documents.some((d: { exclusion: string }) => d.exclusion === "empty_extraction")).toBe(
      true,
    );
    expect(r.complete).toBe(false);
    expect(r.run.status).toBe("partial");
  });
  it("limits retries, persists failure separately and permits explicit retry", async () => {
    const run = await create([b.version]);
    for (let i = 0; i < 3; i++) {
      const c = await worker("claim", undefined, {}, run);
      await worker("fail", c.section);
      await db.query("update policy_review_section set retry_at=null where run_id=$1", [run]);
    }
    let r = await api("get", { run });
    expect(r.coverage.failed).toBe(1);
    expect(r.coverage.no_finding).toBe(0);
    expect(r.run.status).toBe("partial");
    await api("resume", { run, extraBudget: 2, retryFailed: true });
    const c = await worker("claim", undefined, {}, run);
    expect(c.section.attempts).toBe(4);
    await finish(c.section);
    r = await api("get", { run });
    expect(r.complete).toBe(true);
  });
  it("does not add late uploads or change archived-at-start exclusions", async () => {
    await db.query("begin");
    try {
      await db.query("update document_version set archived=true where id=$1", [b.version]);
      const run = await create(undefined, 200, {
        sources: { mode: "active", collection: "review-test" },
      });
      const count = (await api("get", { run })).documents.length;
      await fixture(
        { version: randomUUID(), extraction: randomUUID() },
        "New policy after snapshot.",
      );
      await db.query("update document_version set archived=false where id=$1", [b.version]);
      const r = await api("get", { run });
      expect(r.documents.length).toBe(count);
      expect(
        r.documents.find((d: { version_id: string }) => d.version_id === b.version).exclusion,
      ).toBe("archived");
    } finally {
      await db.query("rollback");
    }
  });
  it("fails closed on invalid page maps and detects mutated canonical text", async () => {
    await db.query("begin");
    try {
      await db.query("update document_extraction set page_map='[]' where id=$1", [a.extraction]);
      const run = await create([a.version]);
      expect((await api("get", { run })).documents[0].exclusion).toBe("invalid_page_map");
      const valid = await create([b.version]);
      await db.query(
        "update document_extraction set canonical_text='Changed canonical evidence' where id=$1",
        [b.extraction],
      );
      const c = await worker("claim", undefined, {}, valid);
      expect(c.integrity_ok).toBe(false);
      expect((await api("section", { run: valid, section: c.section.id })).integrity_ok).toBe(
        false,
      );
    } finally {
      await db.query("rollback");
    }
  });
  it("requires approved strict admin and run ownership, including reads and revoked workers", async () => {
    const run = await create([b.version]);
    await expect(api("get", { run }, other)).rejects.toMatchObject({ code: "42501" });
    await expect(api("list", {}, editor)).rejects.toMatchObject({ code: "42501" });
    await db.query("begin");
    try {
      const c = await worker("claim", undefined, {}, run);
      await db.query("update analysis_users set approved=false where user_id=$1", [actor]);
      expect(await finish(c.section)).toEqual({ active: false });
      expect(await worker("claim", undefined, {}, run)).toBeNull();
      await expect(api("get", { run })).rejects.toMatchObject({ code: "42501" });
    } finally {
      await db.query("rollback");
    }
    await api("cancel", { run });
  });
  it("accounts for unreadable physical pages with Unicode code-point bounds", async () => {
    await db.query("begin");
    try {
      const f = { version: randomUUID(), extraction: randomUUID() };
      const text = "\fMust keep 🧾 receipts.";
      await fixture(f, text, [{ page: 1, code: "blank_or_unreadable_page" }]);
      await db.query("update document_extraction set page_count=2,page_map=$1 where id=$2", [
        JSON.stringify([
          { page: 1, char_start: 0, char_end: 0 },
          { page: 2, char_start: 1, char_end: Array.from(text).length },
        ]),
        f.extraction,
      ]);
      const run = await create([f.version]);
      const c = await worker("claim", undefined, {}, run);
      expect(c.text).toBe("Must keep 🧾 receipts.");
      expect(c.section.page).toBe(2);
      expect(c.integrity_ok).toBe(true);
      await finish(c.section);
      const r = await api("get", { run });
      expect(r.coverage).toMatchObject({ unreadable: 1, no_finding: 1 });
      expect(r.complete).toBe(false);
    } finally {
      await db.query("rollback");
    }
  });
  it("records processing and failed versions without pretending their absent text was examined", async () => {
    await db.query("begin");
    try {
      await db.query("update document_version set current_extraction_id=null where id=any($1)", [
        [a.version, b.version],
      ]);
      await db.query(
        "insert into document_job(version_id,requested_by,stage) values($1,$2,'queued')",
        [a.version, actor],
      );
      const run = await create();
      const r = await api("get", { run });
      expect(r.documents.map((d: { exclusion: string }) => d.exclusion).sort()).toEqual([
        "failed_or_no_extracted_text",
        "processing",
      ]);
      expect(r.coverage.total).toBe(0);
      expect(r.complete).toBe(false);
    } finally {
      await db.query("rollback");
    }
  });
  it("makes creation idempotent and prevents browser-role RPC/table access", async () => {
    const key = randomUUID(),
      one = await create([b.version], 10, { requestKey: key }),
      two = await create([a.version], 10, { requestKey: key });
    expect(one).toBe(two);
    await db.query("begin");
    try {
      await db.query("set local role authenticated");
      await expect(api("get", { run: one })).rejects.toMatchObject({ code: "42501" });
    } finally {
      await db.query("rollback");
    }
    await db.query("begin");
    try {
      await db.query("set local role anon");
      await expect(db.query("select * from policy_review_section")).rejects.toMatchObject({
        code: "42501",
      });
    } finally {
      await db.query("rollback");
    }
  });
});
