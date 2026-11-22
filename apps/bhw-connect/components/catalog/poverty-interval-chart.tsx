import { percentageFormatter } from "@/lib/format";

export type PovertyPoint = { value: number | null; ciLow?: number | null; ciHigh?: number | null };

export function povertyInterval(point: PovertyPoint) {
  return typeof point.ciLow === "number" &&
    Number.isFinite(point.ciLow) &&
    typeof point.ciHigh === "number" &&
    Number.isFinite(point.ciHigh) &&
    point.ciLow <= point.ciHigh
    ? ([point.ciLow, point.ciHigh] as const)
    : null;
}

/** One scale for all places, including published bounds outside the percentage range. */
export function povertyChartDomain(points: readonly PovertyPoint[]): readonly [number, number] {
  let low = 0;
  let high = 100;
  for (const point of points) {
    if (
      point.value === null ||
      !Number.isFinite(point.value) ||
      point.value < 0 ||
      point.value > 100
    )
      continue;
    const interval = povertyInterval(point);
    if (interval) {
      low = Math.min(low, interval[0]);
      high = Math.max(high, interval[1]);
    }
  }
  return [Math.floor(low / 10) * 10, Math.ceil(high / 10) * 10];
}

export function PovertyIntervalChart({
  point,
  confidence,
  label,
  domain = povertyChartDomain([point]),
  format,
}: {
  point: PovertyPoint;
  confidence: 90 | 95;
  label: string;
  domain?: readonly [number, number];
  format?: (value: number) => string;
}) {
  if (point.value === null || !Number.isFinite(point.value) || point.value < 0 || point.value > 100)
    return null;
  const interval = povertyInterval(point);
  const fmt = format ?? percentageFormatter([point.value, ...(interval ?? [])]);
  const x = (value: number) => 30 + ((value - domain[0]) / (domain[1] - domain[0])) * 340;
  const ticks = Array.from(new Set([domain[0], 25, 50, 75, domain[1]]));
  const description = `${label}: ${fmt(point.value)}. ${interval ? `Published ${confidence}% confidence interval: ${fmt(interval[0])} to ${fmt(interval[1])}` : `The published ${confidence}% confidence interval is unavailable or unusable.`}`;
  return (
    <figure className="min-w-0" data-testid="poverty-interval-chart">
      <svg viewBox="0 0 400 76" className="block w-full" role="img" aria-label={description}>
        <line x1="30" x2="370" y1="28" y2="28" className="stroke-border" />
        {ticks.map((tick) => (
          <g key={tick}>
            <line x1={x(tick)} x2={x(tick)} y1="17" y2="39" className="stroke-border" />
            <text
              x={x(tick)}
              y="62"
              textAnchor="middle"
              fontSize="13"
              className="fill-current text-muted"
            >
              {fmt(tick)}
            </text>
          </g>
        ))}
        {interval && (
          <g className="stroke-accent" strokeWidth="3">
            <line x1={x(interval[0])} x2={x(interval[1])} y1="28" y2="28" />
            <line x1={x(interval[0])} x2={x(interval[0])} y1="20" y2="36" />
            <line x1={x(interval[1])} x2={x(interval[1])} y1="20" y2="36" />
          </g>
        )}
        <circle
          cx={x(point.value)}
          cy="28"
          r="6"
          className="fill-accent stroke-background"
          strokeWidth="2"
        />
      </svg>
      <figcaption className="text-sm">
        <span className="font-medium">Estimate: {fmt(point.value)}</span>
        <span className="block text-muted">
          {interval
            ? `Published ${confidence}% confidence interval: ${fmt(interval[0])} to ${fmt(interval[1])}`
            : `The published ${confidence}% confidence interval is unavailable or unusable.`}
        </span>
      </figcaption>
    </figure>
  );
}
