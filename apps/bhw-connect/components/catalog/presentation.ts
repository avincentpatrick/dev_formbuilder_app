import type {
  CatalogDataset,
  CatalogIndicator,
  CatalogSelection,
  PublicValue,
} from "@/lib/catalog/types";
import { hasAvailableValue } from "@/lib/catalog/summary";

export const HEADLINES: Record<CatalogDataset["id"], readonly string[]> = {
  bhw: ["bhw.total", "bhw.validated-profiles", "bhw.profile-accreditation"],
  nhfr: ["nhfr.facilities", "nhfr.type.hospital", "nhfr.barangay-presence"],
  "uuc-phc": ["uuc.listed", "uuc.route.health"],
  fhsis: ["fhsis.fic", "fhsis.anc4", "fhsis.water_basic"],
  population: ["population.residents"],
  poverty: ["poverty.incidence"],
};
export function previewIndicators(dataset: CatalogDataset): CatalogIndicator[] {
  return HEADLINES[dataset.id].flatMap((id) => {
    const indicator = dataset.indicators.find((i) => i.id === id);
    return indicator && hasAvailableValue(indicator.state) && indicator.values.length
      ? [indicator]
      : [];
  });
}
export function formatValue(value: PublicValue, unit: string): string {
  const formatted = value.value.toLocaleString("en-PH", { maximumFractionDigits: 2 });
  if (unit === "%" || unit.startsWith("percent")) return `${formatted}%`;
  const singular: Record<string, string> = {
    facilities: "facility",
    barangays: "barangay",
    people: "person",
    beds: "bed",
    years: "year",
  };
  return `${formatted} ${value.value === 1 ? (singular[unit] ?? unit) : unit}`;
}
export function sourceDate(date: string | null): string {
  return date
    ? new Intl.DateTimeFormat("en-PH", {
        day: "numeric",
        month: "short",
        year: "numeric",
        timeZone: "UTC",
      }).format(new Date(date))
    : "Date not provided";
}
export function omissionReason(dataset: CatalogDataset, selection: CatalogSelection): string {
  if (dataset.state === "failed") return "Could not load the figures. Please try again.";
  if (dataset.state === "suppressed")
    return "Measures are withheld under the catalog’s privacy rules.";
  if (dataset.state === "unsupported")
    return `${dataset.title} is not available at ${selection.mode === "psgc" ? (selection.level === "national" ? "national" : selection.level === "citymun" ? "city/municipality" : selection.level) : "this"} level in the catalog.`;
  return "No usable figures are currently available for this selection.";
}
