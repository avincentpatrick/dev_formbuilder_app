// Smoke-test the actual release, detached from checkout node_modules and behind a current junction.
import assert from "node:assert/strict";
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { cp, mkdtemp, writeFile, readFile, rm, symlink } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import ExcelJS from "exceljs";
const exec = promisify(execFile);
const temp = await mkdtemp(join(tmpdir(), "bhw-release-extractor-"));
try {
  const release = join(temp, "release"),
    current = join(temp, "current");
  const standalone = resolve(".next/standalone");
  await cp(standalone, release, { recursive: true, dereference: true });
  // Directory junctions need no file-symlink privilege on Windows and match production deployment.
  await symlink(release, current, "junction");
  async function extract(name, bytes) {
    const input = join(temp, name),
      output = join(temp, `${name}.result.json`);
    await writeFile(input, bytes);
    await exec(
      process.execPath,
      [
        "--max-old-space-size=512",
        join(current, "analysis-extractor.mjs"),
        input,
        name,
        "{}",
        output,
      ],
      {
        cwd: current,
        timeout: 90000,
        maxBuffer: 1024 * 1024,
        env: { ...process.env, NODE_PATH: "" },
        windowsHide: true,
      },
    );
    const result = JSON.parse(await readFile(output, "utf8"));
    assert.equal(result.status, "ready", `${name}: ${result.error ?? "no ready extraction"}`);
    return result;
  }
  const csv = await extract(
    "python-verification.csv",
    Buffer.from("area,count\nA,2\nB,4\nC,6\nD,8\nE,\n"),
  );
  assert.equal(csv.tables[0].rows.length, 5);
  assert.deepEqual(
    csv.tables[0].rows.map((row) => row.count),
    [2, 4, 6, 8, null],
  );
  const book = new ExcelJS.Workbook(),
    sheet = book.addWorksheet("Synthetic");
  sheet.addRows([
    ["area", "count"],
    ["A", 2],
    ["B", 4],
    ["C", 6],
    ["D", 8],
    ["E", null],
  ]);
  const excel = await extract("synthetic.xlsx", Buffer.from(await book.xlsx.writeBuffer()));
  assert.equal(excel.tables[0].rows[0].count, 2);
  const content = "BT /F1 12 Tf 50 700 Td (Synthetic count 12) Tj ET";
  const objects = [
    "<< /Type /Catalog /Pages 2 0 R >>",
    "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
    "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
    "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    `<< /Length ${content.length} >>\nstream\n${content}\nendstream`,
  ];
  let pdf = "%PDF-1.4\n";
  const offsets = [];
  objects.forEach((value, i) => {
    offsets.push(pdf.length);
    pdf += `${i + 1} 0 obj\n${value}\nendobj\n`;
  });
  const xref = pdf.length;
  pdf += `xref\n0 6\n0000000000 65535 f \n${offsets.map((offset) => `${String(offset).padStart(10, "0")} 00000 n `).join("\n")}\ntrailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF`;
  const document = await extract("synthetic.pdf", Buffer.from(pdf));
  assert.match(document.pages[0].text, /Synthetic count 12/);
  const geo = await extract(
    "synthetic.geojson",
    Buffer.from(
      JSON.stringify({
        type: "FeatureCollection",
        features: [
          {
            type: "Feature",
            properties: { code: "001" },
            geometry: { type: "Point", coordinates: [120, 14] },
          },
        ],
      }),
    ),
  );
  assert.equal(geo.tables[0].rows[0].code, "001");
  console.log(
    "Standalone extractor verified outside checkout through a release junction: CSV, XLSX, PDF, GeoJSON.",
  );
} finally {
  await rm(temp, { recursive: true, force: true });
}
