import { expect, it } from "vitest";
import { trainingOverviewRows, filterTrainingOverview } from "./training-overview";
import type { CatalogDataset } from "@/lib/catalog/types";
const dataset = (state: string, extra = {}) =>
  ({
    indicators: [
      {
        id: "bhw.training.basic_life_support",
        sourceSlug: "bhw-2025",
        unit: "%",
        state: "available",
        values: [{ value: 0, numerator: 0, denominator: 20 }],
        reason: null,
      },
      {
        id: "bhw.training-recency.basic_life_support",
        sourceSlug: "bhw-2025",
        unit: "calendar year",
        state,
        values: [{ value: 2024 }],
        reason: null,
        ...extra,
      },
    ],
  }) as CatalogDataset;
it("joins coverage and year by topic without sharing their status or population", () => {
  const row = trainingOverviewRows(dataset("suppressed")).find((r) =>
    r.id.endsWith("basic_life_support"),
  )!;
  expect(row.coverage.value).toBe(0);
  expect(row.coverage.numerator).toBe(0);
  expect(row.year).toBeNull();
  expect(row.yearStatus).not.toBe("");
  expect(trainingOverviewRows(dataset("available")).find((r) => r.id === row.id)?.year).toBe(2024);
});
it.each(["failed", "unsupported", "unavailable", "suppressed"])(
  "does not serialize %s training years",
  (state) => {
    expect(JSON.stringify(trainingOverviewRows(dataset(state)))).not.toContain("2024");
  },
);
it("rejects wrong source and unit and retains all 44 topics", () => {
  for (const extra of [
    { sourceSlug: "bhw-2026" },
    { unit: "people" },
    { values: [{ value: NaN }] },
  ]) {
    const rows = trainingOverviewRows(dataset("available", extra));
    expect(rows).toHaveLength(44);
    expect(rows.find((r) => r.id.endsWith("basic_life_support"))?.year).toBeNull();
  }
});
it("searches plain and original wording and ranks zero above missing without mutating rows", () => {
  const rows = trainingOverviewRows(dataset("available"));
  expect(filterTrainingOverview(rows, "blood pressure", false)).toHaveLength(1);
  expect(filterTrainingOverview(rows, "apparatus", false)).toHaveLength(1);
  expect(filterTrainingOverview(rows, "", true)[0].id).toBe("bhw.training.basic_life_support");
  expect(filterTrainingOverview(rows, "no-such-topic", false)).toHaveLength(0);
  expect(rows[0].id).not.toBe("bhw.training.basic_life_support");
});
