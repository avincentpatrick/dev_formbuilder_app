import { supportsCircles } from "./symbols";
import { isCensusProfileRate } from "./census-accreditation";
import type { MapChoice, MapRow } from "./prototype";
export type CountRange = { valueMin?: number; valueMax?: number };
export function hasValueFilter(range: CountRange) {
  return range.valueMin !== undefined || range.valueMax !== undefined;
}
export function parseCountRange(min: string | null, max: string | null): CountRange | null {
  const parse = (value: string | null) =>
    value === null
      ? undefined
      : /^(0|[1-9]\d*)$/.test(value) && Number.isSafeInteger(Number(value))
        ? Number(value)
        : NaN;
  const valueMin = parse(min),
    valueMax = parse(max);
  if (
    (valueMin !== undefined && !Number.isFinite(valueMin)) ||
    (valueMax !== undefined && !Number.isFinite(valueMax)) ||
    (valueMin !== undefined && valueMax !== undefined && valueMin > valueMax)
  )
    return null;
  return {
    ...(valueMin !== undefined ? { valueMin } : {}),
    ...(valueMax !== undefined ? { valueMax } : {}),
  };
}
export function valueFilterState(row: MapRow, range: CountRange) {
  if (row.value === null || !Number.isFinite(row.value) || row.value < 0) return "Unavailable";
  return (range.valueMin !== undefined && row.value < range.valueMin) ||
    (range.valueMax !== undefined && row.value > range.valueMax)
    ? "Excluded by range"
    : "Included";
}
export function countFilterSummary(rows: readonly MapRow[], range: CountRange) {
  return {
    minimum: range.valueMin ?? null,
    maximum: range.valueMax ?? null,
    included: rows.filter((row) => valueFilterState(row, range) === "Included").length,
    excluded: rows.filter((row) => valueFilterState(row, range) === "Excluded by range").length,
    unavailable: rows.filter((row) => valueFilterState(row, range) === "Unavailable").length,
    note: "Inclusive count range. Published parent total and complete rows are unchanged. Classification and symbol/bar scales use the full area set. Excluded shapes are white with dashed outlines; unavailable values are distinct from excluded values.",
  };
}

export function valueRangeKind(
  choice: Pick<MapChoice, "dataset" | "indicator"> & { level?: string },
) {
  if (supportsCircles(choice)) return "count" as const;
  if (
    choice.level !== "citymun" &&
    ((choice.dataset === "bhw-stepzero-2026" &&
      choice.indicator === "bhw.declared-accreditation") ||
      (choice.dataset === "bhw-2025" && isCensusProfileRate(choice.indicator)))
  )
    return "percentage" as const;
  return null;
}
export function parsePercentageRange(min: string | null, max: string | null): CountRange | null {
  const parse = (value: string | null) =>
    value === null
      ? undefined
      : /^(0|[1-9]\d*)(\.\d+)?$/.test(value) && Number(value) >= 0 && Number(value) <= 100
        ? Number(value)
        : NaN;
  const valueMin = parse(min),
    valueMax = parse(max);
  if (
    (valueMin !== undefined && !Number.isFinite(valueMin)) ||
    (valueMax !== undefined && !Number.isFinite(valueMax)) ||
    (valueMin !== undefined && valueMax !== undefined && valueMin > valueMax)
  )
    return null;
  return {
    ...(valueMin !== undefined ? { valueMin } : {}),
    ...(valueMax !== undefined ? { valueMax } : {}),
  };
}
export function valueFilterSummary(rows: readonly MapRow[], choice: MapChoice) {
  const summary = countFilterSummary(rows, choice);
  return valueRangeKind(choice) === "percentage"
    ? {
        ...summary,
        unit: "%",
        note: "Inclusive percentage range (0–100%). Published parent rate, denominator and complete rows are unchanged; child percentages are never averaged. Fixed classification is unchanged. Excluded shapes are white with dashed outlines; unavailable values are distinct from excluded values.",
      }
    : { ...summary, unit: "count" };
}
