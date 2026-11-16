import { expect, it } from "vitest";
import { parsePercentageRange, valueRangeKind } from "./value-filter";
import {
  initialMapChoice,
  parseMapChoice,
  mapHref,
  prototypeIndicators,
  type MapPayload,
} from "./prototype";
import { datasetMapExport } from "./export";
import { pngNotes } from "./png";
import { STEPZERO_ACCREDITATION_BINS } from "./stepzero-accreditation";
const choice = {
  ...initialMapChoice("bhw-stepzero-2026"),
  indicator: "bhw.declared-accreditation",
};
it("validates the documented share domain, decimals, both endpoints and invalid bounds", () => {
  expect(parsePercentageRange("0", "100")).toEqual({ valueMin: 0, valueMax: 100 });
  expect(parsePercentageRange("25.5", "50.25")).toEqual({ valueMin: 25.5, valueMax: 50.25 });
  expect(parsePercentageRange(null, null)).toEqual({});
  for (const min of ["", "-1", "101", "Infinity", "NaN", "1e2", "10%", " 20", "00.5"])
    expect(parsePercentageRange(min, null)).toBeNull();
  expect(parsePercentageRange("50", "25")).toBeNull();
});
it("round-trips percentage limits including tiny decimals without changing count validation", () => {
  for (const valueMin of [0, 25.5, 0.0000001]) {
    const current = { ...choice, valueMin, valueMax: 100 };
    expect(parseMapChoice(new URLSearchParams(mapHref(current).split("?")[1]))).toEqual(current);
  }
  for (const suffix of [
    "valueMax=100.1",
    "valueMin=20&valueMin=30",
    "valueMin=1&level=citymun&code=0702234",
  ])
    expect(
      parseMapChoice(
        new URLSearchParams(
          `dataset=bhw-stepzero-2026&indicator=bhw.declared-accreditation&${suffix}`,
        ),
      ),
    ).toBeNull();
  expect(parseMapChoice(new URLSearchParams("dataset=bhw-stepzero-2026&valueMin=0.5"))).toBeNull();
});
it("limits percentage filters to published BHW shares, preserving separate mean/context domains", () => {
  expect(valueRangeKind(choice)).toBe("percentage");
  for (const indicator of prototypeIndicators("bhw-2025").filter((m) => m.unit === "%"))
    expect(valueRangeKind({ dataset: "bhw-2025", indicator: indicator.id })).toBe("percentage");
  for (const current of [
    { dataset: "bhw-stepzero-2026", indicator: "stepzero.population" },
    { dataset: "psa-sae-poverty-2023", indicator: "poverty.incidence" },
  ])
    expect(valueRangeKind(current)).toBeNull();
});
it("exports the complete rates, fixed breaks, denominator and published parent rate independently of filtering", () => {
  const data: MapPayload = {
    state: "available",
    rows: [
      { code: "01", name: "Zero", value: 0 },
      { code: "02", name: "Full", value: 100 },
      { code: "03", name: "Middle", value: 25.5 },
      { code: "04", name: "Missing", value: null },
    ],
    total: null,
    parentRate: 42.1,
    area: "Synthetic",
    childLevel: "region",
    source: "Synthetic",
    date: null,
    license: "Test only",
    geometryUrl: "unused",
  };
  const exported = datasetMapExport(
    { ...choice, valueMin: 25.5, valueMax: 100 },
    data,
    STEPZERO_ACCREDITATION_BINS,
    ["01", "02"],
  );
  expect(exported.parentMeasure).toMatchObject({ kind: "rate", value: 42.1 });
  expect(exported.valueFilter).toMatchObject({
    unit: "%",
    included: 2,
    excluded: 1,
    unavailable: 1,
  });
  expect(exported.rows.map((r) => r.value)).toEqual([0, 100, 25.5, null]);
  expect(exported.rows[2]).toMatchObject({ geometry: "missing", filterState: "Included" });
  expect(exported.legend.method).toBe("fixed");
  expect(exported.legend.bins).toHaveLength(5);
  expect(exported.indicator.denominator).toContain("All LGU-reported BHWs");
  expect(pngNotes(exported).join(" ")).toContain("Percentage range (%): 25.5 to 100");
});
