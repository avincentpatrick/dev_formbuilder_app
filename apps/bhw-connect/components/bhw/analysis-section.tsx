import { categoryAxis } from "@/lib/bhw/category-order";
import Link from "next/link";
import type { CatalogDataset, CatalogIndicator } from "@/lib/catalog/types";
import { publicValues, scalar, measureStatus } from "@/lib/bhw/overview";
import { bhwMeasureHref } from "@/lib/bhw/search";
import { comparisonHref } from "@/lib/bhw/comparison";
import { measureDefinition } from "@/lib/catalog/measure-notes";
import { formatValue } from "@/components/catalog/presentation";
import { FigureView } from "@/components/charts/figure-view";
import type { GeoLevel } from "@/lib/filters/schema";
import { BhwHonorariumDistribution } from "./honorarium-distribution";

type Props = {
  dataset: CatalogDataset;
  level: GeoLevel;
  code: string;
  areaName: string;
  mapState?: string | string[];
};

function Measure({
  item,
  level,
  code,
  areaName,
  mapState,
}: Omit<Props, "dataset"> & { item: CatalogIndicator }) {
  const values = publicValues(item);
  const chart = values.length > 1 && item.unit !== "calendar year";
  return (
    <article aria-label={item.label} className="space-y-3">
      <p className="text-xs text-muted">{areaName} · BHW Profiles 2025 · validated profiles</p>
      {chart ? (
        <FigureView
          data={values.map((v) => ({ label: v.label ?? item.label, value: v.value }))}
          title={item.label}
          caption={`${areaName} · BHW Profiles 2025`}
          xLabel={item.unit}
          yLabel={categoryAxis(item.id) ?? "Category"}
          preserveOrder={Boolean(categoryAxis(item.id))}
          valueFormat={item.unit === "%" ? "percent" : item.unit === "PHP/month" ? "peso" : "count"}
        />
      ) : values.length ? (
        <p className="text-2xl font-semibold">{formatValue(values[0], item.unit)}</p>
      ) : (
        <p role="status" className="text-sm text-muted">
          {measureStatus(item)}
        </p>
      )}
      {values.length > 0 && item.reason && <p className="text-sm text-muted">{item.reason}</p>}
      <p className="text-sm text-muted">{measureDefinition(item)}</p>
      <div className="flex flex-wrap gap-4 text-sm underline">
        <Link href={bhwMeasureHref(item.id, level, code, mapState)}>Full data and definition</Link>
        <Link href={comparisonHref(item.id, [code], undefined, mapState)}>Compare places</Link>
      </div>
    </article>
  );
}

function HonorariumHighlights({ dataset, areaName }: Pick<Props, "dataset" | "areaName">) {
  const get = (id: string) => dataset.indicators.find((i) => i.id === id);
  const median = scalar(get("bhw.combined-honorarium.median"));
  const none = scalar(get("bhw.combined-honorarium.none"));
  const ratio = scalar(get("bhw.honorarium-inequality.ratio"));
  const formatMoney = (value: number) => formatValue({ value }, "PHP/month");
  return (
    <div className="space-y-3 rounded-lg border border-border bg-surface p-4">
      <p className="font-semibold">{areaName} · recorded payment insights</p>
      {median !== null && (
        <p>
          The median combined honorarium is {formatMoney(median)} across all validated profiles,
          including profiles with no positive recorded payment.
        </p>
      )}
      {none !== null && (
        <p>
          {formatValue({ value: none }, "%")} of profiles have no positive recorded honorarium.
          Missing amount records do not confirm non-payment.
        </p>
      )}
      {ratio !== null && (
        <p>
          Among recipients, the 90th-percentile combined amount is{" "}
          {ratio.toLocaleString("en-PH", { maximumFractionDigits: 2 })} times the 10th-percentile
          amount.
        </p>
      )}
      {median === null && none === null && ratio === null && (
        <p>
          Payment insights are unavailable for this location. Check individual measure statuses
          below.
        </p>
      )}
      <p className="text-sm text-muted">
        BHW Profiles 2025. These records describe reported allowances, not current payroll or a
        legal pay standard.
      </p>
    </div>
  );
}

