import { randomUUID, createHash } from "node:crypto";
import { readFileSync } from "node:fs";
import pg from "pg";
import { beforeAll, afterAll, describe, it, expect } from "vitest";
const database = process.env.BHW_TEST_DATABASE_URL;
describe.skipIf(!database)("project-scoped sources against PostgreSQL", () => {
  const c = new pg.Client({ connectionString: database });
  const schema = `sources_test_${process.pid}`;
  const owner = randomUUID(),
    editor = randomUUID(),
    viewer = randomUUID(),
    outsider = randomUUID();
  const project = async (
    actor: string,
    op: string,
    id?: string,
    payload: Record<string, unknown> = {},
  ) =>
    (
      await c.query(`select ${schema}.analysis_project_api($1,$2,$3,$4) as data`, [
        actor,
        op,
        id ?? null,
        payload,
      ])
    ).rows[0].data;
  const source = async (
    actor: string,
    id: string,
    op: string,
    payload: Record<string, unknown> = {},
  ) =>
    (
      await c.query(`select ${schema}.analysis_source_api($1,$2,$3,$4) as data`, [
        actor,
        id,
        op,
        payload,
      ])
    ).rows[0].data;
  const worker = async (
    op: string,
    id?: string,
    lease?: string,
    payload: Record<string, unknown> = {},
  ) =>
    (
      await c.query(`select ${schema}.analysis_source_worker_api($1,$2,$3,$4) as data`, [
        op,
        id ?? null,
        lease ?? null,
        payload,
      ])
    ).rows[0].data;
  const add = async (id: string) =>
    source(owner, id, "create", {
      sourceId: randomUUID(),
      name: "synthetic.csv",
      kind: "file",
      sha256: createHash("sha256").update(randomUUID()).digest("hex"),
      bytes: 10,
    });
  beforeAll(async () => {
    await c.connect();
    await c.query(`create schema ${schema}`);
    for (const file of [
      "20261112100000_analysis_users.sql",
      "20261112220000_analysis_projects.sql",
      "20261113090000_analysis_sources.sql",
    ]) {
      const sql = readFileSync(new URL(`../supabase/migrations/${file}`, import.meta.url), "utf8")
        .replaceAll("public.", `${schema}.`)
        .replace(/set search_path\s*=\s*public,\s*pg_temp/g, `set search_path=${schema},pg_temp`);
      await c.query(sql);
    }
    for (const id of [owner, editor, viewer, outsider])
      await c.query(
        `insert into ${schema}.analysis_users(user_id,approved,updated_by) values($1,true,$1)`,
        [id],
      );
  });
  afterAll(async () => {
    await c.query(`drop schema if exists ${schema} cascade`);
    await c.end();
  });
  it("limits source access to its project and denies viewer mutations", async () => {
    const p = await project(owner, "create", undefined, { requestId: randomUUID() });
    const s = await add(p.id);
    await project(owner, "share", p.id, { userId: viewer, role: "viewer" });
    expect((await source(viewer, p.id, "get", { sourceId: s.id })).sha256).toBe(s.sha256);
    await expect(source(outsider, p.id, "get", { sourceId: s.id })).rejects.toMatchObject({
      code: "42501",
    });
    await expect(source(viewer, p.id, "delete", { sourceId: s.id })).rejects.toMatchObject({
      code: "42501",
    });
    const other = await project(owner, "create", undefined, { requestId: randomUUID() });
    await expect(source(owner, other.id, "get", { sourceId: s.id })).rejects.toMatchObject({
      code: "42501",
    });
  });
  it("fences extraction after project sharing is revoked", async () => {
    const p = await project(owner, "create", undefined, { requestId: randomUUID() });
    await project(owner, "share", p.id, { userId: editor, role: "editor" });
    const s = await add(p.id);
    await source(editor, p.id, "ready", { sourceId: s.id });
    const claim = await worker("claim", s.id);
    expect(claim.id).toBe(s.id);
    await project(owner, "unshare", p.id, { userId: editor });
    expect(
      await worker("finish", s.id, claim.lease_id, {
        content: { status: "ready", pages: [{ text: "Must not publish" }] },
      }),
    ).toEqual({ active: false });
    expect((await source(owner, p.id, "get", { sourceId: s.id })).content).toBeNull();
  });
  it("scrubs source content and invalidates dependent saved results on deletion", async () => {
    const p = await project(owner, "create", undefined, { requestId: randomUUID() });
    const s = await add(p.id);
    await source(owner, p.id, "ready", { sourceId: s.id });
    const claim = await worker("claim", s.id);
    await worker("finish", s.id, claim.lease_id, {
      content: {
        status: "ready",
        pages: [{ page: 1, text: "Private evidence" }],
        tables: [],
        profile: { warnings: [] },
      },
    });
    const job = (
      await c.query(
        `insert into ${schema}.analysis_jobs(project_id,actor_id,request_id,question,status,result) values($1,$2,$3,'Synthetic','completed',$4) returning id`,
        [p.id, owner, randomUUID(), { answer: "Derived answer", evidence: [{ sourceId: s.id }] }],
      )
    ).rows[0];
    await source(owner, p.id, "delete", { sourceId: s.id });
    await expect(source(owner, p.id, "get", { sourceId: s.id })).rejects.toMatchObject({
      code: "42501",
    });
    expect(
      (await c.query(`select result,status from ${schema}.analysis_jobs where id=$1`, [job.id]))
        .rows[0],
    ).toEqual({ result: null, status: "cancelled" });
    expect(
      (await c.query(`select content from ${schema}.analysis_sources where id=$1`, [s.id])).rows[0]
        .content,
    ).toBeNull();
  });
  it("scrubs all sources through the existing project deletion operation and denies public data-plane roles", async () => {
    const p = await project(owner, "create", undefined, { requestId: randomUUID() });
    const s = await add(p.id);
    await project(owner, "delete", p.id);
    const row = (
      await c.query(
        `select status,content,url,metadata from ${schema}.analysis_sources where id=$1`,
        [s.id],
      )
    ).rows[0];
    expect(row).toEqual({ status: "deleted", content: null, url: null, metadata: {} });
    const perms = (
      await c.query(
        `select has_table_privilege('anon','${schema}.analysis_sources','SELECT') as readable,has_function_privilege('anon','${schema}.analysis_source_api(uuid,uuid,text,jsonb)','EXECUTE') as executable`,
      )
    ).rows[0];
    expect(perms).toEqual({ readable: false, executable: false });
  });
});
