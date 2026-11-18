import { readFileSync } from "node:fs";
import { expect, it } from "vitest";
import { buildLocatorMap, type BoundaryCollection } from "@/lib/geo/locator-shapes";

const read = (path: string) =>
  JSON.parse(readFileSync(`public/geo/${path}.json`, "utf8")) as BoundaryCollection;
const hucs = ["07306", "07311", "07313"];

it("joins all five Region VII entries, including the independent cities, to drawable locators", () => {
  const shapes = read("provinces/07");
  expect(shapes.features.map((f) => f.properties.geo_code).sort()).toEqual([
    "07012",
    "07022",
    ...hucs,
  ]);
  for (const code of hucs) {
    const locator = buildLocatorMap(shapes, code);
    expect(Boolean(locator?.highlightPath || locator?.marker), code).toBe(true);
  }
});

it("keeps the three city children separate from Cebu province's 50 municipalities", () => {
  const cebu = read("citymun/07022");
  expect(cebu.features).toHaveLength(50);
  for (const code of hucs) {
    const city = read(`citymun/${code}`);
    expect(city.features.map((f) => f.properties.geo_code)).toEqual([`${code}00`]);
    expect(cebu.features.some((f) => f.properties.geo_code === `${code}00`)).toBe(false);
    const province = read("provinces/07").features.find((f) => f.properties.geo_code === code);
    expect(province?.geometry).toEqual(city.features[0].geometry);
  }
});
