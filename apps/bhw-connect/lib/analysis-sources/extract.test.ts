import { describe, it, expect } from "vitest";
import { zipSync, strToU8 } from "fflate";
import ExcelJS from "exceljs";
import { extractFile, unpack } from "./extract.mjs";
function pdf() {
  const content = "BT /F1 12 Tf 50 700 Td (Synthetic count 12) Tj ET";
  const objs = [
    "<< /Type /Catalog /Pages 2 0 R >>",
    "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
    "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
    "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    `<< /Length ${content.length} >>\nstream\n${content}\nendstream`,
  ];
  let text = "%PDF-1.4\n";
  const offsets = [0];
  objs.forEach((o, i) => {
    offsets.push(text.length);
    text += `${i + 1} 0 obj\n${o}\nendobj\n`;
  });
  const start = text.length;
  text += `xref\n0 6\n0000000000 65535 f \n${offsets
    .slice(1)
    .map((x) => `${String(x).padStart(10, "0")} 00000 n `)
    .join("\n")}\ntrailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${start}\n%%EOF`;
  return strToU8(text);
}
function pointShapeZip() {
  const header = (length: number) => {
    const b = Buffer.alloc(length);
    b.writeInt32BE(9994, 0);
    b.writeInt32BE(length / 2, 24);
    b.writeInt32LE(1000, 28);
    b.writeInt32LE(1, 32);
    b.writeDoubleLE(120, 36);
    b.writeDoubleLE(14, 44);
    b.writeDoubleLE(120, 52);
    b.writeDoubleLE(14, 60);
    return b;
  };
  const shp = header(128);
  shp.writeInt32BE(1, 100);
  shp.writeInt32BE(10, 104);
  shp.writeInt32LE(1, 108);
  shp.writeDoubleLE(120, 112);
  shp.writeDoubleLE(14, 120);
  const shx = header(108);
  shx.writeInt32BE(50, 100);
  shx.writeInt32BE(10, 104);
  const dbf = Buffer.alloc(70);
  dbf[0] = 3;
  dbf.writeUInt32LE(1, 4);
  dbf.writeUInt16LE(65, 8);
  dbf.writeUInt16LE(4, 10);
  dbf.write("code", 32);
  dbf[43] = 67;
  dbf[48] = 3;
  dbf[64] = 13;
  dbf[65] = 32;
  dbf.write("001", 66);
  dbf[69] = 26;
  return zipSync({ "x.shp": shp, "x.shx": shx, "x.dbf": dbf, "x.prj": strToU8("EPSG:4326") });
}
describe("real document and table extraction", () => {
  it("preserves codes, flags ambiguity and retains duplicate rows", async () => {
    const result = await extractFile(
      strToU8(
        "geo_code,count,date,category\n001,10,01/02/2025,Male\n002,20,02/01/2025,male\n002,20,02/01/2025,male",
      ),
      "test.csv",
    );
    expect(result.status).toBe("ready");
    expect(result.tables[0].rows[0]).toEqual({
      geo_code: "001",
      count: 10,
      date: "01/02/2025",
      category: "Male",
    });
    expect(result.profile.warnings.join(" ")).toContain("Duplicate");
    expect(result.profile.warnings.join(" ")).toContain("ambiguous");
    expect(result.profile.warnings.join(" ")).toContain("capitalization");
  });
  it("reads a real XLSX workbook and reports missing formula results", async () => {
    const workbook = new ExcelJS.Workbook();
    const sheet = workbook.addWorksheet("Synthetic");
    sheet.addRow(["geo_code", "count", "formula"]);
    sheet.addRow(["001", 12, { formula: "A1+B1" }]);
    const result = await extractFile(
      new Uint8Array(await workbook.xlsx.writeBuffer()),
      "test.xlsx",
    );
    expect(result.status).toBe("ready");
    expect(result.tables[0].rows[0].count).toBe(12);
    expect(result.tables[0].rows[0].formula).toBeNull();
    expect(result.profile.warnings.join(" ")).toContain("Formula");
  });
  it("reads document and slide text without interpreting images or active content", async () => {
    const doc = zipSync({
      "word/document.xml": strToU8(
        '<w:document xmlns:w="x"><w:body><w:p><w:r><w:t>Synthetic document 12</w:t></w:r></w:p></w:body></w:document>',
      ),
    });
    const ppt = zipSync({
      "ppt/slides/slide1.xml": strToU8(
        '<a:sld xmlns:a="x"><a:p><a:r><a:t>Synthetic slide 20</a:t></a:r></a:p></a:sld>',
      ),
    });
    expect((await extractFile(doc, "test.docx")).pages[0].text).toContain("Synthetic document");
    expect((await extractFile(ppt, "test.pptx")).pages[0].text).toContain("Synthetic slide");
    for (const name of ["test.doc", "test.ppt", "test.xls"])
      expect((await extractFile(strToU8("synthetic legacy placeholder"), name)).status).toBe(
        "blocked",
      );
  });
  it("reads real PDF text and labels an unreadable binary honestly", async () => {
    const result = await extractFile(pdf(), "synthetic.pdf");
    expect(result.status).toBe("ready");
    expect(result.pages[0].text).toContain("Synthetic count 12");
    expect((await extractFile(strToU8("not a PDF"), "bad.pdf")).status).toBe("blocked");
  });
  it("extracts a real zipped point shapefile with preserved properties", async () => {
    const result = await extractFile(pointShapeZip(), "synthetic.zip");
    expect(result.status).toBe("ready");
    expect(result.tables[0].rows[0]).toMatchObject({
      code: "001",
      geometry: { type: "Point" },
    });
    const coordinates = (result.tables[0].rows[0].geometry as { coordinates: number[] })
      .coordinates;
    expect(coordinates[0]).toBeCloseTo(120, 10);
    expect(coordinates[1]).toBeCloseTo(14, 10);
  });
  it("validates geographic coordinates and missing CRS sidecars", async () => {
    const geo = {
      type: "FeatureCollection",
      features: [
        {
          type: "Feature",
          properties: { geo_code: "001" },
          geometry: { type: "Point", coordinates: [120, 14] },
        },
      ],
    };
    expect((await extractFile(strToU8(JSON.stringify(geo)), "test.geojson")).status).toBe("ready");
    geo.features[0].geometry.coordinates = [999, 999];
    expect((await extractFile(strToU8(JSON.stringify(geo)), "bad.geojson")).status).toBe("blocked");
    const zip = zipSync({
      "x.shp": new Uint8Array(4),
      "x.shx": new Uint8Array(4),
      "x.dbf": new Uint8Array(4),
    });
    expect((await extractFile(zip, "test.zip")).error).toContain("CRS");
  });
  it("rejects unsafe archive paths and macros, and strips web scripts", async () => {
    expect(() => unpack(zipSync({ "../escape": strToU8("x") }))).toThrow("unsafe");
    expect(() => unpack(zipSync({ "word/vbaProject.bin": strToU8("x") }))).toThrow("Macro");
    const result = await extractFile(
      strToU8(
        "<html><body><main><p>Synthetic public evidence 12.</p><script>steal()</script></main></body></html>",
      ),
      "test.html",
    );
    expect(result.pages[0].text).toContain("Synthetic public evidence");
    expect(result.pages[0].text).not.toContain("steal");
  });
});
