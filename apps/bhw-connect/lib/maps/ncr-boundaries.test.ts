import { readFileSync } from "node:fs";
import { expect, it } from "vitest";
import type { BoundaryCollection } from "@/lib/geo/locator-shapes";
import { buildLocatorMap } from "@/lib/geo/locator-shapes";

it("draws every NCR city-as-province using codes that join the dashboard geography", () => {
  const shapes = JSON.parse(
    readFileSync("public/geo/provinces/13.json", "utf8"),
  ) as BoundaryCollection;
  const expected = Array.from({ length: 17 }, (_, i) => `138${String(i + 1).padStart(2, "0")}`);
  expect(shapes.features.map((f) => f.properties.geo_code).sort()).toEqual(expected);
  for (const code of expected) {
    const locator = buildLocatorMap(shapes, code);
    expect(locator, code).not.toBeNull();
    expect(Boolean(locator?.highlightPath || locator?.marker), code).toBe(true);
  }
});
