import { FacilityAnalysis } from "@/components/facilities/facility-analysis";
import Link from "next/link";
import type { CatalogDataset, CatalogSelection } from "@/lib/catalog/types";
import { catalogJourneyHref } from "@/lib/catalog/journey";
import { nhfrReading } from "@/lib/catalog/nhfr-reading";
import {
  getNhfrCounts,
  getNhfrTypes,
  getNhfrChildren,
  getNhfrFacilities,
  getNhfrChildGeos,
} from "@/lib/db/nhfr";
import { getGeoAncestors } from "@/lib/db/geo";
import { getProvinceBarangayCentroids } from "@/lib/geo/barangay-centroids";
import { buildFacilityPoints } from "@/lib/geo/facility-points";
import { withBasePath } from "@/lib/base-path";
import { TypeBreakdown } from "@/components/facilities/type-breakdown";
import { ChildBreakdown } from "@/components/facilities/child-breakdown";
import { FacilityList } from "@/components/facilities/facility-list";
import { FacilityMap } from "@/components/facilities/facility-map";
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
  const [counts, types, children, facilities, ancestors, barangays] = await Promise.all([
    getNhfrCounts(selection.code, selection.level),
    getNhfrTypes(selection.code, selection.level),
    getNhfrChildren(selection.code, selection.level),
    city ? getNhfrFacilities(selection.code) : Promise.resolve(null),
    city ? getGeoAncestors(selection.code, selection.level) : Promise.resolve(null),
    getNhfrChildGeos(selection.code, selection.level),
  ]);
  if (!counts || counts.nFacilities !== indicator!.values[0].value)
    return (
      <p role="status">
        Facility details could not be verified against this area’s total. Reload to try again.
      </p>
    );
  const pointData =
    city && facilities && facilities.length === counts.nFacilities && barangays.length > 0
      ? buildFacilityPoints({
          barangays,
          centroids: ancestors?.province
            ? await getProvinceBarangayCentroids(ancestors.province.geoCode)
            : new Map(),
          facilities,
          expectedFacilities: counts.nFacilities,
        })
      : null;
  const heading =
    selection.level === "national"
      ? "Regions"
      : selection.level === "region"
        ? "Provinces"
        : "Cities / municipalities";
  return (
    <div className="space-y-5" data-testid="health-facilities-details">
      <FacilityAnalysis
        locationName={locationName}
        types={types}
        areas={children}
        total={counts.nFacilities}
        expectedCodes={barangays.map((g) => g.geoCode)}
        selection={selection}
        mapState={mapState}
      />
      <div className="rounded-lg border border-border p-4 sm:p-6">
        <TypeBreakdown items={types} expectedCount={counts.nFacilities} />
      </div>
      {city ? (
        <>
          {(!facilities || facilities.length !== counts.nFacilities) && (
            <p role="status">
              The facility map is unavailable until the complete list can be loaded.
            </p>
          )}
          {pointData && pointData.points.length > 0 && (
            <div className="rounded-lg border border-border p-4 sm:p-6">
              <FacilityMap
                data={pointData}
                areaName={locationName}
                outlineUrl={
                  ancestors?.province
                    ? withBasePath(`/geo/citymun/${ancestors.province.geoCode}.json`)
                    : null
                }
                outlineGeoCode={selection.code}
              />
            </div>
          )}
          <div className="rounded-lg border border-border p-4 sm:p-6">
            <FacilityList
              facilities={facilities}
              expectedCount={counts.nFacilities}
              query={query}
              areaHref={catalogJourneyHref(selection, "nhfr", mapState)}
            />
          </div>
        </>
      ) : (
        <div className="rounded-lg border border-border p-4 sm:p-6">
          <ChildBreakdown
            heading={heading}
            items={children}
            areaHref={(c) =>
              catalogJourneyHref(
                { mode: "psgc", level: c.geoLevel, code: c.geoCode },
                "nhfr",
                mapState,
              )
            }
          />
        </div>
      )}
      <DownloadLinks geoLevel={selection.level} geoCode={selection.code} />
    </div>
  );
}
