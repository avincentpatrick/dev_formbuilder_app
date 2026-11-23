import type { Metadata } from "next";
import { resolveUucEdition } from "@/lib/db/uuc-phc-edition";
import { NATIONAL_GEO_CODE } from "@/lib/filters/schema";
import { UUC_PHC_BRAND_LABEL, uucDeckCaption, getUucPhcCounts } from "@/lib/db/uuc-phc";
import { getUucPhcCriteria, getUucPhcCriteriaChildren } from "@/lib/db/uuc-phc-criteria";
import { CriteriaSection } from "@/components/uuc-phc/criteria-section";
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
    title: { absolute: `Why these barangays qualified · UUC for PHC ${edition.year}` },
    description: `How the barangays on the ${edition.year} UUC for PHC list qualified: Indigenous Peoples, conflict and displacement, 4Ps enrolment, and health indicators against the province. The four routes overlap.`,
  };
}

export default async function UucPhcCriteriaLanding({
  searchParams,
}: {
  searchParams: SearchParams;
}) {
  const { edition } = await resolveUucEdition(searchParams);
  const [criteria, counts, children] = await Promise.all([
    getUucPhcCriteria(NATIONAL_GEO_CODE, "national", edition.slug),
    getUucPhcCounts(NATIONAL_GEO_CODE, "national", edition.slug),
    getUucPhcCriteriaChildren(NATIONAL_GEO_CODE, "national", edition.slug),
  ]);

  if (!criteria) {
    return (
      <div className="flex flex-col gap-4">
        <h1 className="text-2xl font-semibold tracking-tight">Why these barangays qualified</h1>
        <p className="text-muted">This breakdown is not available right now.</p>
      </div>
    );
  }

  const deckMeta = {
    pageLabel: "Qualifying criteria",
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
              Why these barangays qualified
            </h1>
            <PresentButton variant="secondary" />
          </div>
          <p className="max-w-2xl text-muted">
            DOH AO No. 2020-0023 sets four socio-economic routes onto the list, and a barangay needs
            only one of them alongside the physical factor. This is how many barangays came in on
            each — nationally, and for every region, province, city and municipality.
          </p>
        </section>

        <CriteriaSection
          listYear={edition.year}
          criteria={criteria}
          areaLabel="the Philippines"
          childHeading="Regions"
          childAreas={children}
          coverageHref="/uuc-phc"
          indicatorsHref="/uuc-phc/indicators"
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
