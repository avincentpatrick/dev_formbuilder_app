import type { MapChoice, MapPayload } from "./prototype";

export type MapCamera = { center: [number, number]; zoom: number };
// Half a zoom level of separation prevents bouncing between adjacent levels.
const enter = { national: 6, region: 8, province: 10, citymun: Infinity };
const leave = { national: -Infinity, region: 5.5, province: 7.5, citymun: 9.5 };

export function zoomGeography(
  choice: MapChoice,
  data: MapPayload,
  zoom: number,
  direction: "in" | "out",
  focus: string | null,
): MapChoice | null {
  if (
    direction === "in" &&
    zoom >= enter[choice.level] &&
    focus &&
    data.rows.some((r) => r.code === focus)
  ) {
    const level = data.childLevel;
    if (level === "barangay" || (level === "citymun" && !data.barangayDrill)) return null;
    return { ...choice, level, code: focus, selected: null };
  }
  if (direction === "out" && zoom <= leave[choice.level]) {
    const parent =
      choice.level === "region"
        ? { level: "national" as const, code: "PH" }
        : data.navigation?.parents.at(-1);
    if (parent) return { ...choice, level: parent.level, code: parent.code, selected: null };
  }
  return null;
}
