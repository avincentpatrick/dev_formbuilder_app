import Link from "next/link";
import type { NhfrChild, NhfrTypeCount } from "@/lib/db/nhfr";
import type { CatalogSelection } from "@/lib/catalog/types";
import { catalogJourneyHref } from "@/lib/catalog/journey";
import { facilityPlaces } from "@/lib/facilities/analysis";
import { formatCount, percentageFormatter } from "@/lib/format";
import type { ReactNode } from "react";
import { FacilityMixCard } from "./facility-mix-card";
import { DistributionCard } from "./distribution-card";
import type { DistributionInput } from "@/lib/facilities/distribution";

function Rows({ children }: { children: ReactNode[] }) {
  return (
    <>
      <ol className="mt-4 space-y-4">{children.slice(0, 8)}</ol>
      {children.length > 8 && (
        <details className="mt-4">
          <summary className="cursor-pointer text-sm font-medium underline">
            Show all {children.length} categories or areas
          </summary>
          <ol start={9} className="mt-4 space-y-4">
            {children.slice(8)}
          </ol>
        </details>
      )}
    </>
  );
}
function Bar({ value, max = 100 }: { value: number; max?: number }) {
  return (
    <div aria-hidden="true" className="mt-1 h-2 overflow-hidden rounded bg-surface">
      <div
        className="h-full bg-accent"
        style={{ width: `${max > 0 ? (value / max) * 100 : 0}%` }}
      />
    </div>
  );
}

export function FacilityAnalysis({
  types,
  areas,
  total,
  expectedCodes,
  selection,
  mapState,
  locationName = "Selected location",
  distribution = null,
}: {
  types: NhfrTypeCount[] | null;
  areas: NhfrChild[];
  total: number;
  expectedCodes: string[];
  selection: CatalogSelection;
  mapState?: string;
  locationName?: string;
  distribution?: DistributionInput | null;
}) {
  const places = facilityPlaces(areas, total, expectedCodes);
  const byPresence = places
    ? [...places].sort(
        (a, b) =>
          (a.presence ?? Infinity) - (b.presence ?? Infinity) || a.geoName.localeCompare(b.geoName),
      )
    : null;
  const formatPresence = percentageFormatter(
    places?.flatMap((i) => (i.presence === null ? [] : [i.presence])) ?? [],
  );
  const areaLink = (i: NhfrChild) =>
    catalogJourneyHref({ mode: "psgc", level: i.geoLevel, code: i.geoCode }, "nhfr", mapState);
  const showPlaces =
    selection.mode === "psgc" && ["national", "region", "province"].includes(selection.level);
  return (
    <section aria-label="Facility analysis" className="space-y-5" data-testid="facility-analysis">
      <FacilityMixCard
        key={JSON.stringify(selection)}
        items={types}
        total={total}
        selection={selection}
        locationName={locationName}
      />
      {showPlaces && (
        <div className="space-y-5">
          <DistributionCard
            key={JSON.stringify(selection)}
            input={distribution}
            selection={selection}
            locationName={locationName}
            mapState={mapState}
          />
          <section
            className="min-w-0 rounded-lg border border-border p-4 sm:p-6"
            aria-label="Barangay presence chart"
          >
            <h3 className="text-lg font-semibold">Where is recorded barangay presence lower?</h3>
            <p className="mt-2 text-sm text-muted">
              Share of each area’s barangays with an attributed facility, lowest first. Bars run
              from 0 to 100%. This is not travel access or a ranking of care quality.
            </p>
            {byPresence ? (
              <Rows>
                {byPresence.map((i) => (
                  <li key={i.geoCode}>
                    <Link className="text-sm font-medium underline" href={areaLink(i)}>
                      {i.geoName}
                    </Link>
                    <p className="text-sm">
                      <span className="whitespace-nowrap tabular-nums">
                        {formatCount(i.nBarangaysWithFacility)} of {formatCount(i.nBarangays)}
                        {i.presence === null
                          ? " (percentage unavailable)"
                          : ` (${formatPresence(i.presence)})`}
                      </span>{" "}
                      barangays
                    </p>
                    {i.presence !== null && <Bar value={i.presence} />}
                  </li>
                ))}
              </Rows>
            ) : (
              <p role="status" className="mt-3 text-sm">
                The complete child-area distribution could not be verified. No ranking is shown.
              </p>
            )}
            <p className="mt-3 text-xs text-muted">
              A barangay without an attributed facility is not proof that none exists there. People
              may use facilities outside their barangay.
            </p>
          </section>
        </div>
      )}
    </section>
  );
}
