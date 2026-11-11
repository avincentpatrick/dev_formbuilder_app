import type { Metadata } from "next";
import Link from "next/link";
import { GeoSearch } from "@/components/home/geo-search";
import { Suspense } from "react";
import { CatalogContent } from "@/components/catalog/catalog-content";
import CatalogLoading from "./catalog/loading";
import { meridianUrl } from "@/lib/meridian-url";
import { IconnectLogo } from "@/components/branding/iconnect-logo";
import { InnovationEndorsement } from "@/components/branding/innovation-endorsement";

// The link back to Meridian, which shares this address on the testing box (Meridian's decision D179).
const MERIDIAN_HOME = meridianUrl("/");

// Absolute title so the root layout's "%s · BHW Connect" template — which is
// correct for the BHW dataset pages — doesn't get appended to the portal, which
// sits above any single dataset.
export const metadata: Metadata = {
  title: { absolute: "iconnect · Health data & innovation" },
  description:
    "A repository of open, publicly available health datasets and innovations — no individual-level data.",
};

export default function Portal() {
  return (
    <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-10 px-4 py-8 sm:px-6 sm:py-12">
      <section className="flex flex-col items-center gap-6 py-6 text-center sm:py-10">
        <h1 className="w-full max-w-md">
          <span className="sr-only">iconnect</span>
          <IconnectLogo className="iconnect-wordmark" />
        </h1>
        <p className="max-w-2xl text-muted">
          Open health data, connected insights, and innovations — explore the evidence shaping
          healthier communities.
        </p>
      </section>

      <section aria-label="Unified dataset catalog" className="space-y-6">
        <h2 className="text-2xl font-semibold">Browse datasets by location</h2>
        <Suspense fallback={<CatalogLoading />}>
          <CatalogContent request={{ selection: { mode: "all" }, dataset: null }} />
        </Suspense>
      </section>

      <section aria-label="Global search" className="flex flex-col items-center gap-3">
        <h2 className="text-xl font-semibold">Search across datasets</h2>
        <p className="max-w-2xl text-center text-sm text-muted">
          Find a barangay, municipality, city, province, region, or legislative district. Choose a
          dataset in the results to explore that place. Barangays open the municipality overview in
          UUC, facilities, and health services.
        </p>
        <GeoSearch mode="portal" />
      </section>

      {/* Disclaimer — publicly available data, no PII. */}
      <section
        aria-label="Data privacy"
        className="rounded-lg border border-border bg-surface p-4 text-sm text-muted sm:p-5"
      >
        <p>
          Everything here uses publicly available data with no personally identifiable information.
          These dashboards never show or export individual-level records — only aggregate counts and
          percentages, with small groups suppressed to prevent re-identification.
        </p>
      </section>

      <section aria-label="More resources" className="space-y-3 border-t border-border pt-5">
        <h2 className="text-lg font-semibold">More resources</h2>
        <p className="text-sm text-muted">
          Existing dashboards and mapping documentation remain available.
        </p>
        <nav aria-label="Existing dashboards" className="flex flex-wrap gap-4 text-sm underline">
          <Link href="/bhw">BHW dashboard</Link>
          <Link href="/facilities">Facilities dashboard</Link>
          <Link href="/uuc-phc">UUC-PHC dashboard</Link>
          <Link href="/health-services">Health services dashboard</Link>
          <Link href="/districts">District mapping and sources</Link>
          <Link href="/map">All dataset maps</Link>
        </nav>
        <p className="text-sm text-muted">
          Ways forward: analyses and innovations will join the repository as they mature.
        </p>
      </section>

      {/* Attribution */}
      <footer className="iconnect-footer mt-auto flex flex-wrap items-center justify-between gap-6 pt-6 text-sm text-muted">
        <InnovationEndorsement />
        <div className="flex flex-wrap items-center gap-4">
          {MERIDIAN_HOME && (
            <a href={MERIDIAN_HOME} className="underline underline-offset-2 hover:text-accent">
              Meridian
            </a>
          )}
          <Link href="/admin/login" className="underline underline-offset-2 hover:text-accent">
            Admin sign in
          </Link>
        </div>
      </footer>
    </div>
  );
}
