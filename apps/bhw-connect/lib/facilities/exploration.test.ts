import { describe, expect, it } from "vitest";
import { exploreFacilities, type ExplorationInput } from "./exploration";
import { explorationFigure } from "@/lib/exports/nhfr-exploration";
const facility = (
  code: string,
  barangay: string | null,
  type = "Clinic",
  ownership = "Government",
) => ({
  facilityCode: code,
  facilityName: code,
  facilityType: type,
  ownershipMajor: ownership,
  ownershipSub: null,
  barangayName: barangay,
  barangayGeoCode: barangay,
  bedCapacity: 0,
  licensingStatus: null,
});
const input: ExplorationInput = {
  code: "0102801",
  name: "Synthetic town",
  expected: 5,
  expectedBarangays: 3,
  facilities: [
    facility("a", "1"),
    facility("b", "1", "Hospital", "Private"),
    facility("c", "2"),
    facility("d", null),
    facility("e", "outside"),
  ],
  barangays: [
    { geoCode: "1", geoName: "One" },
    { geoCode: "2", geoName: "Two" },
    { geoCode: "3", geoName: "Empty" },
  ],
  centroids: [
    ["1", [120, 14]],
    ["3", [120.1, 14.1]],
  ],
  outlineUrl: null,
};
describe("barangay exploration", () => {
  it("retains zero and missing-geometry barangays and reconciles every selected facility", () => {
    const r = exploreFacilities(input, { types: [], ownership: "" })!;
    expect(r.rows.map((b) => b.count)).toEqual([2, 1, 0]);
    expect(r.present).toBe(2);
    expect(r.missingGeometry).toBe(1);
    expect(r.unassigned).toBe(2);
    expect(
      r.data.points.reduce((n, p) => n + p.nFacilities, 0) + r.missingGeometry + r.unassigned,
    ).toBe(5);
  });
  it("unions types and intersects ownership without shrinking the barangay denominator", () => {
    const r = exploreFacilities(input, { types: ["Clinic", "Hospital"], ownership: "Private" })!;
    expect(r.facilities).toHaveLength(1);
    expect(r.present).toBe(1);
    expect(r.rows).toHaveLength(3);
  });
  it("distinguishes a real empty selection from unavailable or duplicate inputs", () => {
    expect(
      exploreFacilities(input, { types: ["Hospital"], ownership: "Government" })?.rows.map(
        (r) => r.count,
      ),
    ).toEqual([0, 0, 0]);
    for (const invalid of [
      { ...input, expected: 6 },
      { ...input, expectedBarangays: 4 },
      { ...input, facilities: [...input.facilities.slice(0, 4), input.facilities[0]] },
      { ...input, barangays: [input.barangays[0], input.barangays[0], input.barangays[2]] },
    ])
      expect(exploreFacilities(invalid, { types: [], ownership: "" })).toBeNull();
  });
  it("keeps tables usable when all geometry is missing or invalid", () => {
    const r = exploreFacilities(
      {
        ...input,
        centroids: [
          ["1", [NaN, 14]],
          ["2", [0, 0]],
        ],
      },
      { types: [], ownership: "" },
    )!;
    expect(r.data.points).toEqual([]);
    expect(r.rows).toHaveLength(3);
    expect(r.missingGeometry).toBe(3);
  });
  it("exports scope, provenance, gaps and honest representative-point wording", () => {
    const f = explorationFigure(input, { types: ["Clinic"], ownership: "Government" });
    expect(f.svg).toContain("September 2026");
    expect(f.svg).toContain("Types: Clinic");
    expect(f.svg).toContain("not buildings");
    expect(f.svg).toContain("without matched barangay");
    expect(f.svg).not.toContain("NaN");
    expect(
      explorationFigure({ ...input, centroids: [] }, { types: [], ownership: "" }).svg,
    ).toContain("Map geometry unavailable");
  });
});

it("keeps the municipality outline as geographic context in exports", () => {
  const outlined = {
    ...input,
    outline: [
      {
        type: "Polygon",
        coordinates: [
          [
            [119, 13],
            [121, 13],
            [121, 15],
            [119, 13],
          ],
        ],
      },
    ],
  };
  expect(explorationFigure(outlined, { types: [], ownership: "" }).svg).toContain('<path d="M');
});
