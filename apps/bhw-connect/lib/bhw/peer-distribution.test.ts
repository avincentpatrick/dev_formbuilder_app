import { expect, it } from "vitest";
import { peerDistribution } from "./peer-distribution";
import type { MapRow } from "@/lib/maps/prototype";
const rows: MapRow[] = [
  { code: "01", name: "First", value: 0 },
  { code: "02", name: "Second", value: 10 },
  { code: "03", name: "Third", value: 10 },
  { code: "04", name: "Fourth", value: null },
  { code: "05", name: "Fifth", value: 20, smallSample: true },
];
it("keeps zero, assigns competition ties before rounding and explains exclusions", () => {
  const p = peerDistribution(rows, "people");
  expect(p.ranked.map((r) => [r.code, r.rank, r.tied])).toEqual([
    ["02", 1, 2],
    ["03", 1, 2],
    ["01", 3, 1],
  ]);
  expect(p).toMatchObject({ total: 5, unavailable: 1, smallSample: 1, filtered: 0 });
  expect(
    peerDistribution(
      [
        { code: "01", name: "A", value: 1.001 },
        { code: "02", name: "B", value: 1.002 },
      ],
      "%",
    ).ranked.map((r) => r.rank),
  ).toEqual([1, 2]);
});
it("changes the eligible universe with inclusive filters without dropping missing/small observations", () => {
  const p = peerDistribution(rows, "people", { valueMin: 10, valueMax: 10 });
  expect(p).toMatchObject({ total: 5, unavailable: 1, smallSample: 1, filtered: 1 });
  expect(p.ranked).toHaveLength(2);
  expect(p.statuses.find((r) => r.code === "01")?.status).toBe("filtered");
});
it("does not require geometry, infer missing values or rank invalid percentage values", () => {
  const p = peerDistribution(
    [0, null, -1, NaN, Infinity, 101].map((value, i) => ({
      code: String(i),
      name: String(i),
      value,
    })),
    "%",
  );
  expect(p.ranked.map((r) => r.value)).toEqual([0]);
  expect(p.unavailable).toBe(5);
  expect(peerDistribution([], "%").ranked).toEqual([]);
});
it("rejects duplicate geography instead of counting an area twice", () => {
  const p = peerDistribution([rows[0], rows[0]], "people");
  expect(p.validUniverse).toBe(false);
  expect(p.ranked).toEqual([]);
});
it("does not mutate supplied values or ordering", () => {
  const before = structuredClone(rows);
  peerDistribution(rows, "people");
  expect(rows).toEqual(before);
});
