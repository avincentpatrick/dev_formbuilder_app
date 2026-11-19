import { expect, it } from "vitest";
import { joinContext, parseContext, contextHref } from "./context";
import { contextBivariate } from "./context-bivariate";
import type { ContextRow } from "./context-review";
import { withBasePath } from "@/lib/base-path";
import type { MapPayload } from "@/lib/maps/prototype";
const payload = (): MapPayload => ({
  state: "available",
  area: "Synthetic province",
  source: "Headcount source",
  date: "2025-01-01",
  license: null,
  total: null,
  childLevel: "citymun",
  geometryUrl: withBasePath("/geo/citymun/01028.json"),
  rows: [
    { code: "0102801", name: "First", value: 20 },
    { code: "0102802", name: "Second", value: null },
  ],
});
it("allows audited contexts only at supported grains and rejects a poverty workforce map", () => {
  for (const q of [
    "level=citymun&code=0102801&context=psa-population",
    "level=region&code=07&context=poverty",
    "level=province&code=13806&context=poverty",
    "level=province&code=01028&context=poverty&view=bivariate",
    "view=unknown",
    "view=scatter&view=bivariate",
  ])
    expect(parseContext(new URLSearchParams(q))).toBeNull();
  for (const q of [
    "level=province&code=01028&context=poverty",
    "context=psa-population",
    "context=households&view=bivariate",
  ]) {
    const c = parseContext(new URLSearchParams(q))!;
    expect(c).not.toBeNull();
    expect(parseContext(new URLSearchParams(contextHref(c).split("?")[1]))).toEqual(c);
  }
});
it("keeps explicitly different accepted dates and never substitutes the headcount source for PSA", () => {
  const h = payload(),
    x = { ...payload(), source: "PSA source", date: "2024-07-01" };
  expect(
    joinContext(h, x, { level: "province", code: "01028" }, "psa-population")?.[0].context,
  ).toBe(20);
  expect(joinContext(h, h, { level: "province", code: "01028" }, "psa-population")).toBeNull();
  expect(joinContext(h, x, { level: "province", code: "01028" }, "households")).toBeNull();
});
it("preserves fractional poverty and published uncertainty but rejects invalid estimates and incompatible Manila units", () => {
  const h = payload(),
    x = {
      ...payload(),
      source: "PSA poverty",
      date: "2023-12-31",
      rows: [{ ...payload().rows[0], value: 1.234, ciLow: -0.2, ciHigh: 4.7 }, payload().rows[1]],
    };
  expect(joinContext(h, x, { level: "province", code: "01028" }, "poverty")?.[0]).toMatchObject({
    context: 1.234,
    ciLow: -0.2,
    ciHigh: 4.7,
  });
  expect(joinContext(h, x, { level: "province", code: "13806" }, "poverty")).toBeNull();
  x.rows[0].value = 101;
  expect(joinContext(h, x, { level: "province", code: "01028" }, "poverty")).toBeNull();
});
const row = (i: number, h: number | null, x: number | null): ContextRow => ({
  code: String(i),
  name: String(i),
  headcount: h,
  context: x,
  eligible: h !== null && x !== null,
});
it("uses the identical complete-pair universe and raw median splits for all four map groups", () => {
  const j = contextBivariate([
    row(1, 0, 0),
    row(2, 0, 100),
    row(3, 100, 0),
    row(4, 100, 100),
    row(5, null, 9000),
  ])!;
  expect(j.headMedian).toBe(50);
  expect(j.contextMedian).toBe(50);
  expect(j.groups.map((r) => r.group)).toEqual([0, 1, 2, 3, null]);
  expect(j.counts).toEqual([1, 1, 1, 1]);
  expect(j.bins.map((b) => b.min)).toEqual([0, 1, 2, 3]);
});
it("keeps ties at the median in the lower group and handles all-zero distributions without inventing missingness", () => {
  const j = contextBivariate([row(1, 0, 0), row(2, 0, 0), row(3, 0, 0), row(4, 0, 0)])!;
  expect(j.counts).toEqual([4, 0, 0, 0]);
  expect(contextBivariate([row(1, 0, 0)])).toBeNull();
});
