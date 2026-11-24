import "server-only";
import {
  PRESENCE_TITLE,
  presenceReading,
  PRESENCE_NOTES,
  PRESENCE_READING_LIMIT,
  type PresenceInput,
  type PresenceResult,
} from "@/lib/facilities/presence";
import { formatCount, percentageFormatter } from "@/lib/format";
import { escapeXml } from "./render-png";
export function presenceSvg(
  input: PresenceInput,
  result: PresenceResult,
  areas: string[],
  types: string[],
  notes: boolean,
  context: { rows?: PresenceResult["rows"]; pageLabel?: string } = {},
) {
  let y = 44;
  const parts: string[] = [];
  const text = (value: string, size = 16, muted = false) => {
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
  };
  text(PRESENCE_TITLE, 24);
  text(input.name, 18);
  text("DOH National Health Facility Registry · September 2026 snapshot", 13, true);
  text(
    areas.length
      ? `Areas: ${input.rows
          .filter((a) => areas.includes(a.code))
          .map((a) => a.name)
          .join("; ")}`
      : "All areas",
    13,
    true,
  );
  text(types.length ? `Types: ${types.join("; ")}` : "All recorded facility types", 13, true);
  y += 8;
  text(presenceReading(result, types.length > 0), 16);
  text(PRESENCE_READING_LIMIT, 14, true);
  text(
    `Fixed scale: 0–100% of each area’s barangays · Teal: with a recorded facility · Pale: no facility${types.length ? " of selected types" : ""} recorded`,
    13,
    true,
  );
  if (context.pageLabel) text(context.pageLabel, 14, true);
  y += 10;
  const share = percentageFormatter(
    result.rows.flatMap((a) => (a.share === null ? [] : [a.share])),
  );
  for (const a of context.rows ?? result.rows) {
    text(a.name, 17);
    text(
      `${a.share === null ? "Percentage unavailable" : share(a.share)} · ${formatCount(a.present)} of ${formatCount(a.barangays)} barangays`,
      14,
    );
    if (a.share !== null) {
      parts.push(
        `<rect x="40" y="${y}" width="920" height="16" rx="4" fill="#edf2f2" stroke="#c5cece"/><rect x="40" y="${y}" width="${(920 * a.share) / 100}" height="16" fill="#0a6e6e"/>`,
      );
      y += 34;
      text(
        `${formatCount(a.barangays - a.present)} barangays with no facility${types.length ? " of selected types" : ""} recorded`,
        13,
        true,
      );
    }
    y += 20;
  }
  if (notes) {
    text("About this figure", 18);
    PRESENCE_NOTES.forEach((note) => text(note, 13, true));
  }
  y += 12;
  text("Source: DOH National Health Facility Registry · Snapshot: September 2026", 12, true);
  return {
    svg: `<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="${y + 20}" viewBox="0 0 1000 ${y + 20}"><rect width="100%" height="100%" fill="white"/><g font-family="DejaVu Sans, sans-serif">${parts.join("")}</g></svg>`,
    height: y + 20,
  };
}
export function presencePages(
  input: PresenceInput,
  result: PresenceResult,
  areas: string[],
  types: string[],
  notes: boolean,
) {
  const pages = [];
  for (let offset = 0; offset < result.rows.length; offset += 8)
    pages.push(
      presenceSvg(input, result, areas, types, notes && offset + 8 >= result.rows.length, {
        rows: result.rows.slice(offset, offset + 8),
        pageLabel: `Areas ${offset + 1}–${Math.min(offset + 8, result.rows.length)} of ${result.rows.length}`,
      }),
    );
  return pages;
}
