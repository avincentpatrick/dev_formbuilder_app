import { expect, it } from "vitest";
import {
  countClassificationBins,
  parseCountClassification,
  classificationDescription,
} from "./classification";
import { initialMapChoice, mapHref, parseMapChoice, type MapPayload } from "./prototype";
import { binIndexForValue } from "@/lib/charts/color-scale";
import { datasetMapExport } from "./export";
import { pngNotes } from "./png";
const choice = { ...initialMapChoice("bhw-2025"), indicator: "bhw.active-years" };
it("round-trips year cut points without a percentage cap while rejecting malformed or unsupported settings", () => {
  const current = {
    ...choice,
    classification: "custom" as const,
    classBreaks: [0.0000001, 12.34, 101.25],
  };
  expect(parseMapChoice(new URLSearchParams(mapHref(current).split("?")[1]))).toEqual(current);
  for (const suffix of [
    "classBreaks=0",
    "classBreaks=20,10",
    "classBreaks=10,10",
    "classBreaks=NaN",
    "classBreaks=Infinity",
    "classBreaks=1e2",
    "classBreaks=10&classBreaks=20",
    "classBreaks=10&level=citymun&code=0702234",
  ])
    expect(
      parseMapChoice(
        new URLSearchParams(
          `dataset=bhw-2025&indicator=bhw.active-years&classification=custom&${suffix}`,
        ),
      ),
    ).toBeNull();
  expect(parseCountClassification("custom", null, "101.25")).toEqual({
    classification: "custom",
    classBreaks: [101.25],
  });
});
it("uses complete mean observations for automatic groups and handles endpoints, ties and empty data", () => {
  const bins = countClassificationBins({ classification: "custom", classBreaks: [12.34, 101.25] }, [
    0,
    12.34,
    101.25,
    null,
  ]);
  expect(bins.map((b) => [b.min, b.max])).toEqual([
    [0, 12.34],
    [12.34, 101.25],
    [101.25, Infinity],
  ]);
  expect([0, 12.34, 101.25].map((v) => binIndexForValue(v, bins))).toEqual([0, 1, 2]);
  expect(
    countClassificationBins({ classification: "equal", classCount: 3 }, [0, 101.25, null]).map(
      (b) => [b.min, b.max],
    ),
  ).toEqual([
    [0, 33.75],
    [33.75, 67.5],
    [67.5, 101.25],
  ]);
  expect(
    countClassificationBins({ classification: "quantile", classCount: 7 }, [12.34, 12.34]),
  ).toHaveLength(1);
  expect(countClassificationBins({ classification: "custom", classBreaks: [10] }, [null])).toEqual(
    [],
  );
});
it("exports year classification without turning means into totals or losing uncertainty and missing shapes", () => {
  const current = {
    ...choice,
    classification: "custom" as const,
    classBreaks: [12.34, 101.25],
    valueMin: 12.34,
  };
  const data: MapPayload = {
    state: "available",
    rows: [
      { code: "01", name: "Zero", value: 0 },
      { code: "99", name: "Missing shape", value: 101.25 },
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
  const bins = countClassificationBins(
    current,
    data.rows.map((r) => r.value),
  );
  const exported = datasetMapExport(current, data, bins, ["01"]);
  expect(exported.legend.method).toBe("custom-mean-year-cut-points");
  expect(exported.legend.bins.map((b) => b.label)).toEqual([
    "0–<12.34 years",
    "12.34–<101.25 years",
    "101.25 years or more",
  ]);
  expect(exported.classification).toMatchObject({
    appliesTo: "mean-years-choropleth",
    actualGroups: 3,
  });
  expect(exported.parentMeasure).toEqual({ kind: "mean", value: 9.87, unit: "years" });
  expect(exported.total).toBeNull();
  expect(exported.rows).toHaveLength(3);
  expect(exported.rows[1]).toMatchObject({
    value: 101.25,
    geometry: "missing",
    filterState: "Included",
  });
  expect(exported.indicator.confidenceLevel).toBeNull();
  expect(exported.indicator.denominator).toContain("contributing count not supplied");
  expect(pngNotes(exported).join(" ")).toContain("child means are never averaged");
  expect(
    classificationDescription({ classification: "equal", classCount: 3 }, bins, "years"),
  ).toContain("all reported child-area means");
});
