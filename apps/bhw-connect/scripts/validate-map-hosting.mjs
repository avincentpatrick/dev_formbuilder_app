/** Read-only delivery probe. Compare the actual hosted archive with its local original.
 * Usage: node scripts/validate-map-hosting.mjs https://host/bhw-connect/maps/v1/base.pmtiles /private/base.pmtiles
 * Passing this probe does NOT certify MapLibre rendering, licensing or phone performance.
 */
import { open } from "node:fs/promises";
import { pathToFileURL } from "node:url";

function immutableCache(value) {
  const directives = (value ?? "").toLowerCase().split(",").map(s => s.trim());
  const ages = directives.filter(s => s.startsWith("max-age="));
  return directives.includes("public") && directives.includes("immutable") &&
    ages.length === 1 && /^max-age=[1-9][0-9]*$/.test(ages[0]) &&
    !directives.some(s => /^(private|no-store|no-cache)(=|$)/.test(s));
}

export async function validateHosting(url, localPath, request = fetch) {
  const target = new URL(url);
  if (!["http:", "https:"].includes(target.protocol) || target.username || target.password ||
      target.search || target.hash || !/^\/bhw-connect\/(?:[a-zA-Z0-9._-]+\/){2,}[a-zA-Z0-9._-]+\.pmtiles$/.test(target.pathname) ||
      target.pathname.split("/").some(part => part.toLowerCase() === "latest"))
    throw new Error("Supply a versioned /bhw-connect/ PMTiles URL without credentials or query parameters");
  const file = await open(localPath, "r");
  try {
    const { size } = await file.stat();
    if (size < 127) throw new Error("Archive too small for PMTiles v3 header");
    const header = Buffer.alloc(127);
    await file.read(header, 0, 127, 0);
    if (header.subarray(0, 7).toString() !== "PMTiles" || header[7] !== 3)
      throw new Error("Expected a PMTiles v3 archive");
    const ranges = [
      [0, 126],
      [Math.floor(size / 2), Math.min(size - 1, Math.floor(size / 2) + 127)],
      [Math.max(0, size - 128), size - 1],
    ];
    let etag;
    const results = [];
    for (const [start, end] of [...ranges, ranges[0]]) {
      const response = await request(url, {
        headers: { Range: `bytes=${start}-${end}`, "Accept-Encoding": "identity" },
        redirect: "error",
        signal: AbortSignal.timeout(20000),
      });
      if (
        response.status !== 206 ||
        response.headers.get("content-range") !== `bytes ${start}-${end}/${size}`
      ) {
        await response.body?.cancel();
        throw new Error("Invalid range response");
      }
      const encoding = response.headers.get("content-encoding");
      if (encoding && encoding !== "identity") {
        await response.body?.cancel();
        throw new Error("Archive bytes transformed");
      }
      const type = response.headers.get("content-type")?.split(";")[0].trim().toLowerCase();
      if (!["application/vnd.pmtiles", "application/octet-stream"].includes(type)) {
        await response.body?.cancel();
        throw new Error("Unexpected archive MIME type");
      }
      const cache = response.headers.get("cache-control") ?? "";
      if (!immutableCache(cache)) {
        await response.body?.cancel();
        throw new Error("Versioned archive lacks public immutable cache policy");
      }
      const current = response.headers.get("etag");
      if (!current || !/^"[^"\r\n]+"$/.test(current) || (etag && etag !== current)) {
        await response.body?.cancel();
        throw new Error("Missing or inconsistent strong ETag");
      }
      etag = current;
      const expected = Buffer.alloc(end - start + 1);
      await file.read(expected, 0, expected.length, start);
      // Bound response consumption even if a broken host streams a whole archive as 206.
      if (!response.body) throw new Error("Missing range response body");
      const reader = response.body.getReader();
      const chunks = [];
      let bytes = 0;
      try {
        while (true) {
          const { done, value } = await reader.read();
          if (done) break;
          bytes += value.length;
          if (bytes > expected.length) throw new Error("Oversized range response");
          chunks.push(Buffer.from(value));
        }
      } finally {
        await reader.cancel();
        reader.releaseLock();
      }
      if (!Buffer.concat(chunks).equals(expected))
        throw new Error("Hosted bytes differ from local archive");
      results.push({ start, end, bytes });
    }
    const conditional = await request(url, {
      headers: { "If-None-Match": etag, "Accept-Encoding": "identity" },
      redirect: "error",
      signal: AbortSignal.timeout(20000),
    });
    await conditional.body?.cancel();
    if (conditional.status !== 304 || conditional.headers.get("etag") !== etag)
      throw new Error("Expected conditional 304 with unchanged ETag");
    return {
      size,
      publication_ready: false,
      archive_structure: "not-tested",
      licensing: "not-tested",
      ranges: results,
      conditional: 304,
      rendering: "not-tested",
      performance: "not-tested",
    };
  } finally {
    await file.close();
  }
}
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const [url, path] = process.argv.slice(2);
  if (!url || !path) throw new Error("Supply versioned archive URL and local archive path");
  console.log(JSON.stringify(await validateHosting(url, path), null, 2));
}
