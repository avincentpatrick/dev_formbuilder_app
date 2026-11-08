import { expect, it } from "vitest";
import { datasetMapExport } from "./export";
import { initialMapChoice, type MapPayload } from "./prototype";
import { POVERTY_BINS } from "./poverty";
const data: MapPayload = { state: "available", rows: [
  { code: "01", name: "Zero", value: 0 },
  { code: "02", name: "Missing", value: null, note: "Unavailable source" },
  { code: "99", name: "Without polygon", value: 42 },
], total: 42, area: "Philippines", childLevel: "region", source: "Test source",
  date: "2026-09-01", license: "Test license", geometryUrl: "/bhw-connect/geo/regions.json" };
it("retains complete rows, zeros, source context, selection and geometry independently", () => {
  const choice = { ...initialMapChoice("nhfr-2026-09"), selected: "99" };
  const result = datasetMapExport(choice, data, [{ min: 0, max: 42, color: "#fff" }], ["01", "02"]);
  expect(result.rows).toHaveLength(3);
  expect(result.rows[0]).toMatchObject({ value: 0, valueState: "available" });
  expect(result.rows[1]).toMatchObject({ value: null, note: "Unavailable source", valueState: "not-reported-or-unavailable" });
  expect(result.rows[2]).toMatchObject({ value: 42, geometry: "missing" });
  expect(result.total).toBe(42);
  expect(result.selection).toEqual(choice);
  expect(result.indicator.period).toBe("September 2026");
  expect(result.source).toMatchObject({ title: data.source, date: data.date, license: data.license });
  expect(result.legend.method).toBe("quantiles-relative-to-displayed-areas");
});
it("does not manufacture rates at navigation-only levels", () => {
  const result = datasetMapExport(initialMapChoice("psa-sae-poverty-2023"), { ...data, valueSupport: "navigation-only" }, POVERTY_BINS, null);
  expect(result.total).toBeNull();
  expect(result.legend.bins).toEqual([]);
  expect(result.rows.every((r) => r.value === null && r.geometry === "not-checked" && r.valueState === "not-published-at-this-level")).toBe(true);
});
it("keeps published uncertainty and exact fixed legend bounds", () => {
  const result = datasetMapExport(initialMapChoice("psa-sae-poverty-2023"), { ...data, rows: [{ code: "0100101", name: "Test", value: 12.3, ciLow: 10.1, ciHigh: 14.5 }] }, POVERTY_BINS, []);
  expect(result.rows[0]).toMatchObject({ value: 12.3, ciLow: 10.1, ciHigh: 14.5 });
  expect(result.legend.bins.at(-1)).toMatchObject({ max: 100, openEnded: false });
  expect(result.legend.method).toBe("fixed");
});
it("rejects planned datasets and wrong indicator periods", () => {
  expect(() => datasetMapExport(initialMapChoice("ways-forward"), data, [], null)).toThrow();
  expect(() => datasetMapExport({ ...initialMapChoice("nhfr-2026-09"), period: "2025" }, data, [], null)).toThrow();
});

it("exports a declared parent rate separately from totals with its readable denominator", () => {
  const choice = { ...initialMapChoice("bhw-stepzero-2026"), indicator: "bhw.declared-accreditation" };
  const result = datasetMapExport(choice, { ...data, total: null, parentRate: 25 }, [], null);
  expect(result.parentMeasure).toEqual({ kind: "rate", value: 25, unit: "%" });
  expect(result.total).toBeNull();
  expect(result.legend.method).toBe("fixed");
  expect(result.indicator.denominator).toContain("All LGU-declared BHWs");
});

it("exports a topic-specific Census training rate with its profile denominator and Wilson level", () => {
  const choice = { ...initialMapChoice("bhw-2025"), indicator: "bhw.training.basic_life_support" };
  const result = datasetMapExport(choice, { ...data, total: null, parentRate: 75 }, [], null);
  expect(result.indicator).toMatchObject({ label: "Basic Life Support", period: "2025", confidenceLevel: 0.95 });
  expect(result.indicator.denominator).toContain("Validated BHW Census profiles");
  expect(result.parentMeasure).toMatchObject({ kind: "rate", value: 75 });
  expect(result.total).toBeNull();
  expect(result.legend.method).toBe("fixed");
});

it("does not assign accreditation uncertainty to honorarium receipt exports", () => {
  const result = datasetMapExport({ ...initialMapChoice("bhw-2025"), indicator: "bhw.any-honorarium" },
    { ...data, total: null, parentRate: 75 }, [], null);
  expect(result.indicator).toMatchObject({ period: "2025", confidenceLevel: null, unit: "%" });
  expect(result.indicator.denominator).toContain("Validated BHW Census profiles");
  expect(result.parentMeasure).toMatchObject({ kind: "rate", value: 75 });
});
