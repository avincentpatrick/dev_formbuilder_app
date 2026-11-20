import { joinContext } from "./context";
export type ContextRow = NonNullable<ReturnType<typeof joinContext>>[number];
function median(values: number[]) {
  const sorted = [...values].sort((a, b) => a - b);
  const mid = Math.floor(sorted.length / 2);
  return sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
}
/** Separate data review from value comparison. No score, ranking or inferred workforce shortage. */
export function contextReview(rows: readonly ContextRow[]) {
  const eligible = rows.filter((r) => r.eligible);
  const gaps = rows.filter((r) => !r.eligible);
  const zeros = eligible.filter((r) => r.headcount === 0);
  const headMedian = eligible.length >= 4 ? median(eligible.map((r) => r.headcount!)) : null;
  const contextMedian = eligible.length >= 4 ? median(eligible.map((r) => r.context!)) : null;
  const contrasts =
    headMedian === null || contextMedian === null
      ? []
      : eligible.filter((r) => r.headcount! < headMedian && r.context! > contextMedian);
  const order = (a: ContextRow, b: ContextRow) =>
    a.name.localeCompare(b.name, "en") || a.code.localeCompare(b.code);
  return {
    eligibleCount: eligible.length,
    headMedian,
    contextMedian,
    gaps: [...gaps].sort(order),
    zeros: [...zeros].sort(order),
    contrasts: [...contrasts].sort(order),
  };
}
