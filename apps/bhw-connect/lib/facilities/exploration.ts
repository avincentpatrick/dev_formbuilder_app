import type { NhfrFacility } from "@/lib/db/nhfr";
import { buildFacilityPoints } from "@/lib/geo/facility-points";
export type ExplorationInput = {
  code: string;
  name: string;
  expected: number;
  expectedBarangays: number;
  facilities: NhfrFacility[];
  barangays: { geoCode: string; geoName: string }[];
  centroids: [string, [number, number]][];
  outlineUrl: string | null;
  outline?: unknown[];
};
export type ExplorationFilters = { types: string[]; ownership: string };
export function validExploration(input: ExplorationInput) {
  return (
    input.facilities.length === input.expected &&
    new Set(input.facilities.map((f) => f.facilityCode)).size === input.expected &&
    input.facilities.every((f) => !!f.facilityCode) &&
    input.barangays.length === input.expectedBarangays &&
    new Set(input.barangays.map((b) => b.geoCode)).size === input.expectedBarangays
  );
}
export function exploreFacilities(input: ExplorationInput, filters: ExplorationFilters) {
  if (!validExploration(input)) return null;
  const facilities = input.facilities.filter(
    (f) =>
      (!filters.types.length || filters.types.includes(f.facilityType)) &&
      (!filters.ownership || f.ownershipMajor === filters.ownership),
  );
  const centroids = new Map(
    input.centroids.filter(
      ([, [lon, lat]]) =>
        Number.isFinite(lon) &&
        Number.isFinite(lat) &&
        lon >= -180 &&
        lon <= 180 &&
        lat >= -90 &&
        lat <= 90 &&
        !(lon === 0 && lat === 0),
    ),
  );
  const data = buildFacilityPoints({
    barangays: input.barangays,
    centroids,
    facilities,
    expectedFacilities: facilities.length,
  });
  const known = new Set(input.barangays.map((b) => b.geoCode));
  const counts = new Map<string, number>();
  let unassigned = 0;
  for (const f of facilities) {
    if (!f.barangayGeoCode || !known.has(f.barangayGeoCode)) unassigned++;
    else counts.set(f.barangayGeoCode, (counts.get(f.barangayGeoCode) ?? 0) + 1);
  }
  const rows = input.barangays.map((b) => ({
    ...b,
    count: counts.get(b.geoCode) ?? 0,
    mapped: centroids.has(b.geoCode),
  }));
  return {
    facilities,
    data,
    rows,
    unassigned,
    missingGeometry: rows.filter((r) => !r.mapped).reduce((n, r) => n + r.count, 0),
    present: rows.filter((r) => r.count > 0).length,
  };
}
