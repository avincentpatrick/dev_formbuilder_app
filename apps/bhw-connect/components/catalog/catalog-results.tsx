import Link from "next/link";
import type { CatalogDataset, PublicCatalog } from "@/lib/catalog/types";
import { catalogHref } from "@/lib/catalog/navigation";
import { hasAvailableValue } from "@/lib/catalog/summary";
import {
  formatValue,
  HEADLINES,
  omissionReason,
  previewIndicators,
  sourceDate,
} from "./presentation";

export function SourceDates({ dataset }: { dataset: CatalogDataset }) {
  return (
    <ul className="mt-4 space-y-2 text-xs text-muted">
      {dataset.sources.map((source) => (
        <li key={source.slug}>
          <span className="block font-medium">{source.sourceName ?? source.name}</span>Source date:{" "}
          {sourceDate(source.asOfDate)} · Updated: {sourceDate(source.lastUpdatedAt)}
        </li>
      ))}
    </ul>
  );
}

export function CatalogResults({
  catalog,
  locationName,
}: {
  catalog: PublicCatalog;
  locationName: string;
}) {
  const visible = catalog.datasets.filter((d) => d.availableIndicators > 0);
  const omitted = catalog.datasets.filter((d) => d.availableIndicators === 0);
  const freshness = catalog.overview.freshness;
  return (
    <div className="space-y-6">
      <section aria-label="Catalog overview" className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Overview
          label="Available datasets"
          value={String(catalog.overview.availableDatasets)}
          note="of 6 logical datasets"
        />
        <Overview
          label="Available indicators"
          value={String(catalog.overview.availableIndicators)}
          note={`of ${catalog.overview.totalIndicators} cataloged measures`}
        />
        <Overview
          label="Geographic coverage"
          value="By source"
          note={
            catalog.selection.mode === "all"
              ? "Reported areas across supported levels"
              : locationName
          }
        />
        <Overview
          label="Latest source date"
          value={freshness.newestSourceDate ? sourceDate(freshness.newestSourceDate) : "Unknown"}
          note={
            freshness.oldestSourceDate
              ? `Earliest: ${sourceDate(freshness.oldestSourceDate)}`
              : "No dated figures available"
          }
        />
      </section>
      {freshness.sourcesWithUnknownDate > 0 && (
        <p className="text-sm text-muted">
          {freshness.sourcesWithUnknownDate} contributing source(s) have no reference date.
        </p>
      )}
      {catalog.overview.failedIndicators > 0 && (
        <p role="status" className="rounded-lg border border-border bg-surface p-4 text-sm">
          Some figures could not be loaded. Available results are shown; a failed read does not mean
          the source reported no data.{" "}
          <Link prefetch={false} href={catalogHref(catalog.selection)} className="underline">
            Try again
          </Link>
          .
        </p>
      )}
      <details className="rounded-lg border border-border p-4 text-sm">
        <summary className="cursor-pointer font-medium">Geographic coverage by source</summary>
        <p className="mt-3 text-muted">
          Each measure has its own coverage. Reported areas can overlap across levels, so these
          counts are not a percentage of the country or its population. Facility presence and UUC
          listing shares are shown separately on their cards.
        </p>
        <ul className="mt-3 space-y-2">
          {catalog.datasets.map((dataset) => {
            const primary = dataset.indicators.find((i) => i.id === HEADLINES[dataset.id][0]);
            return (
              <li key={dataset.id}>
                <strong>{dataset.title}</strong> · {primary?.label}:{" "}
                {primary?.reportedAreaRecords === null || primary?.reportedAreaRecords === undefined
                  ? "Coverage unknown"
                  : `${primary.reportedAreaRecords.toLocaleString("en-PH")} reported area record(s)`}
              </li>
            );
          })}
        </ul>
      </details>
      <section
        aria-label={`Available datasets for ${locationName}`}
        className="grid gap-4 md:grid-cols-2"
      >
        {visible.map((dataset) => (
          <article
            key={dataset.id}
            className="flex min-w-0 flex-col rounded-xl border border-border bg-background p-5"
          >
            <p className="text-xs font-semibold uppercase tracking-wide text-muted">
              {dataset.category}
            </p>
            <h2 className="mt-1 text-xl font-semibold">
              <Link
                prefetch={false}
                href={catalogHref(catalog.selection, dataset.id)}
                className="underline decoration-transparent underline-offset-4 hover:decoration-current"
              >
                {dataset.title}
              </Link>
            </h2>
            <p className="mt-2 text-sm text-muted">
              {dataset.availableIndicators} of {dataset.totalIndicators} indicators available
            </p>
            {catalog.selection.mode === "all" ? (
              <p className="mt-4 text-sm">
                Available in reported locations. Choose a location to view its figures.
              </p>
            ) : (
              <dl className="mt-4 space-y-4">
                {previewIndicators(dataset).map((indicator) => (
                  <div key={indicator.id}>
                    <dt className="text-sm text-muted">{indicator.label}</dt>
                    <dd className="text-xl font-semibold">
                      {indicator.state === "partial" && dataset.id === "population" && (
                        <span className="block text-xs font-medium">Partial total</span>
                      )}
                      {formatValue(indicator.values[0], indicator.unit)}
                    </dd>
                    {indicator.values[0].denominator != null && (
                      <dd className="text-xs text-muted">
                        Denominator: {indicator.values[0].denominator.toLocaleString("en-PH")}
                      </dd>
                    )}
                    {indicator.reason && (
                      <dd className="mt-1 text-xs text-muted">{indicator.reason}</dd>
                    )}
                  </div>
                ))}
                {previewIndicators(dataset).length === 0 && (
                  <div>
                    <dt className="text-sm text-muted">
                      Other indicators are available for this location.
                    </dt>
                  </div>
                )}
              </dl>
            )}
            <div className="mt-auto pt-4">
              <Link
                prefetch={false}
                href={catalogHref(catalog.selection, dataset.id)}
                className="text-sm font-semibold text-accent underline underline-offset-4"
              >
                View {dataset.title} indicators →
              </Link>
              <SourceDates dataset={dataset} />
            </div>
          </article>
        ))}
      </section>
      {visible.length === 0 && (
        <section role="status" className="rounded-xl border border-border bg-surface p-6">
          <h2 className="text-lg font-semibold">No datasets to display for this selection</h2>
          <p className="mt-2 text-sm text-muted">
            The reasons below distinguish unavailable, unsupported, withheld and failed reads.
            Choose another location or try again.
          </p>
        </section>
      )}
      {omitted.length > 0 && (
        <section aria-label="Datasets not shown" className="rounded-xl border border-border p-5">
          <h2 className="font-semibold">Datasets not shown</h2>
          <ul className="mt-3 space-y-3 text-sm">
            {omitted.map((dataset) => (
              <li key={dataset.id}>
                <strong>{dataset.title}:</strong> {omissionReason(dataset, catalog.selection)}
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}

function Overview({ label, value, note }: { label: string; value: string; note: string }) {
  return (
    <div className="min-w-0 rounded-xl border border-border bg-surface p-4">
      <h2 className="text-xs font-medium text-muted">{label}</h2>
      <p className="mt-2 break-words text-2xl font-semibold">{value}</p>
      <p className="mt-1 break-words text-xs text-muted">{note}</p>
    </div>
  );
}

export function CatalogDetails({
  catalog,
  dataset,
}: {
  catalog: PublicCatalog;
  dataset: CatalogDataset;
}) {
  const available = dataset.indicators.filter((i) => hasAvailableValue(i.state));
  const missing = dataset.indicators.filter((i) => !hasAvailableValue(i.state));
  return (
    <section aria-label={`${dataset.title} indicators`} className="space-y-5">
      <Link
        prefetch={false}
        href={catalogHref(catalog.selection)}
        className="text-sm underline underline-offset-4"
      >
        ← All datasets for this selection
      </Link>
      <div>
        <h2 className="text-2xl font-semibold">{dataset.title} indicators</h2>
        <p className="mt-2 text-muted">
          {dataset.availableIndicators} of {dataset.totalIndicators} indicators available
        </p>
      </div>
      {dataset.detailHref && (
        <Link
          href={dataset.detailHref}
          className="inline-block text-sm text-accent underline underline-offset-4"
        >
          Open existing {dataset.title} dashboard →
        </Link>
      )}
      {catalog.selection.mode === "all" && (
        <p className="rounded-lg border border-border bg-surface p-4 text-sm">
          This is an availability summary, not a national data table. Choose a location above for
          its figures.
        </p>
      )}
      {available.length > 0 ? (
        <div className="overflow-x-auto rounded-lg border border-border">
          <table className="w-full text-left text-sm">
            <caption className="sr-only">
              Available {dataset.title} measures for the selected scope
            </caption>
            <thead className="bg-surface">
              <tr>
                <th scope="col" className="p-3">
                  Indicator
                </th>
                <th scope="col" className="p-3">
                  Value
                </th>
                <th scope="col" className="p-3">
                  Source and coverage
                </th>
              </tr>
            </thead>
            <tbody>
              {available.map((indicator) => (
                <tr key={indicator.id} className="border-t border-border align-top">
                  <th scope="row" className="min-w-40 p-3 font-medium">
                    {indicator.label}
                  </th>
                  <td className="min-w-40 p-3">
                    {indicator.values.length ? (
                      <ul className="space-y-2">
                        {indicator.values.map((value, index) => (
                          <li key={index}>
                            {value.label && `${value.label}: `}
                            {formatValue(value, indicator.unit)}
                            {value.denominator != null && (
                              <span className="block text-xs text-muted">
                                Denominator: {value.denominator.toLocaleString("en-PH")}
                              </span>
                            )}
                            {value.ciLow != null && value.ciHigh != null && (
                              <span className="block text-xs text-muted">
                                Interval: {value.ciLow}–{value.ciHigh}
                              </span>
                            )}
                          </li>
                        ))}
                      </ul>
                    ) : (
                      "Available in reported locations"
                    )}
                  </td>
                  <td className="min-w-48 p-3 text-xs text-muted">
                    {dataset.sources.find((s) => s.slug === indicator.sourceSlug)?.sourceName ??
                      "Source details unavailable"}
                    {indicator.reason && <p className="mt-2">{indicator.reason}</p>}
                    {indicator.definition && (
                      <p className="mt-2">
                        {indicator.definition.numerator} / {indicator.definition.denominator}.
                        Source: {indicator.definition.sourceWorkbook}
                      </p>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <p role="status">{omissionReason(dataset, catalog.selection)}</p>
      )}
      {missing.length > 0 && (
        <details className="rounded-lg border border-border p-4">
          <summary className="cursor-pointer text-sm font-medium">
            {missing.length} indicators unavailable or withheld
          </summary>
          <ul className="mt-3 space-y-3 text-sm">
            {missing.map((indicator) => (
              <li key={indicator.id}>
                <strong>{indicator.label}</strong> · {indicator.state}: {indicator.reason}
              </li>
            ))}
          </ul>
        </details>
      )}
      <SourceDates dataset={dataset} />
    </section>
  );
}
