import Link from "next/link";
import { getBhwCounts } from "@/lib/db/indicators";
import {
  FHSIS_HEADLINES,
  FHSIS_RELEASE_LABEL,
  FHSIS_OVER_100_NOTE,
  getFhsisArea,
  getFhsisChildren,
  getFhsisReconciliation,
  fhsisAreaHref,
  fhsisCaption,
  fhsisRateLabel,
  workforceRatio,
} from "@/lib/db/fhsis";
import type { GeoOption } from "@/lib/db/geo";

const count = (value: number | null) =>
  value === null ? "Not reported" : value.toLocaleString("en-PH");
const CADRES: Record<string, string> = {
  physician: "Doctors",
  nurse: "Nurses",
  midwife: "Midwives",
  dentist: "Dentists",
  med_tech: "Medical technologists",
  nutritionist: "Nutritionists",
  sanitary_engineer: "Sanitary engineers",
  sanitary_inspector: "Sanitary inspectors",
};

export async function AreaDashboard({ geo }: { geo: GeoOption }) {
  const [area, children, reconciliation, bhw] = await Promise.all([
    getFhsisArea(geo.geoCode, geo.geoLevel),
    getFhsisChildren(geo.geoCode, geo.geoLevel),
    getFhsisReconciliation(geo.geoCode),
    getBhwCounts(geo.geoCode, geo.geoLevel).catch(() => null),
  ]);
  if (!area)
    return (
      <p role="status">Health services data is not available right now. Please try again later.</p>
    );
  const headlines = FHSIS_HEADLINES.flatMap((key) =>
    area.indicators.filter((i) => i.indicator_key === key),
  );
  const labels = new Map(area.indicators.map((i) => [i.indicator_key, i.label]));
  const childHeading =
    geo.geoLevel === "national"
      ? "Regions"
      : geo.geoLevel === "region"
        ? "Provinces / HUCs"
        : "Cities / municipalities";
  return (
    <div className="flex flex-col gap-8">
      <section aria-labelledby="coverage-heading">
        <h2 id="coverage-heading" className="text-xl font-semibold">
          Health services delivered
        </h2>
        <p className="mt-2 text-sm text-muted">
          {FHSIS_RELEASE_LABEL} · Source-reported rates and their own denominators; rates are never
          averaged across places.
        </p>
        <div className="mt-4 grid gap-4 sm:grid-cols-2">
          {headlines.map((i) => (
            <article
              key={i.indicator_key}
              className="rounded-lg border border-border bg-background p-5"
            >
              <h3 className="font-semibold">{i.label}</h3>
              <p className="my-3 text-2xl font-semibold text-accent">{fhsisRateLabel(i)}</p>
              <p className="text-sm">
                Numerator: {count(i.numerator)} · Denominator: {count(i.denominator)}
              </p>
              <p className="mt-2 text-xs text-muted">{fhsisCaption(i, geo.geoName)}</p>
              {i.over100 && <p className="mt-2 text-xs text-muted">{FHSIS_OVER_100_NOTE}</p>}
            </article>
          ))}
        </div>
        <p className="mt-3 text-sm text-muted">{FHSIS_OVER_100_NOTE}</p>
        <p className="mt-2 text-sm text-muted">
          Eight-visit antenatal care covers Q3–Q4 only; it is not directly comparable with the
          full-year four-visit figure.
        </p>
      </section>

      {geo.geoLevel !== "citymun" && (
        <section aria-labelledby="areas-heading">
          <h2 id="areas-heading" className="text-xl font-semibold">
            {childHeading}
          </h2>
          <p className="my-2 text-sm text-muted">
            Fully immunised children (FIC), lowest reported coverage first. Missing rates appear
            last. {FHSIS_RELEASE_LABEL}.
          </p>
          {children === null ? (
            <p>Area comparison is temporarily unavailable.</p>
          ) : children.length === 0 ? (
            <p>No child areas are available.</p>
          ) : (
            <div
              className="overflow-x-auto"
              role="region"
              aria-label="FIC area comparison"
              tabIndex={0}
            >
              <table className="w-full min-w-[640px] text-left text-sm">
                <caption className="sr-only">
                  FIC coverage by area, with the source numerator and eligible population
                  denominator
                </caption>
                <thead>
                  <tr className="border-b border-border">
                    <th scope="col" className="p-3">
                      Area
                    </th>
                    <th scope="col" className="p-3">
                      FIC coverage
                    </th>
                    <th scope="col" className="p-3">
                      Immunised
                    </th>
                    <th scope="col" className="p-3">
                      Eligible population
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {children.map((child) => (
                    <tr key={child.geoCode} className="border-b border-border">
                      <th scope="row" className="p-3 font-medium">
                        <Link
                          href={fhsisAreaHref(child.geoLevel, child.geoCode)}
                          className="underline hover:text-accent"
                        >
                          {child.geoName}
                        </Link>
                      </th>
                      <td className="p-3">{fhsisRateLabel(child.fic)}</td>
                      <td className="p-3">{count(child.fic.numerator)}</td>
                      <td className="p-3">{count(child.fic.denominator)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <p className="mt-3 text-sm text-muted">{FHSIS_OVER_100_NOTE}</p>
        </section>
      )}

      <section aria-labelledby="indicators-heading">
        <h2 id="indicators-heading" className="text-xl font-semibold">
          All service indicators
        </h2>
        <p className="my-2 text-sm text-muted">
          {FHSIS_RELEASE_LABEL} · Total breakdown only. TB notification rates are cases per 100,000,
          not percentages.
        </p>
        <div className="overflow-x-auto" role="region" aria-label="Service indicators" tabIndex={0}>
          <table className="w-full min-w-[640px] text-left text-sm">
            <caption className="sr-only">
              Source-reported service indicators for {geo.geoName}
            </caption>
            <thead>
              <tr className="border-b border-border">
                <th scope="col" className="p-3">
                  Indicator
                </th>
                <th scope="col" className="p-3">
                  Rate
                </th>
                <th scope="col" className="p-3">
                  Numerator
                </th>
                <th scope="col" className="p-3">
                  Denominator
                </th>
              </tr>
            </thead>
            <tbody>
              {area.indicators.map((i) => (
                <tr key={i.indicator_key} className="border-b border-border align-top">
                  <th scope="row" className="p-3 font-medium">
                    {i.label}
                    <details className="mt-2 font-normal text-muted">
                      <summary className="cursor-pointer">Definition and source</summary>
                      <p className="mt-2">Numerator: {i.numerator_def}</p>
                      <p>Denominator: {i.denominator_def}</p>
                      <p className="break-words">Workbook: {i.source_workbook}</p>
                    </details>
                  </th>
                  <td className="p-3">{fhsisRateLabel(i)}</td>
                  <td className="p-3">{count(i.numerator)}</td>
                  <td className="p-3">{count(i.denominator)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="mt-3 text-sm text-muted">{FHSIS_OVER_100_NOTE}</p>
      </section>

      <section aria-labelledby="workforce-heading">
        <h2 id="workforce-heading" className="text-xl font-semibold">
          The workforce alongside BHWs
        </h2>
        <p className="my-2 text-sm text-muted">
          {FHSIS_RELEASE_LABEL} · LGU- and DOH-hired staff. Ratios use profiled BHWs from the 2025
          BHW Census, not a FHSIS BHW count.
        </p>
        {area.workforce.length === 0 ? (
          <p>Workforce not reported in 2025 for this area.</p>
        ) : (
          <div
            className="overflow-x-auto"
            role="region"
            aria-label="Workforce comparison"
            tabIndex={0}
          >
            <table className="w-full min-w-[640px] text-left text-sm">
              <caption className="sr-only">Health workforce in {geo.geoName}</caption>
              <thead>
                <tr className="border-b border-border">
                  <th scope="col" className="p-3">
                    Cadre
                  </th>
                  <th scope="col" className="p-3">
                    LGU hired
                  </th>
                  <th scope="col" className="p-3">
                    DOH hired
                  </th>
                  <th scope="col" className="p-3">
                    Total
                  </th>
                  <th scope="col" className="p-3">
                    Profiled BHWs per staff member
                  </th>
                </tr>
              </thead>
              <tbody>
                {area.workforce.map((row) => {
                  const ratio = workforceRatio(bhw?.nTotal ?? null, row.total);
                  return (
                    <tr key={row.cadre} className="border-b border-border">
                      <th scope="row" className="p-3 font-medium">
                        {CADRES[row.cadre] ?? row.cadre}
                      </th>
                      <td className="p-3">{count(row.lgu_hired)}</td>
                      <td className="p-3">{count(row.doh_hired)}</td>
                      <td className="p-3">{count(row.total)}</td>
                      <td className="p-3">
                        {ratio === null
                          ? "Not available"
                          : ratio.toLocaleString("en-PH", { maximumFractionDigits: 1 })}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
        <p className="mt-3 text-sm text-muted">
          Ratios are unavailable when the census count is unavailable or suppressed, or the staff
          denominator is missing or zero. FHSIS projections do not replace the population used
          elsewhere on this site.
        </p>
      </section>

      {geo.geoLevel !== "citymun" && (
        <details className="rounded-lg border border-border p-4">
          <summary className="cursor-pointer font-semibold">Source subtotal reconciliation</summary>
          <p className="my-3 text-sm text-muted">
            Published parent numerator minus the sum of reported child numerators. Missing child
            rows are not zero. We display the source’s parent figures, not a replacement calculated
            from incomplete children. {FHSIS_RELEASE_LABEL}.
          </p>
          {reconciliation === null ? (
            <p>Reconciliation is temporarily unavailable.</p>
          ) : reconciliation.length === 0 ? (
            <p>
              No reconciliation rows are available for this area; this does not establish agreement.
            </p>
          ) : (
            <ul className="space-y-2 text-sm">
              {reconciliation.map((r) => (
                <li key={r.indicator_key}>
                  {labels.get(r.indicator_key ?? "") ?? r.indicator_key}:{" "}
                  {count(r.published_parent)} published; {count(r.child_sum)} across{" "}
                  {count(r.child_count)} reported children; residual {count(r.residual)}.
                </li>
              ))}
            </ul>
          )}
        </details>
      )}
      <p className="text-sm text-muted">
        Read the{" "}
        <Link href="/health-services/methodology" className="underline hover:text-accent">
          methodology
        </Link>{" "}
        for release coverage, denominator definitions and limitations.
      </p>
    </div>
  );
}
