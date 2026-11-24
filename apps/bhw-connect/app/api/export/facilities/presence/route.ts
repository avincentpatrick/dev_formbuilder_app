import { NextResponse } from "next/server";
import { z } from "zod";
import { parsePresenceQuery } from "@/lib/facilities/presence-query";
import { getNhfrPresence } from "@/lib/db/nhfr-presence";
import { selectPresence, PRESENCE_NOTES } from "@/lib/facilities/presence";
import { presenceSvg, presencePages } from "@/lib/exports/nhfr-presence";
import { figureFile } from "@/lib/exports/figure-file";
import { slugify } from "@/lib/exports/query";
export const runtime = "nodejs";
const settingsSchema = z.object({
  format: z.enum(["csv", "xlsx", "svg", "png", "pdf", "pptx"]),
  order: z.enum(["highest", "lowest", "name"]).default("lowest"),
});
export async function GET(request: Request) {
  const params = new URL(request.url).searchParams;
  const query = parsePresenceQuery(params);
  const settings = settingsSchema.safeParse({
    format: params.get("format"),
    order: params.get("order") ?? undefined,
  });
  if (!query || !settings.success)
    return NextResponse.json({ error: "Invalid barangay presence selection" }, { status: 400 });
  const { areas, types, order } = query;
  const { format } = settings.data;
  try {
    const input = await getNhfrPresence(query.code, query.level, types);
    const result = selectPresence(input, areas, order);
    if (!input || !result)
      return NextResponse.json(
        { error: "Barangay presence could not be verified. Please try again." },
        { status: 503 },
      );
    const areaLabel = areas.length
      ? input.rows
          .filter((a) => areas.includes(a.code))
          .map((a) => a.name)
          .join("; ")
      : "All areas";
    const typeLabel = types.length ? types.join("; ") : "All recorded types";
    const headers = [
      "Area",
      "Geographic code",
      "Geographic level",
      "Barangays with a recorded facility",
      "All barangays",
      "Share (%)",
      "Share status",
      "Barangays with no facility recorded",
    ];
    const values = result.rows.map((a) => [
      a.name,
      a.code,
      a.level,
      a.present,
      a.barangays,
      a.share,
      a.share === null ? "unavailable" : "available",
      a.barangays - a.present,
    ]);
    const metadata = [
      ["Parent location", input.name],
      ["Parent code", input.code],
      ["Parent level", input.level],
      ["Selected areas: barangays with recorded facility", result.present],
      ["Selected areas: all barangays", result.barangays],
      ["Percentage scale", "0–100%"],
      ["Selected areas", areaLabel],
      ["Facility types", typeLabel],
      ["Order", order],
      ["Source", "DOH National Health Facility Registry"],
      ["Snapshot", "September 2026"],
    ];
    let body: string | Uint8Array, mime: string;
    if (format === "csv") {
      const cell = (v: unknown) =>
        `"${String(v ?? "")
          .replace(/^[=+@\-\t\r\n]/, "'$&")
          .replaceAll('"', '""')}"`;
      body =
        "\uFEFF" +
        [
          [...headers, ...metadata.map((r) => r[0])],
          ...values.map((r) => [...r, ...metadata.map((m) => m[1])]),
        ]
          .map((r) => r.map(cell).join(","))
          .join("\r\n");
      mime = "text/csv; charset=utf-8";
    } else if (format === "xlsx") {
      const { default: ExcelJS } = await import("exceljs");
      const book = new ExcelJS.Workbook();
      book.creator = "BHW Connect";
      const data = book.addWorksheet("Barangay presence");
      data.addRow(headers);
      values.forEach((r) => data.addRow(r));
      data.getRow(1).font = { bold: true };
      data.columns = headers.map((_, i) => ({ width: i === 0 ? 50 : 25 }));
      const about = book.addWorksheet("About this data");
      [...metadata, ...PRESENCE_NOTES.map((n) => ["Note", n])].forEach((r) => about.addRow(r));
      about.columns = [{ width: 32 }, { width: 100 }];
      about.getColumn(2).alignment = { wrapText: true };
      body = new Uint8Array(await book.xlsx.writeBuffer());
      mime = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
    } else {
      const notes = format === "pdf" && params.get("notes") === "1";
      const file = await figureFile(
        format,
        presenceSvg(input, result, areas, types, notes),
        `Barangay presence · ${input.name}`,
        metadata.map((r) => r.join(": ")).join("; "),
        format === "pdf" || format === "pptx"
          ? presencePages(input, result, areas, types, notes)
          : undefined,
      );
      body = file.body;
      mime = file.mime;
    }
    const filename = slugify(
      "barangay-presence",
      input.name.slice(0, 45),
      input.code,
      order,
      areas.length ? `${areas.length}-areas` : "all-areas",
      types.length ? `${types.length}-types` : "all-types",
      "2026-09",
    );
    return new NextResponse(typeof body === "string" ? body : new Uint8Array(body), {
      headers: {
        "Content-Type": mime,
        "Content-Disposition": `attachment; filename="${filename}.${format}"`,
        "Cache-Control": "no-store",
      },
    });
  } catch {
    return NextResponse.json(
      { error: "This barangay presence export could not be generated. Please try again." },
      { status: 503 },
    );
  }
}
