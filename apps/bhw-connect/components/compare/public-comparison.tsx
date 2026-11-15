import Link from "next/link";
import { getGeoByCode } from "@/lib/db/geo";
import { getPublicDataset } from "@/lib/catalog/catalog";
import { createCatalogReader } from "@/lib/catalog/reader";
import { measureDefinition } from "@/lib/catalog/measure-notes";
import { publicValues, scalar, measureStatus } from "@/lib/bhw/overview";
import { BHW_MEASURES, bhwMeasureHref, levelLabel } from "@/lib/bhw/search";
import { comparisonHref, parsePublicComparison } from "@/lib/bhw/comparison";
import { formatValue } from "@/components/catalog/presentation";
import { SourceDates } from "@/components/catalog/catalog-results";
import { BenchmarkBars } from "@/components/place/benchmark";
import { PublicCompareControls } from "./public-controls";

export async function PublicComparison({
  params,
}: {
  params: Record<string, string | string[] | undefined>;
}) {
  const selection = parsePublicComparison(params);
  if (!selection)
    return (
      <div className="mx-auto max-w-4xl space-y-4 p-4">
        <h1 className="text-2xl font-semibold">Invalid comparison settings</h1>
        <p role="alert">
          Use a supported BHW measure and distinct PSGC codes. Districts, repeated settings and an
          unselected focus cannot be mixed into this comparison. No fallback was applied.
        </p>
        <Link href={comparisonHref("bhw.total", [])} className="underline">
          Choose comparison settings
        </Link>
      </div>
    );
  const { spec, codes, focus } = selection;
  const resolved = await Promise.all(codes.map((code) => getGeoByCode(code)));
  const places = resolved.map((geo, i) => ({
    geoCode: codes[i],
    geoName: geo?.geoName ?? codes[i],
    levelLabel: geo ? levelLabel[geo.geoLevel] : "Unverified place",
  }));
  const valid = resolved.filter((geo) => geo !== null);
  const problem =
    codes.length > 4
      ? "Choose at most four places using Remove below. No requested places were discarded."
      : valid.length !== codes.length
        ? "Could not verify every place. Remove an unverified code or retry; no fallback was applied."
        : new Set(valid.map((geo) => geo.geoLevel)).size > 1
          ? "Choose places at the same geographic level before comparing. Replace or remove a place below."
          : codes.length < 2
            ? "Choose at least two places to compare."
            : null;
  // One request-local reader shares source and catalog-wide privacy checks across places.
  const reader = createCatalogReader();
  const data = problem
    ? []
    : await Promise.all(
        valid.map(async (geo) => ({
          geo,
          dataset: await getPublicDataset(
            "bhw",
            { mode: "psgc", level: geo.geoLevel, code: geo.geoCode },
            reader,
          ),
        })),
      );
  const rows = data.map(({ geo, dataset }) => ({
    geo,
    dataset,
    indicator: dataset.indicators.find((i) => i.id === spec.id)!,
  }));
  return (
    <div className="mx-auto w-full max-w-6xl space-y-6 px-4 py-6">
      <Link href="/bhw" className="underline">
        ← BHW overview
      </Link>
      <h1 className="text-2xl font-semibold">Compare BHW places</h1>
      <p>
        {spec.label} · 2025 · {spec.unit}
      </p>
      <p className="text-sm text-muted">
        Compare the same measure and geographic level. Headcount and profiles are different
        populations; a low count alone does not establish a shortage.
      </p>
      {problem && (
        <p role="status" className="rounded border border-border p-3">
          {problem}
        </p>
      )}
      <PublicCompareControls
        key={`${spec.id}:${codes.join(",")}`}
        places={places}
        measure={spec.id}
        focus={focus}
        measures={BHW_MEASURES.map(({ id, label }) => ({ id, label }))}
      />
      {rows.length > 0 && (
        <>
          <h2 className="text-xl font-semibold">{spec.label}</h2>
          <p>{measureDefinition(rows[0].indicator)}</p>
          {!spec.categoryColumn && (
            <BenchmarkBars
              flush
              rows={rows.map(({ geo, indicator }) => ({
                label: `${geo.geoName} (${geo.geoCode})`,
                value: scalar(indicator),
              }))}
              format={spec.unit === "%" ? "percent" : "count"}
              unitSuffix={spec.unit === "%" ? undefined : spec.unit}
            />
          )}
          <p className="text-sm text-muted">
            Only published values are drawn. Zero is a reported value; missing, withheld, failed and
            unsupported observations remain distinct below. Category breakdowns are listed
            separately and are not summed across places.
          </p>
          <nav aria-label="Comparison place details" className="flex flex-wrap gap-3 md:hidden">
            {rows.map(({ geo }) => (
              <Link
                key={geo.geoCode}
                href={comparisonHref(spec.id, codes, geo.geoCode)}
                scroll={false}
                aria-current={geo.geoCode === focus ? "page" : undefined}
                className="rounded border border-border p-3 underline"
              >
                {geo.geoName} ({geo.geoCode})
              </Link>
            ))}
          </nav>
          <div className="grid gap-4 md:grid-cols-2">
            {rows.map(({ geo, indicator, dataset }) => (
              <article
                key={geo.geoCode}
                className={`${geo.geoCode === focus ? "block" : "hidden md:block"} min-w-0 space-y-3 rounded border border-border p-4`}
                aria-label={`${geo.geoName} comparison detail`}
              >
                <h3 className="text-lg font-semibold">
                  {geo.geoName} · {geo.geoCode}
                </h3>
                {publicValues(indicator).length ? (
                  <dl className="space-y-3">
                    {publicValues(indicator).map((value, i) => (
                      <div key={i}>
                        <dt>{value.label ?? indicator.label}</dt>
                        <dd className="text-xl font-semibold">{formatValue(value, spec.unit)}</dd>
                        {value.numerator != null && (
                          <dd>Numerator: {value.numerator.toLocaleString("en-PH")}</dd>
                        )}
                        {value.denominator != null && (
                          <dd>Denominator: {value.denominator.toLocaleString("en-PH")}</dd>
                        )}
                        {value.ciLow != null && value.ciHigh != null && (
                          <dd>
                            Confidence interval: {formatValue({ value: value.ciLow }, spec.unit)}–
                            {formatValue({ value: value.ciHigh }, spec.unit)}
                          </dd>
                        )}
                      </div>
                    ))}
                  </dl>
                ) : (
                  <p role="status">{measureStatus(indicator)}</p>
                )}
                {publicValues(indicator).length > 0 && indicator.reason && (
                  <p>{indicator.reason}</p>
                )}
                <SourceDates
                  dataset={{
                    ...dataset,
                    sources: dataset.sources.filter((s) => s.slug === indicator.sourceSlug),
                  }}
                />
                <Link
                  className="underline"
                  href={bhwMeasureHref(spec.id, geo.geoLevel, geo.geoCode)}
                >
                  Open this measure for {geo.geoName}
                </Link>
              </article>
            ))}
          </div>
        </>
      )}
      <Link href={comparisonHref(spec.id, codes, focus)} className="inline-block underline">
        Shareable comparison link
      </Link>
      <p className="text-sm text-muted">
        Copy the link to retain this measure and all selected places. Changing the measure retains
        the places and reports unsupported levels explicitly.
      </p>
    </div>
  );
}
