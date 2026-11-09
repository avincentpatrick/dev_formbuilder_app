import { expect, it } from "vitest";
import { pngNotes } from "./png";
import { datasetMapExport } from "./export";
import { initialMapChoice, type MapPayload } from "./prototype";
const data: MapPayload = { state: "available", rows: [
  { code: "01", name: "Synthetic zero", value: 0 },
  { code: "02", name: "Without shape", value: 42 },
], total: 42, area: "Philippines", childLevel: "region", source: "Synthetic source",
  date: "2026-09-01", license: "Test license", geometryUrl: "/bhw-connect/geo/regions.json" };
it("keeps selection, zero, period, source, coverage and relative-legend context in image notes", () => {
  const notes = pngNotes(datasetMapExport({ ...initialMapChoice("nhfr-2026-09"), selected: "01" }, data, [], ["01"])).join("\n");
  expect(notes).toContain("September 2026");
  expect(notes).toContain("Selected value: 0 facilities.");
  expect(notes).toContain("2 areas in complete table; 1 without drawable geometry");
  expect(notes).toContain("Reported parent total: 42");
  expect(notes).toContain("quantiles-relative-to-displayed-areas");
  expect(notes).toContain("Source: Synthetic source. Source date: 2026-09-01. License: Test license");
  expect(notes).toContain("not yet assigned for this dataset");
});
it("retains selected published uncertainty and source notes", () => {
  const context = datasetMapExport({ ...initialMapChoice("psa-sae-poverty-2023"), selected: "01" },
    { ...data, total: null, rows: [{ code: "01", name: "Test", value: 12.3, ciLow: 10, ciHigh: 14, note: "Published estimate" }] }, [], ["01"]);
  const notes = pngNotes(context).join("\n");
  expect(notes).toContain("Published 90% confidence interval: 10 to 14");
  expect(notes).toContain("Published estimate");
  expect(notes).toContain("not available / not applicable");
});

it("labels a declared parent rate in PNG notes without turning it into a total", () => {
  const context = datasetMapExport({ ...initialMapChoice("bhw-stepzero-2026"), indicator: "bhw.declared-accreditation" },
    { ...data, total: null, parentRate: 0 }, [], ["01"]);
  const notes = pngNotes(context).join("\n");
  expect(notes).toContain("Reported parent rate: 0%");
  expect(notes).not.toContain("Reported parent total:");
  expect(notes).toContain("All LGU-declared BHWs");
});

it("keeps unverified geometry separate from confirmed missing polygons", () => {
  const notes = pngNotes(datasetMapExport(initialMapChoice("nhfr-2026-09"), data, [], null)).join("\n");
  expect(notes).toContain("0 without drawable geometry; 2 with geometry not checked");
  const checked = pngNotes(datasetMapExport(initialMapChoice("nhfr-2026-09"), data, [], ["01"])).join("\n");
  expect(checked).toContain("1 without drawable geometry; 0 with geometry not checked");
});
it("includes percentage units for selected rates and no unit for unavailable values", () => {
  const choice = { ...initialMapChoice("bhw-stepzero-2026"), indicator: "bhw.declared-accreditation", selected: "01" };
  const context = { ...data, total: null, parentRate: null };
  expect(pngNotes(datasetMapExport(choice, context, [], ["01"])).join("\n")).toContain("Selected value: 0%.");
  expect(pngNotes(datasetMapExport(choice, { ...context, rows: [{ code: "01", name: "Missing", value: null }] }, [], null)).join("\n"))
    .toContain("Selected value: not available.");
});

it("preserves Census confidence level, profile denominator and rate semantics in exports", () => {
  const context = datasetMapExport({ ...initialMapChoice("bhw-2025"), indicator: "bhw.profile-accreditation", selected: "01" },
    { ...data, total: null, parentRate: 25, rows: [{ code: "01", name: "Synthetic", value: 33.33, ciLow: 6.15, ciHigh: 79.23 }] }, [], ["01"]);
  expect(context.indicator.confidenceLevel).toBe(0.95);
  expect(context.parentMeasure).toMatchObject({ kind: "rate", value: 25 });
  expect(context.total).toBeNull();
  const notes = pngNotes(context).join("\n");
  expect(notes).toContain("Published 95% confidence interval: 6.15 to 79.23");
  expect(notes).toContain("Validated BHW Census profiles");
});

it("exports a mean in years with a contributing-count caveat, not a total or percentage", () => {
  const context = datasetMapExport({ ...initialMapChoice("bhw-2025"), indicator: "bhw.active-years" },
    { ...data, total: null, parentMean: 9.87 }, [], null);
  expect(context.parentMeasure).toEqual({ kind: "mean", value: 9.87, unit: "years" });
  expect(context.indicator).toMatchObject({ period: "2025", confidenceLevel: null });
  expect(context.indicator.denominator).toContain("contributing count not supplied");
  expect(context.legend.method).toBe("quantiles-relative-to-displayed-areas");
  const notes = pngNotes(context).join("\n");
  expect(notes).toContain("Reported parent mean: 9.87 years");
  expect(notes).not.toContain("9.87%");
  expect(notes).toContain("never an average of child means");
});
