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
  context: { allRows?: NhfrTypeCount[]; pageLabel?: string } = {},
) {
  let y = 44;
  const parts: string[] = [];
  function text(value: string, size = 16, muted = false) {
    const max = Math.floor(920 / (size * 0.65));
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
    view === "mix"
      ? "Facility mix · share of all facilities here"
      : "Ownership within each facility type",
    16,
  );
  y += 8;
  text(mixReading(context.allRows ?? rows, total, types.length > 0, view), 16);
  if (context.pageLabel) text(context.pageLabel, 14, true);
  y += 12;
  text("Government: teal · Private: grey", 13, true);
  const series = context.allRows ?? rows;
  const share = percentageFormatter(series.map((r) => (100 * r.nFacilities) / total));
  const ownership = percentageFormatter(
    series.flatMap((r) =>
      r.nFacilities > 0
        ? [(100 * r.nGovernment) / r.nFacilities, (100 * r.nPrivate) / r.nFacilities]
        : [],
    ),
  );
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
          pageLabel: `Types ${offset + 1}–${Math.min(offset + 8, rows.length)} of ${rows.length}`,
        },
      ),
    );
  return pages;
}
