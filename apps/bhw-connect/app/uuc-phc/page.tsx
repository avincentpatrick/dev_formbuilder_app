import type { Metadata } from "next";
import Link from "next/link";
import { NATIONAL_GEO_CODE } from "@/lib/filters/schema";
import {
  UUC_PHC_BRAND_LABEL,
  getUucPhcCounts,
  getUucPhcChildren,
  uucDeckCaption,
} from "@/lib/db/uuc-phc";
import { formatCount } from "@/lib/format";
import { CoverageHero } from "@/components/uuc-phc/coverage-hero";
import { ShareBar } from "@/components/uuc-phc/share-bar";
import { ChildBreakdown } from "@/components/uuc-phc/child-breakdown";
import { DownloadLinks } from "@/components/uuc-phc/download-links";
import { PresentationProvider } from "@/components/present/presentation-context";
import { PresentationSlide } from "@/components/present/presentation-slide";
import { PresentButton } from "@/components/present/present-button";
import { AskTheList } from "@/components/uuc-phc/ask-the-list";
import { resolveUucEdition } from "@/lib/db/uuc-phc-edition";
import { editionIssuanceLine, previousEdition, withEdition } from "@/lib/uuc-phc/editions";

type SearchParams = Promise<Record<string, string | string[] | undefined>>;

// Per request: the page shows the edition its `?edition=` names (lib/uuc-phc/editions.ts).
export const dynamic = "force-dynamic";

export async function generateMetadata({
  searchParams,
}: {
  searchParams: SearchParams;
}): Promise<Metadata> {
  const { edition } = await resolveUucEdition(searchParams);
  const counts = await getUucPhcCounts(NATIONAL_GEO_CODE, "national", edition.slug);
  const n = counts ? `${formatCount(counts.nListed)} barangays` : "Barangays";
  return {
    title: { absolute: `UUC for PHC ${edition.year} · Overview` },
    description: `The ${edition.year} list of Unserved and Underserved Communities for Primary Health Care: ${n} across the Philippines, with the share of each region's barangays on the list.`,
  };
}

export default async function UucPhcLanding({ searchParams }: { searchParams: SearchParams }) {
  const { edition, defaultEdition } = await resolveUucEdition(searchParams);
  const ed = (href: string) => withEdition(href, edition, defaultEdition);
  const previous = previousEdition(edition);
  const [counts, children, previousCounts] = await Promise.all([
    getUucPhcCounts(NATIONAL_GEO_CODE, "national", edition.slug),
    getUucPhcChildren(NATIONAL_GEO_CODE, "national", edition.slug),
    previous ? getUucPhcCounts(NATIONAL_GEO_CODE, "national", previous.slug) : null,
  ]);

  if (!counts) {
    return (
      <div className="flex flex-col gap-4">
        <h1 className="text-2xl font-semibold tracking-tight">UUC for PHC {edition.year}</h1>
        <p className="text-muted">This dataset is not available right now.</p>
      </div>
    );
  }

  const withAny = children.filter((c) => c.nListed > 0).length;

  // Title-slide facts for presentation mode (serializable, server → client). brandLabel is what
  // keeps the deck from presenting this dataset under the BHW Census's name — see plan U6.
  const deckMeta = {
    pageLabel: "National overview",
    areaName: "Philippines",
    filterChips: [] as string[],
    captionLine: uucDeckCaption(counts, "Philippines", edition),
    brandLabel: UUC_PHC_BRAND_LABEL,
  };

  return (
    <PresentationProvider meta={deckMeta}>
      <div className="flex flex-col gap-8">
        {/* Hero */}
        <section className="flex flex-col gap-3">
          <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">
            Which barangays are unserved or underserved?
          </h1>
          <p className="max-w-2xl text-muted">
            The Department of Health publishes a list of{" "}
            <strong>Unserved and Underserved Communities for Primary Health Care</strong> —
            barangays that are both hard to reach and socio-economically disadvantaged. A barangay
            qualifies only when <strong>both</strong> a physical and a socio-economic factor are
            present. This is the {edition.year} edition.
          </p>
          {previous && previousCounts && (
            // Only where both editions are loaded: a "changed since" line under a list whose
            // predecessor is not here would point at a page with nothing to show.
            <p className="max-w-2xl text-sm text-muted">
              The {previous.year} list had {formatCount(previousCounts.nListed)} barangays.{" "}
              <Link href={ed("/uuc-phc/changes")} className="underline hover:text-accent">
                See which barangays joined and left the list
              </Link>
              .
            </p>
          )}
          <PresentButton variant="secondary" />
        </section>

        {/* National figure */}
        <PresentationSlide id="coverage" title={`Barangays on the ${edition.year} list`}>
          <section className="rounded-lg border border-border bg-background p-5 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-4">
              <CoverageHero counts={counts} areaLabel="the Philippines" />
              <h2 className="text-lg font-semibold tracking-tight text-muted">Philippines</h2>
            </div>
            <div className="mt-6">
              <ShareBar counts={counts} />
            </div>
            <div className="mt-5 flex flex-col gap-3 border-t border-border pt-4">
              <p className="text-xs text-muted">{editionIssuanceLine(edition)}</p>
              <DownloadLinks
                geoLevel="national"
                geoCode={NATIONAL_GEO_CODE}
                nListed={counts.nListed}
                edition={edition}
              />
            </div>
          </section>
        </PresentationSlide>

        {/* Region breakdown */}
        <PresentationSlide id="regions" title="By region">
          <div className="rounded-lg border border-border bg-background p-5 sm:p-6">
            <ChildBreakdown heading="Regions" items={children} />
            {children.length > 0 && (
              <p className="mt-3 border-t border-border pt-4 text-xs text-muted">
                {withAny} of {children.length} regions have at least one barangay on the list. Drill
                into any region for its provinces, cities and municipalities.
              </p>
            )}
          </div>
        </PresentationSlide>

        <p className="text-sm text-muted">
          See{" "}
          <Link href={ed("/uuc-phc/criteria")} className="underline hover:text-accent">
            why these barangays qualified
          </Link>{" "}
          for the four routes onto the list,{" "}
          <Link href={ed("/uuc-phc/indicators")} className="underline hover:text-accent">
            the indicators behind it
          </Link>{" "}
          for how the 12 measurements are distributed,{" "}
          <Link href={ed("/uuc-phc/bhw-coverage")} className="underline hover:text-accent">
            BHW coverage and the list
          </Link>{" "}
          for how the two datasets here compare, or the{" "}
          <Link href={ed("/uuc-phc/methodology")} className="underline hover:text-accent">
            methodology
          </Link>{" "}
          for how the {formatCount(counts.nListed)} barangays were counted.
        </p>

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
