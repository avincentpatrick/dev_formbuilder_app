import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import { baseCensus } from "./base-path-census";

const APP_ROOT = fileURLToPath(new URL("..", import.meta.url));

describe("root-relative URLs Next's basePath does not prefix", () => {
  it("are all wrapped in withBasePath()", () => {
    // Each line names a raw <a href="/…">, fetch("/…"), location change, new URL("/…", base) or
    // public-file URL that would 404 under /bhw-connect. Wrap it: withBasePath("/…").
    const hits = baseCensus(APP_ROOT).map((h) => `${h.file}:${h.line} ${h.kind} ${h.text}`);
    expect(hits).toEqual([]);
  });

  it("still finds what it is looking for", () => {
    // The census at zero proves nothing unless it can see a defect: run it over a fixture that holds
    // one of each kind, and require every kind back.
    const fixture = fileURLToPath(new URL("./__fixtures__/base-path-census", import.meta.url));
    const kinds = new Set(baseCensus(fixture).map((h) => h.kind));
    expect([...kinds].sort()).toEqual([
      "fetch",
      "location",
      "new URL",
      "public file",
      "raw <a href>",
    ]);
  });
});
