import type { CatalogDataset, CatalogSelection } from "@/lib/catalog/types";
import {
  populationReading,
  POPULATION_DEFINITION,
  POPULATION_COVERAGE,
  POPULATION_COMPARISON,
  POPULATION_PARTIAL_REASON,
} from "@/lib/catalog/population-reading";
import { FigureCard } from "@/components/narrative/figure-card";
import { StatValue } from "@/components/home/stat-value";

export function PopulationOverview({
  dataset,
  selection,
  locationName,
}: {
  dataset: CatalogDataset;
  selection: CatalogSelection;
  locationName: string;
}) {
  const indicator = dataset.indicators.find((item) => item.id === "population.residents");
  const reading = populationReading(indicator, selection);
  const state = indicator?.state ?? dataset.state;
  const status =
    state === "failed"
      ? "Population could not be loaded or verified. Please try again; this is not a reported zero."
      : state === "suppressed"
        ? "Population is withheld under the applicable publication rules."
        : selection.mode === "all"
          ? "Choose a location above to see its accepted census count and coverage. This availability view is not a national population figure."
          : state === "unsupported"
            ? "This release does not support a population count for this selection. No count from a larger area is substituted."
            : "No usable accepted population count is available for this selection. Missing data does not mean zero people; no count from another area is substituted.";
  return (
    <div className="space-y-4" data-testid="population-overview">
      <FigureCard
        title="How many people are recorded here?"
        caption={`${locationName} · PSA Census of Population · 2024 · people`}
        headline="Read the count together with its geographic coverage and census year."
      >
        <p className="mb-4 text-sm">{POPULATION_DEFINITION}</p>
        {reading ? (
          <div className="space-y-3">
            <p className="text-sm font-medium">{reading.label}</p>
            <StatValue value={reading.value} />
            {reading.partial && (
              <p role="status" className="rounded border border-border bg-surface p-3 text-sm">
                {POPULATION_COVERAGE} Do not use this as the full population of the selected area.
              </p>
            )}
            {indicator?.reason && indicator.reason !== POPULATION_PARTIAL_REASON && (
              <p className="text-sm">{indicator.reason}</p>
            )}
            {indicator?.coverage && (
              <p className="text-sm">
                {indicator.coverage.label}: {indicator.coverage.covered} of{" "}
                {indicator.coverage.total}.
              </p>
            )}
          </div>
        ) : (
          <p role="status" className="rounded border border-border bg-surface p-4">
            {status}
          </p>
        )}
        {!reading?.partial && <p className="mt-4 text-sm text-muted">{POPULATION_COVERAGE}</p>}
      </FigureCard>
      <p className="text-sm">{POPULATION_COMPARISON}</p>
      <details className="rounded-lg border border-border p-4 text-sm">
        <summary className="cursor-pointer font-medium">
          Which places and population details are covered?
        </summary>
        <div className="mt-3 space-y-3">
          <p>
            Census place names were matched to the app&apos;s reporting geography. Some
            municipalities could not be matched, and historical boundaries differ. A matched total
            is not proof of complete geographic coverage.
          </p>
          <p>
            Barangay counts are not supplied by this accepted card. A congressional-district total
            requires complete compatible counts for its mapped whole areas. Municipal counts are not
            allocated to split cities or barangays.
          </p>
          <p>
            This card provides one population count for 2024. It does not provide age or sex
            breakdowns, household counts, growth rates or a population trend. Stored 2020 census
            records are not silently mixed with this release.
          </p>
          <p>
            The map and exact data remain available below. Source dates describe the source records;
            they do not make this a current-year estimate.
          </p>
        </div>
      </details>
    </div>
  );
}
