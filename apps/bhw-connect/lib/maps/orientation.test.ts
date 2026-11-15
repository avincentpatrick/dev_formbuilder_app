import { expect, it } from "vitest";
import type { BoundaryCollection } from "@/lib/geo/locator-shapes";
import { mapLabelPoints, nationalLocator } from "./orientation";

function square(code: string, x: number, y: number, size: number) {
  return {
    properties: { geo_code: code },
    geometry: {
      type: "Polygon" as const,
      coordinates: [
        [
          [x, y],
          [x + size, y],
          [x + size, y + size],
          [x, y + size],
          [x, y],
        ],
      ],
    },
  };
}
const country: BoundaryCollection = { features: [square("01", 120, 5, 10)] };
const geometry: BoundaryCollection = {
  features: [
    square("07012", 123, 9, 0.5),
    square("07022", 123, 10, 0.5),
    { properties: { geo_code: "07306" }, geometry: null },
  ],
};

it("labels names from authoritative rows including zero and missing, but never positions an absent shape", () => {
  const result = mapLabelPoints(
    [
      { code: "07012", name: "Published zero", value: 0 },
      { code: "07022", name: "Unavailable value", value: null },
      { code: "07306", name: "Missing HUC shape", value: 42 },
      { code: "07311", name: "Other missing HUC", value: 99 },
    ],
    geometry,
  );
  expect(result.features.map((f) => f.properties)).toEqual([
    { geo_code: "07012", name: "Published zero" },
    { geo_code: "07022", name: "Unavailable value" },
  ]);
  expect(result.features[0].geometry.coordinates).toEqual([123.25, 9.25]);
});
it("keeps country extent while locating the selected supplied boundary", () => {
  const selected = nationalLocator(country, geometry, "07012")!;
  const scope = nationalLocator(country, geometry, null)!;
  expect(selected.viewBox).toBe(scope.viewBox);
  expect(selected.contextPath).toBe(scope.contextPath);
  expect(scope.highlightPath).toContain(selected.highlightPath);
  expect(scope.highlightPath.length).toBeGreaterThan(selected.highlightPath.length);
});
it("does not substitute a parent, neighbour or code-derived point for missing HUCs", () => {
  for (const code of ["07306", "07311", "07313"])
    expect(nationalLocator(country, geometry, code)).toBeNull();
  expect(nationalLocator(country, { features: [] }, null)).toBeNull();
  expect(nationalLocator({ features: [] }, geometry, "07012")).toBeNull();
});
it("uses a ring for a very small supplied place and deduplicates labels", () => {
  const tiny = square("0702234001", 123, 10, 0.0001);
  expect(nationalLocator(country, { features: [tiny] }, "0702234001")?.marker).not.toBeNull();
  expect(
    mapLabelPoints([{ code: "0702234001", name: "Tiny place", value: 1 }], {
      features: [tiny, tiny],
    }).features,
  ).toHaveLength(1);
});
