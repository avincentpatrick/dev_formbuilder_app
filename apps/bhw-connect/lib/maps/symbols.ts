import type { MapChoice, MapRow } from "./prototype";
import { geometryPositions } from "@/lib/geo/positions";

export const MAX_SYMBOL_RADIUS = 24;
export function supportsCircles(choice: Pick<MapChoice, "dataset" | "indicator">) {
  return (
    ["bhw-stepzero-2026", "bhw-2025"].includes(choice.dataset) &&
    [
      "bhw.total",
      "bhw.registered",
      "bhw.registered-accredited",
      "bhw.nonregistered",
      "bhw.validated-profiles",
    ].includes(choice.indicator)
  );
}
export function mapView(choice: MapChoice) {
  return choice.view ?? (supportsCircles(choice) ? "circles" : "choropleth");
}
export function symbolMaximum(rows: readonly MapRow[]) {
  return Math.max(
    0,
    ...rows.map((r) =>
      r.value !== null && Number.isFinite(r.value) && r.value >= 0 ? r.value : 0,
    ),
  );
}
/** No positive minimum: circle area, not radius, is proportional to the count.
 * Zero is drawn separately as a fixed hollow marker, not as a magnitude symbol. */
export function symbolRadius(value: number, maximum: number) {
  return value > 0 && maximum > 0 ? MAX_SYMBOL_RADIUS * Math.sqrt(value / maximum) : 0;
}
export function sizeLegend(rows: readonly MapRow[]) {
  const maximum = symbolMaximum(rows);
  return maximum > 0
    ? [...new Set([Math.max(1, Math.round(maximum / 4)), maximum])].map((value) => ({
        value,
        radius: symbolRadius(value, maximum),
      }))
    : [];
}
/** Approximate area locator from supplied boundary bounds. Never fabricate a
 * position for a row without a shape; positions do not represent worker addresses. */
export function boundaryCenter(geometry: unknown): [number, number] | null {
  const positions = geometryPositions(geometry);
  if (!positions.length) return null;
  let west = Infinity,
    east = -Infinity,
    south = Infinity,
    north = -Infinity;
  for (const [x, y] of positions) {
    west = Math.min(west, x);
    east = Math.max(east, x);
    south = Math.min(south, y);
    north = Math.max(north, y);
  }
  return [(west + east) / 2, (south + north) / 2];
}
