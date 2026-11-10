import type { ColorBin } from "@/lib/charts/color-scale";

/** Match the renderer: shared edges belong to the next bin; the final finite edge is inclusive. */
export function mapBinLabel(bin: ColorBin, index: number, bins: readonly ColorBin[], suffix = ""): string {
  // Keep distinct decimal breaks distinct. Do not round fine quantiles to one decimal.
  const edge = (value: number) => value.toLocaleString("en-PH", { maximumSignificantDigits: 21 });
  if (!Number.isFinite(bin.max)) return `${edge(bin.min)}${suffix} or more`;
  if (bin.min === bin.max) return `${edge(bin.min)}${suffix}`;
  return `${edge(bin.min)}–${index < bins.length - 1 ? "<" : ""}${edge(bin.max)}${suffix}`;
}
