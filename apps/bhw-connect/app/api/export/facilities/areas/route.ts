import { NextResponse } from "next/server";
import { z } from "zod";
import { parseCatalogParams } from "@/lib/catalog/navigation";
import { getNhfrDistribution } from "@/lib/db/nhfr-distribution";
import { AREA_ORDERS, selectAreaFigures, AREA_NOTES } from "@/lib/facilities/area-figures";
import { getNhfrPresence } from "@/lib/db/nhfr-presence";
import { areaFiguresSvg, areaFiguresPages } from "@/lib/exports/nhfr-area-figures";
import { figureFile } from "@/lib/exports/figure-file";
import { slugify } from "@/lib/exports/query";
export const runtime = "nodejs";
const settingsSchema = z.object({
  format: z.enum(["csv", "xlsx", "svg", "png", "pdf", "pptx"]),
  order: z.enum(AREA_ORDERS).default("highest"),
});
export async function GET(request: Request) {
  const params = new URL(request.url).searchParams;
  const parsed = parseCatalogParams(Object.fromEntries(params));
  const settings = settingsSchema.safeParse({
    format: params.get("format"),
    order: params.get("order") ?? undefined,
  });
  const search = (params.get("q") ?? "").trim();
  const ownership = params.get("ownership") ?? "0";
  const areas = [...new Set(params.getAll("area"))].sort(),
    types = [...new Set(params.getAll("type"))].sort();
  if (
    !parsed ||
    parsed.selection.mode !== "psgc" ||
    !settings.success ||
    ["mode", "location", "format", "order", "notes", "q", "ownership", "view", "dataset"].some(
      (k) => params.getAll(k).length > 1,
    ) ||
    search.length > 200 ||
    (ownership !== "0" && ownership !== "1") ||
    (params.has("view") && params.get("view") !== "table") ||
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
    const [input, presence] = await Promise.all([
      getNhfrDistribution(selection.code, selection.level),
      getNhfrPresence(selection.code, selection.level, types),
    ]);
    const result = selectAreaFigures(input, presence, areas, types, order, search);
    if (!input || !result || !result.rows.length)
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
      "Barangays with a recorded facility",
      "All barangays",
      "Share of barangays (%)",
      "Barangay share status",
      "Barangays with no facility of selected types recorded",
    ];
    const values = result.rows.map((a) => [
      a.name,
      a.code,
      a.level,
      a.count,
      a.facilityShare,
      a.facilityShare === null ? "unavailable" : "available",
      a.government,
      a.private,
      a.present,
      a.barangays,
      a.presenceShare,
      a.presenceShare === null ? "unavailable" : "available",
      a.barangays - a.present,
    ]);
    const metadata = [
      ["Parent location", input.name],
      ["Parent code", input.code],
      ["Parent level", input.level],
      ["Parent total for selected types", result.parent],
      ["Exact-area search", search],
      ["Matching facilities", result.count],
      ["Matching barangays", result.barangays],
      ["Matching barangays with recorded facility", result.present],
      ["Show ownership in figure", ownership],
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
      const data = book.addWorksheet("Exact area figures");
      data.addRow(headers);
      values.forEach((r) => data.addRow(r));
      data.getRow(1).font = { bold: true };
      data.columns = headers.map((_, i) => ({ width: i === 0 ? 50 : 25 }));
      const about = book.addWorksheet("About this data");
      [...metadata, ...AREA_NOTES.map((n) => ["Note", n])].forEach((r) => about.addRow(r));
      about.columns = [{ width: 32 }, { width: 100 }];
      about.getColumn(2).alignment = { wrapText: true };
      body = new Uint8Array(await book.xlsx.writeBuffer());
      mime = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
    } else {
      const notes = format === "pdf" && params.get("notes") === "1";
      const file = await figureFile(
        format,
        areaFiguresSvg(input, result, areas, types, notes, {
          query: search,
          ownership: ownership === "1",
        }),
        `Exact area figures · ${input.name}`,
        metadata.map((r) => r.join(": ")).join("; "),
        format === "pdf" || format === "pptx"
          ? areaFiguresPages(input, result, areas, types, notes, search, ownership === "1")
          : undefined,
      );
      body = file.body;
      mime = file.mime;
    }
    const filename = slugify(
      "exact-area-figures",
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
