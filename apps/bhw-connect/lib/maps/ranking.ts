import type { MapRow } from "./prototype";

/** Counts only. Missing/invalid observations have no rank; geometry never gates inclusion. */
export function rankedCounts(rows: readonly MapRow[]) {
  const ranked = rows
    .filter(
      (row): row is MapRow & { value: number } =>
        row.value !== null && Number.isFinite(row.value) && row.value >= 0,
    )
    .sort(
      (a, b) =>
        b.value - a.value || a.name.localeCompare(b.name, "en") || a.code.localeCompare(b.code),
    );
  return { ranked, excluded: rows.length - ranked.length };
}
