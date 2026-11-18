import { expect, it } from "vitest";
import {
  comparisonBins,
  comparableMapPayloads,
  drillComparison,
  mapComparisonHref,
  parentComparison,
  parseMapComparison,
  validComparisonPayload,
} from "./map-comparison";
import type { MapPayload } from "@/lib/maps/prototype";
import { withBasePath } from "@/lib/base-path";

const params = () => new URLSearchParams({ measure: "bhw.total", a: "region:01", b: "region:02" });
function payload(values: (number | null)[] = [0, 10, null]): MapPayload {
  return {
    state: "available",
    area: "Synthetic area",
    source: "Test source",
    date: "2025-01-01",
    license: null,
    childLevel: "province",
    total: null,
    geometryUrl: withBasePath("/geo/provinces/01.json"),
    rows: values.map((value, i) => ({ code: `0102${i}`, name: `Test ${i}`, value })),
    navigation: { complete: true, parents: [] },
  };
}
it("round trips independent selections, phone panel and the original four-place return state", () => {
  const p = params();
  p.set("selectedA", "01028");
  p.set("selectedB", "02015");
  p.set("focus", "b");
  p.set("geos", "01,02,03,04");
  p.set("returnFocus", "03");
  const choice = parseMapComparison(p)!;
  expect(choice).not.toBeNull();
  expect(parseMapComparison(new URLSearchParams(mapComparisonHref(choice).split("?")[1]))).toEqual(
    choice,
  );
});
it("rejects ambiguous, mismatched and unapproved settings instead of falling back", () => {
  for (const [key, value] of [
    ["measure", "bhw.unknown"],
    ["period", "2026"],
    ["dataset", "bhw-2026"],
    ["b", "province:02015"],
    ["b", "region:01"],
    ["selectedA", "bad"],
    ["focus", "c"],
    ["geos", "01,01"],
    ["returnFocus", "03"],
    ["mapState", "bad"],
  ]) {
    const p = params();
    p.set(key, value);
    expect(parseMapComparison(p), `${key}=${value}`).toBeNull();
  }
  const p = params();
  p.append("measure", "bhw.total");
  expect(parseMapComparison(p)).toBeNull();
});
it("uses both complete row universes for shared breaks, including zero and missing shapes", () => {
  const a = payload([0, 10, null]),
    b = payload([10, 1000]);
  const bins = comparisonBins("BHWs", a, b);
  expect(bins[0].min).toBe(0);
  expect(bins.at(-1)!.max).toBe(1000);
  expect(comparisonBins("%", a, payload([20, 100]))).toEqual(
    comparisonBins("%", payload([5]), payload([60])),
  );
});
it("validates responses before drawing, including metadata, duplicates and percentage bounds", () => {
  expect(validComparisonPayload(payload())).toBe(true);
  for (const value of [
    null,
    {},
    { ...payload(), license: {} },
    { ...payload(), navigation: { complete: true } },
    { ...payload(), geometryUrl: "https://example.com/geo/a.json" },
    payload([NaN]),
    payload([-1]),
    { ...payload(), rows: [payload().rows[0], payload().rows[0]] },
  ])
    expect(validComparisonPayload(value)).toBe(false);
  expect(validComparisonPayload(payload([101]), "%")).toBe(false);
  expect(validComparisonPayload(payload([0, 100, null]), "%")).toBe(true);
});
it("refuses incompatible units, dates, sources and navigation-only values", () => {
  const a = payload();
  for (const b of [
    { ...a, date: "2026-01-01" },
    { ...a, source: "Other source" },
    { ...a, childLevel: "citymun" as const },
    { ...a, valueSupport: "navigation-only" as const },
  ])
    expect(comparableMapPayloads(a, b)).toBe(false);
});
it("drills only two selected children with compatible supported detail", () => {
  const c = parseMapComparison(params())!,
    a = payload(),
    b = payload();
  b.rows[0].code = "02009";
  expect(drillComparison(c, a, b)).toBeNull();
  c.a.selected = a.rows[0].code;
  c.b.selected = b.rows[0].code;
  expect(drillComparison(c, a, b)?.a).toMatchObject({
    level: "province",
    code: "01020",
    selected: null,
  });
  expect(
    drillComparison(c, { ...a, childLevel: "citymun" }, { ...b, childLevel: "citymun" }),
  ).toBeNull();
  c.a.selected = "01999";
  expect(drillComparison(c, a, b)).toBeNull();
});
it("uses resolved parents without inferring a hierarchy from code prefixes", () => {
  const p = params();
  p.set("a", "province:01028");
  p.set("b", "province:02015");
  const c = parseMapComparison(p)!,
    a = payload(),
    b = payload();
  expect(parentComparison(c, a, b)).toBeNull();
  a.navigation = { complete: true, parents: [{ level: "region", code: "01", name: "First" }] };
  b.navigation = { complete: true, parents: [{ level: "region", code: "02", name: "Second" }] };
  expect(parentComparison(c, a, b)?.b).toMatchObject({ level: "region", code: "02" });
  b.navigation.complete = false;
  expect(parentComparison(c, a, b)).toBeNull();
});
