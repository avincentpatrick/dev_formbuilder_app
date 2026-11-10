import { expect, it } from "vitest";
import { joinPovertyRows, POVERTY_BINS } from "./poverty";
import { colorForValue } from "@/lib/charts/color-scale";
const children = ["0102801", "0102802", "0330100"].map((geo_code) => ({
  geo_code,
  geo_name: geo_code,
}));
it("preserves decimal estimates and exact published uncertainty, with missing HUC coverage", () => {
  expect(
    joinPovertyRows(children, [
      { geo_code: "0102801", poverty_incidence: 12.345, ci_low: 10.125, ci_high: 14.875 },
      { geo_code: "0102802", poverty_incidence: 0, ci_low: -0.1, ci_high: 0.4 },
    ]),
  ).toEqual([
    { code: "0102801", name: "0102801", value: 12.345, ciLow: 10.125, ciHigh: 14.875 },
    { code: "0102802", name: "0102802", value: 0, ciLow: -0.1, ciHigh: 0.4 },
    { code: "0330100", name: "0330100", value: null, ciLow: null, ciHigh: null },
  ]);
});
it("does not coerce invalid or duplicate observations or invent missing intervals", () => {
  const result = joinPovertyRows(children, [
    { geo_code: "0102801", poverty_incidence: 101 },
    { geo_code: "0102802", poverty_incidence: 10 },
    { geo_code: "0102802", poverty_incidence: 20 },
    { geo_code: "0330100", poverty_incidence: 1.5 },
  ]);
  expect(result.map((r) => r.value)).toEqual([null, null, 1.5]);
  expect(result.every((r) => r.ciLow === null && r.ciHigh === null)).toBe(true);
});
it("keeps fixed breaks and assigns exact cutpoints consistently", () => {
  expect(POVERTY_BINS.map((b) => b.min)).toEqual([0, 10, 20, 30, 40]);
  expect(colorForValue(10, POVERTY_BINS)).toBe(POVERTY_BINS[1].color);
  expect(colorForValue(100, POVERTY_BINS)).toBe(POVERTY_BINS[4].color);
});
