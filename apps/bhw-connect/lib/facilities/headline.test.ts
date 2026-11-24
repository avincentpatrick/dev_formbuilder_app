import { describe, expect, it } from "vitest";
import { headlineSchema, headlineQuery, summaryMatches } from "./headline";
const summary = {
  total: 8,
  count: 5,
  government: 3,
  private: 2,
  beds: 12,
  present: 2,
  barangays: 4,
  unattributed: 1,
  types: ["Hospital", "Rural Health Unit"],
};
describe("headline summary boundary", () => {
  it("rejects impossible counts and overlapping ownership partitions", () => {
    expect(headlineSchema.safeParse(summary).success).toBe(true);
    for (const patch of [
      { count: 9 },
      { government: 4 },
      { present: 5 },
      { beds: -1 },
      { count: 1.5 },
      { unattributed: 6 },
      { types: ["Hospital", "Hospital"] },
    ])
      expect(headlineSchema.safeParse({ ...summary, ...patch }).success).toBe(false);
  });
  it("preserves valid zeros without inventing denominator shares", () => {
    expect(
      headlineSchema.safeParse({
        ...summary,
        total: 0,
        count: 0,
        government: 0,
        private: 0,
        present: 0,
        beds: 0,
        unattributed: 0,
        barangays: 0,
        types: [],
      }).success,
    ).toBe(true);
  });
  it("encodes an OR selection without losing scope or duplicating types", () => {
    const query = headlineQuery(
      {
        mode: "district",
        districtCode: "sample",
        citymunCode: "0102801",
        barangayCode: "0102801001",
      },
      ["Hospital", "Rural Health Unit", "Hospital"],
    );
    expect(query.getAll("type")).toEqual(["Hospital", "Rural Health Unit"]);
    expect(query.get("district")).toBe("sample");
    expect(query.get("barangay")).toBe("0102801001");
  });
  it("requires the unfiltered fact summary to reconcile with the accepted aggregates", () => {
    expect(summaryMatches(summary, { ...summary })).toBe(true);
    expect(summaryMatches({ ...summary, present: 1 }, summary)).toBe(false);
    expect(summaryMatches({ ...summary, beds: 11 }, summary)).toBe(false);
  });
});
