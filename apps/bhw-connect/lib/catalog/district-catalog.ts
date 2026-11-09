import "server-only";
import { CATALOG } from "./definitions";
import { getPublicCatalog } from "./catalog";
import { createDistrictReader } from "./district-reader";
import { datasetState, hasAvailableValue, summarizeCatalog } from "./summary";
import type { CatalogDataset, CatalogIndicator, PublicCatalog } from "./types";
import type { DistrictGeography } from "./district-geography";
import type { CatalogReader } from "./reader";

type Geography = Extract<DistrictGeography, { state: "available" }>;
const additive = (id: string) =>
  [
    "bhw.total",
    "bhw.registered",
    "bhw.registered-accredited",
    "bhw.nonregistered",
    "bhw.validated-profiles",
    "population.residents",
  ].includes(id) ||
  id.startsWith("nhfr.") ||
  id.startsWith("uuc.") ||
  id.startsWith("fhsis.workforce.");
const ratio = (id: string) =>
  ["bhw.declared-accreditation", "bhw.profile-accreditation"].includes(id) ||
  id.startsWith("bhw.training.");
const blank = (
  item: CatalogIndicator,
  state: CatalogIndicator["state"],
  reason: string,
): CatalogIndicator => ({
  ...item,
  state,
  reason,
  values: [],
  reportedAreaRecords: null,
  coverage: undefined,
});

/** Only explicitly approved additive counts or compatible BHW numerator/denominator ratios. */
export function combineDistrictIndicator(
  items: CatalogIndicator[],
  mappingUncertain: boolean,
  mixedGrain: boolean,
): CatalogIndicator {
  const first = items[0];
  if (
    items.some(
      (i) => i.sourceSlug !== first.sourceSlug || i.id !== first.id || i.unit !== first.unit,
    )
  )
    return blank(first, "failed", "Constituent definitions or accepted sources do not agree.");
  if (items.some((i) => i.state === "suppressed"))
    return blank(
      first,
      "suppressed",
      "A constituent measure is protected. No district total is released from protected components.",
    );
  if (items.some((i) => i.state === "failed"))
    return blank(
      first,
      "failed",
      "A constituent read failed. A partial response is not treated as missing source data.",
    );
  if (items.some((i) => i.state === "unsupported"))
    return blank(
      first,
      "unsupported",
      "This measure cannot represent every mapped member. Municipal figures are never allocated to a split city or barangay.",
    );
  if (!additive(first.id) && !ratio(first.id))
    return blank(
      first,
      "unsupported",
      "No approved district calculation exists for this measure. Rates, averages, medians and published poverty estimates are not summed or averaged.",
    );
  const available = items.filter((i) => hasAvailableValue(i.state) && i.values.length === 1);
  if (!available.length)
    return blank(first, "unavailable", "No accepted constituent figures are available.");
  const complete =
    available.length === items.length &&
    items.every((i) => i.state === "available") &&
    !mappingUncertain;
  if (
    (ratio(first.id) || first.id === "population.residents" || first.id.startsWith("fhsis.")) &&
    !complete
  )
    return blank(
      first,
      "unavailable",
      "This calculation requires complete compatible constituent data and verified mapping coverage.",
    );
  if (ratio(first.id)) {
    const values = available.map((i) => i.values[0]);
    if (
      values.some(
        (v) =>
          v.numerator == null ||
          v.denominator == null ||
          !Number.isFinite(v.numerator) ||
          !Number.isFinite(v.denominator) ||
          v.numerator < 0 ||
          v.denominator < 0,
      )
    )
      return blank(
        first,
        "unavailable",
        "Compatible numerators and denominators are required; published rates are not averaged.",
      );
    const numerator = values.reduce((n, v) => n + v.numerator!, 0),
      denominator = values.reduce((n, v) => n + v.denominator!, 0);
    if (!denominator)
      return blank(
        first,
        "unavailable",
        "The compatible denominator is zero; no rate is calculated.",
      );
    return {
      ...first,
      state: "available",
      aggregation: "ratio",
      reason:
        "Calculated as the sum of compatible accepted numerators divided by the sum of their denominators × 100; not an average of rates. Source intervals are not combined.",
      values: [{ value: (numerator / denominator) * 100, numerator, denominator }],
      reportedAreaRecords: available.length,
      coverage: {
        label: "Mapped units with compatible inputs",
        covered: available.length,
        total: items.length,
      },
    };
  }
  if (available.some((i) => !Number.isFinite(i.values[0].value) || i.values[0].value < 0))
    return blank(first, "failed", "The constituent count is invalid.");
  const value = available.reduce((n, i) => n + i.values[0].value, 0);
  if (!Number.isFinite(value)) return blank(first, "failed", "The constituent total is invalid.");
  // In mixed municipal/leaf UUC scopes, a leaf's not-listed status is not a missing qualification
  // record we may silently turn into zero. Missing routes remain a labeled partial count.
  const denominators = available.map((i) => i.values[0].denominator);
  const denominator = denominators.every((n) => n != null)
    ? denominators.reduce<number>((n, v) => n + v!, 0)
    : undefined;
  return {
    ...first,
    state: complete ? "available" : "partial",
    aggregation: "sum",
    values: [{ value, ...(denominator === undefined ? {} : { denominator }) }],
    reportedAreaRecords: available.length,
    coverage: {
      label: mixedGrain
        ? "Non-overlapping mapped city/barangay units reporting this measure"
        : "Mapped constituent units reporting this measure",
      covered: available.length,
      total: items.length,
    },
    reason: `${complete ? "Total" : "Partial total"} calculated from non-overlapping mapped members in the accepted release. ${available.length} of ${items.length} mapped units reported this measure. ${mappingUncertain ? "Mapping gaps remain; this is not a verified full-district total. " : ""}${first.id.startsWith("nhfr.") ? "Split-city contributions include attributed facilities only; unassigned facilities are excluded. " : ""}${items.some((i) => i.state === "partial") ? "Some constituent figures have partial source coverage. " : ""}Different sources retain their own denominators.`,
  };
}

