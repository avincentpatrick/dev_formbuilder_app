import { expect, it } from "vitest";
import { initialMapChoice, joinMapRows, mapHref, parseMapChoice } from "./prototype";
it("round-trips dataset, metric, period, area and selected child without a BHW fallback", () => {
  const choice = {
    ...initialMapChoice("nhfr-2026-09"),
    indicator: "nhfr.government",
    level: "region" as const,
    code: "01",
    selected: "01028",
  };
  expect(parseMapChoice(new URLSearchParams(mapHref(choice).split("?")[1]))).toEqual(choice);
  expect(parseMapChoice(new URLSearchParams("dataset=unknown"))).toBeNull();
  expect(
    parseMapChoice(new URLSearchParams("dataset=uuc-phc-2025&indicator=nhfr.facilities")),
  ).toBeNull();
  expect(parseMapChoice(new URLSearchParams("dataset=uuc-phc-2025&period=2026"))).toBeNull();
  expect(
    parseMapChoice(new URLSearchParams("dataset=uuc-phc-2025&level=barangay&code=0102801001")),
  ).toBeNull();
});
it("keeps zeros distinct from missing and invalid values, and keeps every geography row", () => {
  const children = ["01", "02", "03", "04"].map((geo_code) => ({ geo_code, geo_name: geo_code }));
  expect(
    joinMapRows(
      children,
      [
        { geo_code: "01", n: 0 },
        { geo_code: "03", n: "5" },
        { geo_code: "04", n: 1 },
        { geo_code: "04", n: 2 },
      ],
      "n",
    ).map((r) => r.value),
  ).toEqual([0, null, null, null]);
});
it("offers explicit empty planned slots", () => {
  const choice = initialMapChoice("ways-forward");
  expect(choice.indicator).toBe("");
  expect(parseMapChoice(new URLSearchParams(mapHref(choice).split("?")[1]))).toEqual(choice);
});
