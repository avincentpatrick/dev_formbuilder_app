import { buildLocatorMap, type BoundaryCollection } from "@/lib/geo/locator-shapes";
import { boundaryCenter } from "./symbols";
import type { MapRow } from "./prototype";

/** Label zero and missing observations too, but never invent a position without a shape. */
export function mapLabelPoints(
  rows: readonly MapRow[],
  geometry: BoundaryCollection,
): GeoJSON.FeatureCollection<GeoJSON.Point> {
  const byCode = new Map(rows.map((row) => [row.code, row]));
  const seen = new Set<string>();
  const features: GeoJSON.Feature<GeoJSON.Point>[] = [];
  for (const feature of geometry.features) {
    const code = feature.properties.geo_code;
    const row = code ? byCode.get(code) : undefined;
    const center = boundaryCenter(feature.geometry);
    if (!code || !row || !center || seen.has(code)) continue;
    seen.add(code);
    features.push({
      type: "Feature",
      geometry: { type: "Point", coordinates: center },
      properties: { geo_code: code, name: row.name },
    });
  }
  return { type: "FeatureCollection", features };
}

/** Country context and actual supplied shapes, not a fabricated parent outline or worker location. */
export function nationalLocator(
  country: BoundaryCollection,
  geometry: BoundaryCollection,
  selected: string | null,
) {
  const highlighted = geometry.features.filter(
    (feature) =>
      (!selected || feature.properties.geo_code === selected) &&
      boundaryCenter(feature.geometry) !== null,
  );
  if (!highlighted.length || !country.features.some((feature) => boundaryCenter(feature.geometry)))
    return null;
  const highlightCode = "__locator_highlight__";
  return buildLocatorMap(
    {
      features: [
        ...country.features,
        ...highlighted.map((feature) => ({ ...feature, properties: { geo_code: highlightCode } })),
      ],
    },
    highlightCode,
  );
}
