import type { MapChoice } from "./prototype";

/** Indicators that can be read for each barangay of a municipality, by dataset. Everything else stays
 * at municipality level and keeps the municipal dashboard link. Widen one indicator at a time, and only
 * with a barangay reader (lib/maps/barangay-reader.ts) that uses the indicator's own definition.
 * BHW Census and StepZero wait for a barangay-level small-count suppression review; the UUC qualifying
 * routes are published classifications that must never be recomputed per barangay. */
export const BARANGAY_INDICATORS: Readonly<Record<string, readonly string[]>> = {
  "nhfr-2026-09": [
    "nhfr.facilities",
    "nhfr.government",
    "nhfr.private",
    "nhfr.type.bhs",
    "nhfr.type.rhu",
    "nhfr.type.hospital",
    "nhfr.type.birthing-home",
    "nhfr.beds",
    "nhfr.barangay-presence",
  ],
  "uuc-phc-2025": ["uuc.listed"],
};

export const BARANGAY_DRILL_DATASETS: readonly string[] = Object.keys(BARANGAY_INDICATORS);

export function barangayIndicatorSupported(dataset: string, indicator: string): boolean {
  return BARANGAY_INDICATORS[dataset]?.includes(indicator) ?? false;
}

/** A barangay view is only offered for a supported indicator. When a switch of dataset or indicator would
 * leave a municipality scope unsupported, return to the municipality's province with it selected. */
export function clampBarangayScope(choice: MapChoice): MapChoice {
  if (choice.level !== "citymun" || barangayIndicatorSupported(choice.dataset, choice.indicator)) return choice;
  return { ...choice, level: "province", code: choice.code.slice(0, 5), selected: choice.code };
}
