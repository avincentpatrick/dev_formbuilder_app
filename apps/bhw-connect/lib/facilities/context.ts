import { ACCEPTED_RELEASES as R } from "@/lib/catalog/definitions";
import { populationReading } from "@/lib/catalog/population-reading";
import type { CatalogDataset, CatalogIndicator, PublicCatalog } from "@/lib/catalog/types";
import { UUC_EDITIONS, editionBySlug } from "@/lib/uuc-phc/editions";

/** A scalar is usable only within its accepted source/unit and public state. */
function count(
  dataset: CatalogDataset | undefined,
  id: string,
  slug: string | readonly string[],
  unit: string,
) {
  const item = dataset?.indicators.find((i) => i.id === id);
  if (
    !item ||
    !["available", "partial"].includes(item.state) ||
    !(typeof slug === "string" ? [slug] : slug).includes(item.sourceSlug) ||
    item.unit !== unit ||
    item.values.length !== 1
  )
    return null;
  const value = item.values[0].value;
  if (!Number.isSafeInteger(value) || value < 0) return null;
  return item;
}
export function facilityContext(catalog: PublicCatalog) {
  const get = (id: string) => catalog.datasets.find((d) => d.id === id);
  const scope = catalog.selection;
  // No parent substitution or inferred district/barangay denominator.
  if (scope.mode !== "psgc" || scope.level === "barangay") return null;
  const facilities = count(get("nhfr"), "nhfr.facilities", R.nhfr, "facilities");
  const residents = count(get("population"), "population.residents", R.population, "people");
  const population = populationReading(residents ?? undefined, scope);
  const sourceDate = (id: string, slug: string) =>
    get(id)?.sources.find((s) => s.slug === slug)?.asOfDate;
  const density =
    scope.level === "citymun" &&
    facilities?.state === "available" &&
    residents?.state === "available" &&
    population &&
    !population.partial &&
    residents.values[0].value > 0 &&
    sourceDate("nhfr", R.nhfr) === "2026-09-01" &&
    sourceDate("population", R.population) === "2024-07-01"
      ? (facilities.values[0].value / residents.values[0].value) * 100000
      : null;
  const workforce = count(get("bhw"), "bhw.total", R.stepzero, "people");
  // Any UUC for PHC edition: the catalog reads the newest loaded one (lib/catalog/reader.ts), and
  // the line below names whichever year that was.
  const uuc = count(
    get("uuc-phc"),
    "uuc.listed",
    UUC_EDITIONS.map((e) => e.slug),
    "barangays",
  );
  const uucYear = uuc ? (editionBySlug(uuc.sourceSlug)?.year ?? null) : null;
  const denominator = uuc?.values[0].denominator;
  const uucShare =
    uuc?.state === "available" &&
    typeof denominator === "number" &&
    Number.isSafeInteger(denominator) &&
    denominator > 0 &&
    uuc.values[0].value <= denominator
      ? (uuc.values[0].value / denominator) * 100
      : null;
  const poverty = get("poverty");
  // Preserve official/SAE distinctions and the published uncertainty in the UI.
  const povertyItems =
    poverty?.indicators.filter(
      (i) =>
        ["available", "partial"].includes(i.state) &&
        i.unit === "%" &&
        i.values.length === 1 &&
        ((i.id === "poverty.official-incidence" && i.sourceSlug === R.povertyOfficial) ||
          (scope.level === "citymun" &&
            i.id === "poverty.incidence" &&
            i.sourceSlug === R.poverty)) &&
        Number.isFinite(i.values[0].value) &&
        i.values[0].value >= 0 &&
        i.values[0].value <= 100,
    ) ?? [];
  return {
    facilities,
    residents: population ? residents : null,
    population,
    density,
    workforce,
    uuc,
    uucYear,
    uucShare,
    povertyItems,
  };
}
export function publishedInterval(item: CatalogIndicator) {
  const { ciLow, ciHigh } = item.values[0];
  return typeof ciLow === "number" &&
    Number.isFinite(ciLow) &&
    typeof ciHigh === "number" &&
    Number.isFinite(ciHigh) &&
    ciLow <= ciHigh
    ? ([ciLow, ciHigh] as const)
    : null;
}
