/**
 * Which districts a map lists but cannot draw.
 *
 * `public/geo/districts.json` holds an outline for only some of the 250 districts: a district that
 * splits a city by barangay, or has a member with no source boundary, has none. Its figures still
 * exist, so without this the district is simply absent from the map with nothing saying so
 * (docs/DECISIONS.md, 2026-11-11). Returns those districts by name, for the caller to list.
 *
 * `drawnCodes` is null until the boundary file has loaded; nothing is reported missing before then.
 */
export function districtsWithoutOutline<T extends { districtCode: string; districtName: string }>(
  districts: readonly T[],
  drawnCodes: ReadonlySet<string> | null,
): T[] {
  if (drawnCodes === null) return [];
  return districts
    .filter((d) => !drawnCodes.has(d.districtCode))
    .sort((a, b) => a.districtName.localeCompare(b.districtName));
}
