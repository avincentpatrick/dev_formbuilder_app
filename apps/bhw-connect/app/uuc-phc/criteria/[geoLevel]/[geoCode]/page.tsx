import { formatPct } from "@/lib/format";
import { EditionLink as Link } from "@/components/uuc-phc/edition-link";
import { Breadcrumbs } from "@/components/layout/breadcrumbs";
import { PORTAL_CRUMB, UUC_PHC_CRUMB } from "@/lib/nav/breadcrumbs";
import type { Metadata } from "next";
import { defaultUucEdition, resolveUucEdition } from "@/lib/db/uuc-phc-edition";
import { withEdition } from "@/lib/uuc-phc/editions";
import { notFound } from "next/navigation";
import { getGeoAncestors, getGeoByCode } from "@/lib/db/geo";
import {
  UUC_PHC_BRAND_LABEL,
  getUucPhcCounts,
  getUucPhcStaticParams,
  uucDeckCaption,
} from "@/lib/db/uuc-phc";
import {
  getUucPhcCriteria,
  getUucPhcCriteriaChildren,
  type UucPhcCriteria,
} from "@/lib/db/uuc-phc-criteria";
import { GEO_LEVELS, type GeoLevel } from "@/lib/filters/schema";
import { formatCount } from "@/lib/format";
import { CriteriaSection } from "@/components/uuc-phc/criteria-section";
import { PresentationProvider } from "@/components/present/presentation-context";
import { PresentButton } from "@/components/present/present-button";
import { AskTheList } from "@/components/uuc-phc/ask-the-list";

type SearchParams = Promise<Record<string, string | string[] | undefined>>;

// 1 hour. Region and province are prerendered by the coverage section's params; citymun renders on
// demand. Same reasoning as the rest of the section.
export const revalidate = 3_600;
// Per request, not ISR: the root layout reads the per-request CSP nonce (lib/csp.ts), and an ISR
// render of this page reads it outside a request and answers 500 (DYNAMIC_SERVER_USAGE).
export const dynamic = "force-dynamic";

const CHILD_HEADING: Record<GeoLevel, string | null> = {
  national: "Regions",
  region: "Provinces",
  province: "Cities / municipalities",
  // The aggregate stops at citymun — below it every row would be one barangay's four yes/nos,
  // which is what the coverage page's per-barangay disclosure already shows.
  citymun: null,
  barangay: null,
};

type Params = { geoLevel: string; geoCode: string };

// The same region and province set the coverage route prerenders, from the same helper — the two
// routes are one drill-down and should not differ in which areas are built ahead of time.
// City/municipality pages are left to ISR, as there.
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

/** The lead sentence of the page description — the route that carried most of the area's list. */
function leadingRoute(criteria: UucPhcCriteria): string | null {
  const ranked = criteria.routes
    .filter((route) => route.sharePct !== null)
    .sort((a, b) => (b.sharePct ?? 0) - (a.sharePct ?? 0));
  const top = ranked[0];
  if (!top || top.count === 0) return null;
  return `${formatCount(top.count)} of them on ${top.label.toLowerCase()} (${formatPct(top.sharePct)})`;
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

  const criteria = await getUucPhcCriteria(geo.geoCode, geo.geoLevel, edition.slug);
  if (!criteria || criteria.nListed === 0) {
    return {
      title: `Why these barangays qualified · ${geo.geoName}`,
      description: `No barangay in ${geo.geoName} is on the ${edition.year} UUC for PHC list.`,
    };
  }

  const lead = leadingRoute(criteria);
  return {
    title: `Why these barangays qualified · ${geo.geoName}`,
    description: `How ${geo.geoName}'s ${formatCount(
      criteria.nListed,
    )} barangays on the ${edition.year} UUC for PHC list qualified${lead ? `: ${lead}` : ""}. The four routes overlap.`,
  };
}

export default async function UucPhcCriteriaAreaPage({
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

  // Barangay pages would be four yes/nos, which the coverage page's disclosure already renders.
  if (geo.geoLevel === "barangay") notFound();

  const [criteria, counts, children, ancestors] = await Promise.all([
    getUucPhcCriteria(geo.geoCode, geo.geoLevel, edition.slug),
    getUucPhcCounts(geo.geoCode, geo.geoLevel, edition.slug),
    getUucPhcCriteriaChildren(geo.geoCode, geo.geoLevel, edition.slug),
    getGeoAncestors(geo.geoCode, geo.geoLevel),
  ]);

  const crumbAncestors = [ancestors.region, ancestors.province, ancestors.citymun].filter(
    (a): a is NonNullable<typeof a> => a !== null && a.geoCode !== geo.geoCode,
  );

  const deckMeta = {
    pageLabel: "Qualifying criteria",
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
            { label: "Qualifying criteria", href: ed("/uuc-phc/criteria") },
            ...crumbAncestors.map((a) => ({
              label: a.geoName,
              href: ed(`/uuc-phc/criteria/${a.geoLevel}/${a.geoCode}`),
            })),
            { label: geo.geoName },
          ]}
        />

        <div className="flex flex-wrap items-center justify-between gap-3">
          <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">
            Why these barangays qualified
            <span className="block text-base font-normal text-muted">{geo.geoName}</span>
          </h1>
          <PresentButton variant="secondary" />
        </div>

        {criteria ? (
          <CriteriaSection
            listYear={edition.year}
            criteria={criteria}
            areaLabel={geo.geoName}
            childHeading={CHILD_HEADING[geo.geoLevel]}
            childAreas={children}
            coverageHref={`/uuc-phc/${geo.geoLevel}/${geo.geoCode}`}
            indicatorsHref={`/uuc-phc/indicators/${geo.geoLevel}/${geo.geoCode}`}
          />
        ) : (
          <div className="rounded-lg border border-border bg-surface p-5 text-sm text-muted sm:p-6">
            <p>The qualifying-route breakdown for {geo.geoName} could not be loaded right now.</p>
            <Link
              href="/uuc-phc/criteria"
              className="mt-3 inline-block underline hover:text-accent"
            >
              ← Back to qualifying criteria
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
