import { orderedCategoryValues } from "./category-order";
import type { CatalogDataset, CatalogIndicator } from "@/lib/catalog/types";
import { countAndShare } from "@/lib/format";
export { countAndShare, shareOf } from "@/lib/format";
import { hasAvailableValue } from "@/lib/catalog/summary";

/** Check state again at the presentation boundary: withheld payloads must never
 * reach charts, accessibility labels, tables or presentation mode. */
export function publicValues(indicator: CatalogIndicator | undefined) {
  return indicator && hasAvailableValue(indicator.state)
    ? orderedCategoryValues(indicator.id, indicator.values)
    : [];
}
export function scalar(indicator: CatalogIndicator | undefined): number | null {
  const values = publicValues(indicator);
  return values.length === 1 && Number.isFinite(values[0].value) ? values[0].value : null;
}
export function overviewMeasures(dataset: CatalogDataset) {
  const byId = new Map(dataset.indicators.map((i) => [i.id, i]));
  const get = (id: string) => byId.get(id);
  const total = scalar(get("bhw.total"));
  const parts = ["bhw.registered", "bhw.registered-accredited", "bhw.nonregistered"].map((id) =>
    scalar(get(id)),
  );
  // Never turn missing categories into zero, or present a partial split as the whole.
  const completeMix =
    total !== null &&
    parts.every((n) => n !== null && n >= 0) &&
    parts.reduce<number>((sum, n) => sum + (n ?? 0), 0) === total;
  return { get, total, parts: completeMix ? (parts as number[]) : null };
}
export function measureStatus(indicator: CatalogIndicator | undefined): string {
  if (!indicator) return "No accepted observation is available.";
  if (indicator.state === "suppressed") return "Withheld across locations to protect small groups.";
  if (indicator.state === "failed")
    return "Could not verify or load this measure. Please try again.";
  if (indicator.state === "unsupported") return "Not supported at this geographic level.";
  if (indicator.state === "unavailable") return "No accepted observation is available.";
  return indicator.reason ?? "";
}

export function shareCount(indicator: CatalogIndicator | undefined): number | null {
  const values = publicValues(indicator);
  const count = values.length === 1 ? values[0].numerator : null;
  return count != null && Number.isFinite(count) && count >= 0 ? count : null;
}

/** Prefer a reported numerator; otherwise estimate from this observation's base.
 * Only accepted public values can enter this path, including the fallback base. */
export function shareSummary(
  indicator: CatalogIndicator | undefined,
  fallbackBase: number | null = null,
) {
  const percent = scalar(indicator);
  const reported = shareCount(indicator);
  if (reported !== null) return { text: countAndShare(reported, percent), estimated: false };
  const values = publicValues(indicator);
  const base = values.length === 1 ? (values[0].denominator ?? fallbackBase) : null;
  const estimated =
    percent !== null &&
    percent >= 0 &&
    percent <= 100 &&
    base !== null &&
    Number.isFinite(base) &&
    base > 0;
  const count = estimated ? Math.round((percent * base) / 100) : null;
  return { text: `${estimated ? "≈" : ""}${countAndShare(count, percent)}`, estimated };
}
