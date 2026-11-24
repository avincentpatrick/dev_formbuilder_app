import { expect, it } from "vitest";
import { selectAreaFigures } from "./area-figures";
import { areaFixture, filteredAreaPresence } from "./area-figures.fixtures";
it("joins the same type union and preserves two distinct denominators after filtering and search", () => {
  const { input } = areaFixture();
  const presence = filteredAreaPresence(["Hospital"]);
  const all = selectAreaFigures(input, presence, ["01", "02"], ["Hospital"], "highest")!;
  expect(all.parent).toBe(153);
  expect(all.count).toBe(3);
  expect(all.rows.find((a) => a.code === "01")).toMatchObject({
    count: 1,
    present: 1,
    barangays: 10,
    facilityShare: 100 / 153,
    presenceShare: 10,
  });
  const found = selectAreaFigures(
    input,
    presence,
    ["01", "02"],
    ["Hospital"],
    "highest",
    "AREA 01",
  )!;
  expect(found.rows).toHaveLength(1);
  expect(found.parent).toBe(153);
  expect(found.rows[0].barangays).toBe(10);
  expect(found.selectedAreas).toBe(2);
});
it("rejects mismatched selections, incomplete or inconsistent joins before display filters", () => {
  const { input, presence } = areaFixture();
  expect(selectAreaFigures(input, presence, [], ["Hospital"], "name")).toBeNull();
  for (const bad of [
    { ...presence, code: "XX" },
    { ...presence, rows: presence.rows.slice(1) },
    {
      ...presence,
      rows: presence.rows.map((p, i) => (i === 17 ? { ...p, present: 1, barangays: 10 } : p)),
    },
  ])
    expect(selectAreaFigures(input, bad, ["01"], [], "name")).toBeNull();
  expect(selectAreaFigures({ ...input, cells: [] }, presence, [], [], "name")).toBeNull();
  expect(selectAreaFigures(input, presence, ["99"], [], "name")).toBeNull();
});
it("sorts raw barangay shares, puts unavailable last and distinguishes zero shares", () => {
  const { input, presence } = areaFixture();
  const rows = selectAreaFigures(input, presence, [], [], "presence-lowest")!.rows;
  expect(rows.at(-1)?.code).toBe("18");
  expect(rows.at(-1)?.presenceShare).toBeNull();
  expect(rows[0].code).toBe("03");
  const zero = {
    ...input,
    total: { count: 0, government: 0, private: 0 },
    areas: input.areas.map((a) => ({ ...a, count: 0, government: 0, private: 0 })),
    types: [],
    cells: [],
  };
  const p = { ...presence, rows: presence.rows.map((a) => ({ ...a, present: 0 })) };
  const result = selectAreaFigures(zero, p, [], [], "highest")!;
  expect(result.rows[0]).toMatchObject({ facilityShare: null, presenceShare: 0 });
  expect(selectAreaFigures(input, presence, [], [], "name", "no-match")?.rows).toHaveLength(0);
});
