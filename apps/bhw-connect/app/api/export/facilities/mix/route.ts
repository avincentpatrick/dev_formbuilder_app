import { NextResponse } from "next/server";
import { z } from "zod";
import { parseCatalogParams } from "@/lib/catalog/navigation";
import { getGeoByCode } from "@/lib/db/geo";
import { getNhfrCounts, getNhfrTypes } from "@/lib/db/nhfr";
import { selectMix, searchMixRows, MIX_NOTES } from "@/lib/facilities/mix";
import { mixSvg, mixPages } from "@/lib/exports/nhfr-mix";
import { figureFile } from "@/lib/exports/figure-file";
import { slugify } from "@/lib/exports/query";

export const runtime = "nodejs";
const options = z.object({
  format: z.enum(["csv", "xlsx", "svg", "png", "pdf", "pptx"]),
  view: z.enum(["mix", "ownership", "table"]).default("mix"),
  order: z.enum(["count", "lowest", "name"]).default("count"),
});
export async function GET(request: Request) {
  const params = new URL(request.url).searchParams;
  const parsed = parseCatalogParams(Object.fromEntries(params));
  const settings = options.safeParse({
    format: params.get("format"),
    view: params.get("view") ?? undefined,
    order: params.get("order") ?? undefined,
  });
  const search = (params.get("q") ?? "").trim();
  const types = [...new Set(params.getAll("type"))].sort();
  if (
    !parsed ||
    parsed.selection.mode !== "psgc" ||
    parsed.selection.level === "barangay" ||
    !settings.success ||
    ["mode", "location", "format", "view", "order", "notes", "q"].some(
      (k) => params.getAll(k).length > 1,
    ) ||
    search.length > 200 ||
    (search.length > 0 && settings.data.view !== "table") ||
    types.length > 200 ||
    types.some((t) => !t || t.length > 200)
  )
    return NextResponse.json({ error: "Invalid facility comparison selection" }, { status: 400 });
  const { selection } = parsed;
  const { format, view, order } = settings.data;
  try {
    const [geo, counts, items] = await Promise.all([
      getGeoByCode(selection.code),
      getNhfrCounts(selection.code, selection.level),
      getNhfrTypes(selection.code, selection.level),
    ]);
    const selectedRows = counts ? selectMix(items, counts.nFacilities, types, order) : null;
    const rows = selectedRows ? searchMixRows(selectedRows, search) : null;
    if (!geo || geo.geoLevel !== selection.level || !counts || !rows || !rows.length)
      return NextResponse.json(
        { error: "This facility comparison could not be verified. Please try again." },
        { status: 503 },
      );
    const headers = [
      "Facility type",
      "Facilities",
      "Share of all facilities (%)",
      "Government",
      "Government within type (%)",
      "Private",
      "Private within type (%)",
    ];
    const values = rows.map((r) => [
      r.facilityType,
      r.nFacilities,
      (100 * r.nFacilities) / counts.nFacilities,
      r.nGovernment,
      r.nFacilities ? (100 * r.nGovernment) / r.nFacilities : null,
      r.nPrivate,
      r.nFacilities ? (100 * r.nPrivate) / r.nFacilities : null,
    ]);
    const metadata = [
      ["Location", geo.geoName],
      ["Geographic code", selection.code],
      ["Geographic level", selection.level],
      ["Selected types", types.length ? types.join("; ") : "All recorded types"],
      ["View", view],
      ["Exact-figure search", search],
      ["Displayed facilities", rows.reduce((n, r) => n + r.nFacilities, 0)],
      ["Displayed types", rows.length],
      ["Order", order],
      ["Area facilities", counts.nFacilities],
      ["Source", "DOH National Health Facility Registry"],
      ["Snapshot", "September 2026"],
    ];
    let body: string | Uint8Array;
    let mime: string;
    if (format === "csv") {
      const cell = (v: unknown) =>
        `"${String(v ?? "")
          .replace(/^[=+@\-\t\r\n]/, "'$&")
          .replaceAll('"', '""')}"`;
      body =
        "\uFEFF" +
        [
          [...headers, ...metadata.map((r) => r[0])],
          ...values.map((row) => [...row, ...metadata.map((r) => r[1])]),
        ]
          .map((row) => row.map(cell).join(","))
          .join("\r\n");
      mime = "text/csv; charset=utf-8";
    } else if (format === "xlsx") {
      const { default: ExcelJS } = await import("exceljs");
      const book = new ExcelJS.Workbook();
      book.creator = "BHW Connect";
      const data = book.addWorksheet("Facility types");
      data.addRow(headers);
      values.forEach((row) => data.addRow(row));
      data.getRow(1).font = { bold: true };
      data.columns = headers.map((_, i) => ({ width: i === 0 ? 55 : 24 }));
      const about = book.addWorksheet("About this data");
      [...metadata, ...MIX_NOTES.map((note) => ["Note", note])].forEach((row) => about.addRow(row));
      about.columns = [{ width: 22 }, { width: 100 }];
      about.getColumn(2).alignment = { wrapText: true };
      body = new Uint8Array(await book.xlsx.writeBuffer());
      mime = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
    } else {
      const file = await figureFile(
        format,
        mixSvg(
          rows,
          counts.nFacilities,
          geo.geoName,
          types,
          view,
          format === "pdf" && params.get("notes") === "1",
          { query: search },
        ),
        `Facility types · ${geo.geoName}`,
        metadata.map((r) => r.join(": ")).join("; "),
        format === "pdf" || format === "pptx"
          ? mixPages(
              rows,
              counts.nFacilities,
              geo.geoName,
              types,
              view,
              format === "pdf" && params.get("notes") === "1",
              search,
            )
          : undefined,
      );
      body = file.body;
      mime = file.mime;
    }
    const filename = slugify(
      "facility-types",
      geo.geoName,
      selection.code,
      view,
      order,
      search ? "search-results" : "",
      types.length ? types.slice(0, 2).join("-") : "all-types",
      types.length > 2 ? `and-${types.length - 2}-more` : "",
      "2026-09",
    ).slice(0, 180);
    return new NextResponse(typeof body === "string" ? body : new Uint8Array(body), {
      headers: {
        "Content-Type": mime,
        "Content-Disposition": `attachment; filename="${filename}.${format}"`,
        "Cache-Control": "no-store",
      },
    });
  } catch {
    return NextResponse.json(
      { error: "This facility comparison export could not be generated. Please try again." },
      { status: 503 },
    );
  }
}
