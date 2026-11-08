import { expect, it } from "vitest";
import { joinCensusAccreditation } from "./census-accreditation";
const children = [{ geo_code: "01", geo_name: "Synthetic" }];
const row = { geo_code: "01", n_total: 3, n_accredited: 1, pct_accredited: 33.33, ci_low: 6.15, ci_high: 79.23 };
it("preserves published precision, uncertainty and small-sample context", () => {
  expect(joinCensusAccreditation(children, [row])[0]).toMatchObject({ value: 33.33, ciLow: 6.15, ciHigh: 79.23, smallSample: true });
  expect(joinCensusAccreditation(children, [row])[0].note).toContain("unstable");
});
it.each([0, 100])("preserves valid %s percent", value => {
  expect(joinCensusAccreditation(children, [{ ...row, n_total: 100, n_accredited: value, pct_accredited: value }])[0].value).toBe(value);
});
it.each([{ n_total: 0 }, { n_total: null }, { n_accredited: 4 }, { pct_accredited: null }, { pct_accredited: 50 }])("does not invent a rate from invalid source fields: %j", fields => {
  expect(joinCensusAccreditation(children, [{ ...row, ...fields }])[0]).toMatchObject({ value: null, ciLow: null, ciHigh: null });
});
it("keeps missing and duplicate rows unavailable", () => {
  expect(joinCensusAccreditation(children, [])[0].value).toBeNull();
  expect(joinCensusAccreditation(children, [row, row])[0].value).toBeNull();
});
it("does not manufacture or clamp inconsistent confidence bounds", () => {
  expect(joinCensusAccreditation(children, [{ ...row, ci_low: 40 }])[0]).toMatchObject({ value: 33.33, ciLow: null, ciHigh: null });
});
