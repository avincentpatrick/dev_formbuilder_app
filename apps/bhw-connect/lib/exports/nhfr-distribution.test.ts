import { expect, it } from "vitest";
import { distributionSvg, distributionPages } from "./nhfr-distribution";
import { selectDistribution, type DistributionInput } from "@/lib/facilities/distribution";
import { figureFile } from "./figure-file";
const input: DistributionInput = {
  code: "PH",
  level: "national",
  name: "Synthetic < parent",
  total: { count: 3, government: 2, private: 1 },
  areas: Array.from({ length: 18 }, (_, i) => ({
    code: String(i + 1).padStart(2, "0"),
    name: `Area ${i + 1}`,
    level: "region",
    count: i === 0 ? 3 : 0,
    government: i === 0 ? 2 : 0,
    private: i === 0 ? 1 : 0,
  })),
  types: [{ type: "Hospital", count: 3, government: 2, private: 1 }],
  cells: [{ code: "01", type: "Hospital", count: 3, government: 2, private: 1 }],
};
it("keeps filtered parent denominators, zeros, escaping and optional notes", () => {
  const result = selectDistribution(input, ["01"], [], "highest")!;
  const svg = distributionSvg(input, result, ["01"], [], false).svg;
  expect(svg).toContain("Synthetic &lt; parent");
  expect(svg).toContain("100% of total");
  expect(svg).not.toContain("About this figure");
});
it("exports all areas once across pages while retaining the full comparison context", async () => {
  const result = selectDistribution(input, [], [], "highest")!;
  const pages = distributionPages(input, result, [], [], true);
  expect(pages).toHaveLength(3);
  expect(pages[0].svg).toContain("Areas 1–8 of 18");
  expect(pages[2].svg).toContain("Areas 17–18 of 18");
  expect(pages[2].svg).toContain("About this figure");
  const pdf = await figureFile("pdf", pages[0], "Areas", "Synthetic", pages);
  const { PDFDocument } = await import("pdf-lib");
  expect((await PDFDocument.load(pdf.body as Uint8Array)).getPageCount()).toBe(3);
});
