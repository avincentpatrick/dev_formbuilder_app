import { expect, it } from "vitest";
import { mapParents } from "./breadcrumbs";
const region = {
  geoCode: "07",
  geoLevel: "region" as const,
  geoName: "Region VII",
  incomeClass: null,
};
const province = {
  geoCode: "07022",
  geoLevel: "province" as const,
  geoName: "Cebu",
  incomeClass: null,
};
it("resolves names for direct municipality and province links without including self", () => {
  expect(
    mapParents({ level: "citymun", code: "0702234" }, { region, province, citymun: null }),
  ).toEqual({
    parents: [
      { level: "region", code: "07", name: "Region VII" },
      { level: "province", code: "07022", name: "Cebu" },
    ],
    complete: true,
  });
  expect(
    mapParents({ level: "province", code: "07022" }, { region, province, citymun: null }).parents,
  ).toHaveLength(1);
});
it("does not invent missing parents or accept mismatched levels", () => {
  expect(mapParents({ level: "citymun", code: "0702234" }, null)).toEqual({
    parents: [],
    complete: false,
  });
  expect(
    mapParents(
      { level: "province", code: "07306" },
      { region: province, province: null, citymun: null },
    ),
  ).toEqual({ parents: [], complete: false });
  expect(mapParents({ level: "region", code: "07" }, null)).toEqual({
    parents: [],
    complete: true,
  });
});
