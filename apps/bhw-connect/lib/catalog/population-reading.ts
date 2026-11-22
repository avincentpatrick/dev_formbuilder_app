import type { CatalogIndicator, CatalogSelection } from "./types";
import { ACCEPTED_RELEASES } from "./definitions";
import { hasAvailableValue } from "./summary";
import { formatCount } from "@/lib/format";

export const POPULATION_DEFINITION =
  "People recorded in the accepted PSA 2024 Census of Population rows matched to this app's locations. This is a census count for 2024, not today's population or a household count.";
export const POPULATION_COVERAGE =
  "Most national, regional and provincial figures here sum matched municipalities. Complete coverage has not been verified: these are partial totals, not PSA's published totals for those areas. Manila's published city total is stored at its province node only; it is not repeated for child areas.";
export const POPULATION_COMPARISON =
  "Compare only compatible places, years and coverage. Do not add a parent area to its children. This census is separate from BHW programme population figures and FHSIS projections; it is not automatically a denominator for them or for 2023 Poverty estimates.";

/** Display accepted catalog observations only; never fetch a parent or rebuild a total. */
export function populationReading(
  indicator: CatalogIndicator | undefined,
  selection: CatalogSelection,
) {
  if (
    !indicator ||
    !hasAvailableValue(indicator.state) ||
    indicator.sourceSlug !== ACCEPTED_RELEASES.population ||
    indicator.unit !== "people" ||
    indicator.values.length !== 1 ||
    selection.mode === "all"
  )
    return null;
  if (
    (selection.mode === "psgc" && selection.level === "barangay") ||
    (selection.mode === "district" && selection.barangayCode)
  )
    return null;
  if (selection.mode === "district" && !selection.citymunCode && indicator.aggregation !== "sum")
    return null;
  const value = indicator.values[0].value;
  if (!Number.isSafeInteger(value) || value < 0) return null;
  const manila =
    selection.mode === "psgc" && selection.level === "province" && selection.code === "13806";
  const partial =
    indicator.state === "partial" ||
    (selection.mode === "psgc" && selection.level !== "citymun" && !manila);
  return {
    value: formatCount(value),
    partial,
    label: partial
      ? "Partial total — people in matched census areas"
      : selection.mode === "psgc"
        ? populationAreaLabel(selection.level, selection.code)
        : indicator.aggregation === "sum"
          ? "Population summed from compatible mapped areas"
          : "Recorded population",
  };
}

/** Existing catalog coverage reason, shared to avoid repeating it in the overview. */
export const POPULATION_PARTIAL_REASON =
  "Partial total from matched census areas; complete coverage has not been verified. Manila's province node stores its published census total. Do not repeat it for child areas.";

/** The accepted geographic coverage contract shared by overview and map detail labels. */
export function populationAreaLabel(level: string, code: string): string {
  return level === "province" && code === "13806"
    ? "Published Manila city population"
    : level === "citymun"
      ? "Recorded population"
      : "Partial total — people in matched census areas";
}
