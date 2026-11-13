import { randomUUID } from "node:crypto";
import { readFileSync } from "node:fs";
import pg from "pg";
import { afterAll, beforeAll, describe, expect, it } from "vitest";
const database = process.env.BHW_TEST_DATABASE_URL;
describe.skipIf(!database)("private projects and durable jobs against PostgreSQL", () => {
  const c = new pg.Client({ connectionString: database });
  const schema = `projects_test_${process.pid}`;
  const owner = randomUUID(),
    viewer = randomUUID(),
    editor = randomUUID(),
    outsider = randomUUID();
  const call = async (
    actor: string,
    operation: string,
    id?: string,
    payload: Record<string, unknown> = {},
  ) => {
    const r = await c.query(`select ${schema}.analysis_project_api($1,$2,$3,$4) as data`, [
      actor,
      operation,
      id ?? null,
      payload,
    ]);
    return r.rows[0].data;
  };
  const worker = async (
    operation: string,
    id?: string,
    lease?: string,
    payload: Record<string, unknown> = {},
  ) => {
    const r = await c.query(`select ${schema}.analysis_worker_api($1,$2,$3,$4) as data`, [
      operation,
      id ?? null,
      lease ?? null,
      payload,
    ]);
    return r.rows[0].data;
  };
  const create = () => call(owner, "create", undefined, { requestId: randomUUID() });
  beforeAll(async () => {
    await c.connect();
    await c.query(`CREATE SCHEMA ${schema}`);
    for (const file of [
      "20261112100000_analysis_users.sql",
      "20261112220000_analysis_projects.sql",
    ]) {
      const sql = readFileSync(new URL(`../supabase/migrations/${file}`, import.meta.url), "utf8")
        .replaceAll("public.", `${schema}.`)
        .replaceAll("set search_path = public, pg_temp", `set search_path = ${schema}, pg_temp`);
      await c.query(sql);
    }
    for (const id of [owner, viewer, editor, outsider])
      await c.query(
        `insert into ${schema}.analysis_users(user_id,approved,updated_by) values($1,true,$1)`,
        [id],
      );
  });
  afterAll(async () => {
    await c.query(`DROP SCHEMA IF EXISTS ${schema} CASCADE`);
    await c.end();
  });
  it("keeps projects private, grants viewer/editor roles, and removes revoked memberships", async () => {
    const { id } = await create();
    expect(await call(outsider, "list")).toEqual([]);
    await expect(call(outsider, "get", id)).rejects.toMatchObject({ code: "42501" });
    await call(owner, "share", id, { userId: viewer, role: "viewer" });
    expect((await call(viewer, "get", id)).role).toBe("viewer");
    await expect(
      call(viewer, "rename", id, { title: "Forged", revision: 0 }),
    ).rejects.toMatchObject({ code: "42501" });
    await expect(call(viewer, "delete", id)).rejects.toMatchObject({ code: "42501" });
    await call(owner, "share", id, { userId: editor, role: "editor" });
    await call(editor, "rename", id, { title: "Editor title", revision: 0 });
    await expect(
      call(editor, "share", id, { userId: outsider, role: "editor" }),
    ).rejects.toMatchObject({ code: "42501" });
    await call(owner, "unshare", id, { userId: viewer });
    await expect(call(viewer, "get", id)).rejects.toMatchObject({ code: "42501" });
  });
  it("creates and enqueues idempotently, preserves question history, and fences stale workers", async () => {
    const requestId = randomUUID();
    const a = await call(owner, "create", undefined, { requestId });
    expect(await call(owner, "create", undefined, { requestId })).toEqual(a);
    const input = { question: "Synthetic question", revision: 0, requestId: randomUUID() };
    const j = await call(owner, "enqueue", a.id, input);
    expect(await call(owner, "enqueue", a.id, input)).toEqual(j);
    await expect(
      call(owner, "enqueue", a.id, { ...input, question: "Changed question" }),
    ).rejects.toMatchObject({ code: "22023" });
    const first = await worker("claim");
    expect(first.id).toBe(j.id);
    expect(first.input.messages).toEqual([{ role: "user", content: "Synthetic question" }]);
    await c.query(
      `update ${schema}.analysis_jobs set lease_until=now()-interval '1 second' where id=$1`,
      [j.id],
    );
    const second = await worker("claim");
    expect(second.id).toBe(j.id);
    expect(second.attempt).toBe(2);
    expect(
      await worker("finish", first.id, first.lease_id, { result: { answer: "Stale" } }),
    ).toEqual({ active: false });
    expect(
      await worker("finish", second.id, second.lease_id, {
        result: { answer: "Saved answer", events: [] },
        usage: { requests: 1, estimatedCostUsd: null },
      }),
    ).toEqual({ active: true });
    const project = await call(owner, "get", a.id);
    expect(project.jobs[0].result.answer).toBe("Saved answer");
    const record = await call(owner, "artifact", a.id, { jobId: j.id });
    expect(record.usageHistory).toHaveLength(2);
    await expect(call(outsider, "artifact", a.id, { jobId: j.id })).rejects.toMatchObject({
      code: "42501",
    });
    await expect(
      call(owner, "rename", a.id, { title: "Stale edit", revision: 0 }),
    ).rejects.toMatchObject({ code: "40001" });
  });
  it("cancels on unsharing and approval revocation and prevents stale publication", async () => {
    const { id } = await create();
    await call(owner, "share", id, { userId: editor, role: "editor" });
    const j = await call(editor, "enqueue", id, {
      question: "Question",
      revision: 0,
      requestId: randomUUID(),
    });
    const claimed = await worker("claim");
    expect(claimed.id).toBe(j.id);
    await call(owner, "unshare", id, { userId: editor });
    expect(await worker("heartbeat", j.id, claimed.lease_id)).toEqual({ active: false });
    expect(
      await worker("finish", j.id, claimed.lease_id, { result: { answer: "Must not publish" } }),
    ).toEqual({ active: false });
    const p = await call(owner, "get", id);
    expect(p.jobs[0].status).toBe("cancelled");
    await c.query(`update ${schema}.analysis_users set approved=false where user_id=$1`, [viewer]);
    await expect(
      call(owner, "share", id, { userId: viewer, role: "viewer" }),
    ).rejects.toMatchObject({ code: "22023" });
    await expect(call(viewer, "list")).rejects.toMatchObject({ code: "42501" });
    await c.query(`update ${schema}.analysis_users set approved=true where user_id=$1`, [viewer]);
  });
  it("deletes active content/results and prevents publication after owner deletion", async () => {
    const { id } = await create();
    const j = await call(owner, "enqueue", id, {
      question: "Question",
      revision: 0,
      requestId: randomUUID(),
    });
    const claimed = await worker("claim");
    await call(owner, "delete", id);
    expect(await worker("finish", j.id, claimed.lease_id, { result: { answer: "Late" } })).toEqual({
      active: false,
    });
    await expect(call(owner, "get", id)).rejects.toMatchObject({ code: "42501" });
    const { rows } = await c.query(
      `select question,result,context from ${schema}.analysis_jobs where id=$1`,
      [j.id],
    );
    expect(rows[0]).toEqual({ question: "Deleted", result: null, context: {} });
    expect(
      (await c.query(`select * from ${schema}.analysis_job_usage where job_id=$1`, [j.id])).rows,
    ).toEqual([]);
  });
});
