import type { Metadata } from "next";
import { resolveUucEdition } from "@/lib/db/uuc-phc-edition";
import { EditionLink as Link } from "@/components/uuc-phc/edition-link";
import { NATIONAL_GEO_CODE } from "@/lib/filters/schema";
import { getUucPhcIndicatorDist } from "@/lib/db/uuc-phc-indicator-dist";
import {
  getUucPhcBenchmarkGaps,
  getUucPhcPublishedDeltas,
  getUucPhcQualityTotals,
} from "@/lib/db/uuc-phc-quality";
import {
  BenchmarkSection,
  BoundedSection,
  ReconciliationSection,
  UnresolvedSection,
} from "@/components/uuc-phc/quality-sections";
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
    title: { absolute: `Data quality · UUC for PHC ${edition.year}` },
    description: `What is known to be wrong with the ${edition.year} UUC for PHC indicator data: the values bounded during cleaning, the provincial benchmarks that cannot carry the health comparison, and what remains unresolved.`,
  };
}

export default async function UucPhcDataQualityPage({
  searchParams,
}: {
  searchParams: SearchParams;
}) {
  const { edition } = await resolveUucEdition(searchParams);
  const [totals, dists, gaps, deltas] = await Promise.all([
    getUucPhcQualityTotals(edition.slug),
    getUucPhcIndicatorDist(NATIONAL_GEO_CODE, "national", edition.slug),
    getUucPhcBenchmarkGaps(edition.slug),
    getUucPhcPublishedDeltas(edition.slug),
  ]);

  // An empty data-quality page reads as a clean bill of health, which is the one thing it must
  // never say by accident. If the totals could not be read, say so rather than rendering nothing.
  if (!totals) {
    return (
      <div className="flex flex-col gap-4">
        <h1 className="text-2xl font-semibold tracking-tight">Data quality</h1>
        <p className="text-muted">
          These figures are not available right now. That is a failure to load them, not a finding
          that there is nothing to report — what is known about this dataset&rsquo;s quality is
          written up in{" "}
          <Link href="/uuc-phc/methodology" className="underline hover:text-accent">
            the methodology
          </Link>
          .
        </p>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-8">
      <section className="flex flex-col gap-3">
        <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">Data quality</h1>
        <p className="max-w-2xl text-muted">
          This dashboard publishes the {edition.year} list as issued, and what is wrong with the
          data behind it is published alongside rather than left in a file nobody reads. Everything
          on this page is recomputed from the data itself each time it renders — including the
          figures that make this dashboard look worse — so it cannot quietly drift away from what
          the data actually says.
        </p>
      </section>

      <BoundedSection totals={totals} dists={dists} />
      <BenchmarkSection gaps={gaps} />
      {/* The reconciliation is against the 2027 Budget Cue Cards p37, which cites the 2025 list
          (DC No. 2025-0549) and no other edition — so it belongs to 2025's page alone. */}
      {edition.year === "2025" && <ReconciliationSection deltas={deltas} />}
      <UnresolvedSection totals={totals} />

      <AskTheList
        geoCode={NATIONAL_GEO_CODE}
        geoLevel="national"
        geoName="Philippines"
        listYear={edition.year}
      />
    </div>
  );
}
