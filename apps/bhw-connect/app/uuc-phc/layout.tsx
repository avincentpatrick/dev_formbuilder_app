import { GeoSearch } from "@/components/home/geo-search";
import { IconnectMark } from "@/components/branding/iconnect-logo";
import { InnovationEndorsement } from "@/components/branding/innovation-endorsement";
import type { Metadata } from "next";
import Link from "next/link";
import { Suspense, type ReactNode } from "react";
import { SiteBreadcrumbs } from "@/components/layout/site-breadcrumbs";
import { ListCorrection } from "@/components/uuc-phc/list-correction";
import { EditionLink, EditionSwitch, EditionText } from "@/components/uuc-phc/edition-link";
import { getAvailableUucEditions } from "@/lib/db/uuc-phc-edition";
import { UUC_EDITIONS } from "@/lib/uuc-phc/editions";

// Its own dataset and its own section, distinct from the BHW Census and the 2026 Profiling
// Status — so it carries its own title template rather than the root layout's "%s · BHW Connect",
// and supplies its own slim chrome. The shared BHW header/footer suppress themselves on
// /uuc-phc/* (see components/layout/header.tsx + footer-gate.tsx), mirroring /profiling-status.
//
// The list is published annually and the section shows one edition at a time (`?edition=<year>`,
// defaulting to the newest loaded — lib/uuc-phc/editions.ts). A layout cannot read search params,
// so the title template names no year; each page's own title does.
export const metadata: Metadata = {
  title: {
    default: "UUC for PHC",
    template: "%s · UUC for PHC",
  },
  description:
    "The Department of Health's list of Unserved and Underserved Communities for Primary Health Care — which barangays are on it, from national down to city/municipality level, edition by edition.",
};

/** The section's nav: each link keeps the reader on the edition they are viewing. */
const SECTION_LINKS: readonly [string, string][] = [
  ["/uuc-phc", "Overview"],
  ["/uuc-phc/criteria", "Criteria"],
  ["/uuc-phc/indicators", "Indicators"],
  ["/uuc-phc/bhw-coverage", "BHW coverage"],
  ["/uuc-phc/changes", "Changes"],
  ["/uuc-phc/data-quality", "Data quality"],
  ["/uuc-phc/methodology", "Methodology"],
];

/** The switch's server fallback: the default edition's badge, as the header always showed. */
const NAV_LINK = "shrink-0 whitespace-nowrap rounded-md px-3 py-2 font-medium hover:bg-surface";

function EditionBadge({ year }: { year: string }) {
  return (
    <span className="rounded-full bg-accent-subtle px-2 py-0.5 text-xs font-medium text-accent">
      {year}
    </span>
  );
}

function SectionHeader({ availableYears }: { availableYears: readonly string[] }) {
  return (
    <header className="border-b border-border bg-background">
      <div className="mx-auto flex max-w-5xl flex-wrap items-center gap-x-1 gap-y-2 px-4 py-3 sm:px-6">
        <Link
          href="/uuc-phc"
          className="order-1 flex items-center gap-2 text-lg font-semibold tracking-tight"
        >
          <IconnectMark />
          <span>UUC for PHC</span>
        </Link>
        {/* Outside the home link: the switch is links of its own, and a link inside a link is
            invalid markup. */}
        <Suspense fallback={<EditionBadge year={availableYears[0]} />}>
          <EditionSwitch availableYears={availableYears} className="order-1 ml-1" />
        </Suspense>
        {/* The way back up to the portal keeps a row of its own on narrow screens:
            the section nav below has more links than a phone is wide, so it scrolls
            sideways — and a return link parked at the end of a scrolling row is the
            one link you can never reach. Only at lg do all seven links fit on one
            row, and there it sits at the end of the nav, where it has always been. */}
        <Link
          href="/"
          className="order-2 ml-auto shrink-0 rounded-md px-3 py-2 text-sm text-muted hover:bg-surface hover:text-foreground lg:order-3 lg:ml-0"
        >
          ← iconnect
        </Link>
        <nav
          aria-label="UUC for PHC"
          className="order-3 -my-1 flex w-full items-center gap-1 overflow-x-auto py-1 text-sm lg:order-2 lg:my-0 lg:ml-auto lg:w-auto lg:overflow-x-visible lg:py-0"
        >
          {SECTION_LINKS.map(([href, label]) => (
            <Suspense
              key={href}
              fallback={
                <Link href={href} className={NAV_LINK}>
                  {label}
                </Link>
              }
            >
              <EditionLink href={href} className={NAV_LINK}>
                {label}
              </EditionLink>
            </Suspense>
          ))}
        </nav>
      </div>
    </header>
  );
}

function SectionFooter({ availableYears }: { availableYears: readonly string[] }) {
  return (
    <footer className="mt-auto border-t border-border">
      <div className="mx-auto flex max-w-5xl flex-col gap-5 px-4 py-6 text-sm text-muted sm:px-6">
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
          <InnovationEndorsement />
          <span>
            Source: DOH Bureau of Local Health Systems Development —{" "}
            <Suspense fallback={availableYears[0]}>
              <EditionText availableYears={availableYears} field="year" />
            </Suspense>{" "}
            list of Unserved and Underserved Communities for Primary Health Care (
            <Suspense fallback={null}>
              <EditionText availableYears={availableYears} field="issuance" />
            </Suspense>
            ). Places, not people: no personal data.
          </span>
          <Link href="/" className="underline hover:text-accent">
            iconnect home
          </Link>
        </div>
        <ListCorrection availableYears={availableYears} />
      </div>
    </footer>
  );
}

export default async function UucPhcLayout({ children }: { children: ReactNode }) {
  const available = await getAvailableUucEditions();
  // Never empty: with no edition readable the section still says which list it is about.
  const availableYears = (available.length > 0 ? available : UUC_EDITIONS.slice(-1)).map(
    (e) => e.year,
  );
  return (
    <div className="flex min-h-screen flex-col">
      <SectionHeader availableYears={availableYears} />
      <div className="mx-auto w-full max-w-5xl flex-1 px-4 py-8 sm:px-6 sm:py-10">
        {/* Static routes get their trail from the pathname; the area pages below
            need a resolved geo name, so they render their own in the same spot. */}
        <SiteBreadcrumbs className="mb-8" />
        <div className="mb-6 flex flex-col gap-2">
          <GeoSearch mode="uuc-phc" />
          <p className="text-xs text-muted">
            Search any place. Barangays open their city / municipality overview.
          </p>
        </div>
        {children}
      </div>
      <SectionFooter availableYears={availableYears} />
    </div>
  );
}
