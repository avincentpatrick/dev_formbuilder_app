import type { Metadata } from "next";
import Link from "next/link";
import { Suspense } from "react";
import { IconnectLogo } from "@/components/branding/iconnect-logo";
import { getPublicCatalog } from "@/lib/catalog/catalog";
import { getCatalogGeography } from "@/lib/catalog/geography";
import {
  parseCatalogParams,
  type CatalogParams,
  type CatalogRequest,
} from "@/lib/catalog/navigation";
import { LocationControls } from "@/components/catalog/location-controls";
import { CatalogDetails, CatalogResults } from "@/components/catalog/catalog-results";
import { DistrictControls } from "@/components/catalog/district-controls";
import { getDistrictGeography } from "@/lib/catalog/district-geography";
import { getDistrictCatalog } from "@/lib/catalog/district-catalog";
import CatalogLoading from "./loading";

export const metadata: Metadata = {
  title: { absolute: "Dataset catalog · iconnect" },
  description:
    "Explore accepted public health, population and poverty data by location, with coverage and sources.",
};

export default async function CatalogPage({
  searchParams,
}: {
  searchParams: Promise<CatalogParams>;
}) {
  const params = await searchParams;
  const request = parseCatalogParams(params);
  return (
    <div className="mx-auto w-full max-w-6xl space-y-6 px-4 py-8 sm:px-6">
      <header className="space-y-4">
        <Link href="/" aria-label="iconnect home" className="inline-block w-40">
          <IconnectLogo className="w-full" />
        </Link>
        <h1 className="text-3xl font-semibold tracking-tight">Dataset catalog</h1>
        <p className="max-w-3xl text-muted">
          Explore health workforce, facilities, primary care access, health services, population and
          poverty. Figures keep their own sources, dates and geographic limits.
        </p>
      </header>
      {request ? (
        <Suspense key={JSON.stringify(request)} fallback={<CatalogLoading />}>
          <CatalogContent request={request} />
        </Suspense>
      ) : (
        <section role="alert" className="rounded-xl border border-border bg-surface p-6">
          <h2 className="font-semibold">This catalog selection is not supported</h2>
          <p className="mt-2 text-sm">
            Choose All locations, a PSGC location, or a congressional district and its constituent
            members.
          </p>
          <Link href="/catalog" className="mt-3 inline-block underline">
            Start at All locations
          </Link>
        </section>
      )}
      <footer className="border-t border-border pt-5 text-sm text-muted">
        Public aggregate data only. Small groups are withheld where required.{" "}
        <Link href="/privacy" className="underline">
          Privacy
        </Link>{" "}
        ·{" "}
        <Link href="/" className="underline">
          Back to iconnect
        </Link>
      </footer>
    </div>
  );
}

async function CatalogContent({ request }: { request: CatalogRequest }) {
  if (request.selection.mode === "district") {
    const geography = await getDistrictGeography(request.selection);
    if (geography.state !== "available")
      return (
        <section role="alert" className="rounded-xl border border-border p-6">
          <h2 className="font-semibold">
            {geography.state === "failed"
              ? "District membership could not be loaded"
              : "District member not found"}
          </h2>
          <p className="mt-2">No figures from a different area are shown.</p>
          <Link href="/catalog?mode=district" className="mt-3 inline-block underline">
            Choose a district
          </Link>
        </section>
      );
    if (!geography.current)
      return (
        <>
          <DistrictControls geography={geography} />
          <p role="status">
            {geography.choices.length
              ? "Choose a congressional district to view its mapped members and available datasets."
              : "No public districts are currently available."}
          </p>
        </>
      );
    const city = geography.scope.cities.find((c) => c.code === geography.selection.citymunCode);
    const barangay = city?.barangays.find((b) => b.code === geography.selection.barangayCode);
    const locationName = [
      geography.current.name,
      city ? `${city.name}${city.whole ? "" : " (district barangays only)"}` : null,
      barangay?.name,
    ]
      .filter(Boolean)
      .join(" · ");
    const catalog = await getDistrictCatalog(geography);
    const dataset = catalog.datasets.find((d) => d.id === request.dataset);
    return (
      <div className="space-y-6">
        <DistrictControls geography={geography} />
        <h2 className="text-2xl font-semibold" data-testid="catalog-scope">
          {locationName}
        </h2>
        {dataset ? (
          <CatalogDetails catalog={catalog} dataset={dataset} />
        ) : (
          <CatalogResults catalog={catalog} locationName={locationName} />
        )}
      </div>
    );
  }
  const geography = await getCatalogGeography(request.selection);
  if (geography.state !== "available")
    return (
      <section role="alert" className="rounded-xl border border-border bg-surface p-6">
        <h2 className="font-semibold">
          {geography.state === "failed" ? "Locations could not be loaded" : "Location not found"}
        </h2>
        <p className="mt-2 text-sm">
          {geography.state === "failed"
            ? "Please try again. No figures are being shown for a different place."
            : "This code and geographic level do not identify a supported location."}
        </p>
        <Link href="/catalog" className="mt-3 inline-block underline">
          Return to All locations
        </Link>
      </section>
    );
  const catalog = await getPublicCatalog(request.selection);
  const locationName = geography.current?.name ?? "All locations";
  const dataset = request.dataset ? catalog.datasets.find((d) => d.id === request.dataset) : null;
  return (
    <div className="space-y-6">
      <LocationControls geography={geography} selection={request.selection} />
      <div>
        <h2 className="text-2xl font-semibold" data-testid="catalog-scope">
          {locationName}
        </h2>
        <p className="mt-1 text-sm text-muted">
          {request.selection.mode === "all"
            ? "Availability across reported locations — not national totals."
            : "Figures and availability for this location only."}
        </p>
      </div>
      {dataset ? (
        <CatalogDetails catalog={catalog} dataset={dataset} />
      ) : (
        <CatalogResults catalog={catalog} locationName={locationName} />
      )}
    </div>
  );
}
