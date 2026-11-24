import "server-only";
import type { HeadlineSummary } from "@/lib/facilities/headline";
import { formatCount, formatPct, percentageFormatter } from "@/lib/format";
import {
  NHFR_DEFINITION,
  NHFR_BEDS,
  NHFR_PRESENCE,
  NHFR_ATTRIBUTION,
} from "@/lib/catalog/nhfr-reading";
import { escapeXml, resvgFont } from "./render-png";

export const headlineNotes = [
  NHFR_DEFINITION,
  NHFR_BEDS,
  NHFR_PRESENCE,
  NHFR_ATTRIBUTION,
  "Selected types use OR; each facility and each barangay is counted once. All barangays in the reporting area remain the presence denominator.",
  "Blank bed capacity was stored as zero. Ownership does not establish cost or eligibility. Recorded presence does not establish travel access, staffing, quality or adequate capacity.",
];
export function headlineRows(s: HeadlineSummary, types: string[]) {
  const rows: {
    indicator: string;
    value: number | null;
    unit: string;
    denominator: number | null;
  }[] = [
    { indicator: "Registered facilities", value: s.count, unit: "facilities", denominator: null },
    {
      indicator: "All registered facilities in location",
      value: s.total,
      unit: "facilities",
      denominator: null,
    },
    {
      indicator: "Barangays with an attributed selected facility",
      value: s.present,
      unit: "barangays",
      denominator: s.barangays,
    },
    {
      indicator: "Barangays without an attributed selected facility",
      value: s.barangays - s.present,
      unit: "barangays",
      denominator: s.barangays,
    },
    {
      indicator: "Recorded barangay presence",
      value: s.barangays > 0 ? (100 * s.present) / s.barangays : null,
      unit: "percent",
      denominator: s.barangays,
    },
    {
      indicator: "Government facilities",
      value: s.government,
      unit: "facilities",
      denominator: s.count,
    },
    { indicator: "Private facilities", value: s.private, unit: "facilities", denominator: s.count },
    {
      indicator: "Government share",
      value: s.count > 0 ? (100 * s.government) / s.count : null,
      unit: "percent",
      denominator: s.count,
    },
    {
      indicator: "Private share",
      value: s.count > 0 ? (100 * s.private) / s.count : null,
      unit: "percent",
      denominator: s.count,
    },
  ];
  if (types.length)
    rows.push({
      indicator: "Share of all registered facilities",
      value: s.total > 0 ? (100 * s.count) / s.total : null,
      unit: "percent",
      denominator: s.total,
    });
  if (!(types.length && types.every((t) => t === "Barangay Health Station")))
    rows.push({
      indicator: "Recorded bed capacity",
      value: s.beds,
      unit: "beds",
      denominator: null,
    });
  return rows;
}

/** Pure export composition; long type/location names wrap instead of clipping. */
export function headlineSvg(
  s: HeadlineSummary,
  types: string[],
  locationName: string,
  notes = false,
) {
  const parts: string[] = [];
  let y = 46;
  function text(value: string, size = 16, color = "#182629", weight = 400) {
    const maxChars = Math.floor(920 / (size * 0.65));
    const chunks: string[] = [];
    let line = "";
    for (const token of value.split(/\s+/)) {
      let word = token;
      if (line && line.length + word.length + 1 > maxChars) {
        chunks.push(line);
        line = "";
      }
      while (word.length > maxChars) {
        chunks.push(word.slice(0, maxChars));
        word = word.slice(maxChars);
      }
      line = line ? `${line} ${word}` : word;
    }
    if (line) chunks.push(line);
    for (const chunk of chunks) {
      parts.push(
        `<text x="40" y="${y}" font-size="${size}" font-weight="${weight}" fill="${color}">${escapeXml(chunk.trim())}</text>`,
      );
      y += size + 10;
    }
  }
  function bar(n: number, total: number, second = "#becdcd") {
    if (!total) return;
    parts.push(
      `<rect x="40" y="${y}" width="920" height="14" rx="4" fill="${second}"/><rect x="40" y="${y}" width="${(920 * n) / total}" height="14" rx="4" fill="#0a6e6e"/>`,
    );
    y += 36;
  }
  text("Health facilities", 28, "#182629", 600);
  text(locationName, 18);
  text("DOH National Health Facility Registry · September 2026 snapshot", 14, "#53666a");
  text(types.length ? `Selected types: ${types.join("; ")}` : "All facility types", 14, "#53666a");
  y += 12;
  text(types.length ? "Registered facilities of the selected types" : "Registered facilities", 17);
  text(formatCount(s.count), 48, "#182629", 600);
  text(
    `The registry lists ${formatCount(s.count)} ${types.length ? "facilities of the selected types" : "health facilities"} here.`,
  );
  if (types.length) {
    text(
      `${s.total > 0 ? formatPct((100 * s.count) / s.total, "single") : "Percentage unavailable"} of all registered facilities here.`,
    );
    bar(s.count, s.total);
  }
  y += 16;
  text("Barangays with a recorded facility in this selection", 17);
  text(
    s.barangays ? formatPct((100 * s.present) / s.barangays, "single") : "Percentage unavailable",
    32,
    "#182629",
    600,
  );
  text(`${formatCount(s.present)} of ${formatCount(s.barangays)} barangays`);
  bar(s.present, s.barangays);
  text(
    `With recorded facility: ${formatCount(s.present)} · No facility attributed: ${formatCount(s.barangays - s.present)}`,
  );
  y += 16;
  text("Who owns these facilities?", 17);
  bar(s.government, s.count, "#8593ae");
  const pct = percentageFormatter(
    s.count ? [(100 * s.government) / s.count, (100 * s.private) / s.count] : [],
  );
  text(
    `Government: ${formatCount(s.government)}${s.count ? ` (${pct((100 * s.government) / s.count)})` : ""}`,
  );
  text(
    `Private: ${formatCount(s.private)}${s.count ? ` (${pct((100 * s.private) / s.count)})` : ""}`,
  );
  const beds = headlineRows(s, types).find((r) => r.unit === "beds");
  if (beds) {
    y += 16;
    text("Recorded bed capacity", 17);
    text(`${formatCount(s.beds)} beds`, 28, "#182629", 600);
    text(
      s.beds
        ? "Combined capacity reported by facilities in this selection."
        : "No bed capacity is recorded for this selection.",
    );
  }
  if (notes) {
    y += 22;
    text("About these figures", 18, "#182629", 600);
    for (const note of headlineNotes) {
      text(note, 13, "#53666a");
      y += 8;
    }
  }
  y += 20;
  text("Source: DOH National Health Facility Registry · Snapshot: September 2026", 12, "#53666a");
  return {
    svg: `<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="${y + 24}" viewBox="0 0 1000 ${y + 24}"><rect width="100%" height="100%" fill="white"/><g font-family="DejaVu Sans, sans-serif">${parts.join("")}</g></svg>`,
    height: y + 24,
  };
}
export async function headlinePng(svg: string) {
  const { Resvg } = await import("@resvg/resvg-js");
  return new Resvg(svg, { font: resvgFont(), fitTo: { mode: "width", value: 1600 } })
    .render()
    .asPng();
}
