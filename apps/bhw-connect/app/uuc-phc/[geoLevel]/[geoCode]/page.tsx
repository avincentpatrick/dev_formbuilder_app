import { formatPct } from "@/lib/format";
import Link from "next/link";
import { Breadcrumbs } from "@/components/layout/breadcrumbs";
import { PORTAL_CRUMB, UUC_PHC_CRUMB } from "@/lib/nav/breadcrumbs";
import type { Metadata } from "next";
import { notFound, redirect } from "next/navigation";
import { getGeoAncestors, getGeoByCode } from "@/lib/db/geo";
import {
  UUC_PHC_BRAND_LABEL,
  getUucPhcBarangays,
  getUucPhcChildren,
  getUucPhcCounts,
  getUucPhcStaticParams,
  uucDeckCaption,
} from "@/lib/db/uuc-phc";
import { GEO_LEVELS, type GeoLevel } from "@/lib/filters/schema";
import { formatCount } from "@/lib/format";
import { CoverageHero } from "@/components/uuc-phc/coverage-hero";
import { ShareBar } from "@/components/uuc-phc/share-bar";
import { ChildBreakdown } from "@/components/uuc-phc/child-breakdown";
import { BarangayList } from "@/components/uuc-phc/barangay-list";
import { DownloadLinks } from "@/components/uuc-phc/download-links";
import { getUucPhcBarangayDetails } from "@/lib/db/uuc-phc-indicators";
import { PresentationProvider } from "@/components/present/presentation-context";
import { PresentationSlide } from "@/components/present/presentation-slide";
import { PresentButton } from "@/components/present/present-button";
import { AiInsight } from "@/components/narrative/ai-insight";
import { AskTheList } from "@/components/uuc-phc/ask-the-list";
import { resolveUucEdition, defaultUucEdition } from "@/lib/db/uuc-phc-edition";
import { editionIssuanceLine, withEdition } from "@/lib/uuc-phc/editions";

// 1 hour. ISR: citymun render on demand; region/province are prerendered. Same reasoning as the
// landing page — a shorter window bounds how long a transient empty read can stay cached.
export const revalidate = 3_600;
// Per request, not ISR: the root layout reads the per-request CSP nonce (lib/csp.ts), and an ISR
// render of this page reads it outside a request and answers 500 (DYNAMIC_SERVER_USAGE).
export const dynamic = "force-dynamic";

const CHILD_HEADING: Record<GeoLevel, string | null> = {
  national: "Regions",
  region: "Provinces",
  province: "Cities / municipalities",
  citymun: null, // handled by BarangayList — the aggregate stops at citymun
  barangay: null,
};

type Params = { geoLevel: string; geoCode: string };
type SearchParams = Promise<Record<string, string | string[] | undefined>>;

export async function generateStaticParams() {
  const params = await getUucPhcStaticParams((await defaultUucEdition()).slug);
  return params.map((p) => ({ geoLevel: p.geoLevel, geoCode: p.geoCode }));
}

function isGeoLevel(value: string): value is GeoLevel {
  return (GEO_LEVELS as readonly string[]).includes(value);
}

async function loadGeo(params: Params) {
  if (!isGeoLevel(params.geoLevel)) return null;
  const geo = await getGeoByCode(params.geoCode);
  if (!geo || geo.geoLevel !== params.geoLevel) return null;
  return geo;
}

export async function generateMetadata({
  params,
  searchParams,
}: {
  params: Promise<Params>;
  searchParams: SearchParams;
}): Promise<Metadata> {
  const geo = await loadGeo(await params);
  if (!geo) return { title: "Area not found" };

  const { edition } = await resolveUucEdition(searchParams);
  const counts = await getUucPhcCounts(geo.geoCode, geo.geoLevel, edition.slug);
  const description =
    counts && counts.nBarangays > 0
      ? `${formatCount(counts.nListed)} of ${formatCount(
          counts.nBarangays,
        )} barangays in ${geo.geoName} are on the ${edition.year} list of Unserved and Underserved Communities for Primary Health Care${
          counts.sharePct === null ? "" : ` (${formatPct(counts.sharePct, "single")})`
        }.`
      : `${edition.year} UUC for PHC status for ${geo.geoName}.`;

  return { title: `${geo.geoName} · ${edition.year}`, description };
}

