import { computeQuantileBins, type ColorBin } from "@/lib/charts/color-scale";
import { sequentialRamp } from "@/lib/charts/palette";
export type CountClassification = {
  classification?: "quantile" | "equal" | "custom";
  classCount?: number;
  classBreaks?: number[];
};
export function classificationBreaksText(values: readonly number[]) {
  return values
    .map((value) =>
      value.toLocaleString("en-US", { useGrouping: false, maximumSignificantDigits: 21 }),
    )
    .join(",");
}

/** Explicit modes only; custom cut points cover [0, infinity) and never filter observations. */
export function parseCountClassification(
  method: string | null,
  count: string | null,
  breaks: string | null,
): CountClassification | null {
  if (method === null) return count === null && breaks === null ? {} : null;
  if (method === "custom") {
    if (count !== null || breaks === null) return null;
    const parts = breaks.split(",");
    if (
      parts.length < 1 ||
      parts.length > 6 ||
      parts.some((part) => !/^(0|[1-9]\d*)(\.\d+)?$/.test(part))
    )
      return null;
    const edges = parts.map(Number);
    if (
      edges.some(
        (value, index) =>
          !Number.isFinite(value) ||
          value <= 0 ||
          value > Number.MAX_SAFE_INTEGER ||
          (index > 0 && value <= edges[index - 1]),
      )
    )
      return null;
    return { classification: method, classBreaks: edges };
  }
  if ((method !== "quantile" && method !== "equal") || breaks !== null) return null;
  if (count !== null && !/^[2-7]$/.test(count)) return null;
  return { classification: method, classCount: count === null ? 5 : Number(count) };
}
function binsFromEdges(edges: number[]): ColorBin[] {
  return edges.slice(0, -1).map((min, index) => ({
    min,
    max: edges[index + 1],
    color:
      sequentialRamp[
        Math.round((index / Math.max(1, edges.length - 2)) * (sequentialRamp.length - 1))
      ],
  }));
}
export function countClassificationBins(
  settings: CountClassification,
  values: readonly (number | null)[],
): ColorBin[] {
  const nums = values.filter(
    (value): value is number => value !== null && Number.isFinite(value) && value >= 0,
  );
  if (!nums.length) return [];
  if (settings.classification === "custom")
    return binsFromEdges([0, ...(settings.classBreaks ?? []), Infinity]);
  if (settings.classification !== "equal")
    return computeQuantileBins(nums, settings.classCount ?? 5);
  const min = Math.min(...nums),
    max = Math.max(...nums);
  if (min === max) return computeQuantileBins(nums, 1);
  const count = settings.classCount ?? 5;
  const edges = Array.from({ length: count + 1 }, (_, i) =>
    i === count ? max : min + (max - min) * (i / count),
  );
  return binsFromEdges([...new Set(edges)]);
}
export function classificationDescription(settings: CountClassification, bins: ColorBin[]) {
  const mode = settings.classification ?? "quantile";
  const method =
    mode === "equal" ? "Equal-width groups" : mode === "custom" ? "Custom cut points" : "Quantiles";
  return `${method}: ${bins.length} actual groups${mode !== "custom" ? ` (${settings.classCount ?? 5} requested)` : ""}. ${mode === "custom" ? "Exploratory cut points, not official standards; retained across areas. Counts start at zero and the last group is open-ended." : "Recalculated from all reported child-area counts when geography changes; not common breaks for comparisons. Ties may reduce the group count."} Value filtering does not change the breaks. Shared edges belong to the higher group; the final finite maximum is included.`;
}
