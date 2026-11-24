import "server-only";
import { AREA_NOTES, type AreaFigures, type AreaFigureRow } from "@/lib/facilities/area-figures";
import type { DistributionInput } from "@/lib/facilities/distribution";
import { formatCount, percentageFormatter } from "@/lib/format";
import { escapeXml } from "./render-png";
export function areaFiguresSvg(
  input: DistributionInput,
  result: AreaFigures,
  areas: string[],
  types: string[],
  notes: boolean,
  context: { rows?: AreaFigureRow[]; pageLabel?: string; query?: string; ownership?: boolean } = {},
) {
  let y = 44;
  const parts: string[] = [];
  const text = (value: string, size = 16, muted = false, width = 920) => {
    const max = Math.floor(width / (size * 0.65));
    let line = "";
    const push = (v: string) => {
      parts.push(
        `<text x="40" y="${y}" font-size="${size}" fill="${muted ? "#53666a" : "#182629"}">${escapeXml(v)}</text>`,
      );
      y += size + 9;
    };
    for (const token of value.split(/\s+/)) {
      let word = token;
      if (line && line.length + word.length + 1 > max) {
        push(line);
        line = "";
      }
      while (word.length > max) {
        push(word.slice(0, max));
        word = word.slice(max);
      }
      line = line ? `${line} ${word}` : word;
    }
    if (line) push(line);
  };
  text("Exact health facility figures by area", 24);
  text(input.name, 18);
  text("DOH National Health Facility Registry · September 2026 snapshot", 13, true);
  text(
    areas.length
      ? `Areas: ${input.areas
          .filter((a) => areas.includes(a.code))
          .map((a) => a.name)
          .join("; ")}`
      : "All areas",
    13,
    true,
  );
  text(types.length ? `Types: ${types.join("; ")}` : "All recorded facility types", 13, true);
  if (context.query) text(`Exact-area search: ${context.query}`, 13, true);
  y += 8;
  text(
    `Facility shares use the full parent total: ${formatCount(result.parent)} facilities of the selected types. Barangay shares use each area's own barangay total.`,
    14,
  );
  if (context.pageLabel) text(context.pageLabel, 14, true);
  y += 12;
  const share = percentageFormatter(
    result.rows.flatMap((a) => (a.facilityShare === null ? [] : [a.facilityShare])),
  );
  const presence = percentageFormatter(
    result.rows.flatMap((a) => (a.presenceShare === null ? [] : [a.presenceShare])),
  );
  const columns = [
    { x: 475, label: "Facilities" },
    { x: 620, label: "Share of parent" },
    { x: 830, label: "Barangays with" },
    { x: 960, label: "Share of" },
  ];
  parts.push(`<text x="40" y="${y}" font-size="13" fill="#53666a">Area</text>`);
  for (const c of columns)
    parts.push(
      `<text x="${c.x}" y="${y}" text-anchor="end" font-size="13" fill="#53666a">${c.label}</text>`,
    );
  y += 18;
  parts.push(
    `<text x="620" y="${y}" text-anchor="end" font-size="13" fill="#53666a">facilities</text><text x="830" y="${y}" text-anchor="end" font-size="13" fill="#53666a">a recorded facility</text><text x="960" y="${y}" text-anchor="end" font-size="13" fill="#53666a">barangays</text>`,
  );
  y += 28;
  for (const a of context.rows ?? result.rows) {
    const baseline = y;
    text(a.name, 14, false, 330);
    for (const [x, value] of [
      [475, formatCount(a.count)],
      [620, a.facilityShare === null ? "Unavailable" : share(a.facilityShare)],
      [830, `${formatCount(a.present)} of ${formatCount(a.barangays)}`],
      [960, a.presenceShare === null ? "Unavailable" : presence(a.presenceShare)],
    ] as const)
      parts.push(
        `<text x="${x}" y="${baseline}" text-anchor="end" font-size="14" fill="#182629">${escapeXml(value)}</text>`,
      );
    if (context.ownership)
      text(
        `Government: ${formatCount(a.government)} · Private: ${formatCount(a.private)}`,
        12,
        true,
        330,
      );
    y += 8;
    parts.push(`<line x1="40" x2="960" y1="${y}" y2="${y}" stroke="#d8e0e0"/>`);
    y += 26;
  }
  text(
    `All matching rows: ${formatCount(result.count)} facilities; ${formatCount(result.present)} of ${formatCount(result.barangays)} barangays with a recorded facility.`,
    14,
  );
  if (notes) {
    text("About this figure", 18);
    AREA_NOTES.forEach((note) => text(note, 13, true));
  }
  y += 12;
  text("Source: DOH National Health Facility Registry · Snapshot: September 2026", 12, true);
  return {
    svg: `<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="${y + 20}" viewBox="0 0 1000 ${y + 20}"><rect width="100%" height="100%" fill="white"/><g font-family="DejaVu Sans, sans-serif">${parts.join("")}</g></svg>`,
    height: y + 20,
  };
}
export function areaFiguresPages(
  input: DistributionInput,
  result: AreaFigures,
  areas: string[],
  types: string[],
  notes: boolean,
  query = "",
  ownership = false,
) {
  const pages = [];
  for (let offset = 0; offset < result.rows.length; offset += 8)
    pages.push(
      areaFiguresSvg(input, result, areas, types, notes && offset + 8 >= result.rows.length, {
        query,
        ownership,
        rows: result.rows.slice(offset, offset + 8),
        pageLabel: `Areas ${offset + 1}–${Math.min(offset + 8, result.rows.length)} of ${result.rows.length}`,
      }),
    );
  return pages;
}
