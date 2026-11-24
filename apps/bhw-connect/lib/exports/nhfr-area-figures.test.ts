import { expect, it } from "vitest";
import { areaFixture } from "@/lib/facilities/area-figures.fixtures";
import { selectAreaFigures } from "@/lib/facilities/area-figures";
import { areaFiguresSvg, areaFiguresPages } from "./nhfr-area-figures";
it("keeps parent and per-area denominators, ownership options, escaping and notes", () => {
  const { input, presence } = areaFixture();
  input.name = "Synthetic < parent";
  const result = selectAreaFigures(input, presence, ["01"], [], "name")!;
  const svg = areaFiguresSvg(input, result, ["01"], [], false, {
    query: "<Area>",
    ownership: true,
  }).svg;
  expect(svg).toContain("Synthetic &lt; parent");
  expect(svg).toContain("&lt;Area&gt;");
  expect(svg).toContain("323 facilities");
  expect(svg).toContain("3 of 10");
  expect(svg).toContain("30%");
  expect(svg).toContain("Government: 18");
  expect(svg).not.toContain("About this figure");
});
it("paginates all areas once with a full matching subtotal and shared percentage precision", () => {
  const { input, presence } = areaFixture();
  const result = selectAreaFigures(input, presence, [], [], "name")!;
  const pages = areaFiguresPages(input, result, [], [], true);
  expect(pages).toHaveLength(3);
  expect(pages[2].svg).toContain("Areas 17–18 of 18");
  expect(pages[2].svg).toContain("About this figure");
  expect(pages[0].svg).toContain("323 facilities");
  expect(pages[2].svg).toContain("Unavailable");
  for (const a of result.rows)
    expect(pages.flatMap((p) => p.svg.match(new RegExp(`>${a.name}<`, "g")) ?? [])).toHaveLength(1);
});
