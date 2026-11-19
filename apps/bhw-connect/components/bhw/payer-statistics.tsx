import Link from "next/link";
import type { CatalogDataset } from "@/lib/catalog/types";
import type { GeoLevel } from "@/lib/filters/schema";
import { PAYERS } from "@/lib/bhw/honorarium-distribution";
import { scalar, measureStatus } from "@/lib/bhw/overview";
import { ACCEPTED_RELEASES } from "@/lib/catalog/definitions";
import { measureDefinition } from "@/lib/catalog/measure-notes";
import { bhwMeasureHref } from "@/lib/bhw/search";
import { comparisonHref } from "@/lib/bhw/comparison";
import { formatValue } from "@/components/catalog/presentation";
const stats = [
  ["median", "Middle amount (median)"],
  ["mean", "Average (mean)"],
  ["p25", "Lower quarter (25th percentile)"],
  ["p75", "Upper quarter (75th percentile)"],
  ["min", "Lowest"],
  ["max", "Highest"],
] as const;

export function PayerStatistics({
  dataset,
  level,
  code,
  mapState,
}: {
  dataset: CatalogDataset;
  level: GeoLevel;
  code: string;
  mapState?: string | string[];
}) {
  return (
    <div className="space-y-3">
      <p className="text-sm text-muted">
        Monthly allowances among recipients of each government source. The median is the middle
        amount; the average adds amounts and divides by the number of recipients. The lower and
        upper quarters bound the middle half. The same worker may receive allowances from several
        sources, so do not add these statistics.
      </p>
      <p className="text-xs text-muted">
        Scroll sideways to see all six statistics. Open a statistic’s details for its definition and
        data links.
      </p>
      <div
        role="region"
        aria-label="Payment statistics, scroll horizontally for all columns"
        tabIndex={0}
        className="max-w-full overflow-x-auto rounded border border-border"
      >
        <table className="w-full text-left text-sm">
          <caption className="sr-only">Monthly allowance statistics by government source</caption>
          <thead>
            <tr>
              <th scope="col" className="sticky left-0 bg-surface p-3">
                Government source
              </th>
              {stats.map(([key, label]) => (
                <th key={key} scope="col" className="min-w-40 p-3">
                  {label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {PAYERS.map(([payer, label]) => (
              <tr key={payer} className="border-t border-border align-top">
                <th scope="row" className="sticky left-0 bg-surface p-3">
                  {label}
                </th>
                {stats.map(([stat, label]) => {
                  const id = `bhw.honorarium-amount.${payer}.${stat}`;
                  const item = dataset.indicators.find((i) => i.id === id);
                  const value =
                    item?.sourceSlug === ACCEPTED_RELEASES.bhw && item.unit === "PHP/month"
                      ? scalar(item)
                      : null;
                  return (
                    <td key={stat} className="p-3">
                      <p>
                        {value === null
                          ? measureStatus(item) || "No compatible monthly amount is available."
                          : formatValue({ value }, "PHP/month")}
                      </p>
                      <details className="mt-2">
                        <summary className="cursor-pointer underline">{label} details</summary>
                        {item && <p className="my-2">{measureDefinition(item)}</p>}
                        <Link
                          className="block underline"
                          href={bhwMeasureHref(id, level, code, mapState)}
                        >
                          Full data and definition
                        </Link>
                        <Link
                          className="block underline"
                          href={comparisonHref(id, [code], undefined, mapState)}
                        >
                          Compare places
                        </Link>
                      </details>
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
