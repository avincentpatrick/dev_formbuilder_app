import { getNhfrExploration } from "@/lib/db/nhfr-exploration";
import { BarangayExplorer } from "@/components/facilities/barangay-explorer";
import { getNhfrPresence } from "@/lib/db/nhfr-presence";
import { getNhfrDistribution } from "@/lib/db/nhfr-distribution";
import { FacilityAnalysis } from "@/components/facilities/facility-analysis";
import Link from "next/link";
import type { CatalogDataset, CatalogSelection } from "@/lib/catalog/types";
import { catalogJourneyHref } from "@/lib/catalog/journey";
import { nhfrReading } from "@/lib/catalog/nhfr-reading";
import { getNhfrCounts, getNhfrTypes } from "@/lib/db/nhfr";
import { DownloadLinks } from "@/components/facilities/download-links";

/** Reuse the facility dashboard's readers and views inside the selected catalog card. */
export async function NhfrDetails({
  dataset,
  selection,
  locationName,
  query,
  mapState,
}: {
  dataset: CatalogDataset;
  selection: CatalogSelection;
  locationName: string;
  query: string;
  mapState?: string;
}) {
  if (selection.mode === "all")
    return (
      <Link
        className="inline-block underline"
        href={catalogJourneyHref({ mode: "psgc", level: "national", code: "PH" }, "nhfr", mapState)}
      >
        Explore health facilities in the Philippines →
      </Link>
    );
  if (selection.mode !== "psgc" || selection.level === "barangay")
    return (
      <p className="text-sm text-muted">
        The figures and exact data here retain this selection. Full type tables and facility lists
        are available when you select a municipality; no municipal totals are substituted here.
      </p>
    );
  const indicator = dataset.indicators.find((i) => i.id === "nhfr.facilities");
  if (!nhfrReading(indicator, selection))
    return (
      <p role="status">
        Facility details are unavailable until this area’s accepted total can be verified.
      </p>
    );
  const city = selection.level === "citymun";
  const [counts, types, exploration, distribution, presence] = await Promise.all([
    getNhfrCounts(selection.code, selection.level),
    getNhfrTypes(selection.code, selection.level),
    city ? getNhfrExploration(selection.code) : Promise.resolve(null),
    selection.level === "national" || selection.level === "region" || selection.level === "province"
      ? getNhfrDistribution(selection.code, selection.level)
      : Promise.resolve(null),
    selection.level === "national" || selection.level === "region" || selection.level === "province"
      ? getNhfrPresence(selection.code, selection.level)
      : Promise.resolve(null),
  ]);
  if (!counts || counts.nFacilities !== indicator!.values[0].value)
    return (
      <p role="status">
        Facility details could not be verified against this area’s total. Reload to try again.
      </p>
    );
  return (
    <div className="space-y-5" data-testid="health-facilities-details">
      <FacilityAnalysis
        distribution={distribution}
        presence={presence}
        locationName={locationName}
        types={types}
        total={counts.nFacilities}
        selection={selection}
        mapState={mapState}
      />
      {city && (
        <BarangayExplorer
          key={selection.code}
          input={exploration}
          query={query}
          areaHref={catalogJourneyHref(selection, "nhfr", mapState)}
        />
      )}
      <DownloadLinks
        geoLevel={selection.level}
        geoCode={selection.code}
        locationName={locationName}
      />
    </div>
  );
}
