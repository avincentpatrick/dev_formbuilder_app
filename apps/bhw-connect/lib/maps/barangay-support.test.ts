import { expect, it } from "vitest";
import { BARANGAY_DRILL_DATASETS, BARANGAY_INDICATORS, barangayIndicatorSupported, clampBarangayScope } from "./barangay-support";
import { TRAINING_TOPICS } from "@/lib/catalog/definitions";
import { initialMapChoice, prototypeIndicators } from "./prototype";

it("lists every NHFR indicator but only the UUC list membership", () => {
  expect(BARANGAY_DRILL_DATASETS).toEqual(["nhfr-2026-09", "uuc-phc-2025", "bhw-2025", "bhw-stepzero-2026"]);
  for (const metric of prototypeIndicators("nhfr-2026-09")) expect(barangayIndicatorSupported("nhfr-2026-09", metric.id), metric.id).toBe(true);
  expect(barangayIndicatorSupported("uuc-phc-2025", "uuc.listed")).toBe(true);
  for (const id of ["uuc.route.ip", "uuc.route.conflict", "uuc.route.four_ps", "uuc.route.health"])
    expect(barangayIndicatorSupported("uuc-phc-2025", id), id).toBe(false);
});
it("keeps a supported municipality scope and returns an unsupported one to its province", () => {
  const base = { ...initialMapChoice("uuc-phc-2025"), level: "citymun" as const, code: "0102801", selected: "0102801001" };
  expect(clampBarangayScope({ ...base, indicator: "uuc.listed" })).toMatchObject({ level: "citymun", selected: "0102801001" });
  expect(clampBarangayScope({ ...base, indicator: "uuc.route.ip" }))
    .toMatchObject({ level: "province", code: "01028", selected: "0102801" });
  const national = { ...initialMapChoice("uuc-phc-2025"), indicator: "uuc.route.ip" };
  expect(clampBarangayScope(national)).toBe(national);
});
it("maps BHW Census and StepZero as counts of totals only, never a rate, mean, honorarium or training topic", () => {
  expect(barangayIndicatorSupported("bhw-2025", "bhw.validated-profiles")).toBe(true);
  for (const id of ["bhw.total", "bhw.registered", "bhw.registered-accredited", "bhw.nonregistered", "stepzero.population", "stepzero.households"])
    expect(barangayIndicatorSupported("bhw-stepzero-2026", id), id).toBe(true);
  for (const id of ["bhw.profile-accreditation", "bhw.active-years", "bhw.any-honorarium", `bhw.training.${TRAINING_TOPICS[0][0]}`])
    expect(barangayIndicatorSupported("bhw-2025", id), id).toBe(false);
  expect(barangayIndicatorSupported("bhw-stepzero-2026", "bhw.declared-accreditation")).toBe(false);
});
it("keeps every barangay-mapped BHW indicator a count, so a rate cannot be added by accident", () => {
  for (const dataset of ["bhw-2025", "bhw-stepzero-2026"])
    for (const id of BARANGAY_INDICATORS[dataset]) {
      const metric = prototypeIndicators(dataset).find((m) => m.id === id)!;
      expect(metric.legend, id).toBe("count");
      expect(["people", "households"], id).toContain(metric.unit);
    }
});
