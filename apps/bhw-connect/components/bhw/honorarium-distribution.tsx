import type { CatalogDataset } from "@/lib/catalog/types";
import { publicHonorariumDistributions } from "@/lib/bhw/honorarium-distribution";
import { RangeChartClient } from "@/components/charts/range-chart-client";
import { FigureCard } from "@/components/narrative/figure-card";
import { SourceDates } from "@/components/catalog/catalog-results";
import { formatValue } from "@/components/catalog/presentation";
import { ACCEPTED_RELEASES } from "@/lib/catalog/definitions";

export function BhwHonorariumDistribution({
  dataset,
  areaName,
}: {
  dataset: CatalogDataset;
  areaName: string;
}) {
  const rows = publicHonorariumDistributions(dataset);
  const data = rows.flatMap((row) => (row.data ? [row.data] : []));
  return (
    <FigureCard
      title="How honorarium amounts vary within each paying level"
      caption={`${areaName} · BHW Profiles 2025 · recipients at each paying level · PHP/month`}
      headline="The box covers the middle half of recorded amounts; its mark is the median. The line spans the minimum to maximum."
    >
      <p className="mb-3 text-sm text-muted">
        These are distributions among recipients at each level, not percentages of all profiles. The
        same person can receive payment from several levels. Do not add these medians, percentiles
        or payer ranges to estimate combined pay; use the combined-profile figures below.
      </p>
      {data.length ? (
        <RangeChartClient
          data={data}
          xLabel="Monthly pesos"
          yLabel="Paying level"
          valueFormat="peso"
        />
      ) : (
        <p role="status">
          No complete public payment distributions are available for this locality.
        </p>
      )}
      <div
        role="region"
        aria-label="Honorarium distribution values, scroll horizontally for all statistics"
        tabIndex={0}
        className="mt-4 max-w-full overflow-x-auto rounded border border-border"
      >
        <table className="w-full text-left text-sm">
          <caption className="sr-only">
            Exact monthly payment distributions and availability by paying level
          </caption>
          <thead>
            <tr>
              {[
                "Paying level",
                "Minimum",
                "25th percentile",
                "Median",
                "75th percentile",
                "Maximum",
              ].map((label) => (
                <th key={label} scope="col" className="p-3">
                  {label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.payer} className="border-t border-border">
                <th scope="row" className="p-3 font-medium">
                  {row.label}
                </th>
                {row.data ? (
                  [row.data.min, row.data.p25, row.data.median, row.data.p75, row.data.max].map(
                    (value, i) => (
                      <td key={i} className="whitespace-nowrap p-3">
                        {formatValue({ value }, "PHP/month")}
                      </td>
                    ),
                  )
                ) : (
                  <td colSpan={5} className="p-3">
                    Not plotted. {row.status}
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="mt-3 text-sm text-muted">
        Only complete, ordered published quantiles are plotted. No missing value is estimated.
        Individual approved statistics and their statuses remain available below.
      </p>
      <SourceDates
        dataset={{
          ...dataset,
          sources: dataset.sources.filter((s) => s.slug === ACCEPTED_RELEASES.bhw),
        }}
      />
    </FigureCard>
  );
}
