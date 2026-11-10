import { expect, it } from "vitest";
import { barangayDrillAvailable, barangayGeometryUrl, barangayRelease } from "./barangay-release";

it("is off unless a pinned release is configured", () => {
  for (const value of [undefined, "", "  ", "latest", "../x", "A-Upper", "a", "has space", "x/../y", ".hidden"])
    expect(barangayRelease(value), String(value)).toBeNull();
  expect(barangayRelease(" psgc-2026q3-ring-candidate-v1 ")).toBe("psgc-2026q3-ring-candidate-v1");
});
it("needs a release, an indicator with a barangay reader and a source that declares barangay level", () => {
  const levels = ["national", "region", "province", "citymun", "barangay"] as const;
  const release = "psgc-2026q3-ring-candidate-v1";
  expect(barangayDrillAvailable("nhfr-2026-09", "nhfr.facilities", levels, release)).toBe(true);
  expect(barangayDrillAvailable("uuc-phc-2025", "uuc.listed", levels, release)).toBe(true);
  expect(barangayDrillAvailable("nhfr-2026-09", "nhfr.facilities", levels, null)).toBe(false);
  expect(barangayDrillAvailable("nhfr-2026-09", "nhfr.facilities", ["province", "citymun"], release)).toBe(false);
  // BHW counts map at barangay level; BHW rates do not
  expect(barangayDrillAvailable("bhw-2025", "bhw.validated-profiles", levels, release)).toBe(true);
  expect(barangayDrillAvailable("bhw-2025", "bhw.profile-accreditation", levels, release)).toBe(false);
  // published classifications and datasets without a barangay view stay at municipality level
  expect(barangayDrillAvailable("uuc-phc-2025", "uuc.route.ip", levels, release)).toBe(false);
  for (const dataset of ["bhw-profiling-status-2026", "psa-sae-poverty-2023", "lgu-income-class"])
    expect(barangayDrillAvailable(dataset, "bhw.total", levels, release), dataset).toBe(false);
});
it("names one municipality's partition under the pinned release", () => {
  expect(barangayGeometryUrl("rel-1", "0102801")).toMatch(/\/maps\/rel-1\/citymun\/0102801\.json$/);
});