function PayerAmounts({ dataset, areaName }: Pick<Props, "dataset" | "areaName">) {
  return (
    <div className="grid gap-4 md:grid-cols-2">
      {["mean", "median"].map((stat) => {
        const data = ["region", "province", "citymun", "barangay"].flatMap((payer) => {
          const value = scalar(
            dataset.indicators.find((i) => i.id === `bhw.honorarium-amount.${payer}.${stat}`),
          );
          return value === null
            ? []
            : [
                {
                  label:
                    payer === "citymun"
                      ? "City/municipality"
                      : payer[0].toUpperCase() + payer.slice(1),
                  value,
                },
              ];
        });
        const title = `${stat === "mean" ? "Average" : "Median"} monthly honorarium by paying level`;
        return (
          <article key={stat} className="space-y-3 rounded-lg border border-border p-4">
            <h3 className="text-lg font-semibold">{title}</h3>
            {data.length ? (
              <FigureView
                data={data}
                title={title}
                caption={`${areaName} · recipients at each payer level · 2025`}
                xLabel="PHP/month"
                yLabel="Paying level"
                valueFormat="peso"
              />
            ) : (
              <p>No public amount observations are available here.</p>
            )}
            <p className="text-sm text-muted">
              Recipients at each payer level; averages and medians cannot be added across levels.
              Missing or withheld payers are omitted; see their statuses below.
            </p>
          </article>
        );
      })}
    </div>
  );
}

/** Same catalog-approved observations as search, exact-value pages and comparisons. */
export function BhwAnalysisSection({
  title,
  id,
  prefixes,
  exclude = [],
  detailsLabel,
  description,
  ...props
}: Props & {
  title: string;
  id: string;
  prefixes: string[];
  exclude?: string[];
  detailsLabel?: string;
  description?: string;
}) {
  const items = props.dataset.indicators.filter(
    (i) => !exclude.includes(i.id) && prefixes.some((prefix) => i.id.startsWith(prefix)),
  );
  const measureList = (
    <div className="grid gap-4 md:grid-cols-2">
      {items.map((item) => (
        <details key={item.id} className="rounded-lg border border-border p-3">
          <summary className="cursor-pointer font-medium">
            {item.label}
            {publicValues(item).length === 1
              ? ` · ${formatValue(publicValues(item)[0], item.unit)}`
              : ""}
          </summary>
          <div className="mt-3">
            <Measure {...props} item={item} />
          </div>
        </details>
      ))}
    </div>
  );
  return (
    <section id={id} aria-labelledby={`${id}-heading`} className="scroll-mt-20 space-y-4">
      <h2 id={`${id}-heading`} className="text-2xl font-semibold">
        {title}
      </h2>
      {description && <p className="text-sm text-muted">{description}</p>}
      {props.level === "barangay" && (
        <p className="text-sm text-muted">
          Some analyses are available only from city/municipality to national level. Unsupported
          figures are not replaced with another locality’s values.
        </p>
      )}
      {id === "honorarium-amounts" && (
        <>
          <PayerAmounts dataset={props.dataset} areaName={props.areaName} />
          <BhwHonorariumDistribution dataset={props.dataset} areaName={props.areaName} />
        </>
      )}
      {id === "combined-honorarium" && (
        <HonorariumHighlights dataset={props.dataset} areaName={props.areaName} />
      )}
      {detailsLabel ? (
        <details className="rounded-lg border border-border p-4">
          <summary className="cursor-pointer font-medium">
            {detailsLabel} ({items.length})
          </summary>
          <div className="mt-4">{measureList}</div>
        </details>
      ) : (
        measureList
      )}
    </section>
  );
}
