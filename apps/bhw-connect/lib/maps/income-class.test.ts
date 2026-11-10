import { expect, it } from "vitest";
import { joinIncomeRows, INCOME_CLASSES, INCOME_BINS } from "./income-class";
import { colorForValue } from "@/lib/charts/color-scale";
const children = ["0102801", "0102802", "0102803"].map((geo_code) => ({
  geo_code,
  geo_name: geo_code,
}));
it("distinguishes published class, explicitly unclassified and unmatched without old-class fallback", () => {
  const rows = joinIncomeRows(children, [
    { geo_code: "0102801", new_class: 2, dof_kind: "city", converted: true },
    { geo_code: "0102802", new_class: null, old_class_dof: 1, dof_kind: "municipality" },
  ]);
  expect(rows[0]).toMatchObject({
    value: 2,
    note: "Source LGU type: city; classified as municipality in source",
  });
  expect(rows[1]).toMatchObject({ value: null, note: "Unclassified in source" });
  expect(rows[2]).toMatchObject({ value: null, note: "No matched source record" });
});
it("rejects zero, old sixth class, decimal classes and duplicate matches", () => {
  for (const value of [0, 6, 1.5, "1", Infinity])
    expect(
      joinIncomeRows(children, [{ geo_code: "0102801", new_class: value, dof_kind: "city" }])[0]
        .value,
    ).toBeNull();
  expect(
    joinIncomeRows(children, [
      { geo_code: "0102801", new_class: 1, dof_kind: "city" },
      { geo_code: "0102801", new_class: 2, dof_kind: "city" },
    ])[0].note,
  ).toBe("Ambiguous source records");
});
it("uses the same five categorical colors regardless of which classes are present", () => {
  expect(INCOME_CLASSES.map((c) => c.label)).toEqual([
    "First class",
    "Second class",
    "Third class",
    "Fourth class",
    "Fifth class",
  ]);
  for (const c of INCOME_CLASSES) expect(colorForValue(c.value, INCOME_BINS)).toBe(c.color);
});
