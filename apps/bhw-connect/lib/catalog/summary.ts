import type { CatalogDataset, DataState, PublicCatalog } from "./types";

export const hasAvailableValue = (state: DataState): boolean =>
  state === "available" || state === "partial";

export function datasetState(states: readonly DataState[]): DataState {
  const available = states.filter(hasAvailableValue).length;
  if (available) return states.every((s) => s === "available") ? "available" : "partial";
  if (states.includes("failed")) return "failed";
  if (states.includes("suppressed")) return "suppressed";
  if (states.every((s) => s === "unsupported")) return "unsupported";
  return "unavailable";
}

/** Only successfully available measures contribute to freshness. Null dates never become "today". */
export function summarizeCatalog(datasets: readonly CatalogDataset[]): PublicCatalog["overview"] {
  const indicators = datasets.flatMap((d) => d.indicators);
  const sources = new Map(
    datasets.flatMap((d) => {
      const used = new Set(
        d.indicators.filter((i) => hasAvailableValue(i.state)).map((i) => i.sourceSlug),
      );
      return d.sources.filter((s) => used.has(s.slug)).map((s) => [s.slug, s] as const);
    }),
  );
  const dates = [...sources.values()]
    .flatMap((s) => (s.asOfDate ? [s.asOfDate] : []))
    .sort((a, b) => Date.parse(a) - Date.parse(b));
  const updates = [...sources.values()]
    .flatMap((s) => (s.lastUpdatedAt ? [s.lastUpdatedAt] : []))
    .sort((a, b) => Date.parse(a) - Date.parse(b));
  return {
    availableDatasets: datasets.filter((d) => d.availableIndicators > 0).length,
    availableIndicators: new Set(
      indicators.filter((i) => hasAvailableValue(i.state)).map((i) => i.id),
    ).size,
    totalDatasets: datasets.length,
    totalIndicators: new Set(indicators.map((i) => i.id)).size,
    failedIndicators: indicators.filter((i) => i.state === "failed").length,
    suppressedIndicators: indicators.filter((i) => i.state === "suppressed").length,
    coverage: datasets.flatMap((d) =>
      d.indicators
        .filter((i) => i.reportedAreaRecords !== null)
        .map((i) => ({
          datasetId: d.id,
          indicatorId: i.id,
          reportedAreaRecords: i.reportedAreaRecords,
        })),
    ),
    freshness: {
      oldestSourceDate: dates[0] ?? null,
      newestSourceDate: dates.at(-1) ?? null,
      latestUpdate: updates.at(-1) ?? null,
      sourcesWithUnknownDate: [...sources.values()].filter((s) => !s.asOfDate).length,
    },
  };
}
