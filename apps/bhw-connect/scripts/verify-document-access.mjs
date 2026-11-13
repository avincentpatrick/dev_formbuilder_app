// Local-only acceptance harness. Requires the made-up development DB and PostgREST.
// BHW_TEST_DATABASE_URL=postgres://... BHW_TEST_REST_URL=http://127.0.0.1:53001 node scripts/verify-document-access.mjs
import assert from "node:assert/strict";
import { createServer } from "node:http";
import { spawn } from "node:child_process";
import pg from "pg";
import { chromium } from "@playwright/test";
import { mintRoleToken } from "../db/mint-jwt.mjs";

const database = process.env.BHW_TEST_DATABASE_URL;
const rest = process.env.BHW_TEST_REST_URL;
if (
  !database ||
  !rest ||
  !["localhost", "127.0.0.1"].includes(new URL(database).hostname) ||
  !["localhost", "127.0.0.1"].includes(new URL(rest).hostname)
)
  throw Error("Requires explicit loopback-only disposable database and REST URLs");
const db = new pg.Client({ connectionString: database });
const ids = {
  admin: "11111111-1111-4111-8111-111111111121",
  editor: "11111111-1111-4111-8111-111111111122",
  member: "11111111-1111-4111-8111-111111111123",
};
const identity = createServer((req, res) => {
  const role = /meridian-session=(admin|editor|member)/.exec(req.headers.cookie ?? "")?.[1];
  res.writeHead(role ? 200 : 401, { "content-type": "application/json" });
  res.end(
    JSON.stringify(
      role
        ? { data: { id: ids[role], name: `Synthetic ${role}`, email: `${role}@example.test` } }
        : {},
    ),
  );
});
let app, browser, source;
const results = [];
try {
  await db.connect();
  await db.query("insert into admin_users(user_id,role) values ($1,'admin'),($2,'editor')", [
    ids.admin,
    ids.editor,
  ]);
  source = (
    await db.query(
      `insert into doc_source(key,title,source_path,source_sha256,page_count,char_count,extractor,status) values ('browser-synthetic-policy','Synthetic browser policy','synthetic.pdf',repeat('d',64),1,28,'test','approved') returning doc_id`,
    )
  ).rows[0].doc_id;
  const chunk = (
    await db.query(
      `insert into doc_chunk(doc_id,chunk_index,page_from,page_to,char_start,char_end,content,content_sha256) values ($1,0,1,1,0,length('SYNTHETIC PRIVATE POLICY TEXT'),'SYNTHETIC PRIVATE POLICY TEXT',repeat('e',64)) returning chunk_id`,
      [source],
    )
  ).rows[0].chunk_id;
  await new Promise((resolve) => identity.listen(53101, "127.0.0.1", resolve));
  const secret = "bhw-local-jwt-secret-for-this-machine-only";
  const env = {
    ...process.env,
    NEXT_PUBLIC_BASE_PATH: "/bhw-connect",
    NEXT_PUBLIC_SUPABASE_URL: "http://no-data-source.invalid",
    NEXT_PUBLIC_SUPABASE_ANON_KEY: mintRoleToken("anon", secret),
    SUPABASE_SERVICE_ROLE_KEY: mintRoleToken("service_role", secret),
    SUPABASE_REST_URL: rest,
    MERIDIAN_INTERNAL_URL: "http://127.0.0.1:53101",
    BHW_AUTH_MODE: "meridian",
  };
  delete env.BHW_DATA_SOURCE;
  app = spawn(
    process.execPath,
    ["node_modules/next/dist/bin/next", "start", "-H", "127.0.0.1", "-p", "53100"],
    { env, stdio: ["ignore", "pipe", "pipe"] },
  );
  let log = "";
  app.stdout.on("data", (b) => {
    log += b;
  });
  app.stderr.on("data", (b) => {
    log += b;
  });
  let ready = false;
  for (let i = 0; i < 60; i++) {
    try {
      if (
        (
          await fetch("http://127.0.0.1:53100/bhw-connect/admin/login", {
            signal: AbortSignal.timeout(2000),
          })
        ).ok
      ) {
        ready = true;
        break;
      }
    } catch {}
    await new Promise((r) => setTimeout(r, 500));
  }
  assert(ready, `App did not start: ${log.slice(-2000)}`);
  browser = await chromium.launch({
    executablePath: process.env.BHW_TEST_CHROMIUM ?? "/usr/bin/chromium",
    headless: true,
  });
  for (const role of ["signed_out", "member", "editor", "admin"]) {
    const context = await browser.newContext();
    if (role !== "signed_out")
      await context.addCookies([
        { name: "meridian-session", value: role, domain: "127.0.0.1", path: "/" },
      ]);
    const page = await context.newPage();
    for (const path of ["admin/assistant", `admin/assistant/source/${chunk}`]) {
      await page.goto(`http://127.0.0.1:53100/bhw-connect/${path}`);
      const text = await page.locator("body").innerText();
      const marker = path.includes("/source/")
        ? "SYNTHETIC PRIVATE POLICY TEXT"
        : "Internal assistant";
      assert.equal(text.includes(marker), role === "admin", `${role}: ${path}`);
      results.push({ role, path, visible: role === "admin" });
    }
    // An invalid body must only get as far as schema validation for an admin.
    for (const path of ["api/ai/assistant", "api/ai/assistant/regression"]) {
      const response = await context.request.post(`http://127.0.0.1:53100/bhw-connect/${path}`, {
        data: {},
      });
      assert.equal(response.status(), role === "admin" ? 400 : 401, `${role}: ${path}`);
      results.push({ role, path, status: response.status() });
    }
    await context.close();
  }
  console.log(JSON.stringify({ checks: results.length, results }, null, 2));
} finally {
  await browser?.close();
  app?.kill();
  identity.close();
  if (source) {
    await db.query("delete from doc_chunk where doc_id=$1", [source]);
    await db.query("delete from doc_source where doc_id=$1", [source]);
  }
  await db
    .query("delete from admin_users where user_id=any($1)", [[ids.admin, ids.editor]])
    .catch(() => {});
  await db.end();
}
