import type { MapRow } from "./prototype";
import type { ColorBin } from "@/lib/charts/color-scale";

export const PROFILING_COLUMNS =
  "geo_code,n_total_bhw,n_drafted,n_for_validation,n_back_to_encoder,n_validated,n_approved";
export const PROFILING_BINS: ColorBin[] = [
  { min: 0, max: 25, color: "#eff3ff" },
  { min: 25, max: 50, color: "#bdd7e7" },
  { min: 50, max: 75, color: "#6baed6" },
  { min: 75, max: 100, color: "#3182bd" },
  { min: 100, max: 100, color: "#08519c" },
];
/** Match the existing workflow reader's rounded shares, without treating missing fields as zero. */
export function joinProfilingRows(
  children: { geo_code: string; geo_name: string }[],
  rows: Record<string, unknown>[],
  indicator: string,
): MapRow[] {
  return children.map((child) => {
    const matches = rows.filter((r) => r.geo_code === child.geo_code);
    const base = { code: child.geo_code, name: child.geo_name, value: null };
    if (matches.length !== 1)
      return {
        ...base,
        note: matches.length ? "Ambiguous source records" : "No reported snapshot",
      };
    const row = matches[0];
    const fields = [
      "n_total_bhw",
      "n_drafted",
      "n_for_validation",
      "n_back_to_encoder",
      "n_validated",
      "n_approved",
    ];
    if (
      !fields.every(
        (k) =>
          typeof row[k] === "number" && Number.isSafeInteger(row[k]) && (row[k] as number) >= 0,
      )
    )
      return { ...base, note: "Incomplete or invalid snapshot" };
    const [total, drafted, validation, returned, validated, approved] = fields.map(
      (k) => row[k] as number,
    );
    const encoded = drafted + validation + returned;
    const pipeline = encoded + validated + approved;
    if (!Number.isSafeInteger(pipeline)) return { ...base, note: "Invalid snapshot total" };
    const counts: Record<string, number> = {
      encoded,
      validated,
      attested: approved,
      notEncoded: Math.max(0, total - pipeline),
    };
    const count = counts[indicator];
    if (count === undefined) return { ...base, note: "Unsupported stage" };
    if (total === 0)
      return { ...base, note: `${count} records; zero BHW headcount — share unavailable` };
    return {
      ...base,
      value: Math.round((100 * count) / total),
      note: `${count} records / ${total} BHWs to profile${pipeline > total ? "; pipeline exceeds headcount; shares are not capped" : ""}`,
    };
  });
}
