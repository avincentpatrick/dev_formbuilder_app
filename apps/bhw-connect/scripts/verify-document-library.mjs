// End-to-end synthetic acceptance harness; loopback DB/REST only, fake identity/provider.
import assert from "node:assert/strict";
import { createServer } from "node:http";
import { spawn, execFileSync } from "node:child_process";
import { mkdtemp, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { resolve, join } from "node:path";
import { randomUUID } from "node:crypto";
import pg from "pg";
import { chromium } from "@playwright/test";
import { mintRoleToken } from "../db/mint-jwt.mjs";
const database = process.env.BHW_TEST_DATABASE_URL,
  rest = process.env.BHW_TEST_REST_URL;
if (
  !database ||
  !rest ||
  !["localhost", "127.0.0.1"].includes(new URL(database).hostname) ||
  !["localhost", "127.0.0.1"].includes(new URL(rest).hostname)
)
  throw Error("Use explicit loopback-only disposable DB/REST URLs");
const db = new pg.Client({ connectionString: database });
const roles = {
  admin: randomUUID(),
  other: randomUUID(),
  editor: randomUUID(),
  member: randomUUID(),
};
const root = await mkdtemp(join(tmpdir(), "bhw-library-e2e-"));
let app, browser;
const server = createServer((req, res) => {
  const role = /meridian-session=(admin|other|editor|member)/.exec(req.headers.cookie ?? "")?.[1];
  res.writeHead(role ? 200 : 401, { "content-type": "application/json" });
  res.end(
    JSON.stringify(
      role
        ? { data: { id: roles[role], name: `Synthetic ${role}`, email: `${role}@example.test` } }
        : {},
    ),
  );
});
const checks = [];
const base = "http://127.0.0.1:53102/bhw-connect";
try {
  await db.connect();
  for (const [role, id] of Object.entries(roles)) {
    if (role !== "member")
      await db.query("insert into admin_users values($1,$2)", [
        id,
        role === "editor" ? "editor" : "admin",
      ]);
    await db.query("insert into analysis_users(user_id,approved,updated_by) values($1,true,$1)", [
      id,
    ]);
  }
  await new Promise((r) => server.listen(53103, "127.0.0.1", r));
  const secret = "bhw-local-jwt-secret-for-this-machine-only";
  const env = {
    ...process.env,
    NEXT_PUBLIC_BASE_PATH: "/bhw-connect",
    NEXT_PUBLIC_SUPABASE_URL: "http://no-data-source.invalid",
    NEXT_PUBLIC_SUPABASE_ANON_KEY: mintRoleToken("anon", secret),
    SUPABASE_SERVICE_ROLE_KEY: mintRoleToken("service_role", secret),
    SUPABASE_REST_URL: rest,
    MERIDIAN_INTERNAL_URL: "http://127.0.0.1:53103",
    BHW_AUTH_MODE: "meridian",
    BHW_STORAGE_DIR: root,
    GEMINI_API_KEY: "synthetic-fixture",
    GEMINI_EMBEDDING_MODEL: "",
    NODE_OPTIONS: `--require ${resolve("scripts/fixtures/document-provider.cjs")}`,
  };
  delete env.BHW_DATA_SOURCE;
  app = spawn(
    process.execPath,
    ["node_modules/next/dist/bin/next", "start", "-H", "127.0.0.1", "-p", "53102"],
    { env, stdio: ["ignore", "pipe", "pipe"] },
  );
  let log = "";
  app.stdout.on("data", (b) => (log += b));
  app.stderr.on("data", (b) => (log += b));
  let ready = false;
  for (let i = 0; i < 60; i++) {
    try {
      if ((await fetch(`${base}/admin/login`, { signal: AbortSignal.timeout(2000) })).ok) {
        ready = true;
        break;
      }
    } catch {}
    await new Promise((r) => setTimeout(r, 500));
  }
  assert(ready, log.slice(-2000));
  browser = await chromium.launch({ executablePath: "/usr/bin/chromium", headless: true });
  const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
  await context.addCookies([
    { name: "meridian-session", value: "admin", domain: "127.0.0.1", path: "/" },
  ]);
  const page = await context.newPage();
  page.on("response", async (r) => {
    if (r.url().includes("api/admin/documents") && r.request().method() === "POST")
      console.error("upload response", r.status(), await r.text());
  });
  await page.goto(`${base}/admin/documents`);
  await page.getByLabel("Title", { exact: true }).fill("Synthetic shared policy");
  await page.getByLabel("Issuing agency", { exact: true }).first().fill("Synthetic agency");
  await page.getByLabel("Collection", { exact: true }).fill("synthetic-e2e");
  const bytes = execFileSync("python", [
    "-c",
    `import fitz,sys
x=fitz.open();p=x.new_page();p.insert_text((72,100),'Every clinic must retain a signed referral record.',fontsize=16)
r=p.get_pixmap(dpi=200).tobytes('png');d=fitz.open();p=d.new_page();p.insert_image(p.rect,stream=r);p.insert_text((72,40),'SELECTABLE HEADER',fontsize=16);sys.stdout.buffer.write(d.tobytes(deflate=True))`,
  ]);
  await page
    .getByLabel("PDF file", { exact: true })
    .setInputFiles({ name: "synthetic.pdf", mimeType: "application/pdf", buffer: bytes });
  await page.getByRole("button", { name: "Upload", exact: true }).click();
  try {
    await page.getByText("Upload queued.", { exact: false }).waitFor();
  } catch {
    throw new Error(`Upload UI: ${await page.locator("body").innerText()}\n${log.slice(-1500)}`);
  }
  const href = await page
    .getByRole("link", { name: "Open document", exact: true })
    .getAttribute("href");
  const version = href.split("/").at(-1);
  checks.push("mobile PDF upload and durable catalog");
  // Close the tab before processing, demonstrating no browser-owned work.
  await page.close();
  execFileSync("python", ["ingestion/document_worker.py", "--once"], {
    env: {
      ...process.env,
      BHW_WORKER_DATABASE_URL: database,
      BHW_STORAGE_DIR: root,
      GEMINI_API_KEY: "",
    },
    timeout: 60000,
  });
  const detail = await context.newPage();
  await detail.goto(`${base}/admin/documents/${version}`);
  await detail
    .getByText("Every clinic must retain a signed referral record.", { exact: false })
    .waitFor();
  assert((await detail.locator("body").innerText()).includes("OCR"));
  checks.push("closed-tab mixed OCR, text and warnings");
  const original = await context.request.get(
    `${base}/api/admin/documents/${version}?download=original`,
  );
  assert.equal(original.status(), 200);
  assert.deepEqual(await original.body(), bytes);
  const text = await context.request.get(`${base}/api/admin/documents/${version}?download=text`);
  assert((await text.text()).includes("referral record"));
  checks.push("original and complete text download");
  const duplicate = await context.request.post(`${base}/api/admin/documents`, {
    multipart: {
      title: "Duplicate policy",
      file: { name: "same.pdf", mimeType: "application/pdf", buffer: bytes },
    },
  });
  assert.equal((await duplicate.json()).duplicate, true);
  checks.push("exact duplicate feedback");
  const other = await browser.newContext();
  await other.addCookies([
    { name: "meridian-session", value: "other", domain: "127.0.0.1", path: "/" },
  ]);
  assert.equal((await other.request.get(`${base}/api/admin/documents/${version}`)).status(), 200);
  await other.close();
  checks.push("shared library for second admin");
  for (const role of [null, "editor", "member"]) {
    const c = await browser.newContext();
    if (role)
      await c.addCookies([
        { name: "meridian-session", value: role, domain: "127.0.0.1", path: "/" },
      ]);
    for (const url of [
      `${base}/api/admin/documents`,
      `${base}/api/admin/documents/${version}`,
      `${base}/api/admin/documents/${version}?download=original`,
      `${base}/api/admin/documents/${version}?download=text`,
    ])
      assert.equal((await c.request.get(url)).status(), 403);
    const p = await c.newPage();
    await p.goto(`${base}/admin/documents`);
    assert(!(await p.locator("body").innerText()).includes("Upload a PDF"));
    await c.close();
  }
  checks.push("signed-out, editor and member direct access denial");
  await detail.getByRole("link", { name: "Ask about this document", exact: true }).click();
  const box = detail.getByRole("textbox").first();
  await box.fill("What does the policy require for referral records?");
  await detail.getByRole("button", { name: "Ask", exact: true }).click();
  await detail
    .getByText("The policy requires a signed referral record.", { exact: false })
    .waitFor();
  const sourceSummary = detail
    .locator("summary")
    .filter({ hasText: "Synthetic shared policy" })
    .first();
  await sourceSummary.waitFor();
  await sourceSummary.click();
  await detail.getByRole("link", { name: "Open the stored chunk", exact: true }).waitFor();
  checks.push("scoped shared assistant with synthetic provider and real retrieval/citation");
  const edited = await context.request.post(`${base}/api/admin/documents`, {
    data: {
      operation: "metadata",
      version,
      metadata: {
        title: "Synthetic shared policy",
        issuer: "Revised synthetic agency",
        type: "Policy",
        tags: ["referral"],
        collection: "synthetic-e2e",
      },
    },
  });
  assert.equal(edited.status(), 200);
  const filtered = await context.request.get(
    `${base}/api/admin/documents?issuer=Revised&tag=referral`,
  );
  assert((await filtered.json()).versions.some((v) => v.id === version));
  const unmatched = await context.request.get(`${base}/api/admin/documents?q=absent-unique-phrase`);
  assert.equal((await unmatched.json()).versions.length, 0);
  checks.push("metadata correction and catalog filters");
  const citations = await db.query(
    "select c.chunk_id from doc_chunk c join document_extraction e on e.source_id=c.doc_id where e.version_id=$1",
    [version],
  );
  const chunk = citations.rows[0].chunk_id;
  await context.request.post(`${base}/api/admin/documents`, {
    data: { operation: "archive", version, archived: true },
  });
  const selection = await context.request.post(`${base}/api/analysis/chat`, {
    data: { libraryVersion: version, messages: [{ role: "user", content: "referral" }] },
  });
  assert.equal(selection.status(), 400);
  assert(
    (await (await context.request.get(`${base}/admin/assistant/source/${chunk}`)).text()).includes(
      "referral record",
    ),
  );
  await context.request.post(`${base}/api/admin/documents`, {
    data: { operation: "archive", version, archived: false },
  });
  checks.push("archive excludes future selection; old citation retained; unarchive");
  const retry = await context.request.post(`${base}/api/admin/documents`, {
    data: { operation: "retry", version },
  });
  assert.equal(retry.status(), 200);
  execFileSync("python", ["ingestion/document_worker.py", "--once"], {
    env: {
      ...process.env,
      BHW_WORKER_DATABASE_URL: database,
      BHW_STORAGE_DIR: root,
      GEMINI_API_KEY: "",
    },
    timeout: 60000,
  });
  const revisions = await context.request.get(`${base}/api/admin/documents/${version}`);
  assert.equal((await revisions.json()).revisions.length, 2);
  checks.push("reprocessing creates a retained extraction revision");
  console.log(JSON.stringify({ checks, passed: checks.length }, null, 2));
} finally {
  await browser?.close();
  app?.kill();
  server.close();
  // Only this harness's generated identities/records are removed; the practice DB is retained.
  const actors = Object.values(roles);
  const versions = (
    await db.query("select id from document_version where uploaded_by=any($1)", [actors])
  ).rows.map((x) => x.id);
  const hashes = (
    await db.query("select file_sha256 from document_version where id=any($1)", [versions])
  ).rows.map((x) => x.file_sha256);
  const sources = (
    await db.query("select source_id from document_extraction where version_id=any($1)", [versions])
  ).rows.map((x) => x.source_id);
  await db.query("update document_version set current_extraction_id=null where id=any($1)", [
    versions,
  ]);
  await db.query("delete from document_job where version_id=any($1) and kind='embed'", [versions]);
  await db.query("update document_extraction set job_id=null where version_id=any($1)", [versions]);
  await db.query("delete from document_job where version_id=any($1)", [versions]);
  await db.query("delete from document_extraction where version_id=any($1)", [versions]);
  await db.query("delete from document_upload_attempt where uploaded_by=any($1)", [actors]);
  await db.query("delete from document_audit_event where actor_id=any($1)", [actors]);
  await db.query("delete from document_version where id=any($1)", [versions]);
  await db.query("delete from document_catalog where created_by=any($1)", [actors]);
  await db.query("delete from document_file where sha256=any($1)", [hashes]);
  await db.query("delete from doc_chunk where doc_id=any($1)", [sources]);
  await db.query("delete from doc_source where doc_id=any($1)", [sources]);
  await db.query("delete from analysis_users where user_id=any($1)", [actors]);
  await db.query("delete from admin_users where user_id=any($1)", [actors]);
  await db.end();
  await rm(root, { recursive: true, force: true });
}
