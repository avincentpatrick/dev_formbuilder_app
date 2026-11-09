import { expect, it } from "vitest";
import { barangayDrillAvailable, barangayGeometryUrl, barangayRelease } from "./barangay-release";

it("is off unless a pinned release is configured", () => {
  for (const value of [undefined, "", "  ", "latest", "../x", "A-Upper", "a", "has space", "x/../y", ".hidden"])
    expect(barangayRelease(value), String(value)).toBeNull();
  expect(barangayRelease(" psgc-2026q3-ring-candidate-v1 ")).toBe("psgc-2026q3-ring-candidate-v1");
});
it("needs a release, an enabled dataset and an indicator that carries barangay rows", () => {
  const levels = ["national", "region", "province", "citymun", "barangay"] as const;
  const release = "psgc-2026q3-ring-candidate-v1";
  expect(barangayDrillAvailable("nhfr-2026-09", levels, release)).toBe(true);
  expect(barangayDrillAvailable("uuc-phc-2025", levels, release)).toBe(true);
  expect(barangayDrillAvailable("nhfr-2026-09", levels, null)).toBe(false);
  expect(barangayDrillAvailable("nhfr-2026-09", ["province", "citymun"], release)).toBe(false);
  for (const dataset of ["bhw-2025", "bhw-stepzero-2026", "bhw-profiling-status-2026", "psa-sae-poverty-2023", "lgu-income-class"])
    expect(barangayDrillAvailable(dataset, levels, release), dataset).toBe(false);
});
it("names one municipality's partition under the pinned release", () => {
  expect(barangayGeometryUrl("rel-1", "0102801")).toMatch(/\/maps\/rel-1\/citymun\/0102801\.json$/);
});
