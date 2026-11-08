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
  expect(notes).toContain("Selected value: 0.");
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
