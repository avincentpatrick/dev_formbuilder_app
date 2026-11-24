import { expect, it } from "vitest";
import { presenceReading, selectPresence, type PresenceInput } from "./presence";
const fixture: PresenceInput = {
  code: "PH",
  name: "Synthetic",
  level: "national",
  types: ["Hospital", "Station"],
  selectedTypes: [],
  rows: [
    { code: "01", name: "Alpha", level: "region", present: 2, barangays: 4 },
    { code: "02", name: "Beta", level: "region", present: 0, barangays: 1 },
    { code: "03", name: "Gamma", level: "region", present: 0, barangays: 0 },
  ],
};
it("uses each area's own denominator, weighted combined reading and unavailable-last sorting", () => {
  const result = selectPresence(fixture, [], "lowest")!;
  expect(result.rows.map((a) => a.code)).toEqual(["02", "01", "03"]);
  expect(result.rows.map((a) => a.share)).toEqual([0, 50, null]);
  expect(presenceReading(result)).toContain("4 in every 10");
  const selected = selectPresence(fixture, ["01"], "highest")!;
  expect(selected.rows[0].share).toBe(50);
  expect(selected.barangays).toBe(4);
  expect(selectPresence(fixture, [], "highest")!.rows.map((a) => a.code)).toEqual([
    "01",
    "02",
    "03",
  ]);
});
it("rejects invalid rosters, unknown selections and impossible counts", () => {
  expect(selectPresence(fixture, ["99"], "lowest")).toBeNull();
  for (const patch of [
    { rows: [fixture.rows[0], fixture.rows[0]] },
    { rows: [{ ...fixture.rows[0], present: 5 }] },
    { selectedTypes: ["Unknown"] },
    { rows: [] },
  ])
    expect(selectPresence({ ...fixture, ...patch }, [], "lowest")).toBeNull();
});
it("distinguishes zero, all, small shares and zero-denominator readings", () => {
  expect(presenceReading(selectPresence(fixture, ["02"], "lowest")!)).toContain("No barangays");
  expect(presenceReading(selectPresence(fixture, ["03"], "lowest")!)).toContain("cannot be shown");
  const one = { ...fixture, rows: [{ ...fixture.rows[0], present: 4 }] };
  expect(presenceReading(selectPresence(one, [], "lowest")!)).toContain("Every barangay");
  expect(
    presenceReading(
      selectPresence(
        { ...one, rows: [{ ...one.rows[0], present: 1, barangays: 100 }] },
        [],
        "lowest",
      )!,
    ),
  ).toContain("Fewer than 1");
  const almost = selectPresence(
    { ...one, rows: [{ ...one.rows[0], present: 999, barangays: 1000 }] },
    [],
    "lowest",
  )!;
  expect(presenceReading(almost, true)).toContain(
    "More than 9 in every 10 barangays have a recorded facility of the selected types",
  );
});
