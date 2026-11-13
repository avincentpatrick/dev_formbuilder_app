import type { StatisticalResult } from "./types";
const xml = (s: string) =>
  s.replace(
    /[&<>"']/g,
    (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&apos;" })[c]!,
  );
export const statisticColumns = [
  "Group / term",
  "Statistic",
  "Estimate",
  "Lower CI",
  "Upper CI",
  "p-value",
  "Units",
];
export function formatStatistic(value: number): string {
  return value !== 0 && (Math.abs(value) < 0.001 || Math.abs(value) >= 1e9)
    ? value.toExponential(3)
    : value.toFixed(3);
}
export function statisticCells(r: StatisticalResult) {
  return r.rows.map((row) => [
    row.label,
    row.metric,
    row.estimate === null
      ? "–"
      : ["n", "missing", "numerator", "denominator"].includes(row.metric) &&
          Number.isInteger(row.estimate)
        ? String(row.estimate)
        : formatStatistic(row.estimate),
    row.lower === null ? "–" : formatStatistic(row.lower),
    row.upper === null ? "–" : formatStatistic(row.upper),
    row.p === null ? "–" : row.p < 0.001 ? "<0.001" : row.p.toFixed(3),
    row.unit,
  ]);
}
export function statisticNotes(r: StatisticalResult) {
  return [
    `Source: ${r.provenance.name}; table: ${r.provenance.table ?? "not specified"}; captured ${r.provenance.capturedAt}.`,
    `Reporting period: ${r.provenance.metadata?.reportingPeriod ?? "not documented"}; grain: ${r.provenance.metadata?.grain ?? "not documented"}; boundary version: ${r.provenance.metadata?.geographyVersion ?? "not documented"}.`,
    `Original source hash: ${r.provenance.originalHash ?? "dashboard snapshot"}; extraction hash: ${r.provenance.derivedHash ?? "not applicable"}.`,
    `Method: ${r.method}; confidence: ${r.parameters.confidence * 100}%. Missing/unavailable values: –.`,
    ...r.notes,
    `Input SHA-256: ${r.inputHash}. Engine: ${r.engine}. Result SHA-256: ${r.resultHash}.`,
  ];
}
// Prevent spreadsheet formula execution when a CSV is opened in Excel.
const csv = (value: string) =>
  '"' +
  (/^[\s]*[=+@\-\t\r]/.test(value) && !/^-?\d+(?:\.\d+)?(?:e[+-]?\d+)?$/i.test(value)
    ? "'" + value
    : value
  ).replaceAll('"', '""') +
  '"';
export function statisticCsv(r: StatisticalResult) {
  return [
    [r.title],
    statisticColumns,
    ...statisticCells(r),
    [],
    ...statisticNotes(r).map((n) => [n]),
  ]
    .map((row) => row.map(csv).join(","))
    .join("\r\n");
}
function wrap(s: string, width = 115) {
  const words = s.split(/\s+/).flatMap((word) => {
      const chars = Array.from(word);
      const pieces: string[] = [];
      for (let i = 0; i < chars.length; i += width) pieces.push(chars.slice(i, i + width).join(""));
      return pieces;
    }),
    out: string[] = [];
  let line = "";
  for (const w of words) {
    if (line.length + w.length > width) {
      out.push(line);
      line = "";
    }
    line += (line ? " " : "") + w;
  }
  if (line) out.push(line);
  return out;
}
export function statisticSvg(r: StatisticalResult) {
  const points = r.plot.filter((p) => p.estimate !== null);
  const notes = statisticNotes(r)
    .filter((n) => !/^(Input SHA-256|Original source hash|AI-assisted analysis output)/.test(n))
    .concat(
      "AI-assisted analysis using fixed statistical calculations; full provenance and software versions are in the calculation record.",
    )
    .flatMap((n) => wrap(n));
  const rowHeight = Math.max(
    72,
    ...points.map(
      (p) =>
        Math.max(
          wrap(p.label + ": " + p.metric, 43).length * 13,
          wrap(formatStatistic(p.estimate!) + " " + p.unit, 28).length * 12,
        ) + 14,
    ),
  );
  const height = 150 + Math.max(1, points.length) * rowHeight + notes.length * 18;
  const vals = points.flatMap((p) => [p.estimate!, p.lower ?? p.estimate!, p.upper ?? p.estimate!]);
  let lo = Math.min(...vals, 0),
    hi = Math.max(...vals, 0);
  if (!Number.isFinite(lo) || !Number.isFinite(hi) || lo === hi) {
    lo = 0;
    hi = 1;
  }
  const span = hi - lo;
  lo = lo < 0 ? lo - span * 0.05 : 0;
  hi += span * 0.05;
  const px = (n: number) => 380 + ((n - lo) / (hi - lo)) * 490;
  const title = wrap(r.title, 105);
  return `<svg xmlns="http://www.w3.org/2000/svg" width="1100" height="${height}" viewBox="0 0 1100 ${height}" role="img" aria-label="${xml(r.title)}"><rect width="100%" height="100%" fill="white"/><g font-family="DejaVu Sans,Arial,sans-serif" fill="#17252b">${title.map((t, i) => `<text x="24" y="${28 + i * 20}" font-size="17" font-weight="bold">${xml(t)}</text>`).join("")}<text x="24" y="78" font-size="12">Estimates and available confidence intervals; row-specific units are shown at right.</text>${points
    .map((p, i) => {
      const y = 115 + i * rowHeight;
      return `${wrap(p.label + ": " + p.metric, 43)
        .map((line, j) => `<text x="24" y="${y + j * 13}" font-size="12">${xml(line)}</text>`)
        .join(
          "",
        )}${p.lower !== null && p.upper !== null ? `<line x1="${px(p.lower)}" x2="${px(p.upper)}" y1="${y - 4}" y2="${y - 4}" stroke="#075e68" stroke-width="2"/>` : ""}<circle cx="${px(p.estimate!)}" cy="${y - 4}" r="4" fill="#075e68"/>${wrap(
        formatStatistic(p.estimate!) + " " + p.unit,
        28,
      )
        .map((line, j) => `<text x="900" y="${y + j * 12}" font-size="11">${xml(line)}</text>`)
        .join("")}`;
    })
    .join(
      "",
    )}<line x1="380" x2="870" y1="${100 + points.length * rowHeight}" y2="${100 + points.length * rowHeight}" stroke="#57616a"/>${[0, 0.25, 0.5, 0.75, 1].map((t) => `<text x="${380 + t * 490}" y="${119 + points.length * rowHeight}" font-size="10">${formatStatistic(lo + t * (hi - lo))}</text>`).join("")}${notes.map((n, i) => `<text x="24" y="${153 + points.length * rowHeight + i * 18}" font-size="11">${xml(n)}</text>`).join("")}</g></svg>`;
}
