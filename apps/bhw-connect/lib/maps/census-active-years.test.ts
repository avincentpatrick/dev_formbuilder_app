import { expect, it } from "vitest";
import { joinCensusActiveYears } from "./census-active-years";
const children = [{ geo_code: "01", geo_name: "Synthetic" }];
const row = { geo_code: "01", n_total: 5, avg_active_years: 12.34, ci_low: 5, ci_high: 20 };
it.each([0, 12.34, 101])("preserves published mean %s without percentage limits or invented uncertainty", value => {
  const result = joinCensusActiveYears(children, [{ ...row, avg_active_years: value }])[0];
  expect(result.value).toBe(value);
  expect(result).not.toHaveProperty("smallSample");
  expect(result).not.toHaveProperty("ciLow");
  expect(result.note).toContain("Contributing count and confidence interval are not supplied");
});
it.each([{ n_total: 0 }, { n_total: null }, { avg_active_years: null }, { avg_active_years: -1 }, { avg_active_years: Infinity }])("rejects invalid mean metadata: %j", fields => {
  expect(joinCensusActiveYears(children, [{ ...row, ...fields }])[0].value).toBeNull();
});
it("keeps missing and duplicate means unavailable", () => {
  expect(joinCensusActiveYears(children, [])[0].value).toBeNull();
  expect(joinCensusActiveYears(children, [row, row])[0].value).toBeNull();
});
