import Link from "next/link";
import type { ReactNode } from "react";
import { BhwMapReturnLink } from "@/components/bhw/map-return-link";
import {
  bhwOverviewHref,
  bhwScopedCatalogHref,
  restoredBhwMapHref,
} from "@/lib/maps/catalog-return";
import { getGeoByCode } from "@/lib/db/geo";
import { getPublicDataset } from "@/lib/catalog/catalog";
import { parseCatalogParams } from "@/lib/catalog/navigation";
import { measureDefinition } from "@/lib/catalog/measure-notes";
import { publicValues, measureStatus } from "@/lib/bhw/overview";
import { BHW_MEASURES, levelLabel } from "@/lib/bhw/search";
import { SourceDates } from "@/components/catalog/catalog-results";
import { formatValue } from "@/components/catalog/presentation";
import { BhwSearchForm } from "@/components/bhw/search-form";
import { comparisonHref } from "@/lib/bhw/comparison";

export const metadata = { title: "BHW measure" };

export default async function BhwMeasurePage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;
  const returnMap = restoredBhwMapHref(params.mapState);
  const mapState = returnMap?.split("?")[1];
  const returnPanel =
    params.mapState !== undefined ? (
      <BhwMapReturnLink
        href={returnMap}
        scopeNote="Measure figures use the selected place and indicator; map filters and color groups do not apply."
      />
    ) : null;
  const spec = BHW_MEASURES.find((s) => s.id === params.indicator);
  const request = parseCatalogParams({ mode: "psgc", location: params.location, dataset: "bhw" });
  const selection = request?.selection;
  if (!spec || selection?.mode !== "psgc") return <InvalidSelection returnPanel={returnPanel} />;
  const geo = await getGeoByCode(selection.code);
  if (!geo || geo.geoLevel !== selection.level)
    return <InvalidSelection returnPanel={returnPanel} />;
  const dataset = await getPublicDataset("bhw", selection);
  const indicator = dataset.indicators.find((i) => i.id === spec.id)!;
  const values = publicValues(indicator);
  return (
    <div className="mx-auto w-full max-w-4xl space-y-5 px-4 py-6">
      <Link href={bhwOverviewHref(mapState)} className="underline">
        ← BHW overview
      </Link>
      <h1 className="text-2xl font-semibold">{indicator.label}</h1>
      {returnPanel}
      <p>
        {geo.geoName} · {levelLabel[geo.geoLevel]} · {geo.geoCode} · 2025
      </p>
      <p className="text-sm text-muted">{measureDefinition(indicator)}</p>
      {values.length ? (
        <dl className="space-y-3 rounded-lg border border-border p-4">
          {values.map((value, index) => (
            <div key={index}>
              <dt>{value.label ?? indicator.label}</dt>
              <dd className="text-2xl font-semibold">{formatValue(value, indicator.unit)}</dd>
              {value.numerator != null && (
                <dd>Numerator: {value.numerator.toLocaleString("en-PH")}</dd>
              )}
              {value.denominator != null && (
                <dd>Denominator: {value.denominator.toLocaleString("en-PH")}</dd>
              )}
              {value.ciLow != null && value.ciHigh != null && (
                <dd>
                  Confidence interval: {formatValue({ value: value.ciLow }, indicator.unit)}–
                  {formatValue({ value: value.ciHigh }, indicator.unit)}
                </dd>
              )}
            </div>
          ))}
        </dl>
      ) : (
        <p role="status" className="rounded-lg border border-border p-4">
          {measureStatus(indicator)}
        </p>
      )}
      {values.length > 0 && indicator.reason && <p role="status">{indicator.reason}</p>}
      <SourceDates
        dataset={{
          ...dataset,
          sources: dataset.sources.filter((s) => s.slug === indicator.sourceSlug),
        }}
      />
      <Link className="inline-block underline" href={bhwScopedCatalogHref(selection, mapState)}>
        All BHW indicators for {geo.geoName}
      </Link>
      <p className="text-sm text-muted">
        Copy this page’s URL to share this measure and location. Missing or withheld values are not
        zero.
      </p>
      <Link
        className="block underline"
        href={comparisonHref(spec.id, [geo.geoCode], undefined, mapState)}
      >
        Compare this measure with another place
      </Link>
      <BhwSearchForm />
    </div>
  );
}

function InvalidSelection({ returnPanel }: { returnPanel: ReactNode }) {
  return (
    <div className="mx-auto w-full max-w-4xl space-y-4 px-4 py-6">
      <h1 className="text-2xl font-semibold">Could not verify this selection</h1>
      {returnPanel}
      <p role="status">
        Check the indicator and place code, or retry if the place service is unavailable. No
        national fallback has been applied.
      </p>
      <BhwSearchForm />
    </div>
  );
}
