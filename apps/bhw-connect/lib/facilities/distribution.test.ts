import { expect, it } from "vitest";
import {
  validateDistribution,
  selectDistribution,
  distributionReading,
  type DistributionInput,
} from "./distribution";
export const source: DistributionInput = {
  code: "PH",
  level: "national",
  name: "Synthetic parent",
  total: { count: 10, government: 7, private: 3 },
  areas: [
    { code: "01", name: "A", level: "region", count: 6, government: 4, private: 2 },
    { code: "02", name: "B", level: "region", count: 4, government: 3, private: 1 },
    { code: "03", name: "C", level: "region", count: 0, government: 0, private: 0 },
  ],
  types: [
    { type: "Hospital", count: 7, government: 4, private: 3 },
    { type: "Clinic", count: 3, government: 3, private: 0 },
  ],
  cells: [
    { code: "01", type: "Hospital", count: 3, government: 1, private: 2 },
    { code: "01", type: "Clinic", count: 3, government: 3, private: 0 },
    { code: "02", type: "Hospital", count: 4, government: 3, private: 1 },
  ],
};
it("reconciles both area and type ownership margins before inferring absent cells as zero", () => {
  expect(validateDistribution(source)).toBe(true);
  for (const patch of [
    { cells: source.cells.slice(0, -1) },
    { cells: [...source.cells, source.cells[0]] },
    {
      cells: [...source.cells, { code: "99", type: "Clinic", count: 0, government: 0, private: 0 }],
    },
    { areas: source.areas.slice(0, 1) },
    { types: [...source.types, { type: "Unknown", count: 1, government: 1, private: 0 }] },
  ])
    expect(validateDistribution({ ...source, ...patch })).toBe(false);
  const clinic = selectDistribution(source, [], ["Clinic"], "lowest")!;
  expect(clinic.rows.find((a) => a.code === "02")?.count).toBe(0);
});
it("keeps the full matching-type parent denominator and count scale when areas are filtered", () => {
  const one = selectDistribution(source, ["01"], ["Hospital"], "highest")!;
  expect(one.parent.count).toBe(7);
  expect(one.maximum).toBe(4);
  expect(one.rows[0].count).toBe(3);
  expect(distributionReading(one, true, source.name)).toContain("3 of 7");
  expect(
    selectDistribution(source, ["01", "02"], ["Clinic", "Hospital", "Hospital"], "highest")
      ?.selectedCount,
  ).toBe(10);
  expect(selectDistribution(source, ["99"], [], "highest")).toBeNull();
  expect(selectDistribution(source, [], ["Unknown"], "highest")).toBeNull();
});
it("orders areas consistently and preserves verified zeros without fabricated zero-denominator shares", () => {
  expect(selectDistribution(source, [], [], "highest")?.rows.map((a) => a.code)).toEqual([
    "01",
    "02",
    "03",
  ]);
  expect(selectDistribution(source, [], [], "lowest")?.rows[0].code).toBe("03");
  const zero = {
    ...source,
    total: { count: 0, government: 0, private: 0 },
    areas: source.areas.map((a) => ({ ...a, count: 0, government: 0, private: 0 })),
    types: [],
    cells: [],
  };
  expect(validateDistribution(zero)).toBe(true);
  const result = selectDistribution(zero, [], [], "name")!;
  expect(result.parent.count).toBe(0);
  expect(distributionReading(result, false, zero.name)).toContain("No facilities");
});
