import type { MapRow } from "./prototype";
import type { ColorBin } from "@/lib/charts/color-scale";
export const STEPZERO_ACCREDITATION_ID = "bhw.declared-accreditation";
export const STEPZERO_ACCREDITATION_DENOMINATOR = "All LGU-declared BHWs: registered only, registered and accredited, and nonregistered";
export const STEPZERO_ACCREDITATION_BINS: ColorBin[] = [
  { min: 0, max: 25, color: "#eff3ff" }, { min: 25, max: 50, color: "#bdd7e7" },
  { min: 50, max: 75, color: "#6baed6" }, { min: 75, max: 100, color: "#3182bd" },
  { min: 100, max: 100, color: "#08519c" },
];
/** Preserve the published percentage; never derive a new rate or average child rates. */
export function joinStepzeroAccreditation(
  children: { geo_code: string; geo_name: string }[], records: Record<string, unknown>[],
  column = "pct_registered_accredited",
): MapRow[] {
  const index = new Map<string, Record<string, unknown>[]>();
  for (const row of records) index.set(String(row.geo_code), [...(index.get(String(row.geo_code)) ?? []), row]);
  return children.map(child => {
    const rows = index.get(child.geo_code) ?? [];
    const row = rows.length === 1 ? rows[0] : null;
    const total = row?.n_total_bhw;
    const accredited = row?.n_registered_accredited;
    const rate = row?.[column];
    const validCounts = typeof total === "number" && Number.isSafeInteger(total) && total > 0 &&
      typeof accredited === "number" && Number.isSafeInteger(accredited) && accredited >= 0 && accredited <= total;
    const validRate = typeof rate === "number" && Number.isFinite(rate) && rate >= 0 && rate <= 100;
    // The ingestion contract rounds to two decimals. Check that precision without replacing the published value.
    const consistent = validCounts && validRate &&
      Math.abs(rate - 100 * accredited / total) <= 0.005 + Number.EPSILON * 100;
    return { code: child.geo_code, name: child.geo_name, value: consistent ? rate : null,
      note: !row ? "Missing or duplicate StepZero source row."
        : !validCounts ? "No valid positive StepZero BHW denominator or numerator."
          : !validRate ? "Published StepZero percentage is unavailable or invalid."
            : !consistent ? "Published percentage is inconsistent with StepZero counts."
            : "Published share of registered and accredited BHWs among all LGU-declared BHWs." };
  });
}
