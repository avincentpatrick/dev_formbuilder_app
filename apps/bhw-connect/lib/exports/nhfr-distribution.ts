import "server-only";
import {
  distributionTitle,
  distributionReading,
  DISTRIBUTION_NOTES,
  type DistributionInput,
  type DistributionResult,
  type DistributionArea,
} from "@/lib/facilities/distribution";
import { formatCount, percentageFormatter } from "@/lib/format";
import { escapeXml } from "./render-png";
export function distributionSvg(
  input: DistributionInput,
  result: DistributionResult,
  areas: string[],
  types: string[],
  notes: boolean,
  context: { rows?: DistributionArea[]; pageLabel?: string } = {},
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
  text(distributionTitle(input.level), 24);
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
  y += 8;
  text(distributionReading(result, areas.length > 0, input.name), 16);
  text(
    `Total for ${input.name}: ${formatCount(result.parent.count)} facilities${types.length ? " of the selected types" : ""}`,
    14,
  );
  text(
    `Common count scale: 0–${formatCount(result.maximum)} facilities · Government: teal · Private: grey`,
    13,
    true,
  );
  if (context.pageLabel) text(context.pageLabel, 14, true);
  y += 10;
  const share = percentageFormatter(
    result.parent.count > 0 ? result.rows.map((a) => (100 * a.count) / result.parent.count) : [],
  );
  for (const a of context.rows ?? result.rows) {
    text(a.name, 17);
    text(
      result.parent.count > 0
        ? `${formatCount(a.count)} (${share((100 * a.count) / result.parent.count)} of total)`
        : `${formatCount(a.count)} · share unavailable`,
      14,
    );
    parts.push(`<rect x="40" y="${y}" width="920" height="14" rx="4" fill="#f3f7f7"/>`);
    if (result.maximum > 0)
      parts.push(
        `<rect x="40" y="${y}" width="${(920 * a.government) / result.maximum}" height="14" fill="#0a6e6e"/><rect x="${40 + (920 * a.government) / result.maximum}" y="${y}" width="${(920 * a.private) / result.maximum}" height="14" fill="#919da3"/>`,
      );
    y += 32;
    text(`Government: ${formatCount(a.government)} · Private: ${formatCount(a.private)}`, 13, true);
    y += 18;
  }
  if (notes) {
    text("About this figure", 18);
    DISTRIBUTION_NOTES.forEach((note) => text(note, 13, true));
  }
  y += 12;
  text("Source: DOH National Health Facility Registry · Snapshot: September 2026", 12, true);
  return {
    svg: `<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="${y + 20}" viewBox="0 0 1000 ${y + 20}"><rect width="100%" height="100%" fill="white"/><g font-family="DejaVu Sans, sans-serif">${parts.join("")}</g></svg>`,
    height: y + 20,
  };
}
export function distributionPages(
  input: DistributionInput,
  result: DistributionResult,
  areas: string[],
  types: string[],
  notes: boolean,
) {
  const pages = [];
  for (let offset = 0; offset < result.rows.length; offset += 8)
    pages.push(
      distributionSvg(input, result, areas, types, notes && offset + 8 >= result.rows.length, {
        rows: result.rows.slice(offset, offset + 8),
        pageLabel: `Areas ${offset + 1}–${Math.min(offset + 8, result.rows.length)} of ${result.rows.length}`,
      }),
    );
  return pages;
}
