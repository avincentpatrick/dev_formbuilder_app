import type { Metadata } from "next";
import { resolveUucEdition } from "@/lib/db/uuc-phc-edition";
import { NATIONAL_GEO_CODE } from "@/lib/filters/schema";
import { UUC_PHC_BRAND_LABEL, uucDeckCaption, getUucPhcCounts } from "@/lib/db/uuc-phc";
import { getUucBhwCoverage, getUucBhwCoverageChildren } from "@/lib/db/uuc-phc-bhw-coverage";
import { BhwCoverageSection } from "@/components/uuc-phc/bhw-coverage-section";
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
    title: { absolute: `BHW coverage and the list · UUC for PHC ${edition.year}` },
    description: `A consistency check, not a finding: how BHW coverage compares between the barangays on the ${edition.year} UUC for PHC list and every other barangay in the same area. UUC status is defined partly on distance to a health facility, so the comparison is partly definitional.`,
  };
}

export default async function UucPhcBhwCoverageLanding({
  searchParams,
}: {
  searchParams: SearchParams;
}) {
  const { edition } = await resolveUucEdition(searchParams);
  const [coverage, counts, children] = await Promise.all([
    getUucBhwCoverage(NATIONAL_GEO_CODE, "national", edition.slug),
    getUucPhcCounts(NATIONAL_GEO_CODE, "national", edition.slug),
    getUucBhwCoverageChildren(NATIONAL_GEO_CODE, "national", edition.slug),
  ]);

  if (!coverage) {
    return (
      <div className="flex flex-col gap-4">
        <h1 className="text-2xl font-semibold tracking-tight">BHW coverage and the list</h1>
        <p className="text-muted">This comparison is not available right now.</p>
      </div>
    );
  }

  const deckMeta = {
    pageLabel: "BHW coverage and the list",
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
              BHW coverage and the list
            </h1>
            <PresentButton variant="secondary" />
          </div>
          <p className="max-w-2xl text-muted">
            The two datasets on this dashboard meet here: how many households each Barangay Health
            Worker covers in the barangays on the {edition.year} list, against every other barangay
            in the same area. Read the note below first — the comparison is not the discovery it
            looks like.
          </p>
        </section>

        <BhwCoverageSection
          listYear={edition.year}
          coverage={coverage}
          areaLabel="the Philippines"
          childHeading="Regions"
          childAreas={children}
          coverageHref="/uuc-phc"
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
