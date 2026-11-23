import { describe, expect, it } from "vitest";
import {
  countValueChanges,
  groupChangesByProvince,
  summariseChanges,
  type UucListChange,
} from "./uuc-phc-changes";

const change = (
  kind: "added" | "removed",
  barangay: string,
  citymun: string,
  province: string,
  region = "02",
): UucListChange => ({
  change: kind,
  geoCode: `${province}-${citymun}-${barangay}`,
  barangayName: barangay,
  citymunCode: citymun,
  citymunName: citymun,
  provinceCode: province,
  provinceName: province,
  regionCode: region,
  regionName: `Region ${region}`,
});

const rows = [
  change("added", "CAPELLAN", "CITY OF ILAGAN", "ISABELA"),
  change("added", "NAMNAMA", "CITY OF ILAGAN", "ISABELA"),
  change("removed", "TUPA", "SAN PABLO", "ISABELA"),
  change("added", "PURAY", "RODRIGUEZ", "RIZAL", "04"),
];

describe("UUC list changes", () => {
  it("counts joins and departures per region without netting them away", () => {
    expect(summariseChanges(rows, "region")).toEqual([
      { code: "02", name: "Region 02", added: 2, removed: 1 },
      { code: "04", name: "Region 04", added: 1, removed: 0 },
    ]);
  });

  it("groups each province's barangays by city or municipality, joins and departures apart", () => {
    const [isabela, rizal] = groupChangesByProvince(rows);
    expect(isabela).toMatchObject({
      provinceName: "ISABELA",
      nAdded: 2,
      nRemoved: 1,
      added: [{ citymunName: "CITY OF ILAGAN", barangays: ["CAPELLAN", "NAMNAMA"] }],
      removed: [{ citymunName: "SAN PABLO", barangays: ["TUPA"] }],
    });
    expect(rizal).toMatchObject({ provinceName: "RIZAL", nAdded: 1, nRemoved: 0, removed: [] });
  });

  it("counts value changes per indicator, largest first", () => {
    const base = {
      geoCode: "x",
      barangayName: "x",
      citymunName: "",
      provinceCode: "",
      provinceName: "",
      regionCode: "",
      regionName: "",
    };
    expect(
      countValueChanges([
        { ...base, changedIndicators: ["health_indicators", "abr"] },
        { ...base, changedIndicators: ["health_indicators"] },
      ]),
    ).toEqual([
      ["health_indicators", 2],
      ["abr", 1],
    ]);
  });
});
