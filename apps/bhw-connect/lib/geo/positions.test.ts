import { expect, it } from "vitest";
import { geometryPositions } from "./positions";
it("handles integer, exponent, negative and altitude coordinates without shifting pairs", () => {
  expect(
    geometryPositions({
      type: "Polygon",
      coordinates: [
        [
          [120, 12, 500],
          [1.21e2, -1, 10],
          [120, 12, 500],
        ],
      ],
    }),
  ).toEqual([
    [120, 12],
    [121, -1],
    [120, 12],
  ]);
});
it("handles islands, collections, nulls and invalid positions", () => {
  expect(
    geometryPositions({
      type: "GeometryCollection",
      geometries: [
        null,
        {
          type: "MultiPolygon",
          coordinates: [
            [
              [
                [120, 10],
                [121, 11],
              ],
            ],
            [[[122, 12]]],
          ],
        },
        { type: "Point", coordinates: [Infinity, 10] },
        { type: "Point", coordinates: [120, 91] },
      ],
    }),
  ).toEqual([
    [120, 10],
    [121, 11],
    [122, 12],
  ]);
});
