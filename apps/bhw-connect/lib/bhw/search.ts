import { CATALOG, type IndicatorSpec } from "@/lib/catalog/definitions";
import { restoredBhwMapHref } from "@/lib/maps/catalog-return";
import type { GeoLevel } from "@/lib/filters/schema";

export const BHW_MEASURES = CATALOG.find((dataset) => dataset.id === "bhw")!.indicators;
const normalize = (text: string) =>
  text
    .toLowerCase()
    .replace(/[^\p{L}\p{N}]+/gu, " ")
    .trim();
const aliases: Record<string, readonly string[]> = {
  headcount: ["bhw.total"],
  "total bhws": ["bhw.total"],
  workforce: ["bhw.total"],
  profiles: ["bhw.validated-profiles"],
  accreditation: ["bhw.declared-accreditation", "bhw.profile-accreditation"],
  "lgu accreditation": ["bhw.declared-accreditation"],
  "profile accreditation": ["bhw.profile-accreditation"],
  "years active": ["bhw.active-years"],
  honorarium: BHW_MEASURES.filter((s) => s.id.includes("honorarium")).map((s) => s.id),
  training: BHW_MEASURES.filter((s) => s.id.includes(".training.")).map((s) => s.id),
  education: ["bhw.demographics.education"],
  age: ["bhw.demographics.age_band"],
  tesda: BHW_MEASURES.filter((s) => s.id.includes("tesda")).map((s) => s.id),
};
const terms = [
  ...Object.entries(aliases),
  ...BHW_MEASURES.map((spec) => [normalize(spec.label), [spec.id]] as const),
  ...BHW_MEASURES.map((spec) => [normalize(spec.id), [spec.id]] as const),
].sort((a, b) => b[0].length - a[0].length);

export type BhwSearchIntent = {
  state: "ready" | "empty" | "unsupported";
  placeQuery: string;
  measures: readonly IndicatorSpec[];
};

/** Deliberately bounded grammar: a place, a measure, or a place plus a recognized
 * measure phrase. Longest phrase wins; geography ambiguity is never auto-resolved. */
export function parseBhwSearch(query: string): BhwSearchIntent {
  const text = normalize(query);
  const blank = { placeQuery: "", measures: [] };
  if (!text) return { state: "empty", ...blank };
  if (query.length > 100 || /\b(compare|versus|vs|trend|predict|forecast)\b/.test(text))
    return { state: "unsupported", ...blank };
  const padded = ` ${text} `;
  for (const [term, ids] of terms) {
    if (!padded.includes(` ${term} `)) continue;
    const placeQuery = padded
      .replace(` ${term} `, " ")
      .trim()
      .replace(/^(in|for)\s+|\s+(in|for)$/g, "");
    return { state: "ready", placeQuery, measures: BHW_MEASURES.filter((s) => ids.includes(s.id)) };
  }
  return { state: "ready", placeQuery: query.trim(), measures: [] };
}

export function bhwMeasureHref(
  indicator: string,
  level: GeoLevel,
  code: string,
  mapState?: string | string[],
) {
  const query = new URLSearchParams({ indicator, location: `${level}:${code}` });
  const restored = restoredBhwMapHref(mapState);
  if (restored) query.set("mapState", restored.split("?")[1]);
  return `/bhw/measure?${query}`;
}

export const levelLabel: Record<GeoLevel, string> = {
  national: "Country",
  region: "Region",
  province: "Province",
  citymun: "City/Municipality",
  barangay: "Barangay",
};