export default async function UucPhcAreaPage({
  params,
  searchParams,
}: {
  params: Promise<Params>;
  searchParams: SearchParams;
}) {
  const geo = await loadGeo(await params);
  if (!geo) notFound();
  const { edition, defaultEdition } = await resolveUucEdition(searchParams);
  const ed = (href: string) => withEdition(href, edition, defaultEdition);

  // Barangay pages would be a single yes/no — the city/municipality page already names every
  // barangay and its status, so that is where the drill-down ends.
  if (geo.geoLevel === "barangay") {
    const ancestors = await getGeoAncestors(geo.geoCode, geo.geoLevel);
    if (!ancestors.citymun) notFound();
    redirect(ed(`/uuc-phc/citymun/${ancestors.citymun.geoCode}`));
  }

  const isCitymun = geo.geoLevel === "citymun";
  const [counts, children, barangays, details, ancestors] = await Promise.all([
    getUucPhcCounts(geo.geoCode, geo.geoLevel, edition.slug),
    getUucPhcChildren(geo.geoCode, geo.geoLevel, edition.slug),
    isCitymun ? getUucPhcBarangays(geo.geoCode, edition.slug) : Promise.resolve([]),
    // Indicators render only here, at barangay grain, where a capped value can carry its own
    // marker — see lib/db/uuc-phc-indicators.ts on why there are no indicator aggregates.
    isCitymun ? getUucPhcBarangayDetails(geo.geoCode, edition.slug) : Promise.resolve([]),
    getGeoAncestors(geo.geoCode, geo.geoLevel),
  ]);

  // Breadcrumb: Overview › region › province › (current). Only ancestors above this level.
  const crumbAncestors = [ancestors.region, ancestors.province, ancestors.citymun].filter(
    (a): a is NonNullable<typeof a> => a !== null && a.geoCode !== geo.geoCode,
  );

  const childHeading = CHILD_HEADING[geo.geoLevel];

  // Title-slide facts for presentation mode. The chips are this area's ancestry, the same context
  // the breadcrumb carries — a deck opened on a city should say which province it is in.
  const deckMeta = {
    pageLabel: "Area profile",
    areaName: geo.geoName,
    filterChips: crumbAncestors.map((a) => a.geoName),
    captionLine: uucDeckCaption(counts, geo.geoName, edition),
    brandLabel: UUC_PHC_BRAND_LABEL,
  };

  return (
    <PresentationProvider meta={deckMeta}>
      <div className="flex flex-col gap-8">
        {/* Breadcrumb — built here rather than by the layout's `SiteBreadcrumbs`
            because the ancestor names come from the database. */}
        <Breadcrumbs
          items={[
            PORTAL_CRUMB,
            { ...UUC_PHC_CRUMB, href: ed(UUC_PHC_CRUMB.href ?? "/uuc-phc") },
            ...crumbAncestors.map((a) => ({
              label: a.geoName,
              href: ed(`/uuc-phc/${a.geoLevel}/${a.geoCode}`),
            })),
            { label: geo.geoName },
          ]}
        />

        <div className="flex flex-wrap items-center justify-between gap-3">
          <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">{geo.geoName}</h1>
          <PresentButton variant="secondary" />
        </div>

        {counts ? (
          <>
            <PresentationSlide id="coverage" title={`Barangays on the ${edition.year} list`}>
              <section className="rounded-lg border border-border bg-background p-5 sm:p-6">
                <CoverageHero counts={counts} areaLabel={geo.geoName} />
                <div className="mt-6">
                  <ShareBar counts={counts} />
                </div>
                <div className="mt-5 flex flex-col gap-3 border-t border-border pt-4">
                  <p className="text-xs text-muted">
                    {editionIssuanceLine(edition)}
                    {counts.nListed > 0 && (
                      <>
                        {" · "}
                        <Link
                          href={ed(`/uuc-phc/criteria/${geo.geoLevel}/${geo.geoCode}`)}
                          className="underline hover:text-accent"
                        >
                          Why these {formatCount(counts.nListed)} qualified
                        </Link>
                        {" · "}
                        <Link
                          href={ed(`/uuc-phc/indicators/${geo.geoLevel}/${geo.geoCode}`)}
                          className="underline hover:text-accent"
                        >
                          The indicators behind them
                        </Link>
                      </>
                    )}
                  </p>
                  <DownloadLinks
                    geoLevel={geo.geoLevel}
                    geoCode={geo.geoCode}
                    nListed={counts.nListed}
                    edition={edition}
                  />
                </div>
                {counts.nListed === 0 && (
                  // A real zero, not a gap: this dataset is a single national publication, so an
                  // area with nothing listed was covered and assessed, not left out.
                  <p className="mt-4 text-sm text-muted">
                    No barangay in {geo.geoName} is on the {edition.year} list. The list is national
                    and complete as published, so this is a result rather than missing data.
                  </p>
                )}
              </section>
            </PresentationSlide>

            {/* The insight is promoted like /bhw's, and unlike U6's barangay disclosures: its
                caveats are inside its sentences (UUC_PHC_SYSTEM_PROMPT rule 4) rather than in a
                footnote that promotion would leave behind, so there is nothing here for a slide to
                strip. `narrativeType` is what keeps this out of the BHW insight's cache row. */}
            {/* The insight summarises the newest edition (lib/ai/dataset-scope.ts), so it shows only
                there: under an older edition's figures it would describe a different list. */}
            {edition.slug === defaultEdition.slug && (
              <PresentationSlide id="ai-insight" title="AI insight">
                <AiInsight
                  geoCode={geo.geoCode}
                  geoLevel={geo.geoLevel}
                  geoName={geo.geoName}
                  narrativeType="uuc_overview"
                  methodologyHref="/uuc-phc/methodology#ask"
                />
              </PresentationSlide>
            )}

            {childHeading && children.length > 0 && (
              <PresentationSlide id="children" title={childHeading}>
                <div className="rounded-lg border border-border bg-background p-5 sm:p-6">
                  <ChildBreakdown heading={childHeading} items={children} />
                </div>
              </PresentationSlide>
            )}

            {isCitymun && barangays.length > 0 && (
              // The barangay list is one slide, not one per barangay: the indicator disclosures
              // inside it stay closed on promotion, so a capped value cannot reach a screen
              // without the † footnote that travels with it (U3).
              <PresentationSlide id="barangays" title="Listed barangays">
                <div className="rounded-lg border border-border bg-background p-5 sm:p-6">
                  <BarangayList items={barangays} details={details} />
                </div>
              </PresentationSlide>
            )}
          </>
        ) : (
          <div className="rounded-lg border border-border bg-surface p-5 text-sm text-muted sm:p-6">
            <p>UUC for PHC data for {geo.geoName} could not be loaded right now.</p>
            <Link href={ed("/uuc-phc")} className="mt-3 inline-block underline hover:text-accent">
              ← Back to overview
            </Link>
          </div>
        )}

        <AskTheList
          geoCode={geo.geoCode}
          geoLevel={geo.geoLevel}
          geoName={geo.geoName}
          listYear={edition.year}
        />
      </div>
    </PresentationProvider>
  );
}
