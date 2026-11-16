import { expect, it } from "vitest";
import {
  parsePercentageClassification,
  percentageClassificationBins,
  classificationDescription,
} from "./classification";
import { binIndexForValue } from "@/lib/charts/color-scale";
import { initialMapChoice, mapHref, parseMapChoice, type MapPayload } from "./prototype";
import { datasetMapExport } from "./export";
import { pngNotes } from "./png";

it("validates interior percentage cut points and round-trips bounded settings", () => {
  expect(parsePercentageClassification(null, null, null)).toEqual({});
  expect(parsePercentageClassification("custom", null, "25.5,75")).toEqual({
    classification: "custom",
    classBreaks: [25.5, 75],
  });
  for (const breaks of ["0", "100", "101", "-1", "50,25", "25,25", "NaN", "1e1"])
    expect(parsePercentageClassification("custom", null, breaks)).toBeNull();
  const choice = {
    ...initialMapChoice("bhw-stepzero-2026"),
    indicator: "bhw.declared-accreditation",
    classification: "custom" as const,
    classBreaks: [0.0000001, 25.5, 75],
  };
  expect(parseMapChoice(new URLSearchParams(mapHref(choice).split("?")[1]))).toEqual(choice);
  for (const suffix of [
    "classBreaks=100",
    "classBreaks=25&classBreaks=50",
    "classBreaks=25&level=citymun&code=0702234",
  ])
    expect(
      parseMapChoice(
        new URLSearchParams(
          `dataset=bhw-stepzero-2026&indicator=bhw.declared-accreditation&classification=custom&${suffix}`,
        ),
      ),
    ).toBeNull();
});
it("includes endpoints, reduces ties and handles unavailable or invalid values", () => {
  const custom = percentageClassificationBins(
    { classification: "custom", classBreaks: [25.5, 75] },
    [0, 100, null],
  );
  expect(custom.map((b) => [b.min, b.max])).toEqual([
    [0, 25.5],
    [25.5, 75],
    [75, 100],
  ]);
  expect([0, 25.5, 75, 100].map((value) => binIndexForValue(value, custom))).toEqual([0, 1, 2, 2]);
  expect(
    percentageClassificationBins({ classification: "equal", classCount: 4 }, [0, 100]).map((b) => [
      b.min,
      b.max,
    ]),
  ).toEqual([
    [0, 25],
    [25, 50],
    [50, 75],
    [75, 100],
  ]);
  expect(
    percentageClassificationBins({ classification: "quantile", classCount: 7 }, [50, 50]),
  ).toHaveLength(1);
  expect(
    percentageClassificationBins({ classification: "custom", classBreaks: [25] }, [
      null,
      NaN,
      Infinity,
      -1,
      101,
    ]),
  ).toEqual([]);
});
it("exports percentage legends without changing parent rates, full rows, or filter states", () => {
  const choice = {
    ...initialMapChoice("bhw-stepzero-2026"),
    indicator: "bhw.declared-accreditation",
    classification: "custom" as const,
    classBreaks: [25.5, 75],
    valueMin: 50,
  };
  const data: MapPayload = {
    state: "available",
    rows: [
      { code: "01", name: "Zero", value: 0 },
      { code: "99", name: "Missing shape", value: 100 },
      { code: "98", name: "Missing value", value: null },
    ],
    total: null,
    parentRate: 42.1,
    area: "Philippines",
    childLevel: "region",
    source: "Synthetic",
    date: "2025-12-31",
    license: "Test only",
    geometryUrl: "/bhw-connect/geo/regions.json",
  };
  const bins = percentageClassificationBins(
    choice,
    data.rows.map((r) => r.value),
  );
  const result = datasetMapExport(choice, data, bins, ["01"]);
  expect(result.legend.method).toBe("custom-percentage-cut-points");
  expect(result.legend.bins.map((b) => b.label)).toEqual(["0–<25.5%", "25.5–<75%", "75–100%"]);
  expect(result.legend.bins[2]).toMatchObject({ max: 100, maxInclusive: true, openEnded: false });
  expect(result.parentMeasure).toMatchObject({ kind: "rate", value: 42.1 });
  expect(result.rows).toHaveLength(3);
  expect(result.rows[1]).toMatchObject({
    value: 100,
    geometry: "missing",
    filterState: "Included",
  });
  expect(result.classification).toMatchObject({
    appliesTo: "percentage-choropleth",
    actualGroups: 3,
  });
  expect(pngNotes(result).join(" ")).toContain("Groups cover 0–100%");
  expect(pngNotes(result).join(" ")).not.toContain("Fixed classification");
  expect(
    classificationDescription({ classification: "equal", classCount: 3 }, bins, "percentage"),
  ).toContain("all reported child-area percentages");
});
