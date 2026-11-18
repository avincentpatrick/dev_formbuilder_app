import { expect, it } from "vitest";
import type { CatalogDataset, CatalogIndicator, DataState } from "@/lib/catalog/types";
import { sortTrainingRows, trainingComparisonRows } from "./training-comparison";

const id = "bhw.training.basic_life_support";
function dataset(state: DataState, value: number, extra: Partial<CatalogIndicator> = {}) {
  return {
    indicators: [
      {
        id,
        state,
        unit: "%",
        sourceSlug: "bhw-2025",
        reason: null,
        values: [{ value, numerator: 987654, denominator: 1234567 }],
        ...extra,
      },
    ],
  } as CatalogDataset;
}
const cells = (...data: CatalogDataset[]) =>
  trainingComparisonRows(data).find((r) => r.id === id)!.cells;

it.each(["suppressed", "failed", "unsupported", "unavailable"] as DataState[])(
  "removes %s values and counts before serialization",
  (state) => {
    const result = cells(dataset(state, 57));
    expect(result[0].value).toBeNull();
    expect(result[0].status).not.toBe("");
    expect(JSON.stringify(result)).not.toContain("987654");
    expect(JSON.stringify(result)).not.toContain("1234567");
  },
);
it("keeps zero and unequal bases separate without pooling or summing topics", () => {
  const result = cells(
    dataset("available", 0),
    dataset("partial", 62.5, { values: [{ value: 62.5, numerator: 5, denominator: 8 }] }),
  );
  expect(result.map((c) => c.value)).toEqual([0, 62.5]);
  expect(result.map((c) => c.denominator)).toEqual([1234567, 8]);
  expect(trainingComparisonRows([dataset("available", 0)])).toHaveLength(44);
});
it("does not present incompatible releases, units or invalid percentages as comparable", () => {
  for (const data of [
    dataset("available", 50, { sourceSlug: "bhw-2026" }),
    dataset("available", 50, { unit: "people" }),
    dataset("available", -1),
    dataset("available", 101),
    dataset("available", NaN),
  ]) {
    expect(cells(data)[0].value).toBeNull();
    expect(cells(data)[0].numerator).toBeNull();
  }
});
it("sorts high to low with alphabetical ties, preserving zero ahead of missing", () => {
  const row = (label: string, value: number | null) => ({
    id: label,
    label,
    cells: [{ value, numerator: null, denominator: null, status: "" }],
  });
  const rows = [row("Missing", null), row("Zero", 0), row("Beta", 10), row("Alpha", 10)];
  expect(sortTrainingRows(rows, 0).map((r) => r.label)).toEqual([
    "Alpha",
    "Beta",
    "Zero",
    "Missing",
  ]);
  expect(sortTrainingRows(rows, null).map((r) => r.label)).toEqual([
    "Alpha",
    "Beta",
    "Missing",
    "Zero",
  ]);
  expect(rows[0].label).toBe("Missing");
});
