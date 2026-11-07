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
    "https://example.test/maps/v1/test.pmtiles",
    path,
    async (_url, init) => {
      const headers = new Headers(init?.headers);
      if (headers.has("If-None-Match")) return new Response(null, { status: 304 });
      const [start, end] = headers.get("Range")!.slice(6).split("-").map(Number);
      return new Response(data.subarray(start, end + 1), {
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
      "https://example.test/test.pmtiles",
      path,
      async () => new Response("wrong", { status: 200 }),
    ),
  ).rejects.toThrow("Invalid range response");
});
it("rejects a JSON asset as archive evidence", async () => {
  const { path } = await fixture();
  await writeFile(path, Buffer.alloc(512));
  await expect(validateHosting("https://example.test/test.pmtiles", path)).rejects.toThrow(
    "PMTiles v3",
  );
});
