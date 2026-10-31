import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
import { describe, expect, it, vi } from "vitest";
import { loadMaplibre, MAPLIBRE_WORKER_PATH } from "./load-maplibre";

type Maplibre = typeof import("maplibre-gl");

function fakeMaplibre() {
  return { setWorkerUrl: vi.fn(), getVersion: () => "6.16.0" } as unknown as Maplibre & {
    setWorkerUrl: ReturnType<typeof vi.fn>;
  };
}

describe("loadMaplibre", () => {
  it("points the worker at the served copy under the base path, with the version", async () => {
    const maplibregl = fakeMaplibre();
    await loadMaplibre(async () => maplibregl, "/bhw-connect");
    expect(maplibregl.setWorkerUrl).toHaveBeenCalledWith(
      "/bhw-connect/maplibre-gl-worker.mjs?v=6.16.0",
    );
  });

  it("serves it from the root with no base path, which is every laptop and Vercel build", async () => {
    const maplibregl = fakeMaplibre();
    await loadMaplibre(async () => maplibregl, "");
    expect(maplibregl.setWorkerUrl).toHaveBeenCalledWith("/maplibre-gl-worker.mjs?v=6.16.0");
  });

  it("hands back the module it loaded", async () => {
    const maplibregl = fakeMaplibre();
    await expect(loadMaplibre(async () => maplibregl, "")).resolves.toBe(maplibregl);
  });
});

describe("the worker file the build copies into public/", () => {
  // The same specifier scripts/copy-maplibre-worker.mjs copies from.
  const worker = createRequire(import.meta.url).resolve("maplibre-gl/dist/maplibre-gl-worker.mjs");

  it("exists in the installed package under the name it is served as", () => {
    expect(MAPLIBRE_WORKER_PATH).toBe("/maplibre-gl-worker.mjs");
    expect(readFileSync(worker).length).toBeGreaterThan(0);
  });

  it("is self-contained, so serving that one file is enough", () => {
    // 6.x briefly split a shared chunk out of the worker; a copy of the worker alone would then
    // fail on its first import.
    const source = readFileSync(worker, "utf8");
    expect(source).not.toMatch(/\bimport\s*(?:[\w*{][^;]*?\bfrom\s*)?["'`]\.\.?\//);
  });
});
