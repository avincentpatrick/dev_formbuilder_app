// Copies MapLibre's worker into public/ so the app serves it; components/maps/load-maplibre.ts says
// why. Runs before `next dev` and `next build`, so the copy always matches the installed version.
import { copyFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

const from = fileURLToPath(import.meta.resolve("maplibre-gl/dist/maplibre-gl-worker.mjs"));
const to = fileURLToPath(new URL("../public/maplibre-gl-worker.mjs", import.meta.url));
copyFileSync(from, to);
