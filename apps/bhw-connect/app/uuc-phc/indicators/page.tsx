import type { Metadata } from "next";
import { resolveUucEdition } from "@/lib/db/uuc-phc-edition";
import { NATIONAL_GEO_CODE } from "@/lib/filters/schema";
import { UUC_PHC_BRAND_LABEL, uucDeckCaption, getUucPhcCounts } from "@/lib/db/uuc-phc";
import { getUucPhcIndicatorDist } from "@/lib/db/uuc-phc-indicator-dist";
import { IndicatorsSection } from "@/components/uuc-phc/indicators-section";
import { PresentationProvider } from "@/components/present/presentation-context";
import { PresentButton } from "@/components/present/present-button";
import { AskTheList } from "@/components/uuc-phc/ask-the-list";

type SearchParams = Promise<Record<string, string | string[] | undefined>>;

// Per request: the page shows the edition its `?edition=` names (lib/uuc-phc/editions.ts).
export const dynamic = "force-dynamic";

export async function generateMetadata({
  searchParams,
}: {
  searchParams: SearchParams;
}): Promise<Metadata> {
  const { edition } = await resolveUucEdition(searchParams);
  return {
    title: { absolute: `The indicators behind the list · UUC for PHC ${edition.year}` },
    description: `How the barangays on the ${edition.year} UUC for PHC list are distributed across the 12 indicators they were assessed on, with the values bounded during cleaning counted separately. No averages: a mean would hide them.`,
  };
}

export default async function UucPhcIndicatorsLanding({
  searchParams,
}: {
  searchParams: SearchParams;
}) {
  const { edition } = await resolveUucEdition(searchParams);
  const [counts, dists] = await Promise.all([
    getUucPhcCounts(NATIONAL_GEO_CODE, "national", edition.slug),
    getUucPhcIndicatorDist(NATIONAL_GEO_CODE, "national", edition.slug),
  ]);

  // `counts` is what says whether anything is listed; `dists` being empty with counts present is a
  // read failure, and the two must not be confused — see the module note on getUucPhcIndicatorDist.
  if (!counts || dists.length === 0) {
    return (
      <div className="flex flex-col gap-4">
        <h1 className="text-2xl font-semibold tracking-tight">The indicators behind the list</h1>
        <p className="text-muted">These distributions are not available right now.</p>
      </div>
    );
  }

  const deckMeta = {
    pageLabel: "Indicators",
    areaName: "Philippines",
    filterChips: [] as string[],
    captionLine: uucDeckCaption(counts, "Philippines", edition),
    brandLabel: UUC_PHC_BRAND_LABEL,
  };

  return (
    <PresentationProvider meta={deckMeta}>
      <div className="flex flex-col gap-8">
        <section className="flex flex-col gap-3">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">
              The indicators behind the list
            </h1>
            <PresentButton variant="secondary" />
          </div>
          <p className="max-w-2xl text-muted">
            The 12 measurements each listed barangay was assessed on, as distributions rather than
            as figures — every value at its own position, for the whole country and for every
            region, province, city and municipality.
          </p>
        </section>

        <IndicatorsSection
          listYear={edition.year}
          dists={dists}
          areaLabel="the Philippines"
          nListed={counts.nListed}
          coverageHref="/uuc-phc"
          criteriaHref="/uuc-phc/criteria"
        />

        <AskTheList
          geoCode={NATIONAL_GEO_CODE}
          geoLevel="national"
          geoName="Philippines"
          listYear={edition.year}
        />
      </div>
    </PresentationProvider>
  );
}
