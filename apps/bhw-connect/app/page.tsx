import type { Metadata } from "next";
import Link from "next/link";
import { GeoSearch } from "@/components/home/geo-search";
import { DatasetCard } from "@/components/portal/dataset-card";
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

// The portal is a static front door: no data fetching, so a plain Server
// Component. It supplies its own slim chrome (the shared BHW Header/Footer hide
// themselves on "/"), keeping the hub visually distinct from any one dataset.
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

      <section
        aria-label="Unified dataset catalog"
        className="rounded-xl border border-border bg-surface p-5 text-center"
      >
        <h2 className="text-xl font-semibold">Browse datasets by location</h2>
        <p className="mt-2 text-sm text-muted">
          See available measures, coverage and source dates together, from national figures to
          cities and municipalities.
        </p>
        <Link
          href="/catalog"
          prefetch={false}
          className="mt-4 inline-block rounded-md bg-accent px-4 py-2 text-sm font-semibold text-white"
        >
          Open dataset catalog →
        </Link>
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

      {/* Published dashboards and the section's ways forward. */}
      <section aria-label="Datasets" className="flex flex-col gap-4">
        <h2 className="sr-only">Datasets</h2>
        <div className="grid grid-cols-1 gap-4">
          <DatasetCard
            title="2025 BHW Census"
            description="The Philippine BHW workforce — accreditation, training, demographics, and honorarium, down to the city/municipality level."
            href="/bhw"
          />
          <DatasetCard
            title="2025 UUC for PHC"
            description="The barangays on the 2025 list of Unserved and Underserved Communities for Primary Health Care — where primary care is hardest to reach, from national down to city/municipality level."
            href="/uuc-phc"
          />
          <DatasetCard
            title="Health facilities"
            description="The September 2026 National Health Facility Registry — hospitals, rural health units, barangay health stations, and other facilities, with ownership and geographic coverage."
            href="/facilities"
          />
          <DatasetCard
            title="Legislative districts"
            description="Philippine legislative districts and the BHW workforce they cover, with member cities, municipalities, and barangays, mapping sources, and public corrections."
            href="/districts"
          />
          <DatasetCard
            title="Health services"
            description="The DOH FHSIS 2025 partial release — immunisation, maternal care, water and sanitation, TB services, and health workforce context by area."
            href="/health-services"
          />
          <DatasetCard
            title="Ways forward"
            description="Analyses and innovations built on these datasets will join the repository as they mature."
          />
        </div>
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
