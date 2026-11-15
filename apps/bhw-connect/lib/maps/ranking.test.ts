import { expect, it } from "vitest";
import { rankedCounts } from "./ranking";
it("keeps missing-shape HUCs, zeros and ties in the count ordering", () => {
  const rows = [
    { code: "07012", name: "Bohol", value: 0 },
    { code: "07022", name: "Cebu", value: 100 },
    { code: "07306", name: "Cebu City", value: 100 },
    { code: "07311", name: "Lapu-Lapu", value: null },
    { code: "07313", name: "Mandaue", value: 25 },
  ];
  expect(rankedCounts(rows).ranked.map((r) => [r.code, r.value])).toEqual([
    ["07022", 100],
    ["07306", 100],
    ["07313", 25],
    ["07012", 0],
  ]);
  expect(rankedCounts(rows).excluded).toBe(1);
  expect(rows[0].code).toBe("07012");
});
it("orders equal-name ties by code and excludes invalid observations", () => {
  const rows = [
    { code: "02", name: "Same", value: 0 },
    { code: "01", name: "Same", value: 0 },
    ...[null, NaN, Infinity, -1].map((value, i) => ({ code: `x${i}`, name: "Invalid", value })),
  ];
  expect(rankedCounts(rows).ranked.map((r) => r.code)).toEqual(["01", "02"]);
  expect(rankedCounts(rows).excluded).toBe(4);
  expect(rankedCounts([])).toEqual({ ranked: [], excluded: 0 });
});
