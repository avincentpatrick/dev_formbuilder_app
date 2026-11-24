import { NextResponse } from "next/server";
import { parseCatalogParams } from "@/lib/catalog/navigation";
import { readNhfrHeadline } from "@/lib/db/nhfr-headline";
import { headlineNotes, headlineRows, headlineSvg, headlinePng } from "@/lib/exports/nhfr-headline";
import { slugify } from "@/lib/exports/query";

export const runtime = "nodejs";
const formats = ["csv", "xlsx", "svg", "png", "pdf", "pptx"];
export async function GET(request: Request) {
  const params = new URL(request.url).searchParams;
  const parsed = parseCatalogParams(Object.fromEntries(params));
  const types = [...new Set(params.getAll("type"))].sort();
  const format = params.get("format") ?? "";
  if (
    !parsed ||
    parsed.selection.mode === "all" ||
    !formats.includes(format) ||
    ["mode", "location", "district", "citymun", "barangay", "format", "notes"].some(
      (k) => params.getAll(k).length > 1,
    ) ||
    types.length > 200 ||
    types.some((t) => !t || t.length > 200)
  )
    return NextResponse.json({ error: "Invalid headline export selection" }, { status: 400 });
  try {
    const summary = await readNhfrHeadline(parsed.selection, types);
    if (!summary)
      return NextResponse.json(
        { error: "The selected headline could not be verified. Please try again." },
        { status: 503 },
      );
    const rows = headlineRows(summary, types);
    const typeLabel = types.length ? types.join("; ") : "All facility types";
    const filename = slugify(
      "health-facilities",
      summary.locationName,
      types.length ? types.slice(0, 2).join("-") : "all-types",
      types.length > 2 ? `and-${types.length - 2}-more` : "",
      "2026-09",
    ).slice(0, 180);
    let body: string | Uint8Array;
    let mime: string;
    if (format === "csv") {
      // Quote every field and neutralize spreadsheet formulas in source labels.
      const cell = (v: unknown) =>
        `"${String(v ?? "")
          .replace(/^[=+@\-\t\r]/, "'$&")
          .replaceAll('"', '""')}"`;
      const grid = [
        [
          "Indicator",
          "Value",
          "Unit",
          "Denominator",
          "Status",
          "Location",
          "Facility types",
          "Source",
          "Snapshot",
        ],
        ...rows.map((r) => [
          r.indicator,
          r.value,
          r.unit,
          r.denominator,
          r.value === null ? "unavailable" : "available",
          summary.locationName,
          typeLabel,
          "DOH NHFR",
          "2026-09",
        ]),
      ];
      body = "\uFEFF" + grid.map((row) => row.map(cell).join(",")).join("\r\n");
      mime = "text/csv; charset=utf-8";
    } else if (format === "xlsx") {
      const { default: ExcelJS } = await import("exceljs");
      const workbook = new ExcelJS.Workbook();
      workbook.creator = "BHW Connect";
      const data = workbook.addWorksheet("Headline data");
      data.addRow(["Indicator", "Value", "Unit", "Denominator", "Status"]);
      rows.forEach((r) =>
        data.addRow([
          r.indicator,
          r.value,
          r.unit,
          r.denominator,
          r.value === null ? "unavailable" : "available",
        ]),
      );
      data.columns = [{ width: 55 }, { width: 22 }, { width: 18 }, { width: 18 }, { width: 18 }];
      data.getRow(1).font = { bold: true };
      const about = workbook.addWorksheet("About this data");
      [
        ["Location", summary.locationName],
        ["Facility types", typeLabel],
        ["Source", "DOH National Health Facility Registry"],
        ["Snapshot", "September 2026"],
        ...headlineNotes.map((n) => ["Note", n]),
      ].forEach((r) => about.addRow(r));
      about.columns = [{ width: 20 }, { width: 100 }];
      about.getColumn(2).alignment = { wrapText: true };
      body = new Uint8Array(await workbook.xlsx.writeBuffer());
      mime = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
    } else {
      const figure = headlineSvg(
        summary,
        types,
        summary.locationName,
        format === "pdf" && params.get("notes") === "1",
      );
      if (format === "svg") {
        body = figure.svg;
        mime = "image/svg+xml";
      } else {
        const png = await headlinePng(figure.svg);
        if (format === "png") {
          body = png;
          mime = "image/png";
        } else if (format === "pdf") {
          const { PDFDocument } = await import("pdf-lib");
          const pdf = await PDFDocument.create();
          pdf.setTitle(`Health facilities · ${summary.locationName}`);
          pdf.setSubject(typeLabel);
          const image = await pdf.embedPng(png);
          // A suitably sized page preserves the complete headline and optional notes.
          const page = pdf.addPage([600, figure.height * 0.6]);
          page.drawImage(image, { x: 0, y: 0, width: 600, height: figure.height * 0.6 });
          body = await pdf.save();
          mime = "application/pdf";
        } else {
          const { default: PptxGenJS } = await import("pptxgenjs");
          const deck = new PptxGenJS();
          deck.defineLayout({ name: "HEADLINE", width: 10, height: figure.height / 100 });
          deck.layout = "HEADLINE";
          deck.title = `Health facilities · ${summary.locationName}`;
          deck.subject = typeLabel;
          const slide = deck.addSlide();
          slide.addImage({
            data: `image/png;base64,${Buffer.from(png).toString("base64")}`,
            x: 0,
            y: 0,
            w: 10,
            h: figure.height / 100,
          });
          body = new Uint8Array((await deck.write({ outputType: "uint8array" })) as Uint8Array);
          mime = "application/vnd.openxmlformats-officedocument.presentationml.presentation";
        }
      }
    }
    return new NextResponse(typeof body === "string" ? body : new Uint8Array(body), {
      headers: {
        "Content-Type": mime,
        "Content-Disposition": `attachment; filename="${filename}.${format}"`,
        "Cache-Control": "no-store",
      },
    });
  } catch {
    return NextResponse.json(
      { error: "This headline export could not be generated. Please try again." },
      { status: 503 },
    );
  }
}
