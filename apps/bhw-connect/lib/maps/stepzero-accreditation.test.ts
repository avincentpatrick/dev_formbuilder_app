import { expect, it } from "vitest";
import { joinStepzeroAccreditation } from "./stepzero-accreditation";
const child = [{ geo_code: "01", geo_name: "Test" }];
it.each([[0, 10, 0], [1, 3, 33.33], [161, 800, 20.12], [10, 10, 100]])(
  "preserves a published percentage at source precision (%s/%s)", (n, total, pct) => {
    expect(joinStepzeroAccreditation(child, [{ geo_code: "01", n_registered_accredited: n, n_total_bhw: total, pct_registered_accredited: pct }])[0].value).toBe(pct);
  },
);
it.each([[0, 0, 0], [1, null, 25], [11, 10, 50], [1, 10, null], [1, 10, 101], [1, 10, 90]])(
  "keeps invalid/undefined or inconsistent shares unavailable (%s/%s, %s)", (n, total, pct) => {
    expect(joinStepzeroAccreditation(child, [{ geo_code: "01", n_registered_accredited: n, n_total_bhw: total, pct_registered_accredited: pct }])[0].value).toBeNull();
  },
);
it("preserves geography membership and rejects duplicates", () => {
  const row = { geo_code: "01", n_registered_accredited: 1, n_total_bhw: 4, pct_registered_accredited: 25 };
  expect(joinStepzeroAccreditation(child, []).map(r => r.value)).toEqual([null]);
  expect(joinStepzeroAccreditation(child, [row, row]).map(r => r.value)).toEqual([null]);
});
