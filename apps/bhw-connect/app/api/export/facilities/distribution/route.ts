import { NextResponse } from "next/server";
import { z } from "zod";
import { parseCatalogParams } from "@/lib/catalog/navigation";
import { getNhfrDistribution } from "@/lib/db/nhfr-distribution";
import { selectDistribution, DISTRIBUTION_NOTES } from "@/lib/facilities/distribution";
import { distributionSvg, distributionPages } from "@/lib/exports/nhfr-distribution";
import { figureFile } from "@/lib/exports/figure-file";
import { slugify } from "@/lib/exports/query";
export const runtime = "nodejs";
const settingsSchema = z.object({
  format: z.enum(["csv", "xlsx", "svg", "png", "pdf", "pptx"]),
  order: z.enum(["highest", "lowest", "name"]).default("highest"),
});
export async function GET(request: Request) {
  const params = new URL(request.url).searchParams;
  const parsed = parseCatalogParams(Object.fromEntries(params));
  const settings = settingsSchema.safeParse({
    format: params.get("format"),
    order: params.get("order") ?? undefined,
  });
  const areas = [...new Set(params.getAll("area"))].sort(),
    types = [...new Set(params.getAll("type"))].sort();
  if (
    !parsed ||
    parsed.selection.mode !== "psgc" ||
    !settings.success ||
    ["mode", "location", "format", "order", "notes"].some((k) => params.getAll(k).length > 1) ||
    areas.length > 1000 ||
    areas.some((a) => !/^\d{2,10}$/.test(a)) ||
    types.length > 200 ||
    types.some((t) => !t || t.length > 200)
  )
    return NextResponse.json({ error: "Invalid geographic comparison selection" }, { status: 400 });
  const { selection } = parsed;
  if (
    selection.level !== "national" &&
    selection.level !== "region" &&
    selection.level !== "province"
  )
    return NextResponse.json({ error: "Unsupported geographic comparison level" }, { status: 400 });
  const { format, order } = settings.data;
  try {
    const input = await getNhfrDistribution(selection.code, selection.level);
    const result = selectDistribution(input, areas, types, order);
    if (!input || !result)
      return NextResponse.json(
        { error: "The complete geographic comparison could not be verified. Please try again." },
        { status: 503 },
      );
    const areaLabel = areas.length
      ? input.areas
          .filter((a) => areas.includes(a.code))
          .map((a) => a.name)
          .join("; ")
      : "All areas";
    const typeLabel = types.length ? types.join("; ") : "All recorded types";
    const headers = [
      "Area",
      "Geographic code",
      "Geographic level",
      "Facilities",
      "Share of parent (%)",
      "Share status",
      "Government",
      "Private",
    ];
    const values = result.rows.map((a) => [
      a.name,
      a.code,
      a.level,
      a.count,
      result.parent.count > 0 ? (100 * a.count) / result.parent.count : null,
      result.parent.count > 0 ? "available" : "unavailable",
      a.government,
      a.private,
    ]);
    const metadata = [
      ["Parent location", input.name],
      ["Parent code", input.code],
      ["Parent level", input.level],
      ["Parent total for selected types", result.parent.count],
      ["Count scale maximum", result.maximum],
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
      const data = book.addWorksheet("Geographic distribution");
      data.addRow(headers);
      values.forEach((r) => data.addRow(r));
      data.getRow(1).font = { bold: true };
      data.columns = headers.map((_, i) => ({ width: i === 0 ? 50 : 25 }));
      const about = book.addWorksheet("About this data");
      [...metadata, ...DISTRIBUTION_NOTES.map((n) => ["Note", n])].forEach((r) => about.addRow(r));
      about.columns = [{ width: 32 }, { width: 100 }];
      about.getColumn(2).alignment = { wrapText: true };
      body = new Uint8Array(await book.xlsx.writeBuffer());
      mime = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
    } else {
      const notes = format === "pdf" && params.get("notes") === "1";
      const file = await figureFile(
        format,
        distributionSvg(input, result, areas, types, notes),
        `Facility distribution · ${input.name}`,
        metadata.map((r) => r.join(": ")).join("; "),
        format === "pdf" || format === "pptx"
          ? distributionPages(input, result, areas, types, notes)
          : undefined,
      );
      body = file.body;
      mime = file.mime;
    }
    const filename = slugify(
      "facility-distribution",
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
      { error: "This geographic comparison export could not be generated. Please try again." },
      { status: 503 },
    );
  }
}
