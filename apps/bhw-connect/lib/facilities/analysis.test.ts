import { expect, it } from "vitest";
import { facilityMix, facilityPlaces } from "./analysis";
import { toNhfrCounts, type NhfrChild } from "@/lib/db/nhfr";
const types = [
  { facilityType: "Hospital", nFacilities: 2, nGovernment: 1, nPrivate: 1 },
  { facilityType: "Clinic", nFacilities: 8, nGovernment: 6, nPrivate: 2 },
];
it("requires a reconciled, unique partition before deriving type shares", () => {
  expect(facilityMix(types, 10)?.map((i) => i.facilityType)).toEqual(["Clinic", "Hospital"]);
  for (const rows of [
    null,
    [],
    [types[0]],
    [...types, types[0]],
    [{ ...types[0], nPrivate: 0 }, types[1]],
    [{ ...types[0], nFacilities: NaN }, types[1]],
  ])
    expect(facilityMix(rows, 10)).toBeNull();
  expect(facilityMix([], 0)).toBeNull();
});
const child: NhfrChild = {
  ...toNhfrCounts({
    geo_code: "01",
    geo_level: "region",
    n_facilities: 10,
    n_government: 7,
    n_private: 3,
    n_barangay_health_station: 0,
    n_rural_health_unit: 0,
    n_hospital: 2,
    n_birthing_home: 0,
    total_bed_capacity: 0,
    n_barangays: 3,
    n_barangays_with_facility: 1,
  }),
  geoName: "Synthetic",
};
it("requires the full child roster and matching total, using raw presence fractions", () => {
  expect(facilityPlaces([child], 10, ["01"])?.[0].presence).toBe(100 / 3);
  expect(facilityPlaces([child], 10, ["01", "02"])).toBeNull();
  expect(facilityPlaces([child], 11, ["01"])).toBeNull();
  expect(facilityPlaces([child, child], 20, ["01", "02"])).toBeNull();
  expect(facilityPlaces([{ ...child, nBarangaysWithFacility: 4 }], 10, ["01"])).toBeNull();
  expect(
    facilityPlaces([{ ...child, nBarangays: 0, nBarangaysWithFacility: 0 }], 10, ["01"])?.[0]
      .presence,
  ).toBeNull();
});
