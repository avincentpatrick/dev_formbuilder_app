/** GeoJSON subset actually present in public/geo/* (see ingestion/reconcile_boundaries.py). */
type Geometry =
  | { type: "Polygon"; coordinates: number[][][] }
  | { type: "MultiPolygon"; coordinates: number[][][][] };

export type BoundaryCollection = {
  // `geometry` is nullable: the boundary pipeline emits a feature with a null
  // geometry for geos it can't source a shape for (e.g. the Kalayaan /
  // Spratly Islands citymun, 1705321), which is valid GeoJSON. Five citymun
  // context files ship one such feature today.
  features: { properties: { geo_code?: string }; geometry: Geometry | null }[];
};

export type LocatorMap = {
  /** `0 0 W H`, longer edge scaled to LONG_EDGE. */
  viewBox: string;
  /** All sibling polygons in the context file, as one SVG path. */
  contextPath: string;
  /** The highlighted geo's polygon(s). Empty when it collapsed below pixel size. */
  highlightPath: string;
  /** Ring marker at the highlight's center, when the polygon is too small to see. */
  marker: { cx: number; cy: number } | null;
};

export type PlaceLocator = LocatorMap & {
  /** Name of the geo the highlight outlines. */
  highlightName: string;
  /** True on barangay pages, where the parent citymun is highlighted instead
   * (barangay boundaries aren't in the boundary pipeline). */
  highlightIsParent: boolean;
};

/** Longer edge of the viewBox, in SVG user units. */
const LONG_EDGE = 200;
/** Context rings whose bounding box is under this (px) are dropped — invisible
 * islets that would only bloat the inline SVG. Highlight rings are never dropped. */
const MIN_CONTEXT_RING_PX = 2;
/** Below this highlight size (px, longer edge) a ring marker is added so the
 * place stays findable in the thumbnail. */
const MARKER_THRESHOLD_PX = 8;

function ringsOf(geometry: Geometry | null): number[][][] {
  if (!geometry) return [];
  if (geometry.type === "Polygon") return geometry.coordinates;
  if (geometry.type === "MultiPolygon") return geometry.coordinates.flat();
  return [];
}

type Bounds = { minX: number; minY: number; maxX: number; maxY: number };

function extend(b: Bounds, x: number, y: number) {
  if (x < b.minX) b.minX = x;
  if (x > b.maxX) b.maxX = x;
  if (y < b.minY) b.minY = y;
  if (y > b.maxY) b.maxY = y;
}

function emptyBounds(): Bounds {
  return { minX: Infinity, minY: Infinity, maxX: -Infinity, maxY: -Infinity };
}

/**
 * Projects a boundary FeatureCollection into a small inline-SVG locator:
 * every feature drawn as muted context, the `highlightCode` feature drawn
 * in accent on top. Pure — fs/IO lives in {@link getPlaceLocator}.
 *
 * Equirectangular projection with a cos(mid-latitude) x-correction is plenty
 * at thumbnail scale; coordinates are rounded to integers and consecutive
 * duplicates dropped, which cuts the whole-country context from ~600 KB of
 * GeoJSON to ~44 KB of path data.
 */
export function buildLocatorMap(
  collection: BoundaryCollection,
  highlightCode: string,
): LocatorMap | null {
  const highlightFeatures = collection.features.filter(
    (f) => f.properties.geo_code === highlightCode,
  );
  if (highlightFeatures.length === 0) return null;

  const lonLat = emptyBounds();
  for (const feature of collection.features) {
    for (const ring of ringsOf(feature.geometry)) {
      for (const [x, y] of ring) extend(lonLat, x, y);
    }
  }
  if (lonLat.minX > lonLat.maxX) return null;

  const kx = Math.cos((((lonLat.minY + lonLat.maxY) / 2) * Math.PI) / 180);
  const spanX = (lonLat.maxX - lonLat.minX) * kx || 1e-9;
  const spanY = lonLat.maxY - lonLat.minY || 1e-9;
  const scale = LONG_EDGE / Math.max(spanX, spanY);
  const width = Math.max(1, Math.round(spanX * scale));
  const height = Math.max(1, Math.round(spanY * scale));

  const project = ([x, y]: number[]): [number, number] => [
    (x - lonLat.minX) * kx * scale,
    (lonLat.maxY - y) * scale,
  ];

  const ringToPath = (ring: number[][], dropTiny: boolean): string => {
    const pts: [number, number][] = [];
    for (const position of ring) {
      const [px, py] = project(position).map(Math.round) as [number, number];
      const last = pts[pts.length - 1];
      if (!last || last[0] !== px || last[1] !== py) pts.push([px, py]);
    }
    if (pts.length < 4) return "";
    if (dropTiny) {
      const b = emptyBounds();
      for (const [px, py] of pts) extend(b, px, py);
      if (b.maxX - b.minX < MIN_CONTEXT_RING_PX && b.maxY - b.minY < MIN_CONTEXT_RING_PX) return "";
    }
    return `M${pts.map((p) => p.join(" ")).join("L")}Z`;
  };

  let contextPath = "";
  for (const feature of collection.features) {
    if (feature.properties.geo_code === highlightCode) continue;
    for (const ring of ringsOf(feature.geometry)) contextPath += ringToPath(ring, true);
  }

  let highlightPath = "";
  const highlightBounds = emptyBounds();
  for (const feature of highlightFeatures) {
    for (const ring of ringsOf(feature.geometry)) {
      highlightPath += ringToPath(ring, false);
      for (const position of ring) {
        const [px, py] = project(position);
        extend(highlightBounds, px, py);
      }
    }
  }
  if (highlightBounds.minX > highlightBounds.maxX) return null;

  const highlightSpan = Math.max(
    highlightBounds.maxX - highlightBounds.minX,
    highlightBounds.maxY - highlightBounds.minY,
  );
  const marker =
    highlightSpan < MARKER_THRESHOLD_PX
      ? {
          cx: Math.round((highlightBounds.minX + highlightBounds.maxX) / 2),
          cy: Math.round((highlightBounds.minY + highlightBounds.maxY) / 2),
        }
      : null;

  return { viewBox: `0 0 ${width} ${height}`, contextPath, highlightPath, marker };
}
