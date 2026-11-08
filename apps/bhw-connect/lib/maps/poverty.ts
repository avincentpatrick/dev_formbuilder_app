import type { MapRow } from "./prototype";
import type { ColorBin } from "@/lib/charts/color-scale";
import { sequentialRamp } from "@/lib/charts/palette";

/** Fixed percentage-point breaks, stable across provinces; not area-relative quantiles. */
export const POVERTY_BINS: ColorBin[] = [0, 10, 20, 30, 40].map((min, index) => ({
  min,
  max: index === 4 ? 100 : min + 10,
  color: sequentialRamp[Math.round((index * (sequentialRamp.length - 1)) / 4)],
}));
const finite = (n: unknown): n is number => typeof n === "number" && Number.isFinite(n);
/** Published municipal estimates only. Missing/HUC exclusions are never coerced to zero. */
export function joinPovertyRows(
  children: { geo_code: string; geo_name: string }[],
  rows: Record<string, unknown>[],
): MapRow[] {
  const index = new Map<string, Record<string, unknown>[]>();
  for (const row of rows) {
    if (typeof row.geo_code !== "string") continue;
    index.set(row.geo_code, [...(index.get(row.geo_code) ?? []), row]);
  }
  return children.map((child) => {
    const matches = index.get(child.geo_code) ?? [];
    const row = matches.length === 1 ? matches[0] : undefined;
    const value =
      finite(row?.poverty_incidence) && row.poverty_incidence >= 0 && row.poverty_incidence <= 100
        ? row.poverty_incidence
        : null;
    const validInterval =
      value !== null && finite(row?.ci_low) && finite(row?.ci_high) && row.ci_low <= row.ci_high;
    return {
      code: child.geo_code,
      name: child.geo_name,
      value,
      // Preserve published bounds, including values outside 0–100; never clamp uncertainty.
      ciLow: validInterval ? (row!.ci_low as number) : null,
      ciHigh: validInterval ? (row!.ci_high as number) : null,
    };
  });
}
