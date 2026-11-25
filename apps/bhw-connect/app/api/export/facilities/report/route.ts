import { NextResponse } from "next/server";
import { z } from "zod";
import { parseReportQuery } from "@/lib/facilities/report";
import { readNhfrReport } from "@/lib/db/nhfr-report";
import { reportPages, reportTables, reportFile, REPORT_NOTES } from "@/lib/exports/nhfr-report";
import { slugify } from "@/lib/exports/query";
export const runtime = "nodejs";
const options = z.object({
  format: z.enum(["pdf", "pptx", "png", "svg", "csv", "xlsx"]),
  notes: z.enum(["0", "1"]).default("0"),
});
export async function GET(request: Request) {
  const params = new URL(request.url).searchParams;
  const query = parseReportQuery(params, true);
  const parsed = options.safeParse({
    format: params.get("format"),
    notes: params.get("notes") ?? undefined,
  });
  if (!query || !parsed.success)
    return NextResponse.json({ error: "Invalid full-location report selection" }, { status: 400 });
  try {
    const r = await readNhfrReport(query.code, query.level);
    if (!r)
      return NextResponse.json(
        { error: "The complete location report could not be verified. Please try again." },
        { status: 503 },
      );
    const { format, notes } = parsed.data;
    const generated = new Date().toISOString();
    const { tables, metadata } = reportTables(r, generated);
    let body: string | Uint8Array,
      mime: string,
      extension: string = format;
    if (format === "csv") {
      // A common long schema preserves every table and distinguishes no rows from a zero.
      const headers = [
        "Table",
        "Record",
        ...new Set(tables.flatMap((t) => t.headers)),
        ...metadata.map((m) => String(m[0])),
      ];
      const rows: unknown[][] = [];
      const add = (table: string, record: string, values: Record<string, unknown>) =>
        rows.push(
          headers.map((h) =>
            h === "Table"
              ? table
              : h === "Record"
                ? record
                : metadata.some((m) => m[0] === h)
                  ? metadata.find((m) => m[0] === h)![1]
                  : (values[h] ?? null),
          ),
        );
      for (const table of tables) {
        if (!table.rows.length)
          add(
            table.name,
            table.name === "Child areas" && r.level === "citymun"
              ? "Not applicable at city/municipality level"
              : "No aggregate rows (verified zero facilities)",
            {},
          );
        for (const row of table.rows)
          add(table.name, "Data", Object.fromEntries(table.headers.map((h, i) => [h, row[i]])));
      }
      for (const note of REPORT_NOTES) add("Definitions", "Note", { Indicator: note });
      const cell = (v: unknown) =>
        `"${String(v ?? "")
          .replace(/^[=+@\-\t\r\n]/, "'$&")
          .replaceAll('"', '""')}"`;
      body = "\uFEFF" + [headers, ...rows].map((row) => row.map(cell).join(",")).join("\r\n");
      mime = "text/csv; charset=utf-8";
    } else if (format === "xlsx") {
      const { default: ExcelJS } = await import("exceljs");
      const book = new ExcelJS.Workbook();
      book.creator = "BHW Connect";
      for (const table of tables) {
        const sheet = book.addWorksheet(table.name);
        sheet.addRow(table.headers);
        table.rows.forEach((row) => sheet.addRow(row));
        sheet.getRow(1).font = { bold: true };
        sheet.columns = table.headers.map((_, i) => ({ width: i === 0 ? 50 : 26 }));
        sheet.getColumn(1).alignment = { wrapText: true };
      }
      const about = book.addWorksheet("About this report");
      [...metadata, ...REPORT_NOTES.map((n) => ["Note", n])].forEach((row) => about.addRow(row));
      about.columns = [{ width: 30 }, { width: 110 }];
      about.getColumn(2).alignment = { wrapText: true };
      body = new Uint8Array(await book.xlsx.writeBuffer());
      mime = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
    } else {
      const result = await reportFile(
        format,
        reportPages(r, notes === "1", generated),
        `Health facilities · ${r.name} · Full location report`,
      );
      body = result.body;
      mime = result.mime;
      extension = result.extension;
    }
    const name = slugify(
      "health-facilities",
      r.name.slice(0, 45),
      r.code,
      "all-types",
      "2026-09",
      format === "png" || format === "svg" ? `${format}-pages` : format,
    );
    return new NextResponse(typeof body === "string" ? body : new Uint8Array(body), {
      headers: {
        "Content-Type": mime,
        "Content-Disposition": `attachment; filename="${name}.${extension}"`,
        "Cache-Control": "no-store",
      },
    });
  } catch {
    return NextResponse.json(
      { error: "The full-location report could not be generated. Please try again." },
      { status: 503 },
    );
  }
}
