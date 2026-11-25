import { NextResponse } from "next/server";
import { z } from "zod";
import { getNhfrExploration } from "@/lib/db/nhfr-exploration";
import { exploreFacilities } from "@/lib/facilities/exploration";
import { searchFacilities } from "@/lib/facilities/search";
import { explorationFigure } from "@/lib/exports/nhfr-exploration";
import { figureFile } from "@/lib/exports/figure-file";
export const runtime = "nodejs";
const schema = z.object({
  code: z.string().regex(/^\d{7,10}$/),
  types: z.array(z.string().min(1).max(200)).max(100),
  ownership: z.string().max(200),
  format: z.enum(["png", "svg", "pdf", "pptx", "csv", "xlsx"]),
  table: z.enum(["barangays", "facilities"]),
  barangay: z.string().max(12),
  search: z.string().max(200),
});
export async function GET(request: Request) {
  const p = new URL(request.url).searchParams;
  const parsed = schema.safeParse({
    code: p.get("code"),
    types: p.getAll("type"),
    ownership: p.get("ownership") ?? "",
    format: p.get("format"),
    table: p.get("table") ?? "barangays",
    barangay: p.get("barangay") ?? "",
    search: p.get("search") ?? "",
  });
  if (!parsed.success)
    return NextResponse.json({ error: "Invalid map selection" }, { status: 400 });
  const q = parsed.data;
  try {
    const input = await getNhfrExploration(q.code);
    if (!input)
      return NextResponse.json(
        { error: "The complete facility inventory and barangay roster could not be verified." },
        { status: 503 },
      );
    if (
      q.types.some((t) => !input.facilities.some((f) => f.facilityType === t)) ||
      (q.ownership && !input.facilities.some((f) => f.ownershipMajor === q.ownership)) ||
      (q.barangay && !input.barangays.some((b) => b.geoCode === q.barangay))
    )
      return NextResponse.json({ error: "Unknown filter" }, { status: 400 });
    const result = exploreFacilities(input, q)!;
    const facilities = searchFacilities(
      result.facilities.filter((f) => !q.barangay || f.barangayGeoCode === q.barangay),
      q.search,
    );
    const headers =
      q.table === "barangays"
        ? ["Barangay", "Geographic code", "Facilities", "Mapping status"]
        : ["Facility code", "Facility", "Type", "Ownership", "Barangay", "Barangay code"];
    const rows =
      q.table === "barangays"
        ? result.rows.map((r) => [
            r.geoName,
            r.geoCode,
            r.count,
            r.mapped ? "Mapped representative point" : "Geometry unavailable",
          ])
        : facilities.map((f) => [
            f.facilityCode,
            f.facilityName,
            f.facilityType,
            f.ownershipMajor,
            f.barangayName,
            f.barangayGeoCode,
          ]);
    const metadata: (string | number)[][] = [
      ["Location", input.name],
      ["Location code", input.code],
      ["Types", q.types.join("; ") || "All recorded types"],
      ["Ownership", q.ownership || "All ownership"],
      ["Source", "DOH NHFR"],
      ["Snapshot", "September 2026"],
      ["Geometry source", "faeldon/philippines-json-maps, 2023 PSGC series"],
      ["Selected facilities", result.facilities.length],
      ["Facilities without geometry", result.missingGeometry],
      ["Facilities without matched barangay", result.unassigned],
      ["Map meaning", "Representative barangay points, not facility coordinates"],
      ["Barangay selection", q.table === "facilities" ? q.barangay || "All" : "All"],
      ["List search", q.table === "facilities" ? q.search : "Not applied"],
    ];
    let body: string | Uint8Array;
    let mime: string;
    if (q.format === "csv") {
      const cell = (v: unknown) =>
        `"${String(v ?? "")
          .replace(/^[=+@\-\t\r\n]/, "'$&")
          .replaceAll('"', '""')}"`;
      // A metadata-only row keeps the selected scope visible for a genuine zero result.
      body =
        "\uFEFF" +
        [
          [...headers, ...metadata.map((r) => r[0])],
          ...(rows.length ? rows : [headers.map(() => "")]).map((r) => [
            ...r,
            ...metadata.map((m) => m[1]),
          ]),
        ]
          .map((r) => r.map(cell).join(","))
          .join("\r\n");
      mime = "text/csv; charset=utf-8";
    } else if (q.format === "xlsx") {
      const { default: ExcelJS } = await import("exceljs");
      const book = new ExcelJS.Workbook();
      const sheet = book.addWorksheet(q.table);
      sheet.addRow(headers);
      rows.forEach((r) => sheet.addRow(r));
      sheet.getRow(1).font = { bold: true };
      sheet.columns = headers.map(() => ({ width: 32 }));
      const about = book.addWorksheet("About");
      metadata.forEach((r) => about.addRow(r));
      about.columns = [{ width: 36 }, { width: 90 }];
      about.getColumn(2).alignment = { wrapText: true };
      body = new Uint8Array(await book.xlsx.writeBuffer());
      mime = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
    } else {
      const file = await figureFile(
        q.format,
        explorationFigure(input, q),
        "Facilities recorded by barangay",
        metadata.map((r) => r.join(": ")).join("; "),
      );
      body = file.body;
      mime = file.mime;
    }
    return new NextResponse(typeof body === "string" ? body : new Uint8Array(body), {
      headers: {
        "Content-Type": mime,
        "Content-Disposition": `attachment; filename="nhfr-${q.code}-${q.table}-2026-09.${q.format}"`,
        "Cache-Control": "no-store",
      },
    });
  } catch {
    return NextResponse.json({ error: "Export unavailable. Please try again." }, { status: 503 });
  }
}
