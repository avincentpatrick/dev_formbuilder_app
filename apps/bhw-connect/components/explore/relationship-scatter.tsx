/** Shared SVG drawing for Explore and approved BHW context observations. */
export type ScatterPoint = {
  code: string;
  x: number;
  y: number;
  label: string;
  href: string;
  radius?: number;
  hollow?: boolean;
};
// Fixed drawing coordinate system; the SVG scales to its container via viewBox.
const W = 480;
const H = 340;
const M = { top: 16, right: 16, bottom: 46, left: 56 };
const PLOT_W = W - M.left - M.right;
const PLOT_H = H - M.top - M.bottom;

function niceDomain(values: number[]): [number, number] {
  const lo = Math.min(...values);
  const hi = Math.max(...values);
  if (lo === hi) return [lo - 1, hi + 1];
  const pad = (hi - lo) * 0.06;
  return [lo - pad, hi + pad];
}

export function RelationshipScatter({
  points,
  xLabel,
  yLabel,
  xFormat,
  yFormat,
  ariaLabel,
  nonNegative = false,
  xMaximum,
}: {
  points: ScatterPoint[];
  xLabel: string;
  yLabel: string;
  xFormat: (v: number) => string;
  yFormat: (v: number) => string;
  ariaLabel: string;
  nonNegative?: boolean;
  xMaximum?: number;
}) {
  const xd = niceDomain(points.length ? points.map((p) => p.x) : [0, 1]);
  const yd = niceDomain(points.length ? points.map((p) => p.y) : [0, 1]);
  if (nonNegative) {
    xd[0] = Math.max(0, xd[0]);
    yd[0] = Math.max(0, yd[0]);
  }
  if (xMaximum !== undefined) xd[1] = Math.min(xMaximum, xd[1]);
  const xs = (v: number) => M.left + ((v - xd[0]) / (xd[1] - xd[0])) * PLOT_W;
  const ys = (v: number) => M.top + (1 - (v - yd[0]) / (yd[1] - yd[0])) * PLOT_H;
  return (
    <svg
      viewBox={`0 0 ${W} ${H}`}
      className="w-full"
      style={{ height: "auto" }}
      aria-label={ariaLabel}
    >
      <line x1={M.left} y1={M.top} x2={M.left} y2={M.top + PLOT_H} stroke="var(--border)" />
      <line
        x1={M.left}
        y1={M.top + PLOT_H}
        x2={M.left + PLOT_W}
        y2={M.top + PLOT_H}
        stroke="var(--border)"
      />
      <text x={M.left} y={H - 26} fontSize="11" fill="var(--muted)">
        {xFormat(xd[0])}
      </text>
      <text x={M.left + PLOT_W} y={H - 26} fontSize="11" fill="var(--muted)" textAnchor="end">
        {xFormat(xd[1])}
      </text>
      <text x={M.left - 6} y={M.top + PLOT_H} fontSize="11" fill="var(--muted)" textAnchor="end">
        {yFormat(yd[0])}
      </text>
      <text x={M.left - 6} y={M.top + 8} fontSize="11" fill="var(--muted)" textAnchor="end">
        {yFormat(yd[1])}
      </text>
      <text
        x={M.left + PLOT_W / 2}
        y={H - 8}
        fontSize="12"
        fontWeight="600"
        fill="var(--foreground)"
        textAnchor="middle"
      >
        {xLabel}
      </text>
      <text
        transform={`translate(14 ${M.top + PLOT_H / 2}) rotate(-90)`}
        fontSize="12"
        fontWeight="600"
        fill="var(--foreground)"
        textAnchor="middle"
      >
        {yLabel}
      </text>
      {points.map((p) => (
        <a key={p.code} href={p.href} aria-label={p.label}>
          <title>{p.label}</title>
          <circle
            cx={xs(p.x)}
            cy={ys(p.y)}
            r={p.radius ?? 5}
            fill={p.hollow ? "transparent" : "var(--seq-4)"}
            stroke={p.hollow ? "var(--muted)" : "var(--seq-5)"}
            strokeWidth={1}
            fillOpacity={p.hollow ? 1 : 0.75}
          />
        </a>
      ))}
    </svg>
  );
}
