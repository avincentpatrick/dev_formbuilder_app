import { expect, it } from "vitest";
import { orderedCategoryValues } from "./category-order";
import { publicValues } from "./overview";
import type { CatalogIndicator } from "@/lib/catalog/types";

it("orders only published age bands without inventing omitted categories or mutating input", () => {
  const values = [
    { label: "60+", value: 10 },
    { label: "<30", value: 0 },
    { label: "Unknown", value: 7 },
    { label: "40-49", value: 99 },
  ];
  expect(orderedCategoryValues("bhw.demographics.age_band", values).map((v) => v.label)).toEqual([
    "<30",
    "40-49",
    "60+",
    "Unknown",
  ]);
  expect(values[0].label).toBe("60+");
  expect(
    orderedCategoryValues("bhw.demographics.age_band", values).find((v) => v.label === "<30")
      ?.value,
  ).toBe(0);
});
it("orders years numerically and payment bands by their source sequence, not their values", () => {
  const years = [
    { label: "2025", value: 40 },
    { label: "1999", value: 2 },
    { label: "2000", value: 100 },
  ];
  expect(orderedCategoryValues("bhw.cohorts.registered", years).map((v) => v.label)).toEqual([
    "1999",
    "2000",
    "2025",
  ]);
  const bands = [
    { label: "Over ₱24,000", value: 3 },
    { label: "₱8,001-12,000", value: 80 },
    { label: "None", value: 0 },
    { label: "₱1-4,000", value: 10 },
  ];
  expect(orderedCategoryValues("bhw.combined-honorarium.bands", bands).map((v) => v.label)).toEqual(
    ["None", "₱1-4,000", "₱8,001-12,000", "Over ₱24,000"],
  );
  expect(orderedCategoryValues("bhw.demographics.education", years)).toBe(years);
});
it("still rejects withheld and failed category payloads before ordering", () => {
  for (const state of ["suppressed", "failed", "unsupported", "unavailable"] as const) {
    const item: CatalogIndicator = {
      id: "bhw.demographics.age_band",
      label: "Age",
      unit: "people",
      sourceSlug: "bhw-2025",
      state,
      reason: null,
      reportedAreaRecords: 1,
      values: [{ label: "<30", value: 987654 }],
    };
    expect(publicValues(item)).toEqual([]);
  }
});
