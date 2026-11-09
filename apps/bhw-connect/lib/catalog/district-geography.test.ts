import { describe, expect, it } from "vitest";
import { normalizeDistrict, type DistrictPlace, type DistrictReceipt } from "./district-geography";
const places: DistrictPlace[] = [
  { code: "01001", name: "Whole city", level: "citymun", parentCode: "01" },
  { code: "01001001", name: "A", level: "barangay", parentCode: "01001" },
  { code: "01001002", name: "B", level: "barangay", parentCode: "01001" },
  { code: "01002", name: "Split city", level: "citymun", parentCode: "01" },
  { code: "01002001", name: "C", level: "barangay", parentCode: "01002" },
  { code: "01002002", name: "D", level: "barangay", parentCode: "01002" },
];
const receipt = (
  code: string,
  level: "citymun" | "barangay",
  districtCode = "d1",
): DistrictReceipt => ({
  code,
  level,
  districtCode,
  sourceKind: "manual",
  sourceRef: "synthetic",
  retrievedAt: "2026-01-01",
  corroboration: "single_source",
});
describe("district membership normalization", () => {
  it("expands whole municipalities and deduplicates overlapping leaf membership without double counting", () => {
    const result = normalizeDistrict(
      "d1",
      [
        receipt("01001", "citymun"),
        receipt("01001001", "barangay"),
        receipt("01001001", "barangay"),
        receipt("01002001", "barangay"),
      ],
      places,
    );
    expect(result.issues).toEqual([]);
    expect(result.units.map((p) => p.code)).toEqual(["01002001", "01001"]);
    expect(result.cities.find((c) => c.code === "01002")).toMatchObject({
      whole: false,
      barangays: [{ code: "01002001" }],
      allBarangays: [{ code: "01002001" }, { code: "01002002" }],
    });
  });
  it.each([
    [receipt("01001", "citymun"), receipt("01001001", "barangay", "d2")],
    [receipt("01002001", "barangay"), receipt("01002", "citymun", "d2")],
    [receipt("01002001", "barangay"), receipt("01002001", "barangay", "d2")],
  ])("rejects cross-district overlap in either representation", (...members) => {
    expect(normalizeDistrict("d1", members, places).issues.join(" ")).toContain("another district");
  });
  it("recognizes a whole municipality only from its exact complete barangay roster", () => {
    const scope = normalizeDistrict(
      "d1",
      [receipt("01002001", "barangay"), receipt("01002002", "barangay")],
      places,
    );
    expect(scope.cities[0].whole).toBe(true);
    expect(scope.units.map((p) => p.code)).toEqual(["01002"]);
    const duplicate = normalizeDistrict(
      "d1",
      [receipt("01002001", "barangay"), receipt("01002001", "barangay")],
      places,
    );
    expect(duplicate.cities[0].whole).toBe(false);
  });
  it("reports empty, unresolved, wrong-level and source-conflict membership", () => {
    expect(normalizeDistrict("d1", [], places).issues).not.toHaveLength(0);
    expect(
      normalizeDistrict("d1", [receipt("missing", "barangay")], places).issues,
    ).not.toHaveLength(0);
    expect(normalizeDistrict("d1", [receipt("01001", "barangay")], places).issues).not.toHaveLength(
      0,
    );
    expect(
      normalizeDistrict(
        "d1",
        [{ ...receipt("01001", "citymun"), corroboration: "conflict" }],
        places,
      ).issues.join(" "),
    ).toContain("source conflict");
  });
});
