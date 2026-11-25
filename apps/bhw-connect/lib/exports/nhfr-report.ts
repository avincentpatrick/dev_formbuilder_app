import "server-only";
import {
  REPORT_ROWS,
  REPORT_SOURCE,
  REPORT_SNAPSHOT,
  REPORT_SCOPE,
  type FacilityReport,
} from "@/lib/facilities/report";
import { headlineRows, headlineNotes } from "./nhfr-headline";
import { mixSvg } from "./nhfr-mix";
import { areaFiguresSvg } from "./nhfr-area-figures";
import { formatCount, formatPct, percentageFormatter } from "@/lib/format";
import { escapeXml, resvgFont } from "./render-png";
import type { ExportPage } from "./figure-file";
export const REPORT_NOTES = [
  ...headlineNotes,
  "The report covers the full selected location and all facility types. Individual card filters and facility-list search are not applied. It contains aggregate tables, not an individual facility directory.",
  "Facility-type shares use all facilities in the location. Ownership shares within a type use that type's own total. Child-area facility shares use the full location's facility total; barangay shares use each child's own barangay denominator. Zero denominators have unavailable percentages.",
  "All facility types and child areas are included across pages and sheets. A city/municipality report contains its overview and type totals; child-area comparisons are provided at national, regional and provincial levels.",
  "Source snapshot and generated date are different: the snapshot dates the registry data; generated date records when this report file was created.",
];
function textPage(
  r: FacilityReport,
  title: string,
  paragraphs: string[],
  generated: string,
  overview = false,
): ExportPage {
  let y = 44;
  const parts: string[] = [];
  const text = (value: string, size = 16, color = "#182629") => {
    const limit = Math.floor(920 / (size * 0.65));
    let line = "";
    const push = (s: string) => {
      parts.push(
        `<text x="40" y="${y}" font-size="${size}" fill="${color}">${escapeXml(s)}</text>`,
      );
      y += size + 10;
    };
    for (const token of value.split(/\s+/)) {
      let word = token;
      if (line && line.length + word.length + 1 > limit) {
        push(line);
        line = "";
      }
      while (word.length > limit) {
        push(word.slice(0, limit));
        word = word.slice(limit);
      }
      line = line ? `${line} ${word}` : word;
    }
    if (line) push(line);
  };
  text(title, 26);
  text(r.name, 18);
  text(`${REPORT_SOURCE} · ${REPORT_SNAPSHOT} snapshot`, 13, "#53666a");
  text(REPORT_SCOPE, 13, "#53666a");
  y += 12;
  if (overview) {
    text("Registered facilities", 17);
    y += 28;
    text(formatCount(r.summary.count), 44, "#0a6e6e");
    y -= 12;
  }
  paragraphs.forEach((p) => {
    text(p);
    y += 10;
  });
  y += 8;
  text(`Source snapshot: ${REPORT_SNAPSHOT} · Generated: ${generated} UTC`, 12, "#53666a");
  const height = Math.max(520, y + 25);
  return {
    svg: `<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="${height}" viewBox="0 0 1000 ${height}"><rect width="100%" height="100%" fill="white"/><g font-family="DejaVu Sans, sans-serif">${parts.join("")}</g></svg>`,
    height,
  };
}
/** Full rows, chunked before rendering: there are no "top N" or named-facility exclusions. */
export function reportPages(r: FacilityReport, notes: boolean, generated: string): ExportPage[] {
  const s = r.summary;
  const ownership = percentageFormatter(
    s.count ? [(100 * s.government) / s.count, (100 * s.private) / s.count] : [],
  );
  const presence = s.barangays
    ? `${formatPct((100 * s.present) / s.barangays, "single")} · ${formatCount(s.present)} of ${formatCount(s.barangays)} barangays`
    : "Percentage unavailable · no barangay denominator";
  const pages = [
    textPage(
      r,
      "Health facilities · Full location report",
      [
        "The registry lists these health facilities in this location.",
        `Barangays with a recorded facility: ${presence}.`,
        `Barangays with no facility recorded: ${formatCount(s.barangays - s.present)}. People may also use facilities in nearby barangays.`,
        `Government: ${formatCount(s.government)}${s.count ? ` (${ownership((100 * s.government) / s.count)})` : ""}. Private: ${formatCount(s.private)}${s.count ? ` (${ownership((100 * s.private) / s.count)})` : ""}.`,
        `Recorded bed capacity: ${formatCount(s.beds)} beds.`,
        `Included: ${r.types.length} recorded facility types${r.areas ? ` and ${r.areas.rows.length} child areas` : ""}, with every aggregate row across the following pages. Individual facilities are listed in the facility directory on screen.`,
      ],
      generated,
      true,
    ),
  ];
  if (!r.types.length)
    pages.push(
      textPage(
        r,
        "Facility types",
        [
          "No facilities are recorded in this location, so there are no facility-type rows to list.",
        ],
        generated,
      ),
    );
  // Keep print text readable even when names wrap over several lines.
  const paginate = <T>(
    items: T[],
    limit: number,
    render: (rows: T[], start: number, end: number) => ExportPage,
  ) => {
    for (let offset = 0; offset < items.length;) {
      let end = Math.min(offset + limit, items.length);
      let page = render(items.slice(offset, end), offset, end);
      while (page.height > 740 && end - offset > 1) {
        end--;
        page = render(items.slice(offset, end), offset, end);
      }
      pages.push(page);
      offset = end;
    }
  };
  paginate(r.types, REPORT_ROWS, (rows, start, end) =>
    mixSvg(rows, s.count, r.name, [], "table", false, {
      allRows: r.types,
      pageLabel: `All facility types · Rows ${start + 1}–${end} of ${r.types.length}`,
    }),
  );
  if (r.areas) {
    const areas = r.areas;
    const input = { name: r.name, areas: areas.rows };
    paginate(areas.rows, REPORT_ROWS, (rows, start, end) =>
      areaFiguresSvg(input, areas, [], [], false, {
        rows,
        pageLabel: `All child areas · Rows ${start + 1}–${end} of ${areas.rows.length}`,
        ownership: true,
      }),
    );
  }
  if (notes)
    paginate(REPORT_NOTES, 3, (paragraphs) =>
      textPage(r, "About these figures", paragraphs, generated),
    );
  return pages.map((page, i) => {
    // Reserve a footer beyond each renderer's content; original complete table stays intact.
    const height = page.height + 32;
    return {
      height,
      svg: page.svg
        .replace(
          `height="${page.height}" viewBox="0 0 1000 ${page.height}"`,
          `height="${height}" viewBox="0 0 1000 ${height}"`,
        )
        .replace(
          "</svg>",
          `<text x="960" y="${height - 12}" text-anchor="end" font-family="DejaVu Sans, sans-serif" font-size="12" fill="#53666a">Full location report · Page ${i + 1} of ${pages.length} · Generated ${escapeXml(generated)} UTC</text></svg>`,
        ),
    };
  });
}
export function reportTables(r: FacilityReport, generated: string) {
  const summaryHeaders = ["Indicator", "Value", "Unit", "Denominator"];
  const summary = headlineRows(r.summary, [])
    .filter((x) => x.indicator !== "All registered facilities in location")
    .map((x) => [
      x.indicator
        .replace("an attributed selected facility", "a recorded facility")
        .replace("without a recorded facility", "with no facility recorded"),
      x.value,
      x.unit,
      x.denominator,
    ]);
  const typeHeaders = [
    "Facility type",
    "Facilities",
    "Share of all facilities (%)",
    "Government",
    "Government within type (%)",
    "Private",
    "Private within type (%)",
    "Share status",
  ];
  const types = r.types.map((t) => [
    t.facilityType,
    t.nFacilities,
    r.summary.count ? (100 * t.nFacilities) / r.summary.count : null,
    t.nGovernment,
    t.nFacilities ? (100 * t.nGovernment) / t.nFacilities : null,
    t.nPrivate,
    t.nFacilities ? (100 * t.nPrivate) / t.nFacilities : null,
    r.summary.count ? "available" : "unavailable",
  ]);
  const areaHeaders = [
    "Area",
    "Geographic code",
    "Geographic level",
    "Facilities",
    "Share of parent (%)",
    "Facility share status",
    "Government",
    "Private",
    "Barangays with a recorded facility",
    "All barangays",
    "Share of barangays (%)",
    "Barangay share status",
    "Barangays with no facility recorded",
  ];
  const areas = (r.areas?.rows ?? []).map((a) => [
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
    ["Location", r.name],
    ["Location code", r.code],
    ["Location level", r.level],
    ["Scope", REPORT_SCOPE],
    ["Source", REPORT_SOURCE],
    ["Snapshot", REPORT_SNAPSHOT],
    ["Generated UTC", generated],
    ["Facility-type row count", r.types.length],
    ["Child-area row count", r.areas?.rows.length ?? 0],
    [
      "Tables",
      r.areas
        ? "Overview; all recorded facility types; all child areas"
        : "Overview; all recorded facility types. No child-area table at city/municipality level.",
    ],
    ["Directory", "Aggregate report; no individual facility records"],
  ];
  return {
    tables: [
      { name: "Summary", headers: summaryHeaders, rows: summary },
      { name: "Facility types", headers: typeHeaders, rows: types },
      { name: "Child areas", headers: areaHeaders, rows: areas },
    ],
    metadata,
  };
}
export async function reportFile(
  format: "pdf" | "pptx" | "png" | "svg",
  pages: ExportPage[],
  title: string,
) {
  const raster = async (page: ExportPage) => {
    const { Resvg } = await import("@resvg/resvg-js");
    return new Resvg(page.svg, { font: resvgFont(), fitTo: { mode: "width", value: 1600 } })
      .render()
      .asPng();
  };
  if (format === "png" || format === "svg") {
    const { zipSync, strToU8 } = await import("fflate");
    const files: Record<string, Uint8Array> = {};
    for (let i = 0; i < pages.length; i++)
      files[`report-page-${String(i + 1).padStart(3, "0")}.${format}`] =
        format === "svg" ? strToU8(pages[i].svg) : await raster(pages[i]);
    return {
      body: zipSync(files, { level: format === "png" ? 0 : 6 }),
      mime: "application/zip",
      extension: "zip",
    };
  }
  if (format === "pdf") {
    const { PDFDocument } = await import("pdf-lib");
    const doc = await PDFDocument.create();
    doc.setTitle(title);
    doc.setSubject(REPORT_SCOPE);
    for (const p of pages) {
      const image = await doc.embedPng(await raster(p));
      const page = doc.addPage([841.89, 595.28]);
      const factor = Math.min(793.89 / 1000, 547.28 / p.height);
      const width = 1000 * factor,
        height = p.height * factor;
      page.drawImage(image, { x: (841.89 - width) / 2, y: (595.28 - height) / 2, width, height });
    }
    return { body: await doc.save(), mime: "application/pdf", extension: "pdf" };
  }
  const { default: PptxGenJS } = await import("pptxgenjs");
  const deck = new PptxGenJS();
  deck.layout = "LAYOUT_WIDE";
  deck.title = title;
  deck.subject = REPORT_SCOPE;
  for (const p of pages) {
    const factor = Math.min(12.73 / 1000, 6.9 / p.height);
    const width = 1000 * factor,
      height = p.height * factor;
    deck.addSlide().addImage({
      data: `image/png;base64,${Buffer.from(await raster(p)).toString("base64")}`,
      x: (13.333 - width) / 2,
      y: (7.5 - height) / 2,
      w: width,
      h: height,
    });
  }
  return {
    body: new Uint8Array((await deck.write({ outputType: "uint8array" })) as Uint8Array),
    mime: "application/vnd.openxmlformats-officedocument.presentationml.presentation",
    extension: "pptx",
  };
}
