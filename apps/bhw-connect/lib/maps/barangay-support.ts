import type { MapChoice } from "./prototype";

/** Indicators that can be mapped for each barangay of a municipality, by dataset. Everything else stays at
 * municipality level and keeps the municipal dashboard link. Widen one indicator at a time.
 *
 * NHFR and UUC are place inventories read from their source tables (lib/maps/barangay-reader.ts).
 * BHW Census and StepZero are mapped for **counts of totals only**, read from their own barangay aggregate
 * rows: the owner decided on 10 November 2026 that BHW counts per barangay may be mapped (BUILD_PLAN §4.1:
 * "counts of totals ... are not suppressed"), and that percentages, averages, honorarium and training stay at
 * municipality level (docs/BARANGAY_BHW_DISCLOSURE_PROPOSAL.md). If rates are ever mapped below municipalities
 * the agreed rule is that both groups behind a rate have at least 5 people. */
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
  "bhw-2025": ["bhw.validated-profiles"],
  "bhw-stepzero-2026": [
    "bhw.total",
    "bhw.registered",
    "bhw.registered-accredited",
    "bhw.nonregistered",
    "stepzero.population",
    "stepzero.households",
  ],
};

/** Datasets whose barangay values are computed from source facts; the others read barangay aggregate rows. */
export const BARANGAY_FACT_DATASETS: readonly string[] = ["nhfr-2026-09", "uuc-phc-2025"];

/** Said beside a barangay map of BHW counts, so a missing rate is explained, not mistaken for a gap. */
export const BARANGAY_BHW_NOTE =
  "Barangay maps show counts only, as on the dashboards. Percentages, averages, honorarium and training are shown for municipalities and above, to protect individuals.";

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
