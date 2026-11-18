import { expect, it } from "vitest";
import type { CatalogDataset, DataState } from "@/lib/catalog/types";
import { publicHonorariumDistributions } from "./honorarium-distribution";

function fixture(values = [0, 25, 50, 75, 100], state: DataState = "available") {
  return {
    indicators: ["min", "p25", "median", "p75", "max"].map((key, i) => ({
      id: `bhw.honorarium-amount.region.${key}`,
      state,
      sourceSlug: "bhw-2025",
      unit: "PHP/month",
      reason: null,
      values: [{ value: values[i] }],
    })),
  } as CatalogDataset;
}
it("uses only complete ordered quantiles and keeps zero and ties", () => {
  const result = publicHonorariumDistributions(fixture([0, 0, 50, 50, 100]));
  expect(result[0].data).toEqual({
    label: "Region",
    min: 0,
    p25: 0,
    median: 50,
    p75: 50,
    max: 100,
  });
  expect(result.slice(1).every((row) => row.data === null)).toBe(true);
});
it.each(["suppressed", "failed", "unavailable", "unsupported"] as DataState[])(
  "removes every quantile from a box when an endpoint is %s",
  (state) => {
    const data = fixture();
    data.indicators[4].state = state;
    data.indicators[4].values = [{ value: 987654321 }];
    const result = publicHonorariumDistributions(data);
    expect(result[0].data).toBeNull();
    expect(result[0].status).toContain("Maximum:");
    expect(JSON.stringify(result)).not.toContain("987654321");
  },
);
it("does not repair invalid order, negative or nonfinite amounts", () => {
  for (const values of [
    [0, 75, 50, 80, 100],
    [-1, 25, 50, 75, 100],
    [0, 25, NaN, 75, 100],
  ]) {
    expect(publicHonorariumDistributions(fixture(values))[0].data).toBeNull();
  }
});
it("does not mix releases or units, or fill a missing endpoint from other statistics", () => {
  for (const field of ["sourceSlug", "unit"] as const) {
    const data = fixture();
    data.indicators[2][field] = field === "sourceSlug" ? "bhw-2026" : "PHP/year";
    expect(publicHonorariumDistributions(data)[0].data).toBeNull();
  }
  const data = fixture();
  data.indicators[4].id = "bhw.honorarium-amount.region.mean";
  expect(publicHonorariumDistributions(data)[0].data).toBeNull();
});
