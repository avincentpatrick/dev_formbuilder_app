import type { MapRow } from "./prototype";
export const CENSUS_ACTIVE_YEARS_ID = "bhw.active-years";
export const CENSUS_ACTIVE_YEARS_DENOMINATOR = "Profiles with reported active-year counts; contributing count not supplied";
/** Stored AVG ignores null active-year counts. Total profiles are not the contributing count. */
export function joinCensusActiveYears(children: { geo_code: string; geo_name: string }[], records: Record<string, unknown>[]): MapRow[] {
  const index = new Map<string, Record<string, unknown>[]>();
  for (const row of records) {
    if (typeof row.geo_code === "string") index.set(row.geo_code, [...(index.get(row.geo_code) ?? []), row]);
  }
  return children.map(child => {
    const matches = index.get(child.geo_code) ?? [];
    const row = matches.length === 1 ? matches[0] : undefined;
    const n = row?.n_total, mean = row?.avg_active_years;
    const valid = typeof n === "number" && Number.isSafeInteger(n) && n > 0 &&
      typeof mean === "number" && Number.isFinite(mean) && mean >= 0;
    return { code: child.geo_code, name: child.geo_name, value: valid ? mean : null,
      note: valid ? "Published mean of reported active-year counts; missing counts excluded. Contributing count and confidence interval are not supplied."
        : "Missing, duplicate or invalid BHW Profiles mean or profile count." };
  });
}
