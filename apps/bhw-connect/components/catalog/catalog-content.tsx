import { NhfrDetails } from "./nhfr-details";
import { PovertyCoverage } from "./poverty-coverage";
import { PovertyMunicipalities } from "./poverty-municipalities";
import Link from "next/link";
import { catalogJourneyHref } from "@/lib/catalog/journey";
import { getPublicCatalog } from "@/lib/catalog/catalog";
import { getCatalogGeography } from "@/lib/catalog/geography";
import { type CatalogRequest } from "@/lib/catalog/navigation";
import { LocationControls } from "@/components/catalog/location-controls";
import { CatalogDetails, CatalogResults } from "@/components/catalog/catalog-results";
import { DistrictControls } from "@/components/catalog/district-controls";
import { getDistrictGeography } from "@/lib/catalog/district-geography";
import { getDistrictCatalog } from "@/lib/catalog/district-catalog";

export async function CatalogContent({
  request,
  mapState,
  facilitySearch = "",
}: {
  request: CatalogRequest;
  mapState?: string;
  facilitySearch?: string;
}) {
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
          <Link
            href={catalogJourneyHref({ mode: "district", districtCode: "" }, null, mapState)}
            className="mt-3 inline-block underline"
          >
            Choose a district
          </Link>
        </section>
      );
    if (!geography.current)
      return (
        <>
          <DistrictControls geography={geography} dataset={request.dataset} mapState={mapState} />
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
        <DistrictControls geography={geography} dataset={request.dataset} mapState={mapState} />
        <h2 className="text-2xl font-semibold" data-testid="catalog-scope">
          {locationName}
        </h2>
        {dataset ? (
          <CatalogDetails
            catalog={catalog}
            dataset={dataset}
            mapState={mapState}
            locationName={locationName}
          />
        ) : (
          <CatalogResults catalog={catalog} locationName={locationName} mapState={mapState} />
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
        <Link
          href={catalogJourneyHref({ mode: "all" }, request.dataset, mapState)}
          className="mt-3 inline-block underline"
        >
          Return to All locations
        </Link>
      </section>
    );
  const catalog = await getPublicCatalog(request.selection);
  const locationName = geography.current?.name ?? "All locations";
  const povertyProvince =
    request.dataset === "poverty" &&
    (geography.current?.level === "province" || geography.current?.level === "citymun")
      ? [geography.current, ...geography.ancestors].find((place) => place?.level === "province")
      : null;
  const dataset = request.dataset ? catalog.datasets.find((d) => d.id === request.dataset) : null;
  return (
    <div className="space-y-6">
      <LocationControls
        geography={geography}
        selection={request.selection}
        dataset={request.dataset}
        mapState={mapState}
      />
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
      {request.dataset === "poverty" &&
        (!geography.current || ["national", "region"].includes(geography.current.level)) && (
          <PovertyCoverage
            key={geography.current?.code ?? "all"}
            places={geography.children}
            mapState={mapState}
          />
        )}
      {povertyProvince && (
        <a className="inline-block underline" href="#poverty-municipal-list">
          See cities and municipalities in {povertyProvince.name} →
        </a>
      )}
      {dataset ? (
        <CatalogDetails
          catalog={catalog}
          dataset={dataset}
          mapState={mapState}
          locationName={locationName}
          facilityDetails={
            dataset.id === "nhfr" ? (
              <NhfrDetails
                dataset={dataset}
                selection={request.selection}
                locationName={locationName}
                query={facilitySearch}
                mapState={mapState}
              />
            ) : undefined
          }
        />
      ) : (
        <CatalogResults catalog={catalog} locationName={locationName} mapState={mapState} />
      )}
      {povertyProvince && (
        <div id="poverty-municipal-list">
          <PovertyMunicipalities
            key={povertyProvince.code}
            province={povertyProvince.code}
            name={povertyProvince.name}
            selected={geography.current?.level === "citymun" ? geography.current.code : null}
            mapState={mapState}
          />
        </div>
      )}
    </div>
  );
}
