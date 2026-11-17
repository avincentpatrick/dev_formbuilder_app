/**
 * Shared number formatters for display. Centralizes the inline helpers that
 * used to be duplicated across app/page.tsx and the explore figures, so every
 * count/percent/peso figure formats the same way everywhere.
 */
export const formatCount = (n: number | null): string => (n === null ? "—" : n.toLocaleString());

export type PercentageContext = "single" | "multiple";

/** Display only: never round source data. Headlines use whole %, comparisons one decimal.
 * Keep up to two decimals when rounding would hide a nonzero value or a near-100 gap.
 * Related series can also request two decimals to distinguish otherwise equal labels. */
export function formatPct(
  n: number | null,
  context: PercentageContext = "multiple",
  decimals?: number,
): string {
  if (n === null || !Number.isFinite(n)) return "—";
  let precision = decimals ?? (context === "single" ? 0 : 1);
  const rounded = () => Number(n.toFixed(precision));
  while (precision < 2 && ((n !== 0 && rounded() === 0) || (n !== 100 && rounded() === 100)))
    precision++;
  if (n !== 0 && rounded() === 0) return n > 0 ? "<0.01%" : ">−0.01%";
  if (n < 100 && rounded() === 100) return ">99.99%";
  return `${rounded()}%`;
}

/** Use a consistent precision across a comparison, increasing only for meaningful differences. */
export function percentageFormatter(values: readonly number[]): (n: number) => string {
  const labels = new Map<string, number>();
  const needsTwo = values.some((n) => {
    const label = formatPct(n);
    const previous = labels.get(label);
    labels.set(label, n);
    return (
      previous !== undefined &&
      previous !== n &&
      formatPct(previous, "multiple", 2) !== formatPct(n, "multiple", 2)
    );
  });
  return (n) => formatPct(n, "multiple", needsTwo ? 2 : 1);
}

export const formatPeso = (n: number | null): string =>
  n === null ? "—" : `₱${Math.round(n).toLocaleString()}`;

/** Same as formatPeso, but amounts under ₱100 collapse to "<₱100" instead of
 * an exact tiny figure — used where very small honorarium amounts (a few
 * pesos a month) are real but too fine-grained to usefully compare exactly. */
export const formatPesoFloor100 = (n: number | null): string =>
  n === null ? "—" : n < 100 ? "<₱100" : formatPeso(n);

/** Compact form for tight labels, e.g. 306,835 -> "306.8K". */
export const formatCompact = (n: number | null): string =>
  n === null
    ? "—"
    : new Intl.NumberFormat("en-US", { notation: "compact", maximumFractionDigits: 1 }).format(n);

/**
 * Named formatter kinds, for components that need to accept a formatting
 * choice as a prop across a Server -> Client Component boundary. Functions
 * aren't serializable across that boundary (Next.js RSC), so callers pass one
 * of these strings instead and the client component resolves it locally via
 * `formatterFor`.
 */
export type ValueFormatKind = "count" | "percent" | "peso" | "pesoFloor100";

export function formatterFor(kind: ValueFormatKind = "count"): (n: number) => string {
  switch (kind) {
    case "percent":
      return (n) => formatPct(n);
    case "peso":
      return (n) => formatPeso(n);
    case "pesoFloor100":
      return (n) => formatPesoFloor100(n);
    case "count":
    default:
      return (n) => n.toLocaleString();
  }
}

/** Pair a count with its share; callers must label any estimated count. */
export function countAndShare(
  count: number | null,
  percent: number | null,
  context: PercentageContext = "single",
): string {
  return `${count === null ? "Count unavailable" : formatCount(count)} (${percent === null ? "percentage unavailable" : formatPct(percent, context)})`;
}

export function shareOf(count: number | null, total: number | null): number | null {
  return count !== null && total !== null && total > 0 ? (count / total) * 100 : null;
}
