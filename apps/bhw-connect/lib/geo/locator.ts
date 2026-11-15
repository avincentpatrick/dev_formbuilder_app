import "server-only";
import { readFile } from "node:fs/promises";
import path from "node:path";
import type { GeoLevel } from "@/lib/filters/schema";
import type { GeoAncestors } from "@/lib/db/geo";

import { buildLocatorMap, type BoundaryCollection, type PlaceLocator } from "./locator-shapes";
export { buildLocatorMap } from "./locator-shapes";
export type { BoundaryCollection, LocatorMap, PlaceLocator } from "./locator-shapes";

const GEO_DIR = path.join(process.cwd(), "public", "geo");

// Boundary files are immutable per deploy, so parses are cached for the
// lifetime of the server process (keyed by relative path; failures cache as
// null so a missing file isn't re-stat'd on every render).
const fileCache = new Map<string, Promise<BoundaryCollection | null>>();

function loadBoundaryFile(relPath: string): Promise<BoundaryCollection | null> {
  let entry = fileCache.get(relPath);
  if (!entry) {
    entry = readFile(path.join(GEO_DIR, relPath), "utf8")
      .then((raw) => JSON.parse(raw) as BoundaryCollection)
      .catch(() => null);
    fileCache.set(relPath, entry);
  }
  return entry;
}

/**
 * Locator-map thumbnail data for a place page: the place highlighted among
 * its siblings (region within the country, province within its region,
 * citymun within its province). Barangays highlight their parent citymun,
 * since barangay boundaries aren't part of the boundary pipeline.
 *
 * Returns null whenever a locator can't be drawn — national level, missing
 * ancestors, or a geo with no source boundary (HUC pseudo-provinces, NCR
 * districts; see docs/BOUNDARY_RECONCILIATION.md) — so callers just omit
 * the thumbnail rather than render a broken one.
 */
export async function getPlaceLocator(
  geo: { geoCode: string; geoLevel: GeoLevel; geoName: string },
  ancestors: GeoAncestors,
): Promise<PlaceLocator | null> {
  let relPath: string;
  let highlightCode: string;
  let highlightName = geo.geoName;
  let highlightIsParent = false;

  switch (geo.geoLevel) {
    case "region":
      relPath = "regions.json";
      highlightCode = geo.geoCode;
      break;
    case "province":
      if (!ancestors.region) return null;
      relPath = `provinces/${ancestors.region.geoCode}.json`;
      highlightCode = geo.geoCode;
      break;
    case "citymun":
      if (!ancestors.province) return null;
      relPath = `citymun/${ancestors.province.geoCode}.json`;
      highlightCode = geo.geoCode;
      break;
    case "barangay":
      if (!ancestors.province || !ancestors.citymun) return null;
      relPath = `citymun/${ancestors.province.geoCode}.json`;
      highlightCode = ancestors.citymun.geoCode;
      highlightName = ancestors.citymun.geoName;
      highlightIsParent = true;
      break;
    default:
      return null;
  }

  const collection = await loadBoundaryFile(relPath);
  if (!collection) return null;

  const map = buildLocatorMap(collection, highlightCode);
  if (!map) return null;

  return { ...map, highlightName, highlightIsParent };
}
