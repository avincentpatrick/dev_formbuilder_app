import { GeoSearch } from "@/components/home/geo-search";
import { InnovationEndorsement } from "@/components/branding/innovation-endorsement";
import type { Metadata } from "next";
import Link from "next/link";
import type { ReactNode } from "react";
import { SiteBreadcrumbs } from "@/components/layout/site-breadcrumbs";
export const metadata: Metadata = {
  title: { default: "Health services", template: "%s · Health services" },
  description:
    "DOH FHSIS 2025 partial release: health service delivery and the public health workforce, from national to city and municipality level.",
};
export default function HealthServicesLayout({ children }: { children: ReactNode }) {
  return (
    <div className="flex min-h-screen flex-col">
      <header className="border-b border-border bg-background">
        <div className="mx-auto flex max-w-5xl flex-wrap items-center gap-4 px-4 py-4 sm:px-6">
          <Link href="/health-services" className="text-lg font-semibold">
            Health services <span className="text-sm text-muted">2025 · Partial release</span>
          </Link>
          <nav aria-label="Health services" className="flex flex-wrap gap-4 text-sm">
            <Link href="/health-services" className="underline">
              Overview
            </Link>
            <Link href="/health-services/methodology" className="underline">
              Methodology
            </Link>
            <Link href="/" className="underline">
              ← iconnect
            </Link>
          </nav>
        </div>
      </header>
      <div className="mx-auto w-full max-w-5xl flex-1 px-4 py-8 sm:px-6">
        <SiteBreadcrumbs className="mb-8" />
        <div className="mb-6 flex flex-col gap-2">
          <GeoSearch mode="health-services" />
          <p className="text-xs text-muted">
            Search any place. Barangays open their city / municipality overview.
          </p>
        </div>
        {children}
      </div>
      <footer className="border-t border-border">
        <p className="mx-auto max-w-5xl px-4 py-6 text-sm text-muted">
          Source: Department of Health, FHSIS 2025 selected programme workbooks, retrieved in
          November 2026. Eight-visit antenatal care covers Q3–Q4; water and sanitation cover Q4.{" "}
          <Link href="/health-services/methodology" className="underline">
            Sources and methodology
          </Link>
        </p>
        <div className="mx-auto max-w-5xl px-4 pb-6">
          <InnovationEndorsement />
        </div>
      </footer>
    </div>
  );
}
