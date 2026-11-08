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
  fhsisPeriodLabel,
  workforceRatio,
  getFhsisBreakdowns,
  fhsisBreakdownLabel,
  fhsisLimitedGeography,
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
  const [area, children, reconciliation, bhw, breakdowns] = await Promise.all([
    getFhsisArea(geo.geoCode, geo.geoLevel),
    getFhsisChildren(geo.geoCode, geo.geoLevel),
    getFhsisReconciliation(geo.geoCode),
    getBhwCounts(geo.geoCode, geo.geoLevel).catch(() => null),
    getFhsisBreakdowns(geo.geoCode, geo.geoLevel),
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
          full-year four-visit figure. Water and sanitation are Q4 snapshots.
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
          {FHSIS_RELEASE_LABEL} · Totals; available age and sex breakdowns are below. TB
          notification and leprosy detection rates are cases per 100,000; leprosy prevalence is per
          10,000. These are not percentages. Additional prenatal and postnatal care,
          delivery/newborn care, nutrition, school vaccination and childhood illness sources cover
          provinces/HUCs and selected separately listed cities; they do not provide every
          municipality separately.
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
                    {i.indicator_key.startsWith("morb_") && i.reported && (
                      <Link
                        className="mt-2 block text-sm underline"
                        href={`/health-services/morbidity/${i.indicator_key}/${geo.geoCode}`}
                      >
                        View age and sex case counts
                      </Link>
                    )}
                    <details className="mt-2 font-normal text-muted">
                      <summary className="cursor-pointer">Definition and source</summary>
                      <p className="mt-2">Numerator: {i.numerator_def}</p>
                      <p>Denominator: {i.denominator_def}</p>
                      <p>Period: {fhsisPeriodLabel(i.indicator_key)}</p>
                      <p className="break-words">Workbook: {i.source_workbook}</p>
                    </details>
                  </th>
                  <td className="p-3">
                    {!i.reported &&
                    geo.geoLevel === "citymun" &&
                    fhsisLimitedGeography(i.indicator_key)
                      ? "Not separately published for this municipality"
                      : fhsisRateLabel(i)}
                  </td>
                  <td className="p-3">{count(i.numerator)}</td>
                  <td className="p-3">{count(i.denominator)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="mt-3 text-sm text-muted">{FHSIS_OVER_100_NOTE}</p>
      </section>

      {area.indicators.some((i) => i.indicator_key.startsWith("fp_")) && (
        <p className="text-sm text-muted">
          Family-planning beginning and ending users are snapshots. New acceptors, other acceptors
          and dropouts are separate annual flows; do not add them to user totals. Method totals
          already include their age groups. Source fractional counts are retained and are not
          presented as unique people. Demand satisfied is a Q4 measure for ages 15–49 and uses the
          denominator published in that table.
        </p>
      )}

      {area.indicators.some((i) => i.indicator_key.startsWith("ncd_")) && (
        <p className="text-sm text-muted">
          NCD medicine access is a December 2025 snapshot. Other included NCD measures are annual.
          Risk factors and cancer screening pathways can overlap; do not add them as unique people.
          Suspicious or significant screening findings are not confirmed cancer diagnoses. Most NCD
          sources report provinces/HUCs and selected cities; adult annual risk assessment also
          includes municipalities. The cervical precancerous-lesion rate is withheld because its
          denominator is not explicit in the source; its count is retained.
        </p>
      )}

      {area.indicators.some((i) => i.indicator_key.startsWith("oral_")) && (
        <p className="text-sm text-muted">
          Oral-health first visits and completed two visits are separate measures. Facility and
          non-facility reports may overlap; do not add them as unique people. For ages 5 and above,
          annual rates are withheld because the source adds quarterly populations into the annual
          denominator. Visit counts are retained. These sources cover provinces/HUCs and selected
          cities, not every municipality.
        </p>
      )}

      {area.indicators.some((i) => i.program_area === "vital_statistics") && (
        <p className="text-sm text-muted">
          Vital statistics distinguish live births from deliveries. Adolescent birth rates are per
          1,000 source estimated population in the stated age group; infant mortality is per 1,000
          live births, and maternal mortality ratios are per 100,000 live births. The workbook’s
          percent headings do not describe these scales. Direct/indirect and resident/nonresident
          maternal deaths are separate source groups.
        </p>
      )}
      {area.indicators.some((i) => i.program_area === "morbidity") && (
        <p className="text-sm text-muted">
          Morbidity covers cumulative January–December case reports, not unique patients. Region
          XII’s May report is missing. The source counts blank submitted cells as zero; a zero does
          not prove complete reporting. Do not add overlapping ICD categories. Rates are per 100,000
          population; regional and national population denominators were not supplied. Provincial
          rows use the companion Province workbook. Open a disease’s age and sex link for its
          detailed counts.
        </p>
      )}

      {area.indicators.some((i) => i.program_area === "demographics") && (
        <p className="text-sm text-muted">
          FHSIS demographic summaries report population, household estimates and counts of
          barangays, rural health units and barangay health stations. Population per unit is a
          ratio, not a percentage or a staffing target; it is undefined where the source reports
          zero units. Facility and barangay summaries cover provinces/HUCs and selected cities, not
          every municipality. These source totals are separate from the facility registry, the BHW
          census and the population figures used elsewhere on this site.
        </p>
      )}

      <section aria-labelledby="breakdowns-heading">
        <h2 id="breakdowns-heading" className="text-xl font-semibold">
          Age and sex breakdowns
        </h2>
        <p className="my-2 text-sm text-muted">
          These are the source’s reported groups. Age groups and sexes are separate views of the
          same services; do not add them together. Sex-specific counts have no rate where the source
          supplies no matching denominator. Some maternal age rates use the same all-age estimated
          population and represent shares of that population; screening-positive rates use the
          matching age group tested. Check each indicator’s denominator definition.
        </p>
        {breakdowns === null ? (
          <p role="status">Breakdown data is temporarily unavailable.</p>
        ) : breakdowns.length === 0 ? (
          <p>No age or sex breakdowns were reported for this area.</p>
        ) : (
          area.indicators
            .filter((i) => breakdowns.some((b) => b.indicator_key === i.indicator_key))
            .map((i) => (
              <details key={i.indicator_key} className="mb-3 rounded-lg border border-border p-4">
                <summary className="cursor-pointer font-medium">{i.label} — age / sex</summary>
                <p className="my-2 text-xs text-muted">
                  {fhsisPeriodLabel(i.indicator_key)} · {i.source_workbook}
                </p>
                <p className="mb-2 text-sm text-muted">Denominator: {i.denominator_def}</p>
                {i.indicator_key.startsWith("infect_schisto_") && i.unit === "count" && (
                  <p className="mb-2 text-sm text-muted">
                    Source rates are withheld because their denominators could not be established
                    from the annual tables. Source counts are retained.
                  </p>
                )}
                {i.indicator_key === "maternal_td_repeat" && (
                  <p className="mb-2 text-sm text-muted">
                    Age-group rates are withheld because the source reports zero rates despite
                    nonzero counts. Source counts are retained.
                  </p>
                )}
                <div
                  className="overflow-x-auto"
                  role="region"
                  aria-label={`${i.label} breakdowns`}
                  tabIndex={0}
                >
                  <table className="w-full min-w-[480px] text-left text-sm">
                    <caption className="sr-only">
                      Age and sex breakdowns: {i.label}, {geo.geoName}
                    </caption>
                    <thead>
                      <tr>
                        <th scope="col" className="p-2">
                          Group
                        </th>
                        <th scope="col" className="p-2">
                          Count
                        </th>
                        <th scope="col" className="p-2">
                          Denominator
                        </th>
                        <th scope="col" className="p-2">
                          Rate
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {breakdowns
                        .filter((b) => b.indicator_key === i.indicator_key)
                        .map((b) => (
                          <tr key={b.breakdown} className="border-t border-border">
                            <th scope="row" className="p-2 font-medium">
                              {fhsisBreakdownLabel(b.breakdown)}
                            </th>
                            <td className="p-2">{count(b.numerator)}</td>
                            <td className="p-2">{count(b.denominator)}</td>
                            <td className="p-2">{fhsisRateLabel(b)}</td>
                          </tr>
                        ))}
                    </tbody>
                  </table>
                </div>
              </details>
            ))
        )}
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
