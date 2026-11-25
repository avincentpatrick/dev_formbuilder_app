import { expect, it } from "vitest";
import { unzipSync, strFromU8 } from "fflate";
import { reportFixture } from "@/lib/facilities/report.fixtures";
import { reportPages, reportFile, reportTables } from "./nhfr-report";
it("includes all types and areas beyond the old limits, with source scope and page context", () => {
  const r = reportFixture();
  const pages = reportPages(r, false, "2026-11-25T00:00:00.000Z");
  expect(pages.length).toBeGreaterThanOrEqual(16);
  expect(pages.every((p) => p.height <= 772)).toBe(true);
  const text = pages.map((p) => p.svg).join(" ");
  const readable = text.replace(/<[^>]*>/g, " ").replace(/\s+/g, " ");
  for (const t of r.types) expect(readable).toContain(t.facilityType);
  for (const a of r.areas!.rows) expect(text).toContain(a.name);
  expect(text).toContain("All facility types. Individual card filters do not apply.");
  expect(text).toContain(`Page ${pages.length} of ${pages.length}`);
  expect(text).not.toContain("About these figures");
  expect(reportPages(r, true, "2026-11-25").length).toBeGreaterThan(pages.length);
  const tables = reportTables(r, "2026-11-25");
  expect(tables.tables[1].rows).toHaveLength(25);
  expect(tables.tables[2].rows).toHaveLength(55);
  expect(tables.tables[2].rows[0][4]).toBe(100 / 55);
});
it("creates separate complete image pages, fixed A4 PDF pages and widescreen slides", async () => {
  const r = reportFixture(2, 2);
  const pages = reportPages(r, false, "2026-11-25");
  expect(pages).toHaveLength(3);
  for (const format of ["svg", "png"] as const) {
    const file = await reportFile(format, pages, "Report");
    expect(file.extension).toBe("zip");
    const entries = unzipSync(file.body);
    expect(Object.keys(entries)).toHaveLength(3);
    if (format === "svg")
      expect(strFromU8(entries["report-page-003.svg"])).toContain("Page 3 of 3");
    else expect([...entries["report-page-001.png"].slice(0, 4)]).toEqual([137, 80, 78, 71]);
  }
  const pdf = await reportFile("pdf", pages, "Report");
  const { PDFDocument } = await import("pdf-lib");
  const document = await PDFDocument.load(pdf.body);
  expect(document.getPageCount()).toBe(3);
  expect(document.getPages()[0].getSize().width).toBeCloseTo(841.89);
  expect(document.getPages()[0].getSize().height).toBeCloseTo(595.28);
  const pptx = await reportFile("pptx", pages, "Report");
  const entries = unzipSync(pptx.body);
  expect(Object.keys(entries).filter((k) => /^ppt\/slides\/slide\d+\.xml$/.test(k))).toHaveLength(
    3,
  );
  expect(strFromU8(entries["ppt/presentation.xml"])).toContain('cx="12192000"');
}, 15000);
