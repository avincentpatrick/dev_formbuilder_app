import type { CatalogIndicator } from "./types";
import { hasAvailableValue } from "./summary";
import { ACCEPTED_RELEASES } from "./definitions";
import { percentageFormatter } from "@/lib/format";

export const POVERTY_DEFINITION =
  "PSA's 2023 small-area estimate of the percentage of people below the poverty threshold. It describes people, not households, and is an estimate rather than an exact count.";
export const POVERTY_UNCERTAINTY =
  "The published 90% confidence interval describes uncertainty around the estimate. A wider interval means less precision. It is not the range of people's incomes; a difference between two estimates alone does not establish a meaningful difference between places.";
export const POVERTY_SCOPE =
  "This release provides city/municipality estimates. Municipal percentages are not averaged into provincial, regional or national rates. No barangay or district rate is inferred.";

/** Presentation only: never recover rejected payloads or derive a poor-person count. */
export function povertyReading(indicator: CatalogIndicator | undefined) {
  if (!indicator || !hasAvailableValue(indicator.state)) return null;
  if (
    indicator.sourceSlug !== ACCEPTED_RELEASES.poverty ||
    indicator.unit !== "%" ||
    indicator.values.length !== 1
  )
    return null;
  const value = indicator.values[0];
  if (!Number.isFinite(value.value) || value.value < 0 || value.value > 100) return null;
  const interval =
    typeof value.ciLow === "number" &&
    Number.isFinite(value.ciLow) &&
    typeof value.ciHigh === "number" &&
    Number.isFinite(value.ciHigh) &&
    value.ciLow <= value.ciHigh
      ? ([value.ciLow, value.ciHigh] as const)
      : null;
  const format = percentageFormatter([value.value, ...(interval ?? [])]);
  return {
    estimate: format(value.value),
    interval: interval ? `${format(interval[0])} to ${format(interval[1])}` : null,
  };
}
