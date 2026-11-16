import { expect, it } from "vitest";
import {
  parseCountClassification,
  countClassificationBins,
  classificationDescription,
} from "./classification";
import { binIndexForValue } from "@/lib/charts/color-scale";
import { initialMapChoice, mapHref, parseMapChoice, type MapPayload } from "./prototype";
import { datasetMapExport } from "./export";
import { pngNotes } from "./png";
it("validates automatic group counts and finite strictly increasing custom cut points", () => {
  expect(parseCountClassification("equal", "3", null)).toEqual({
    classification: "equal",
    classCount: 3,
  });
  expect(parseCountClassification("custom", null, "25,50")).toEqual({
    classification: "custom",
    classBreaks: [25, 50],
  });
  for (const breaks of [
    "",
    "0,5",
    "-1,5",
    "25,25",
    "50,25",
    "NaN",
    "Infinity",
    "1e2",
    "1,2,3,4,5,6,7",
  ])
    expect(parseCountClassification("custom", null, breaks)).toBeNull();
  for (const count of ["1", "8", "2.5", "02"])
    expect(parseCountClassification("equal", count, null)).toBeNull();
  expect(parseCountClassification(null, "3", null)).toBeNull();
  expect(parseCountClassification("custom", "3", "25,50")).toBeNull();
});
it("includes shared boundaries in the higher group and the maximum in the last group", () => {
  const bins = countClassificationBins({ classification: "custom", classBreaks: [25, 50] }, [
    0,
    25,
    50,
    100,
    null,
  ]);
  expect(bins.map((b) => [b.min, b.max])).toEqual([
    [0, 25],
    [25, 50],
    [50, Infinity],
  ]);
  expect([0, 24, 25, 49, 50, 100].map((v) => binIndexForValue(v, bins))).toEqual([
    0, 0, 1, 1, 2, 2,
  ]);
  const equal = countClassificationBins({ classification: "equal", classCount: 4 }, [0, 100]);
  expect(equal.map((b) => [b.min, b.max])).toEqual([
    [0, 25],
    [25, 50],
    [50, 75],
    [75, 100],
  ]);
  expect(binIndexForValue(100, equal)).toBe(3);
});
it("handles ties, identical observations and empty data without duplicate bins", () => {
  const ties = countClassificationBins(
    { classification: "quantile", classCount: 7 },
    [0, 0, 0, 0, 10],
  );
  expect(ties.length).toBeLessThan(7);
  expect(ties.every((b) => b.max > b.min)).toBe(true);
  expect(
    countClassificationBins({ classification: "equal", classCount: 7 }, [10, 10]).map((b) => [
      b.min,
      b.max,
    ]),
  ).toEqual([[10, 10]]);
  expect(countClassificationBins({ classification: "custom", classBreaks: [10] }, [null])).toEqual(
    [],
  );
  expect(classificationDescription({ classification: "equal", classCount: 7 }, [])).toContain(
    "0 actual groups",
  );
});
it("round-trips settings and rejects duplicate or incompatible share parameters", () => {
  const choice = {
    ...initialMapChoice("bhw-stepzero-2026"),
    classification: "custom" as const,
    classBreaks: [25, 50],
  };
  expect(parseMapChoice(new URLSearchParams(mapHref(choice).split("?")[1]))).toEqual(choice);
  const tiny = { ...choice, classBreaks: [0.0000001, 0.0000002] };
  expect(parseMapChoice(new URLSearchParams(mapHref(tiny).split("?")[1]))).toEqual(tiny);
  for (const tail of [
    "classification=custom&classBreaks=50,25",
    "classification=equal&classCount=8",
    "classification=equal&classCount=3&classCount=4",
    "classBreaks=25",
    "classification=custom&classBreaks=25&classBreaks=50",
  ])
    expect(parseMapChoice(new URLSearchParams(`dataset=bhw-stepzero-2026&${tail}`))).toBeNull();
  expect(
    parseMapChoice(
      new URLSearchParams(
        "dataset=bhw-stepzero-2026&indicator=stepzero.population&classification=equal",
      ),
    ),
  ).toBeNull();
});
it("exports the actual custom legend without changing counts, totals or filter states", () => {
  const choice = {
    ...initialMapChoice("bhw-stepzero-2026"),
    view: "choropleth" as const,
    classification: "custom" as const,
    classBreaks: [25, 50],
    valueMin: 25,
  };
  const data: MapPayload = {
    state: "available",
    rows: [
      { code: "01", name: "Zero", value: 0 },
      { code: "99", name: "Missing shape", value: 50 },
    ],
    total: 999,
    area: "Philippines",
    childLevel: "region",
    source: "Synthetic",
    date: "2025-12-31",
    license: "Test only",
    geometryUrl: "/bhw-connect/geo/regions.json",
  };
  const bins = countClassificationBins(
    choice,
    data.rows.map((r) => r.value),
  );
  const result = datasetMapExport(choice, data, bins, ["01"]);
  expect(result.legend.method).toBe("custom-count-cut-points");
  expect(result.legend.bins.map((b) => b.label)).toEqual(["0–<25", "25–<50", "50 or more"]);
  expect(result.rows[1]).toMatchObject({ value: 50, geometry: "missing", filterState: "Included" });
  expect(result.total).toBe(999);
  expect(result.classification?.actualGroups).toBe(3);
  expect(pngNotes(result).join(" ")).toContain("Exploratory cut points, not official standards");
});
