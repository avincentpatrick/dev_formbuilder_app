import { expect, it } from "vitest";
import { selectMix, mixReading } from "./mix";
const items = [
  { facilityType: "Clinic", nFacilities: 6, nGovernment: 2, nPrivate: 4 },
  { facilityType: "Hospital", nFacilities: 4, nGovernment: 3, nPrivate: 1 },
];
it("filters multiple types without renormalizing the area denominator", () => {
  expect(selectMix(items, 10, ["Clinic", "Hospital", "Clinic"], "count")).toHaveLength(2);
  const rows = selectMix(items, 10, ["Hospital"], "count")!;
  expect(mixReading(rows, 10, true, "mix")).toContain("4 of 10");
  expect(mixReading(rows, 10, true, "mix")).toContain("40%");
  expect(mixReading(rows, 10, true, "ownership")).toContain("75%");
});
it("keeps ordering stable and avoids claiming a unique leader for tied types", () => {
  expect(selectMix([...items].reverse(), 10, [], "name")?.[0].facilityType).toBe("Clinic");
  const tied = [{ ...items[0], nFacilities: 4, nGovernment: 0, nPrivate: 4 }, items[1]];
  expect(mixReading(tied, 8, false, "mix")).toContain("2 types share");
});
it("rejects unknown types and incomplete ownership/type partitions", () => {
  expect(selectMix(items, 10, ["Invented"], "count")).toBeNull();
  expect(selectMix(items, 11, [], "count")).toBeNull();
  expect(selectMix([{ ...items[0], nGovernment: 99 }, items[1]], 10, [], "count")).toBeNull();
  expect(selectMix([], 0, [], "count")).toBeNull();
});
it("sorts fewest first and searches lookup rows without changing selections or the area denominator", async () => {
  const { searchMixRows } = await import("./mix");
  const rows = selectMix(items, 10, [], "lowest")!;
  expect(rows.map((r) => r.facilityType)).toEqual(["Hospital", "Clinic"]);
  const found = searchMixRows(rows, "  HOSP  ");
  expect(found).toHaveLength(1);
  expect(found[0].nFacilities / 10).toBe(0.4);
  expect(rows).toHaveLength(2);
  expect(searchMixRows(rows, "unmatched")).toHaveLength(0);
  expect(selectMix([items[0], items[0]], 12, [], "count")).toBeNull();
  expect(
    selectMix([{ ...items[0], nGovernment: -1, nPrivate: 7 }, items[1]], 10, [], "count"),
  ).toBeNull();
});
