import { expect, it } from "vitest";
import { presenceSvg, presencePages } from "./nhfr-presence";
import { selectPresence, type PresenceInput } from "@/lib/facilities/presence";
import { figureFile } from "./figure-file";
const input: PresenceInput = {
  code: "PH",
  level: "national",
  name: "Synthetic < parent",
  types: ["Hospital"],
  selectedTypes: ["Hospital"],
  rows: Array.from({ length: 18 }, (_, i) => ({
    code: String(i + 1).padStart(2, "0"),
    name: `Area ${i + 1}`,
    level: "region",
    present: i === 0 ? 1 : 0,
    barangays: i === 17 ? 0 : 3,
  })),
};
it("exports each area's denominator, unavailable shares, escaping and optional notes", () => {
  const result = selectPresence(input, ["01", "18"], "highest")!;
  const svg = presenceSvg(input, result, ["01", "18"], ["Hospital"], false).svg;
  expect(svg).toContain("Synthetic &lt; parent");
  expect(svg).toContain("33.3% · 1 of 3 barangays");
  expect(svg).toContain("Percentage unavailable · 0 of 0 barangays");
  expect(svg).not.toContain("About this figure");
});
it("retains the complete selection reading and all 18 areas across three PDF pages", async () => {
  const result = selectPresence(input, [], "lowest")!;
  const pages = presencePages(input, result, [], ["Hospital"], true);
  expect(pages).toHaveLength(3);
  expect(pages[0].svg).toContain("Areas 1–8 of 18");
  expect(pages[2].svg).toContain("Areas 17–18 of 18");
  expect(pages[2].svg).toContain("About this figure");
  const pdf = await figureFile("pdf", pages[0], "Presence", "Synthetic", pages);
  const { PDFDocument } = await import("pdf-lib");
  expect((await PDFDocument.load(pdf.body as Uint8Array)).getPageCount()).toBe(3);
}, 15000);
