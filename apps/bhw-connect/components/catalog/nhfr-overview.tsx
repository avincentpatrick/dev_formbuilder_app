import Link from "next/link";
import { countAndShare, shareOf } from "@/lib/format";
import type { CatalogDataset, CatalogSelection } from "@/lib/catalog/types";
import {
  nhfrReading,
  NHFR_DEFINITION,
  NHFR_BEDS,
  NHFR_PRESENCE,
  NHFR_ATTRIBUTION,
} from "@/lib/catalog/nhfr-reading";
import { FigureCard } from "@/components/narrative/figure-card";
import { StatValue } from "@/components/home/stat-value";

export function NhfrOverview({
  dataset,
  selection,
  locationName,
}: {
  dataset: CatalogDataset;
  selection: CatalogSelection;
  locationName: string;
}) {
  const measures = [
    ["nhfr.facilities", "Registered facilities", NHFR_DEFINITION],
    ["nhfr.beds", "Recorded bed capacity", NHFR_BEDS],
    ["nhfr.barangay-presence", "Barangays with an attributed facility", NHFR_PRESENCE],
  ];
  return (
    <div className="space-y-4" data-testid="nhfr-overview">
      <FigureCard
        title="What health facilities are recorded here?"
        caption={`${locationName} · DOH National Health Facility Registry · September 2026 snapshot`}
        headline="Read facility counts, beds and barangay presence separately. They do not show staffing, service quality or whether capacity is enough."
      >
        {selection.mode === "all" ? (
          <p role="status">
            Choose a location above to see its figures. This availability view is not a national
            facility total.
          </p>
        ) : (
          <dl className="space-y-5">
            {measures.map(([id, label, definition]) => {
              const indicator = dataset.indicators.find((item) => item.id === id);
              const reading = nhfrReading(indicator, selection);
              const state = indicator?.state ?? dataset.state;
              const status =
                state === "failed"
                  ? "Could not be loaded or verified. Please try again; this is not a reported zero."
                  : state === "suppressed"
                    ? "Withheld under the applicable publication rules."
                    : state === "unsupported"
                      ? "Not supported for this selection. No larger area's figure is substituted."
                      : "No usable accepted figure is available. Missing data does not mean zero.";
              return (
                <div key={id}>
                  <dt className="text-sm font-medium">{label}</dt>
                  <dd className="mt-1">
                    {reading ? (
                      <>
                        {reading.partial && (
                          <p className="font-medium">Partial total — incomplete coverage</p>
                        )}
                        <StatValue value={reading.display} />
                        {id === "nhfr.barangay-presence" &&
                          indicator?.state === "available" &&
                          (indicator.values[0].denominator ?? 0) > 0 && (
                            <div
                              aria-hidden="true"
                              className="mt-3 h-3 overflow-hidden rounded bg-surface"
                            >
                              <div
                                className="h-full bg-accent"
                                style={{
                                  width: `${(100 * indicator.values[0].value) / indicator.values[0].denominator!}%`,
                                }}
                              />
                            </div>
                          )}
                        {reading.denominatorNote && (
                          <p className="mt-2 text-sm">{reading.denominatorNote}</p>
                        )}
                        {indicator?.coverage && (
                          <p className="mt-2 text-sm">
                            {indicator.coverage.label}: {indicator.coverage.covered} of{" "}
                            {indicator.coverage.total}.
                          </p>
                        )}
                        {indicator?.reason && <p className="mt-2 text-sm">{indicator.reason}</p>}
                      </>
                    ) : (
                      <p role="status" className="text-sm">
                        {status}
                      </p>
                    )}
                    <p className="mt-2 text-sm text-muted">{definition}</p>
                  </dd>
                </div>
              );
            })}
          </dl>
        )}
        <p className="mt-4 text-sm">{NHFR_ATTRIBUTION}</p>
      </FigureCard>
      {selection.mode !== "all" && (
        <section
          aria-label="Facility ownership"
          className="rounded-lg border border-border p-4 sm:p-6"
        >
          <h3 className="font-semibold">Who owns the facilities?</h3>
          <p className="mt-2 text-sm text-muted">
            Government and private describe ownership, not cost or eligibility for care.
          </p>
          <dl className="mt-3 flex flex-wrap gap-6">
            {["government", "private"].map((key) => {
              const total = dataset.indicators.find((i) => i.id === "nhfr.facilities");
              const item = dataset.indicators.find((i) => i.id === `nhfr.${key}`);
              const reading = nhfrReading(item, selection);
              const totalReading = nhfrReading(total, selection);
              const canShare =
                reading &&
                totalReading &&
                item!.state === "available" &&
                total!.state === "available" &&
                item!.values[0].value <= total!.values[0].value;
              return (
                <div key={key}>
                  <dt className="text-sm text-muted">
                    {key === "government" ? "Government" : "Private"}
                  </dt>
                  <dd className="mt-1 whitespace-nowrap font-semibold tabular-nums">
                    {reading
                      ? canShare
                        ? countAndShare(
                            item!.values[0].value,
                            shareOf(item!.values[0].value, total!.values[0].value),
                          )
                        : reading.display
                      : "Unavailable"}
                  </dd>
                  {reading?.partial && (
                    <dd className="text-sm">Partial total — see exact data and coverage below.</dd>
                  )}
                </div>
              );
            })}
          </dl>
        </section>
      )}
      <details className="rounded-lg border border-border p-4 text-sm">
        <summary className="cursor-pointer font-medium">
          How should I read the types, ownership and source?
        </summary>
        <div className="mt-3 space-y-3">
          <p>{NHFR_DEFINITION}</p>
          <p>
            Government and private describe facility ownership. The four headline types are selected
            categories, not all facility types. The full type breakdown is included below for
            national, regional, provincial and municipal selections.
          </p>
          <p>
            Missing licence status means not stated, not unlicensed. This snapshot does not
            establish current licensing or service availability.
          </p>
          <p>
            Counts follow the app&apos;s reporting geography. Sulu is grouped under Region IX,
            as the source names it, through the documented code mapping. Do not add parent
            areas to their children or compare counts as a measure of need.
          </p>
          <p>
            <Link href="/facilities/methodology" className="underline">
              Source and counting methodology
            </Link>
            . Source dates below describe the records, not when this page was deployed.
          </p>
        </div>
      </details>
    </div>
  );
}
