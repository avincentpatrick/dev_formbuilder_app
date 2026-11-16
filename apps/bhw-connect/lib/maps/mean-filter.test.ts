import { expect, it } from "vitest";
import { parseYearsRange, valueRangeKind } from "./value-filter";
import { initialMapChoice, mapHref, parseMapChoice, type MapPayload } from "./prototype";
import { computeQuantileBins } from "@/lib/charts/color-scale";
import { datasetMapExport } from "./export";
import { pngNotes } from "./png";
const choice = { ...initialMapChoice("bhw-2025"), indicator: "bhw.active-years" };
it("accepts nonnegative decimal years without a percentage cap and rejects malformed bounds", () => {
  expect(parseYearsRange("0", "101.25")).toEqual({ valueMin: 0, valueMax: 101.25 });
  expect(parseYearsRange(null, null)).toEqual({});
  for (const min of ["", "-1", "NaN", "Infinity", "1e2", " 5", "01", "9".repeat(400)])
    expect(parseYearsRange(min, null)).toBeNull();
  expect(parseYearsRange("10.5", "10.4")).toBeNull();
  expect(valueRangeKind(choice)).toBe("years");
  expect(valueRangeKind({ ...choice, level: "citymun" })).toBeNull();
});
it("round-trips decimal limits and preserves count, percentage and classification domains", () => {
  for (const valueMin of [0, 12.34, 101.25, 0.0000001]) {
    const current = { ...choice, valueMin };
    expect(parseMapChoice(new URLSearchParams(mapHref(current).split("?")[1]))).toEqual(current);
  }
  for (const suffix of [
    "valueMin=1&valueMin=2",
    "valueMin=1&level=citymun&code=0702234",
    "classification=equal&classCount=3",
  ])
    expect(
      parseMapChoice(new URLSearchParams(`dataset=bhw-2025&indicator=bhw.active-years&${suffix}`)),
    ).toBeNull();
  expect(parseMapChoice(new URLSearchParams("dataset=bhw-2025&valueMin=0.5"))).toBeNull();
  expect(
    parseMapChoice(
      new URLSearchParams("dataset=bhw-2025&indicator=bhw.profile-accreditation&valueMin=101"),
    ),
  ).toBeNull();
});
it("exports full means, unchanged quantiles and published parent mean without invented sample size", () => {
  const data: MapPayload = {
    state: "available",
    rows: [
      { code: "01", name: "Zero", value: 0 },
      { code: "02", name: "Decimal", value: 12.34 },
      { code: "99", name: "Missing shape", value: 20.5 },
      { code: "98", name: "Missing value", value: null },
    ],
    total: null,
    parentMean: 9.87,
    area: "Synthetic",
    childLevel: "region",
    source: "Synthetic",
    date: "2025-12-31",
    license: "Test only",
    geometryUrl: "unused",
  };
  const bins = computeQuantileBins(data.rows.map((r) => r.value));
  const before = datasetMapExport(choice, data, bins, ["01", "02"]);
  const after = datasetMapExport({ ...choice, valueMin: 12.34, valueMax: 20.5 }, data, bins, [
    "01",
    "02",
  ]);
  expect(after.legend).toEqual(before.legend);
  expect(after.parentMeasure).toEqual({ kind: "mean", value: 9.87, unit: "years" });
  expect(after.rows.map((r) => r.value)).toEqual([0, 12.34, 20.5, null]);
  expect(after.rows[2]).toMatchObject({ filterState: "Included", geometry: "missing" });
  expect(after.valueFilter).toMatchObject({
    unit: "years",
    included: 2,
    excluded: 1,
    unavailable: 1,
  });
  expect(after.indicator.denominator).toContain("contributing count not supplied");
  expect(after.indicator.confidenceLevel).toBeNull();
  expect(pngNotes(after).join(" ")).toContain("Mean active years range: 12.34 to 20.5");
  expect(pngNotes(after).join(" ")).toContain("child means are never averaged");
});
