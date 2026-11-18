import type { MapRow } from "@/lib/maps/prototype";
import { valueFilterState, type CountRange } from "@/lib/maps/value-filter";

/** Public child-area observations only. Geometry and display rounding never
 * determine eligibility or ties; no parent rates/means are recomputed. */
export function peerDistribution(rows: readonly MapRow[], unit: string, range: CountRange = {}) {
  const status = (row: MapRow) => {
    if (
      row.value === null ||
      !Number.isFinite(row.value) ||
      row.value < 0 ||
      (unit === "%" && row.value > 100)
    )
      return "unavailable" as const;
    if (row.smallSample) return "small-sample" as const;
    if (valueFilterState(row, range) === "Excluded by range") return "filtered" as const;
    return "eligible" as const;
  };
  const validUniverse = new Set(rows.map((row) => row.code)).size === rows.length;
  const statuses = rows.map((row) => ({ code: row.code, status: status(row) }));
  const eligible = validUniverse
    ? rows
        .filter((row): row is MapRow & { value: number } => status(row) === "eligible")
        .sort(
          (a, b) =>
            b.value - a.value || a.name.localeCompare(b.name, "en") || a.code.localeCompare(b.code),
        )
    : [];
  let rank = 0;
  const frequencies = new Map<number, number>();
  for (const row of eligible) frequencies.set(row.value, (frequencies.get(row.value) ?? 0) + 1);
  const ranked = eligible.map((row, i) => {
    if (i === 0 || row.value !== eligible[i - 1].value) rank = i + 1;
    return { ...row, rank, tied: frequencies.get(row.value)! };
  });
  return {
    ranked,
    statuses,
    validUniverse,
    total: rows.length,
    unavailable: statuses.filter((r) => r.status === "unavailable").length,
    smallSample: statuses.filter((r) => r.status === "small-sample").length,
    filtered: statuses.filter((r) => r.status === "filtered").length,
  };
}
