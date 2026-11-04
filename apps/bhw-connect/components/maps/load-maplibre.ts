import { BASE_PATH, withBasePath } from "@/lib/base-path";

/**
 * Where MapLibre's worker is served from: a file under `public/`, copied there from the installed
 * package by `predev` and `prebuild` (`scripts/copy-maplibre-worker.mjs`), so it always matches the
 * version in `node_modules`.
 *
 * WHY. MapLibre 6 is ESM-only and starts its worker as a module from a URL it derives from its own
 * `import.meta.url`. Bundled by Next, that is not an http URL, so it derives `""`: the browser loads
 * the page itself as the worker, it fails ("Worker failed to load"), and every map stays an empty
 * grey box. MapLibre 5 shipped its worker inline as a `blob:` URL, which is why this never came up
 * before the upgrade. The worker file is self-contained, so serving that one file is the whole fix.
 */
export const MAPLIBRE_WORKER_PATH = "/maplibre-gl-worker.mjs";

type Maplibre = typeof import("maplibre-gl");

/**
 * `import("maplibre-gl")` with the worker URL set, which must happen before the first map is made.
 * The version rides as `?v=`, as MapLibre's own default URL does, so a browser never pairs a new
 * main bundle with a cached old worker.
 */
export async function loadMaplibre(
  load: () => Promise<Maplibre> = () => import("maplibre-gl"),
  basePath: string = BASE_PATH,
): Promise<Maplibre> {
  const maplibregl = await load();
  maplibregl.setWorkerUrl(
    `${withBasePath(MAPLIBRE_WORKER_PATH, basePath)}?v=${maplibregl.getVersion()}`,
  );
  return maplibregl;
}
