import { expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { FacilityList } from "./facility-list";
import { TypeBreakdown } from "./type-breakdown";
import { searchFacilities, facilitySearchQuery } from "@/lib/facilities/search";
import type { NhfrFacility } from "@/lib/db/nhfr";
const row: NhfrFacility = {
  facilityCode: "synthetic-2",
  facilityName: "Station 2 with a very long complete name",
  facilityType: "Clinic",
  ownershipMajor: "Government",
  ownershipSub: null,
  barangayName: "North",
  barangayGeoCode: null,
  bedCapacity: 0,
  licensingStatus: null,
};
const rows = [
  { ...row, facilityCode: "synthetic-10", facilityName: "Station 10", barangayName: null },
  row,
];
function list(facilities: NhfrFacility[] | null, expectedCount = 2, query = "") {
  return renderToStaticMarkup(
    <FacilityList
      facilities={facilities}
      expectedCount={expectedCount}
      query={query}
      areaHref="/facilities/citymun/0102801"
    />,
  );
}
it("distinguishes unavailable, true zero, partial and no-match lists", () => {
  expect(list(null)).toContain("could not be loaded");
  expect(list([])).toContain("not a reported zero");
  expect(list([], 0)).toContain("No health facilities are on the DOH registry");
  expect(list([row])).toContain("Incomplete list");
  expect(list(rows, 2, "impossible")).toContain("No facilities match this search");
  expect(list(rows, 2, "impossible")).not.toContain("No health facilities are on");
});
it("searches name/type/barangay without mutating or truncating the source list", () => {
  expect(searchFacilities(rows, "CLINIC north")).toEqual([row]);
  expect(searchFacilities(rows, "station").map((r) => r.facilityCode)).toEqual([
    "synthetic-2",
    "synthetic-10",
  ]);
  expect(rows[0].facilityCode).toBe("synthetic-10");
  const html = list(rows, 2, "north");
  expect(html).toContain(row.facilityName);
  expect(html).toContain("1 matching facility of 2 loaded");
  expect(html).toContain("Area totals, map and downloads stay unchanged");
  expect(html).toContain('name="facilitySearch"');
  expect(html).toContain("Licence status not stated");
  expect(facilitySearchQuery(["a", "b"])).toBe("");
  expect(facilitySearchQuery("x".repeat(300))).toHaveLength(200);
});
it("refuses to turn failed or incomplete type reads into zero", () => {
  const render = (items: Parameters<typeof TypeBreakdown>[0]["items"], count: number) =>
    renderToStaticMarkup(<TypeBreakdown items={items} expectedCount={count} />);
  expect(render(null, 0)).toContain("could not be loaded");
  expect(render([], 3)).toContain("not a reported zero");
  expect(render([], 0)).toContain("No health facilities are on");
  expect(
    render([{ facilityType: "Clinic", nFacilities: 1, nGovernment: 1, nPrivate: 0 }], 3),
  ).toContain("Incomplete type detail");
});
