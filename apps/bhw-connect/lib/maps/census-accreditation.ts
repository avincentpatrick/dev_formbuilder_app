import type { MapRow } from "./prototype";
import { MIN_LEADER_N } from "@/lib/analysis/thresholds";
export const CENSUS_HONORARIUM_ID = "bhw.any-honorarium";
export const CENSUS_ACCREDITATION_ID = "bhw.profile-accreditation";
export const CENSUS_ACCREDITATION_DENOMINATOR = "Validated BHW Census profiles in the selected area";
export function isCensusProfileRate(id: string) {
  return id === CENSUS_HONORARIUM_ID || id === CENSUS_ACCREDITATION_ID || id.startsWith("bhw.training.");
}
/** Preserve source percentages and Wilson intervals; never substitute declarations or adjusted rates. */
export function joinCensusAccreditation(children: { geo_code: string; geo_name: string }[], records: Record<string, unknown>[]): MapRow[] {
  return joinProfileRate(children, records, "n_accredited", "pct_accredited");
}
export function joinCensusTraining(children: { geo_code: string; geo_name: string }[], records: Record<string, unknown>[]): MapRow[] {
  return joinProfileRate(children, records, "n_trained", "coverage_pct");
}
function joinProfileRate(children: { geo_code: string; geo_name: string }[], records: Record<string, unknown>[], numerator: string, percentage: string): MapRow[] {
  const index = new Map<string, Record<string, unknown>[]>();
  for (const row of records) {
    if (typeof row.geo_code === "string") index.set(row.geo_code, [...(index.get(row.geo_code) ?? []), row]);
  }
  return children.map(child => {
    const matches = index.get(child.geo_code) ?? [];
    const row = matches.length === 1 ? matches[0] : undefined;
    const n = row?.n_total, k = row?.[numerator], rate = row?.[percentage];
    const counts = typeof n === "number" && Number.isSafeInteger(n) && n > 0 &&
      typeof k === "number" && Number.isSafeInteger(k) && k >= 0 && k <= n;
    const valid = counts && typeof rate === "number" && Number.isFinite(rate) && rate >= 0 && rate <= 100 &&
      Math.abs(rate - 100 * k / n) <= 0.005 + Number.EPSILON * 100;
    const low = row?.ci_low, high = row?.ci_high;
    const interval = valid && typeof low === "number" && typeof high === "number" &&
      Number.isFinite(low) && Number.isFinite(high) && low >= 0 && low <= rate && high >= rate && high <= 100;
    return { code: child.geo_code, name: child.geo_name, value: valid ? rate : null,
      ciLow: interval ? low : null, ciHigh: interval ? high : null,
      smallSample: valid && n < MIN_LEADER_N,
      note: !valid ? "Missing, duplicate or inconsistent Census percentage or denominator."
        : `Published Census percentage; ${n} validated profiles.${n < MIN_LEADER_N ? " Few BHWs profiled — unstable." : ""}` };
  });
}

/** This published aggregate has no numerator or interval columns for honorarium receipt. */
export function joinCensusHonorarium(children: { geo_code: string; geo_name: string }[], records: Record<string, unknown>[]): MapRow[] {
  const index = new Map<string, Record<string, unknown>[]>();
  for (const row of records) {
    if (typeof row.geo_code === "string") index.set(row.geo_code, [...(index.get(row.geo_code) ?? []), row]);
  }
  return children.map(child => {
    const matches = index.get(child.geo_code) ?? [];
    const row = matches.length === 1 ? matches[0] : undefined;
    const n = row?.n_total, rate = row?.any_honorarium_pct;
    const valid = typeof n === "number" && Number.isSafeInteger(n) && n > 0 &&
      typeof rate === "number" && Number.isFinite(rate) && rate >= 0 && rate <= 100;
    return { code: child.geo_code, name: child.geo_name, value: valid ? rate : null,
      ciLow: null, ciHigh: null, smallSample: valid && n < MIN_LEADER_N,
      note: !valid ? "Missing, duplicate or invalid honorarium percentage or profile denominator."
        : `Published share receiving any honorarium; ${n} validated profiles. No confidence interval supplied for this measure.${n < MIN_LEADER_N ? " Few BHWs profiled — unstable." : ""}` };
  });
}
