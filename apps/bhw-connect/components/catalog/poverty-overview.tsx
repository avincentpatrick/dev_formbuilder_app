import type { CatalogDataset, CatalogSelection } from "@/lib/catalog/types";
import {
  povertyReading,
  POVERTY_DEFINITION,
  POVERTY_SCOPE,
  POVERTY_UNCERTAINTY,
} from "@/lib/catalog/poverty-reading";
import { FigureCard } from "@/components/narrative/figure-card";
import { StatValue } from "@/components/home/stat-value";

export function PovertyOverview({
  dataset,
  selection,
  locationName,
}: {
  dataset: CatalogDataset;
  selection: CatalogSelection;
  locationName: string;
}) {
  const indicator = dataset.indicators.find((item) => item.id === "poverty.incidence");
  const local = selection.mode === "psgc" && selection.level === "citymun";
  const reading = local ? povertyReading(indicator) : null;
  const state = indicator?.state ?? dataset.state;
  const status =
    state === "failed"
      ? "The poverty estimate could not be loaded or verified. Please try again; this is not a reported zero."
      : state === "suppressed"
        ? "The poverty estimate is withheld under the applicable publication rules."
        : selection.mode === "all"
          ? "Choose a region, then a province and a city or municipality using the location controls above. No national estimate is shown."
          : !local
            ? "There is no poverty estimate for this geographic level in this release. Choose a supported city or municipality; no local value is replaced with a higher-area figure."
            : "No usable accepted poverty estimate is available for this place. Missing estimates are not zero.";
  return (
    <div className="space-y-4" data-testid="poverty-overview">
      <FigureCard
        title="What does the poverty estimate tell us?"
        caption={`${locationName} · PSA Small Area Estimates · 2023 · people`}
        headline="Use the estimate together with its uncertainty and source coverage. It does not identify individual households or explain why poverty occurs."
      >
        <p className="mb-4 text-sm">{POVERTY_DEFINITION}</p>
        {reading ? (
          <div className="space-y-3">
            <p className="text-sm text-muted">
              Estimated share of people below the poverty threshold
            </p>
            <StatValue value={reading.estimate} />
            <p className="text-sm font-medium">
              {reading.interval
                ? `Published 90% confidence interval: ${reading.interval}`
                : "The published 90% confidence interval is unavailable or unusable."}
            </p>
            {indicator?.reason && (
              <p role="status" className="text-sm">
                {indicator.reason}
              </p>
            )}
          </div>
        ) : (
          <p role="status" className="rounded border border-border bg-surface p-4">
            {status}
          </p>
        )}
        <p className="mt-4 text-sm text-muted">{POVERTY_UNCERTAINTY}</p>
      </FigureCard>
      <p className="text-sm">{POVERTY_SCOPE}</p>
      <details className="rounded-lg border border-border p-4 text-sm">
        <summary className="cursor-pointer font-medium">
          Which places and years are covered?
        </summary>
        <div className="mt-3 space-y-3">
          <p>
            The source excludes highly urbanized cities from this municipal estimate series. Known
            gaps also include the BARMM Special Geographic Areas, Pateros and Kalayaan. An absent
            estimate does not mean nobody experiences poverty.
          </p>
          <p>
            Manila’s source districts and the app’s reporting geography do not match completely. Do
            not interpret partial district records as a complete Manila estimate.
          </p>
          <p>
            The source release also contains 2018 and 2021 back-estimates. This card uses 2023 only;
            it does not show change over time. Published confidence bounds are retained even when
            they extend below 0% or above 100%.
          </p>
          <p>
            The map compares municipal estimates within a province. Higher-level map areas are for
            navigation, not provincial or national poverty rates. Poverty is separate from the BHW
            workforce map.
          </p>
        </div>
      </details>
    </div>
  );
}
