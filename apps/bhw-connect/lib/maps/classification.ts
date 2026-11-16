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
export function classificationDescription(
  settings: CountClassification,
  bins: ColorBin[],
  kind: "count" | "percentage" | "years" = "count",
) {
  if (kind === "percentage") {
    if (!settings.classification)
      return "Fixed percentage breaks across areas. Value filtering does not change the breaks.";
    const method =
      settings.classification === "custom"
        ? "Custom cut points"
        : settings.classification === "equal"
          ? "Equal-width groups"
          : "Quantiles";
    return `${method}: ${bins.length} actual groups${settings.classification !== "custom" ? ` (${settings.classCount ?? 5} requested)` : ""}. ${settings.classification === "custom" ? "Exploratory cut points, not official standards; retained across areas. Groups cover 0–100%." : "Recalculated from all reported child-area percentages when geography changes; not common breaks for comparisons. Ties may reduce the group count."} Value filtering does not change the breaks. Shared edges belong to the higher group; the final finite maximum is included. Published parent rates and denominators are unchanged.`;
  }
  const mode = settings.classification ?? "quantile";
  const method =
    mode === "equal" ? "Equal-width groups" : mode === "custom" ? "Custom cut points" : "Quantiles";
  const unit = kind === "years" ? "Mean active years" : "Counts";
  const observations = kind === "years" ? "means" : "counts";
  return `${method}: ${bins.length} actual groups${mode !== "custom" ? ` (${settings.classCount ?? 5} requested)` : ""}. ${mode === "custom" ? `Exploratory cut points, not official standards; retained across areas. ${unit} start at zero and the last group is open-ended.` : `Recalculated from all reported child-area ${observations} when geography changes; not common breaks for comparisons. Ties may reduce the group count.`} Value filtering does not change the breaks. Shared edges belong to the higher group; the final finite maximum is included.${kind === "years" ? " Published parent means and denominators are unchanged; child means are never averaged. Contributing count and confidence interval are not supplied." : ""}`;
}

/** Only validated BHW shares use this bounded domain; other ratios are not assumed bounded. */
export function parsePercentageClassification(
  method: string | null,
  count: string | null,
  breaks: string | null,
): CountClassification | null {
  const parsed = parseCountClassification(method, count, breaks);
  return parsed?.classBreaks?.some((edge) => edge >= 100) ? null : parsed;
}
export function percentageClassificationBins(
  settings: CountClassification,
  values: readonly (number | null)[],
): ColorBin[] {
  const valid = values.filter(
    (value): value is number =>
      value !== null && Number.isFinite(value) && value >= 0 && value <= 100,
  );
  if (!valid.length) return [];
  return settings.classification === "custom"
    ? binsFromEdges([0, ...(settings.classBreaks ?? []), 100])
    : countClassificationBins(settings, valid);
}
