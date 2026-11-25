import { beforeEach, expect, it, vi } from "vitest";
import { parse } from "csv-parse/sync";
import { unzipSync, strFromU8 } from "fflate";
import { reportFixture } from "@/lib/facilities/report.fixtures";
import { GET } from "./route";
const m = vi.hoisted(() => ({ read: vi.fn() }));
vi.mock("@/lib/db/nhfr-report", () => ({ readNhfrReport: m.read }));
const url = "http://localhost/api/export/facilities/report?geoLevel=national&geoCode=PH&";
beforeEach(() => {
  vi.clearAllMocks();
  m.read.mockResolvedValue(reportFixture(2, 2));
});
it("exports all rows and raw shares in a sectioned CSV and complete workbook", async () => {
  const r = reportFixture();
  r.types[0].facilityType = "=Synthetic formula";
  m.read.mockResolvedValue(r);
  const csv = await GET(new Request(url + "format=csv"));
  expect(csv.status).toBe(200);
  const rows = parse(await csv.text(), { bom: true, columns: true }) as Record<string, string>[];
  const types = rows.filter((r) => r.Table === "Facility types");
  const areas = rows.filter((r) => r.Table === "Child areas");
  expect(types).toHaveLength(25);
  expect(areas).toHaveLength(55);
  expect(types[0]["Facility type"]).toBe("'=Synthetic formula");
  expect(Number(areas[0]["Share of parent (%)"])).toBe(100 / 55);
  expect(areas[0]["All barangays"]).toBe("10");
  expect(areas[0]["Scope"]).toContain("Individual card filters do not apply");
  expect(rows.some((r) => r.Table === "Definitions")).toBe(true);
  const xlsx = await GET(new Request(url + "format=xlsx"));
  const { default: ExcelJS } = await import("exceljs");
  const book = new ExcelJS.Workbook();
  await book.xlsx.load(await xlsx.arrayBuffer());
  expect(book.getWorksheet("Facility types")!.rowCount).toBe(26);
  expect(book.getWorksheet("Child areas")!.rowCount).toBe(56);
  expect(book.getWorksheet("About this report")).toBeDefined();
});
it("serves all six formats and bundles every image page instead of clipping a tall image", async () => {
  for (const format of ["pdf", "pptx", "png", "svg", "csv", "xlsx"]) {
    const response = await GET(new Request(url + `format=${format}`));
    expect(response.status).toBe(200);
    expect(response.headers.get("cache-control")).toBe("no-store");
    const bytes = new Uint8Array(await response.arrayBuffer());
    expect(bytes.length).toBeGreaterThan(100);
    if (format === "pdf") expect(Buffer.from(bytes).subarray(0, 4).toString()).toBe("%PDF");
    if (format === "png" || format === "svg") {
      expect(response.headers.get("content-type")).toBe("application/zip");
      const files = unzipSync(bytes);
      expect(Object.keys(files)).toHaveLength(3);
      expect(response.headers.get("content-disposition")).toContain(`${format}-pages.zip`);
      if (format === "svg")
        expect(strFromU8(files["report-page-003.svg"])).toContain("Page 3 of 3");
    }
  }
});
it("rejects scope-changing filters, duplicate parameters and failed reports", async () => {
  for (const q of [
    "format=pdf&type=Hospital",
    "format=pdf&area=01",
    "format=pdf&facilitySearch=x",
    "format=pdf&format=csv",
    "format=pdf&notes=bad",
    "format=bad",
  ]) {
    expect((await GET(new Request(url + q))).status).toBe(400);
  }
  expect(
    (await GET(new Request("http://localhost/?geoLevel=barangay&geoCode=0102801001&format=pdf")))
      .status,
  ).toBe(400);
  m.read.mockResolvedValue(null);
  expect((await GET(new Request(url + "format=pdf"))).status).toBe(503);
  m.read.mockRejectedValue(new Error("private database detail"));
  const response = await GET(new Request(url + "format=pdf"));
  expect(response.status).toBe(503);
  expect(await response.text()).not.toContain("private database detail");
});
it("keeps city child tables not applicable and zero denominators distinct", async () => {
  const r = reportFixture(2, 2);
  r.level = "citymun";
  r.code = "0102801";
  r.areas = null;
  r.types = [];
  r.summary = { ...r.summary, count: 0, total: 0, government: 0, private: 0, present: 0, beds: 0 };
  m.read.mockResolvedValue(r);
  const response = await GET(
    new Request("http://localhost/?geoLevel=citymun&geoCode=0102801&format=csv"),
  );
  expect(response.status).toBe(200);
  const rows = parse(await response.text(), { bom: true, columns: true }) as Record<
    string,
    string
  >[];
  expect(rows.find((r) => r.Table === "Child areas")?.Record).toBe(
    "Not applicable at city/municipality level",
  );
  expect(rows.find((r) => r.Indicator === "Government share")?.Value).toBe("");
  expect(rows.find((r) => r.Indicator === "Registered facilities")?.Value).toBe("0");
});
