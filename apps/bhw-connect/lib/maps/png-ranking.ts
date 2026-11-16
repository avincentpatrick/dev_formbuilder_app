import type { datasetMapExport } from "./export";
import { rankedCounts } from "./ranking";
import { supportsCircles, symbolMaximum } from "./symbols";
import { valueFilterState } from "./value-filter";

/** A bounded image summary, not a replacement for the complete exported table. */
export function pngRanking(data: ReturnType<typeof datasetMapExport>) {
  if (!supportsCircles(data.selection) || data.legend.method === "not-applicable") return null;
  const rows = data.rows.map(({ code, name, value }) => ({ code, name, value }));
  const { ranked, excluded } = rankedCounts(rows);
  const included = ranked.filter((row) => valueFilterState(row, data.selection) === "Included");
  const shown = included.slice(0, 20);
  const missing = new Set(
    data.rows.filter((row) => row.geometry === "missing").map((row) => row.code),
  );
  return {
    rows: shown.map((row) => ({ ...row, missingGeometry: missing.has(row.code) })),
    maximum: symbolMaximum(rows),
    note: `${shown.length} of ${included.length} included child areas shown; ${included.length - shown.length} omitted from this image. ${ranked.length - included.length} excluded by range; ${excluded} without a usable count. Largest counts first; ties ordered by name then code. Bars start at zero; scale uses all child areas. Areas without shapes remain eligible. A low count alone does not show a shortage. JSON contains every area.`,
  };
}
