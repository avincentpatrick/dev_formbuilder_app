import type { ExpressionSpecification, Map as LibreMap } from "maplibre-gl";
import { colorForValue, NO_DATA_COLOR, type ColorBin } from "@/lib/charts/color-scale";
import type { MapRow } from "@/lib/maps/prototype";

/** Update exploration styling without replacing geometry, workers or the user's viewport. */
export function applyDatasetStyle(
  map: Pick<LibreMap, "getLayer" | "setPaintProperty" | "setFilter">,
  rows: MapRow[],
  bins: ColorBin[],
  excludedCodes: string[],
  view: "circles" | "choropleth",
) {
  const excluded: ExpressionSpecification = ["in", ["get", "geo_code"], ["literal", excludedCodes]];
  const included: ExpressionSpecification = ["!", excluded];
  const colorEntries = [
    ...new Map(
      rows.map(
        (row) =>
          [row.code, row.value === null ? NO_DATA_COLOR : colorForValue(row.value, bins)] as const,
      ),
    ),
  ];
  const [first, ...remaining] = colorEntries;
  const colors: ExpressionSpecification = first
    ? ["match", ["get", "geo_code"], first[0], first[1], ...remaining.flat(), NO_DATA_COLOR]
    : ["literal", NO_DATA_COLOR];
  map.setPaintProperty("fill", "fill-color", [
    "case",
    excluded,
    "#ffffff",
    view === "circles" ? "#e5e7eb" : colors,
  ]);
  map.setFilter("excluded-range", excluded);
  if (map.getLayer("counts"))
    map.setFilter("counts", ["all", included, [">", ["get", "value"], 0]]);
  if (map.getLayer("zeros")) map.setFilter("zeros", ["all", included, ["==", ["get", "value"], 0]]);
  if (map.getLayer("place-labels")) map.setFilter("place-labels", included);
}
