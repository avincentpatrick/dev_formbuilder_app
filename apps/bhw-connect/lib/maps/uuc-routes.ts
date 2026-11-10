import type { MapRow } from "./prototype";

export const UUC_ROUTE_LABELS: Readonly<Record<string, string>> = {
  "uuc.route.ip": "Qualifying route: Indigenous Peoples",
  "uuc.route.conflict": "Qualifying route: conflict-affected or displaced",
  "uuc.route.four_ps": "Qualifying route: 4Ps / CCT enrolled",
  "uuc.route.health": "Qualifying route: worse than province on health",
};
const validCount = (value: unknown): value is number =>
  typeof value === "number" && Number.isSafeInteger(value) && value >= 0;

/** Published route classifications only; never recompute eligibility from capped indicators. */
export function joinUucRouteRows(
  children: { geo_code: string; geo_name: string }[],
  rows: Record<string, unknown>[],
  column: string,
): MapRow[] {
  return children.map((child) => {
    const matches = rows.filter((row) => row.geo_code === child.geo_code);
    const base = { code: child.geo_code, name: child.geo_name, value: null };
    if (matches.length !== 1)
      return {
        ...base,
        note: matches.length ? "Ambiguous source rows" : "No reported route count",
      };
    const row = matches[0];
    const count = row[column];
    const listed = row.n_listed;
    const health = column === "n_route_health";
    const denominator = health ? row.n_health_evaluable : listed;
    if (
      !validCount(count) ||
      !validCount(listed) ||
      !validCount(denominator) ||
      count > denominator ||
      denominator > listed
    )
      return { ...base, note: "Incomplete or inconsistent route count" };
    return {
      ...base,
      value: count,
      note: health
        ? `${denominator} health-evaluable of ${listed} listed barangays; ${listed - denominator} excluded from health evaluation${denominator === 0 ? "; no evaluable denominator for a share" : ""}`
        : `${listed} listed barangays; routes overlap`,
    };
  });
}
