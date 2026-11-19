import { contextReview, type ContextRow } from "./context-review";
import type { ColorBin } from "@/lib/charts/color-scale";
export const jointColors = ["#e8d9b0", "#c85a5a", "#64acbe", "#57467b"];
/** Two explicit median splits, not a derived workload or need score. Ties stay in the lower group. */
export function contextBivariate(rows: readonly ContextRow[]) {
  const review = contextReview(rows);
  const h = review.headMedian,
    x = review.contextMedian;
  if (h === null || x === null) return null;
  const groups = rows.map((r) => ({
    ...r,
    group: r.eligible ? (r.headcount! > h ? 2 : 0) + (r.context! > x ? 1 : 0) : null,
  }));
  const bins: ColorBin[] = jointColors.map((color, i) => ({
    min: i,
    max: i === 3 ? 3 : i + 1,
    color,
  }));
  return {
    headMedian: h,
    contextMedian: x,
    groups,
    bins,
    counts: jointColors.map((_, i) => groups.filter((r) => r.group === i).length),
  };
}
