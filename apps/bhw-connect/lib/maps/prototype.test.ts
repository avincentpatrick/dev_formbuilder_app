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
it("keeps census, StepZero and PSA releases and dates independent", () => {
  expect(initialMapChoice("bhw-2025").indicator).toBe("bhw.validated-profiles");
  expect(initialMapChoice("bhw-stepzero-2026").period).toBe("2026");
  expect(initialMapChoice("psa-popcen-2024").period).toBe("2024");
  expect(parseMapChoice(new URLSearchParams("dataset=psa-popcen-2024&period=2026"))).toBeNull();
  expect(parseMapChoice(new URLSearchParams("dataset=bhw-2025&indicator=bhw.total"))).toBeNull();
});

it("keeps StepZero population and households separate from PSA and other releases", () => {
  for (const indicator of ["stepzero.population", "stepzero.households"]) {
    expect(parseMapChoice(new URLSearchParams(`dataset=bhw-stepzero-2026&indicator=${indicator}&period=2026`)))
      .toMatchObject({ dataset: "bhw-stepzero-2026", indicator, period: "2026" });
    expect(parseMapChoice(new URLSearchParams(`dataset=psa-popcen-2024&indicator=${indicator}&period=2024`))).toBeNull();
    expect(parseMapChoice(new URLSearchParams(`dataset=bhw-stepzero-2026&indicator=${indicator}&period=2024`))).toBeNull();
  }
});

it.each(["dataset", "indicator", "period", "level", "code", "selected"])("rejects duplicate %s choices instead of choosing a value", key => {
  const params = new URLSearchParams("dataset=nhfr-2026-09&indicator=nhfr.facilities&period=September+2026&level=national&code=PH&selected=01");
  params.append(key, params.get(key)!);
  expect(parseMapChoice(params)).toBeNull();
});
it.each([
  ["national", "PH", "01"], ["region", "01", "01028"], ["province", "01028", "0102801"],
])("preserves a correctly shaped selected child at %s level", (level, code, selected) => {
  expect(parseMapChoice(new URLSearchParams({ dataset: "nhfr-2026-09", level, code, selected })))
    .toMatchObject({ level, code, selected });
});
it.each([
  ["national", "PH", "01028"], ["region", "01", "01"], ["province", "01028", "01028"],
  ["province", "01028", "0102801001"], ["national", "PH", ""], ["national", "PH", "001"],
])("rejects a selected code with the wrong child depth: %s/%s/%s", (level, code, selected) => {
  expect(parseMapChoice(new URLSearchParams({ dataset: "nhfr-2026-09", level, code, selected }))).toBeNull();
});
it("does not carry selected areas into planned availability slots", () => {
  expect(parseMapChoice(new URLSearchParams("dataset=ways-forward&selected=01"))).toBeNull();
});
it("ignores unrelated tracking parameters without weakening map-choice validation", () => {
  expect(parseMapChoice(new URLSearchParams("dataset=nhfr-2026-09&utm_source=one&utm_source=two")))
    .toEqual(initialMapChoice("nhfr-2026-09"));
});
