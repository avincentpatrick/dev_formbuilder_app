import { ACCEPTED_RELEASES, TRAINING_TOPICS } from "@/lib/catalog/definitions";
import type { CatalogDataset } from "@/lib/catalog/types";
import { publicValues, measureStatus } from "./overview";

export type TrainingCell = {
  value: number | null;
  numerator: number | null;
  denominator: number | null;
  status: string;
};
export type TrainingRow = { id: string; label: string; cells: TrainingCell[] };

/** Only approved, compatible observations cross the client boundary. Never pass
 * rejected payloads (including their counts) to a hidden table or serialized prop. */
export function trainingComparisonRows(datasets: CatalogDataset[]): TrainingRow[] {
  return TRAINING_TOPICS.map(([topic, label]) => ({
    id: `bhw.training.${topic}`,
    label,
    cells: datasets.map((dataset) => {
      const item = dataset.indicators.find((i) => i.id === `bhw.training.${topic}`);
      const values = publicValues(item);
      const compatible = item?.sourceSlug === ACCEPTED_RELEASES.bhw && item.unit === "%";
      const value = compatible && values.length === 1 ? values[0] : null;
      if (!value || !Number.isFinite(value.value) || value.value < 0 || value.value > 100)
        return {
          value: null,
          numerator: null,
          denominator: null,
          status: measureStatus(item) || "No comparable percentage is available.",
        };
      const count = (n: number | null | undefined) =>
        n != null && Number.isFinite(n) && n >= 0 ? n : null;
      return {
        value: value.value,
        numerator: count(value.numerator),
        denominator: count(value.denominator),
        status: item?.reason ?? "",
      };
    }),
  }));
}

export function sortTrainingRows(rows: TrainingRow[], place: number | null): TrainingRow[] {
  return [...rows].sort((a, b) => {
    if (place !== null) {
      const av = a.cells[place]?.value ?? null;
      const bv = b.cells[place]?.value ?? null;
      if (av !== bv) {
        if (av === null) return 1;
        if (bv === null) return -1;
        return bv - av;
      }
    }
    return a.label.localeCompare(b.label, "en");
  });
}
