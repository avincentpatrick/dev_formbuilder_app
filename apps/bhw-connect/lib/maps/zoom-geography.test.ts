import { expect, it } from "vitest";
import { initialMapChoice, type MapPayload } from "./prototype";
import { zoomGeography } from "./zoom-geography";
const choice = {
  ...initialMapChoice("bhw-stepzero-2026"),
  valueMin: 2,
  classification: "equal" as const,
  classCount: 4,
};
const data: MapPayload = {
  state: "available",
  rows: [{ code: "01", name: "Region", value: 20 }],
  total: 20,
  area: "Philippines",
  childLevel: "region",
  source: "fixture",
  date: null,
  license: null,
  geometryUrl: "fixture",
};
it("zooms into an authoritative visible child while retaining analysis settings", () => {
  expect(zoomGeography(choice, data, 6, "in", "01")).toEqual({
    ...choice,
    level: "region",
    code: "01",
    selected: null,
  });
  expect(zoomGeography(choice, data, 5.9, "in", "01")).toBeNull();
  expect(zoomGeography(choice, data, 7, "in", "unknown")).toBeNull();
  expect(zoomGeography(choice, data, 7, "in", null)).toBeNull();
});
it("uses a separate zoom-out threshold and authoritative parents", () => {
  const region = { ...choice, level: "region" as const, code: "01" };
  expect(zoomGeography(region, data, 5.7, "out", null)).toBeNull();
  expect(zoomGeography(region, data, 5.5, "out", null)).toEqual({ ...choice, selected: null });
  const province = { ...choice, level: "province" as const, code: "01028" };
  expect(zoomGeography(province, data, 7, "out", null)).toBeNull();
  expect(
    zoomGeography(
      province,
      {
        ...data,
        navigation: { complete: true, parents: [{ level: "region", code: "01", name: "Region" }] },
      },
      7,
      "out",
      null,
    )?.code,
  ).toBe("01");
});
it("stops where the indicator cannot map barangays and never drills past barangays", () => {
  const province = { ...choice, level: "province" as const, code: "01028" };
  const cities = { ...data, childLevel: "citymun" as const };
  expect(zoomGeography(province, cities, 11, "in", "01")).toBeNull();
  expect(zoomGeography(province, { ...cities, barangayDrill: true }, 11, "in", "01")?.level).toBe(
    "citymun",
  );
  expect(
    zoomGeography(
      { ...choice, level: "citymun", code: "0102801" },
      { ...data, childLevel: "barangay" },
      16,
      "in",
      "01",
    ),
  ).toBeNull();
});
