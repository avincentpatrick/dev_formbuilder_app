import type { ColorBin } from "@/lib/charts/color-scale";

/** Match the renderer: shared edges belong to the next bin; the final finite edge is inclusive. */
export function mapBinLabel(
  bin: ColorBin,
  index: number,
  bins: readonly ColorBin[],
  suffix = "",
): string {
  // Trim floating-point noise, increasing precision when needed to keep every edge distinct.
  // Only labels change; bin membership and exported numeric bounds retain full precision.
  const edges = [...new Set(bins.flatMap((item) => [item.min, item.max]).filter(Number.isFinite))];
  let precision = 12;
  while (
    precision < 21 &&
    new Set(edges.map((value) => value.toPrecision(precision))).size < edges.length
  )
    precision++;
  const edge = (value: number) =>
    value.toLocaleString("en-PH", {
      maximumSignificantDigits: Number.isInteger(value) ? 21 : precision,
    });
  if (!Number.isFinite(bin.max)) return `${edge(bin.min)}${suffix} or more`;
  if (bin.min === bin.max) return `${edge(bin.min)}${suffix}`;
  return `${edge(bin.min)}–${index < bins.length - 1 ? "<" : ""}${edge(bin.max)}${suffix}`;
}
