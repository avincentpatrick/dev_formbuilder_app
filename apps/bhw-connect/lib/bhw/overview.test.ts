import { expect, it } from "vitest";
import { overviewMeasures, publicValues, scalar, measureStatus } from "./overview";
import type { CatalogDataset, CatalogIndicator } from "@/lib/catalog/types";

const indicator = (
  id: string,
  value: number | null,
  state: CatalogIndicator["state"] = "available",
): CatalogIndicator => ({
  id,
  label: id,
  unit: "people",
  sourceSlug: "bhw-stepzero-2026",
  state,
  values: value === null ? [] : [{ value }],
  reason: null,
  reportedAreaRecords: 1,
});
const dataset = (indicators: CatalogIndicator[]): CatalogDataset => ({
  id: "bhw",
  title: "BHW",
  category: "Health workforce",
  state: "available",
  availableIndicators: 4,
  totalIndicators: 4,
  supportedLevels: ["national"],
  detailHref: "/bhw",
  sources: [],
  indicators,
});

it("keeps real zero while refusing withheld/failed values even in a malformed payload", () => {
  expect(scalar(indicator("bhw.total", 0))).toBe(0);
  for (const state of ["suppressed", "failed", "unavailable", "unsupported"] as const) {
    const item = indicator("bhw.demographics.education", 12345, state);
    expect(publicValues(item)).toEqual([]);
    expect(scalar(item)).toBeNull();
    expect(measureStatus(item)).not.toBe("");
  }
});
it("never substitutes profiles or a partial registration split for missing headcount", () => {
  const result = overviewMeasures(
    dataset([
      indicator("bhw.total", null, "unavailable"),
      indicator("bhw.validated-profiles", 50),
      indicator("bhw.registered", 30),
      indicator("bhw.registered-accredited", 20),
      indicator("bhw.nonregistered", null),
    ]),
  );
  expect(result.total).toBeNull();
  expect(result.parts).toBeNull();
});
it("only draws a complete split that reconciles with the published headcount", () => {
  const rows = [
    indicator("bhw.total", 100),
    indicator("bhw.registered", 60),
    indicator("bhw.registered-accredited", 30),
    indicator("bhw.nonregistered", 10),
  ];
  expect(overviewMeasures(dataset(rows)).parts).toEqual([60, 30, 10]);
  expect(
    overviewMeasures(dataset([indicator("bhw.total", 101), ...rows.slice(1)])).parts,
  ).toBeNull();
  expect(
    overviewMeasures(dataset(rows.map((r) => ({ ...r, values: [{ value: 0 }] })))).parts,
  ).toEqual([0, 0, 0]);
});
it("keeps partial-data explanations rather than silently treating partial values as complete", () => {
  const partial = {
    ...indicator("bhw.demographics.education", 10, "partial"),
    reason: "Some categories have no reported value.",
  };
  expect(publicValues(partial)).toEqual([{ value: 10 }]);
  expect(measureStatus(partial)).toBe(partial.reason);
});

it("keeps full names in chart data while using optional compact tick labels on phones", async () => {
  const { horizontalBarSpec } = await import("@/lib/charts/bar-chart");
  const data = [
    { label: "BHW Reference Manual training", shortLabel: "Manual training", value: 25 },
  ];
  const phone = horizontalBarSpec(data, { width: 320 });
  expect((phone.y?.tickFormat as (v: string) => string)(data[0].label)).toBe("Manual training");
  expect(
    (horizontalBarSpec(data, { width: 800 }).y?.tickFormat as (v: string) => string)(
      data[0].label,
    ).replaceAll("\n", " "),
  ).toBe(data[0].label);
  expect(data[0].label).toBe("BHW Reference Manual training");
});

it("pairs counts and percentages without hiding zero or capping cross-source ratios", async () => {
  const { countAndShare, shareOf, shareCount } = await import("./overview");
  expect(countAndShare(270917, shareOf(270917, 306835))).toBe("270,917 (88%)");
  expect(countAndShare(0, 0)).toBe("0 (0%)");
  expect(shareOf(120, 100)).toBe(120);
  expect(shareOf(0, 0)).toBeNull();
  expect(countAndShare(null, 65.72)).toBe("Count unavailable (66%)");
  expect(countAndShare(20, null)).toBe("20 (percentage unavailable)");
  const row = {
    ...indicator("share", 65.72),
    values: [{ value: 65.72, numerator: 201654, denominator: 306835 }],
  };
  expect(shareCount(row)).toBe(201654);
  expect(shareCount({ ...row, state: "suppressed" })).toBeNull();
  expect(shareCount(indicator("share", 65.72))).toBeNull();
});

it("estimates missing numerators from the matching population and labels them approximate", async () => {
  const { shareSummary } = await import("./overview");
  const row = {
    ...indicator("bhw.any-honorarium", 94.7),
    values: [{ value: 94.7, denominator: 1000 }],
  };
  expect(shareSummary(row)).toEqual({ text: "≈947 (95%)", estimated: true });
  expect(shareSummary(row, 2000).text).toBe("≈947 (95%)");
  expect(
    shareSummary({ ...row, values: [{ value: 94.7, numerator: 946, denominator: 1000 }] }),
  ).toEqual({ text: "946 (95%)", estimated: false });
  expect(shareSummary(indicator("share", 94.7), 1000).text).toBe("≈947 (95%)");
  expect(shareSummary({ ...row, values: [{ value: 0, denominator: 1000 }] }).text).toBe("≈0 (0%)");
  for (const state of ["suppressed", "failed", "unavailable", "unsupported"] as const) {
    expect(shareSummary({ ...row, state }, 1000)).toEqual({
      text: "Count unavailable (percentage unavailable)",
      estimated: false,
    });
  }
  for (const denominator of [0, -1, NaN, Infinity]) {
    expect(shareSummary({ ...row, values: [{ value: 94.7, denominator }] }, 1000).estimated).toBe(
      false,
    );
  }
  expect(shareSummary(indicator("share", 94.7)).estimated).toBe(false);
});
