import { afterEach, expect, it } from "vitest";
import { mkdtemp, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { validateHosting } from "./validate-map-hosting.mjs";
let dir: string;
afterEach(async () => {
  if (dir) await rm(dir, { recursive: true, force: true });
});
async function fixture() {
  dir = await mkdtemp(join(tmpdir(), "bhw-map-"));
  const data = Buffer.alloc(512, 42);
  data.write("PMTiles");
  data[7] = 3;
  const path = join(dir, "synthetic.pmtiles");
  await writeFile(path, data);
  return { data, path };
}
it("checks beginning, middle, end, reuse and conditional requests", async () => {
  const { data, path } = await fixture();
  const result = await validateHosting(
    "https://example.test/bhw-connect/maps/v1/test.pmtiles",
    path,
    async (_url, init) => {
      const headers = new Headers(init?.headers);
      if (headers.has("If-None-Match")) return new Response(null, { status: 304, headers: { ETag: '"fixture"' } });
      const [start, end] = headers.get("Range")!.slice(6).split("-").map(Number);
      return new Response(new Uint8Array(data.subarray(start, end + 1)), {
        status: 206,
        headers: {
          "Content-Range": `bytes ${start}-${end}/${data.length}`,
          "Content-Type": "application/vnd.pmtiles",
          "Cache-Control": "public, max-age=31536000, immutable",
          ETag: '"fixture"',
        },
      });
    },
  );
  expect(result.ranges).toHaveLength(4);
  expect(result.rendering).toBe("not-tested");
});
it("rejects a host that ignores ranges", async () => {
  const { path } = await fixture();
  await expect(
    validateHosting(
      "https://example.test/bhw-connect/maps/v1/test.pmtiles",
      path,
      async () => new Response("wrong", { status: 200 }),
    ),
  ).rejects.toThrow("Invalid range response");
});
it("rejects a JSON asset as archive evidence", async () => {
  const { path } = await fixture();
  await writeFile(path, Buffer.alloc(512));
  await expect(validateHosting("https://example.test/bhw-connect/maps/v1/test.pmtiles", path)).rejects.toThrow(
    "PMTiles v3",
  );
});

const url = "https://example.test/bhw-connect/maps/v1/test.pmtiles";
function hosted(data: Buffer, override: Record<string, string> = {}, conditionalTag = '"fixture"') {
  return async (_url: unknown, init?: RequestInit) => {
    const headers = new Headers(init?.headers);
    if (headers.has("If-None-Match")) return new Response(null, { status: 304, headers: { ETag: conditionalTag } });
    const [start, end] = headers.get("Range")!.slice(6).split("-").map(Number);
    return new Response(new Uint8Array(data.subarray(start, end + 1)), { status: 206, headers: {
      "Content-Range": `bytes ${start}-${end}/${data.length}`,
      "Content-Type": "application/vnd.pmtiles",
      "Cache-Control": "public, max-age=31536000, immutable", ETag: '"fixture"', ...override,
    } });
  };
}
it.each([
  "public, max-age=123invalid, immutable", "public, s-max-age=123, immutable",
  "public, max-age=0, immutable", "public, max-age=123, immutable, private",
  "public, max-age=123, immutable, no-store", "public, max-age=123, immutable, no-cache",
  "public, max-age=123, max-age=0, immutable",
])("rejects contradictory or malformed cache policy: %s", async cache => {
  const { data, path } = await fixture();
  await expect(validateHosting(url, path, hosted(data, { "Cache-Control": cache }))).rejects.toThrow("cache policy");
});
it("accepts case-insensitive cache directives and keeps certification limits explicit", async () => {
  const { data, path } = await fixture();
  expect(await validateHosting(url, path, hosted(data, { "Cache-Control": "Public, MAX-AGE=123, Immutable" })))
    .toMatchObject({ publication_ready: false, archive_structure: "not-tested", licensing: "not-tested" });
});
it.each(['W/"fixture"', "unquoted", '"one", "two"'])("rejects invalid strong validators: %s", async tag => {
  const { data, path } = await fixture();
  await expect(validateHosting(url, path, hosted(data, { ETag: tag }))).rejects.toThrow("strong ETag");
});
it("rejects a changed validator on conditional reuse", async () => {
  const { data, path } = await fixture();
  await expect(validateHosting(url, path, hosted(data, {}, '"changed"'))).rejects.toThrow("unchanged ETag");
});
it.each([
  "https://example.test/maps/v1/test.pmtiles", "https://example.test/bhw-connect/maps/latest/test.pmtiles",
  "https://user:secret@example.test/bhw-connect/maps/v1/test.pmtiles", `${url}?token=secret`, `${url}#fragment`,
  "https://example.test/bhw-connect/test.pmtiles",
])("refuses unversioned or credential-bearing routes before network access: %s", async target => {
  let calls = 0;
  await expect(validateHosting(target, "/unused", async () => { calls++; return new Response(); })).rejects.toThrow("versioned");
  expect(calls).toBe(0);
});
it("rejects transformed, oversized and different bytes", async () => {
  const { data, path } = await fixture();
  await expect(validateHosting(url, path, hosted(data, { "Content-Encoding": "gzip" }))).rejects.toThrow("transformed");
  for (const [body, message] of [[Buffer.alloc(128), "Oversized"], [Buffer.alloc(127), "differ"]] as const) {
    await expect(validateHosting(url, path, async () => new Response(body, { status: 206, headers: {
      "Content-Range": "bytes 0-126/512", "Content-Type": "application/octet-stream",
      "Cache-Control": "public, max-age=123, immutable", ETag: '"fixture"',
    } }))).rejects.toThrow(message);
  }
});
