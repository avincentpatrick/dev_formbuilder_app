import { ACCEPTED_RELEASES } from "@/lib/catalog/definitions";
import type { CatalogDataset } from "@/lib/catalog/types";
import type { RangeDatum } from "@/lib/charts/range-chart";
import { measureStatus, scalar } from "./overview";

export const PAYERS = [
  ["region", "Region"],
  ["province", "Province"],
  ["citymun", "City/municipality"],
  ["barangay", "Barangay"],
] as const;
const QUANTILES = ["min", "p25", "median", "p75", "max"] as const;
const QUANTILE_LABELS = ["Minimum", "25th percentile", "Median", "75th percentile", "Maximum"];

/** A box needs all five approved quantiles from the same release and population.
 * Never fill a missing endpoint from a mean, another payer or another locality. */
export function publicHonorariumDistributions(dataset: CatalogDataset) {
  return PAYERS.map(([payer, label]) => {
    const items = QUANTILES.map((key) =>
      dataset.indicators.find((i) => i.id === `bhw.honorarium-amount.${payer}.${key}`),
    );
    const values = items.map((item) =>
      item?.sourceSlug === ACCEPTED_RELEASES.bhw && item.unit === "PHP/month" ? scalar(item) : null,
    );
    const missing = values.findIndex((v) => v === null);
    if (missing !== -1)
      return {
        payer,
        label,
        data: null,
        status: `${QUANTILE_LABELS[missing]}: ${measureStatus(items[missing]) || "No compatible monthly amount is available."}`,
      };
    const numbers = values as number[];
    if (numbers.some((v, i) => v < 0 || (i > 0 && v < numbers[i - 1])))
      return {
        payer,
        label,
        data: null,
        status:
          "The reported distribution is not ordered from minimum to maximum; no box is drawn.",
      };
    const data: RangeDatum = {
      label,
      min: numbers[0],
      p25: numbers[1],
      median: numbers[2],
      p75: numbers[3],
      max: numbers[4],
    };
    return { payer, label, data, status: "" };
  });
}
