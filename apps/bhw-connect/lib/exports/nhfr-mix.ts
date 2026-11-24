import "server-only";
import type { NhfrTypeCount } from "@/lib/db/nhfr";
import { MIX_NOTES, mixReading, type MixView } from "@/lib/facilities/mix";
import { formatCount, percentageFormatter } from "@/lib/format";
import { escapeXml } from "./render-png";

/** Rows are already validated, selected and sorted by the same model as the card. */
export function mixSvg(
  rows: NhfrTypeCount[],
  total: number,
  location: string,
  types: string[],
  view: MixView,
  notes: boolean,
  context: { allRows?: NhfrTypeCount[]; pageLabel?: string; query?: string } = {},
) {
  let y = 44;
  const parts: string[] = [];
  function text(value: string, size = 16, muted = false, maxWidth = 920) {
    const max = Math.floor(maxWidth / (size * 0.65));
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
  }
  text("What kinds of facilities are recorded?", 26);
  text(location, 18);
  text("DOH National Health Facility Registry · September 2026 snapshot", 13, true);
  text(types.length ? `Selected: ${types.join("; ")}` : "All recorded types", 13, true);
  text(
    view === "table"
      ? "Exact figures by facility type · share of all facilities here"
      : view === "mix"
        ? "Facility mix · share of all facilities here"
        : "Ownership within each facility type",
    16,
  );
  if (context.query) text(`Exact-figure search: ${context.query}`, 13, true);
  y += 8;
  text(mixReading(context.allRows ?? rows, total, types.length > 0 || !!context.query, view), 16);
  if (context.pageLabel) text(context.pageLabel, 14, true);
  y += 12;
  if (view !== "table") text("Government: teal · Private: grey", 13, true);
  const series = context.allRows ?? rows;
  const share = percentageFormatter(series.map((r) => (100 * r.nFacilities) / total));
  const ownership = percentageFormatter(
    series.flatMap((r) =>
      r.nFacilities > 0
        ? [(100 * r.nGovernment) / r.nFacilities, (100 * r.nPrivate) / r.nFacilities]
        : [],
    ),
  );
  if (view === "table") {
    const columns = [
      { x: 490, label: "Facilities" },
      { x: 650, label: "Share of all" },
      { x: 820, label: "Government" },
      { x: 960, label: "Private" },
    ];
    parts.push(`<text x="40" y="${y}" font-size="13" fill="#53666a">Facility type</text>`);
    for (const c of columns)
      parts.push(
        `<text x="${c.x}" y="${y}" text-anchor="end" font-size="13" fill="#53666a">${c.label}</text>`,
      );
    y += 18;
    parts.push(
      `<text x="650" y="${y}" text-anchor="end" font-size="13" fill="#53666a">facilities here</text>`,
    );
    y += 28;
    for (const r of rows) {
      const baseline = y;
      text(r.facilityType, 14, false, 370);
      for (const [x, value] of [
        [490, formatCount(r.nFacilities)],
        [650, share((100 * r.nFacilities) / total)],
        [820, formatCount(r.nGovernment)],
        [960, formatCount(r.nPrivate)],
      ] as const)
        parts.push(
          `<text x="${x}" y="${baseline}" text-anchor="end" font-size="14" fill="#182629">${escapeXml(value)}</text>`,
        );
      y += 8;
      parts.push(`<line x1="40" x2="960" y1="${y}" y2="${y}" stroke="#d8e0e0"/>`);
      y += 26;
    }
    const shown = series.reduce((n, r) => n + r.nFacilities, 0);
    text(
      `All matching rows: ${formatCount(shown)} (${share((100 * shown) / total)}) of all facilities here`,
      14,
    );
  } else {
    for (const r of rows) {
      text(r.facilityType, 17);
      text(
        `${formatCount(r.nFacilities)} (${share((100 * r.nFacilities) / total)} of all facilities)`,
        14,
      );
      const denominator = view === "mix" ? total : r.nFacilities;
      if (denominator > 0) {
        parts.push(
          `<rect x="40" y="${y}" width="920" height="14" rx="4" fill="#f3f7f7"/><rect x="40" y="${y}" width="${(920 * r.nGovernment) / denominator}" height="14" fill="#0a6e6e"/><rect x="${40 + (920 * r.nGovernment) / denominator}" y="${y}" width="${(920 * r.nPrivate) / denominator}" height="14" fill="#919da3"/>`,
        );
        y += 32;
      }
      text(
        `Government: ${formatCount(r.nGovernment)}${r.nFacilities > 0 ? ` (${ownership((100 * r.nGovernment) / r.nFacilities)})` : ""} · Private: ${formatCount(r.nPrivate)}${r.nFacilities > 0 ? ` (${ownership((100 * r.nPrivate) / r.nFacilities)})` : ""}`,
        13,
        true,
      );
      y += 18;
    }
  }
  if (notes) {
    text("About this figure", 18);
    for (const note of MIX_NOTES) text(note, 13, true);
  }
  y += 12;
  text(`Area total: ${formatCount(total)} registered facilities`, 13, true);
  text("Source: DOH National Health Facility Registry · Snapshot: September 2026", 12, true);
  return {
    svg: `<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="${y + 20}" viewBox="0 0 1000 ${y + 20}"><rect width="100%" height="100%" fill="white"/><g font-family="DejaVu Sans, sans-serif">${parts.join("")}</g></svg>`,
    height: y + 20,
  };
}

/** Every selected type appears exactly once, with the full comparison's denominator. */
export function mixPages(
  rows: NhfrTypeCount[],
  total: number,
  location: string,
  types: string[],
  view: MixView,
  notes: boolean,
  query = "",
) {
  const pages = [];
  for (let offset = 0; offset < rows.length; offset += 8)
    pages.push(
      mixSvg(
        rows.slice(offset, offset + 8),
        total,
        location,
        types,
        view,
        notes && offset + 8 >= rows.length,
        {
          allRows: rows,
          query,
          pageLabel: `Types ${offset + 1}–${Math.min(offset + 8, rows.length)} of ${rows.length}`,
        },
      ),
    );
  return pages;
}
