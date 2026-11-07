import type { Metadata } from "next";
import Link from "next/link";
export const metadata: Metadata = { title: "Methodology" };
export default function Methodology() {
  return (
    <article className="flex max-w-3xl flex-col gap-5">
      <h1 className="text-3xl font-semibold">Health services: methodology</h1>
      <h2 className="text-xl font-semibold">Source and release coverage</h2>
      <p>
        The Department of Health’s{" "}
        <a href="https://bit.ly/FHSISPHSannualreports" className="underline">
          public FHSIS annual reports archive
        </a>{" "}
        supplies the 2025 programme workbooks. This snapshot was retrieved on 7 November 2026 and
        includes selected programmes; it is not a claim that every 2025 programme is included or
        that this is today’s latest release.
      </p>
      <p>
        This section covers 20 service indicators in immunisation, maternal antenatal care,
        environmental health and TB, plus eight non-BHW health workforce cadres from Demographics.
        It shows the source’s total breakdown, at national, region, province/HUC and
        city/municipality levels. Eight-visit antenatal care was introduced mid-year and covers
        Q3–Q4 2025 only; do not compare it directly with full-year four-visit coverage. Water and
        sanitation are Q4 snapshots, not annual totals.
      </p>
      <h2 className="text-xl font-semibold">Geography and missing reports</h2>
      <p>
        PSGC-coded records join to the site’s geography. Source code errors are resolved using area
        names and province context, with corrections retained in the load audit. Additional hospital
        rows are excluded from LGU records; published parent totals are retained. The source does
        not provide barangay-level service indicators in this section. Province/HUC name-keyed
        programmes such as intrapartum care, nutrition, vital statistics and family planning, and
        the separate morbidity tables, are not included.
      </p>
      <p>
        A missing indicator row means “Not reported in 2025”, not zero. A row with a zero is
        displayed as zero. A reported count with no rate retains its count and says “Rate not
        reported”. A failed data read says the data is temporarily unavailable.
      </p>
      <h2 className="text-xl font-semibold">Rates, units and denominators</h2>
      <p>
        Every rate is the source’s own published rate for that area; rates are never averaged or
        recomputed from child areas. Each indicator includes its source numerator and denominator
        definitions. Fractional source values are preserved. Water-system and sanitation-facility
        component shares use households with basic services as denominator; overall basic and safely
        managed coverage use projected households. Most rates are percentages; TB case notification
        rates are cases per 100,000 population and may normally exceed 100.
      </p>
      <p>
        Percentage coverage above 100% is retained and marked †, never capped. The source recorded
        more services than its stated denominator; where the denominator is a DOH projected eligible
        population, the true coverage is unknown. TB treatment success uses registered cases as its
        denominator, not projected population. Some CIC denominators and rates are negative in the
        source because the projected cohort minus prior FIC is negative; these are retained as
        reported and should not be interpreted as valid negative coverage.
      </p>
      <p>
        FHSIS’s population and household projections are only its own indicator denominators. BHW
        density elsewhere on the site continues to use StepZero population with PSA census
        population as fallback. A figure’s denominator N describes that indicator’s eligible
        population or cases, never the national BHW count.
      </p>
      <h2 className="text-xl font-semibold">Published subtotals</h2>
      <p>
        Source subtotals may differ from the sum of child records. Each area’s reconciliation panel
        reports the published numerator, the sum of reported children and the residual (parent minus
        children). A positive residual means reported children fall short; a negative residual means
        they exceed the parent. Missing reconciliation rows do not prove agreement. The displayed
        rates always use the published parent row.
      </p>
      <h2 className="text-xl font-semibold">Workforce context</h2>
      <p>
        FHSIS supplies eight other health workforce cadres, split into LGU- and DOH-hired staff. It
        supplies no BHW count here: the source’s BHW filing tally was excluded at ingestion.
        BHW-per-staff ratios use the profiled workforce from the{" "}
        <Link href="/bhw" className="underline">
          2025 BHW Census
        </Link>{" "}
        as numerator and the source’s staff total as denominator. They are descriptive context, not
        staffing targets or evidence of causation. Missing or zero denominators and unavailable or
        suppressed census counts produce no ratio.
      </p>
      <h2 className="text-xl font-semibold">Interpretation</h2>
      <p>
        The region and area tables rank FIC coverage from lowest to highest, placing missing rates
        last. This is a comparison of reported service coverage, not a league table of BHW
        performance. Service counts, population projections, reporting completeness and cohort
        definitions can all affect a rate. The geographic and time coverage differs from the UUC for
        PHC targeting list.
      </p>
    </article>
  );
}
