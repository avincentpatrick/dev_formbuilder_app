import Link from "next/link";
import { getPublicCatalog } from "@/lib/catalog/catalog";
import { getCatalogGeography } from "@/lib/catalog/geography";
import { type CatalogRequest } from "@/lib/catalog/navigation";
import { LocationControls } from "@/components/catalog/location-controls";
import { CatalogDetails, CatalogResults } from "@/components/catalog/catalog-results";
import { DistrictControls } from "@/components/catalog/district-controls";
import { getDistrictGeography } from "@/lib/catalog/district-geography";
import { getDistrictCatalog } from "@/lib/catalog/district-catalog";

export async function CatalogContent({ request }: { request: CatalogRequest }) {
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