export async function getDistrictCatalog(
  geography: Geography,
  reader: CatalogReader = createDistrictReader(geography.scope),
): Promise<PublicCatalog> {
  const { scope, selection } = geography;
  // An index has no geographic facts. Unresolved or conflicting mapping never leaks a whole city.
  if (!geography.current || scope.issues.length || !scope.units.length) {
    const datasets: CatalogDataset[] = CATALOG.map((d) => ({
      id: d.id,
      title: d.title,
      category: d.category,
      state: "unsupported",
      availableIndicators: 0,
      totalIndicators: d.indicators.length,
      supportedLevels: [],
      detailHref: null,
      sources: [],
      indicators: d.indicators.map((i) => ({
        id: i.id,
        label: i.label,
        unit: i.unit,
        sourceSlug: i.source,
        state: "unsupported",
        reason:
          "Verified, non-conflicting district membership is required before figures can be shown.",
        values: [],
        reportedAreaRecords: null,
      })),
    }));
    return { selection, datasets, overview: summarizeCatalog(datasets) };
  }
  // Sequential constituent composition keeps the existing four-indicator I/O bound. Reads within
  // the shared reader batch ten peers per table and reuse municipal facility attribution queries.
  const catalogs: PublicCatalog[] = [];
  for (const unit of scope.units)
    catalogs.push(
      await getPublicCatalog({ mode: "psgc", level: unit.level, code: unit.code }, reader),
    );
  const mappingUncertain =
    !selection.barangayCode &&
    (!selection.citymunCode ||
      !scope.cities.find((c) => c.code === selection.citymunCode)?.whole) &&
    (geography.ownGaps > 0 ||
      geography.globalGaps.cities > 0 ||
      geography.globalGaps.barangays > 0);
  const exactScope = catalogs.length === 1 && !mappingUncertain;
  const mixedGrain = new Set(scope.units.map((u) => u.level)).size > 1;
  const datasets = catalogs[0].datasets.map((dataset, index): CatalogDataset => {
    const indicators = dataset.indicators.map((item, i) =>
      exactScope
        ? {
            ...item,
            reason: [
              item.reason,
              "Source figure for this exact mapped constituent; district scope is retained.",
            ]
              .filter(Boolean)
              .join(" "),
          }
        : combineDistrictIndicator(
            catalogs.map((c) => c.datasets[index].indicators[i]),
            mappingUncertain,
            mixedGrain,
          ),
    );
    const sources = [
      ...new Map(
        catalogs.flatMap((c) => c.datasets[index].sources.map((s) => [s.slug, s] as const)),
      ).values(),
    ];
    return {
      ...dataset,
      indicators,
      sources,
      detailHref: null,
      state: datasetState(indicators.map((i) => i.state)),
      availableIndicators: indicators.filter((i) => hasAvailableValue(i.state)).length,
    };
  });
  return { selection, datasets, overview: summarizeCatalog(datasets) };
}
