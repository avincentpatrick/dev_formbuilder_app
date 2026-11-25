import type { FacilityReport } from "./report";
/** Generated aggregate-only fixtures; no source records. */
export function reportFixture(nTypes = 25, nAreas = 55): FacilityReport {
  const count = nAreas * 2;
  const government = nAreas;
  const privateCount = nAreas;
  const present = nAreas;
  const barangays = nAreas * 10;
  const types = Array.from({ length: nTypes }, (_, i) => ({
    facilityType: `Synthetic type ${String(i + 1).padStart(2, "0")} with a long complete category name`,
    nFacilities: Math.floor(count / nTypes) + (i < count % nTypes ? 1 : 0),
    nGovernment: 0,
    nPrivate: 0,
  }));
  let gov = government;
  for (const t of types) {
    t.nGovernment = Math.min(gov, t.nFacilities);
    t.nPrivate = t.nFacilities - t.nGovernment;
    gov -= t.nGovernment;
  }
  return {
    code: "PH",
    level: "national",
    name: "Synthetic parent",
    summary: {
      total: count,
      count,
      government,
      private: privateCount,
      beds: 100,
      present,
      barangays,
      unattributed: 0,
      types: types.map((t) => t.facilityType),
    },
    types,
    areas: {
      parent: count,
      count,
      present,
      barangays,
      selectedAreas: nAreas,
      rows: Array.from({ length: nAreas }, (_, i) => ({
        code: String(i + 1).padStart(2, "0"),
        name: `Synthetic area ${String(i + 1).padStart(2, "0")}`,
        level: "region",
        count: 2,
        government: 1,
        private: 1,
        present: 1,
        barangays: 10,
        facilityShare: 100 / nAreas,
        presenceShare: 10,
      })),
    },
  };
}
