import { expect, it } from "vitest";
import { BARANGAY_DRILL_DATASETS, barangayIndicatorSupported, clampBarangayScope } from "./barangay-support";
import { initialMapChoice, prototypeIndicators } from "./prototype";

it("lists every NHFR indicator but only the UUC list membership", () => {
  expect(BARANGAY_DRILL_DATASETS).toEqual(["nhfr-2026-09", "uuc-phc-2025"]);
  for (const metric of prototypeIndicators("nhfr-2026-09")) expect(barangayIndicatorSupported("nhfr-2026-09", metric.id), metric.id).toBe(true);
  expect(barangayIndicatorSupported("uuc-phc-2025", "uuc.listed")).toBe(true);
  for (const id of ["uuc.route.ip", "uuc.route.conflict", "uuc.route.four_ps", "uuc.route.health"])
    expect(barangayIndicatorSupported("uuc-phc-2025", id), id).toBe(false);
  expect(barangayIndicatorSupported("bhw-2025", "bhw.validated-profiles")).toBe(false);
});
it("keeps a supported municipality scope and returns an unsupported one to its province", () => {
  const base = { ...initialMapChoice("uuc-phc-2025"), level: "citymun" as const, code: "0102801", selected: "0102801001" };
  expect(clampBarangayScope({ ...base, indicator: "uuc.listed" })).toMatchObject({ level: "citymun", selected: "0102801001" });
  expect(clampBarangayScope({ ...base, indicator: "uuc.route.ip" }))
    .toMatchObject({ level: "province", code: "01028", selected: "0102801" });
  const national = { ...initialMapChoice("uuc-phc-2025"), indicator: "uuc.route.ip" };
  expect(clampBarangayScope(national)).toBe(national);
});
