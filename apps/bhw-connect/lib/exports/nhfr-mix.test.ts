import { expect, it } from "vitest";
import { mixSvg, mixPages } from "./nhfr-mix";
import { figureFile } from "./figure-file";
const rows = [{ facilityType: "Hospital & Clinic", nFacilities: 3, nGovernment: 1, nPrivate: 2 }];
it("exports the selected denominator and view with safe labels and optional notes", () => {
  const mix = mixSvg(rows, 10, "A < B", ["Hospital & Clinic"], "mix", false);
  expect(mix.svg).toContain("A &lt; B");
  expect(mix.svg).toContain("Hospital &amp; Clinic");
  expect(mix.svg).toContain("30% of all facilities");
  expect(mix.svg).not.toContain("About this figure");
  const ownership = mixSvg(rows, 10, "A", [], "ownership", true);
  expect(ownership.svg).toContain("Ownership within each facility type");
  expect(ownership.svg).toContain("About this figure");
  expect(ownership.height).toBeGreaterThan(mix.height);
});
it("generates real PNG/PDF/PPTX files from the same comparison", async () => {
  const svg = mixSvg(rows, 3, "Synthetic", [], "ownership", false);
  const png = await figureFile("png", svg, "Types", "Synthetic");
  expect([...new Uint8Array(png.body as Uint8Array).slice(0, 4)]).toEqual([137, 80, 78, 71]);
  const pdf = await figureFile("pdf", svg, "Types", "Synthetic");
  expect(
    Buffer.from(pdf.body as Uint8Array)
      .subarray(0, 4)
      .toString(),
  ).toBe("%PDF");
  const pptx = await figureFile("pptx", svg, "Types", "Synthetic");
  expect(
    Buffer.from(pptx.body as Uint8Array)
      .subarray(0, 2)
      .toString(),
  ).toBe("PK");
});

it("paginates all 45 types without changing the full-comparison reading or losing rows", async () => {
  const rows = Array.from({ length: 45 }, (_, i) => ({
    facilityType: `Type ${String(i).padStart(2, "0")}`,
    nFacilities: 1,
    nGovernment: 1,
    nPrivate: 0,
  }));
  const pages = mixPages(rows, 45, "Synthetic", [], "mix", true);
  expect(pages).toHaveLength(6);
  expect(pages[0].svg).toContain("Types 1–8 of 45");
  expect(pages[5].svg).toContain("Types 41–45 of 45");
  expect(pages[0].svg).toContain("45 types share");
  expect(pages[0].svg).not.toContain("About this figure");
  expect(pages[5].svg).toContain("About this figure");
  for (const r of rows)
    expect(
      pages.flatMap((p) => p.svg.match(new RegExp(`>${r.facilityType}<`, "g")) ?? []),
    ).toHaveLength(1);
  const result = await figureFile("pdf", pages[0], "Types", "Synthetic", pages);
  const { PDFDocument } = await import("pdf-lib");
  expect((await PDFDocument.load(result.body as Uint8Array)).getPageCount()).toBe(6);
});
