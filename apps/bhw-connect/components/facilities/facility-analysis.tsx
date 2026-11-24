import { PresenceCard } from "./presence-card";
import type { PresenceInput } from "@/lib/facilities/presence";
import type { NhfrTypeCount } from "@/lib/db/nhfr";
import type { CatalogSelection } from "@/lib/catalog/types";
import { FacilityMixCard } from "./facility-mix-card";
import { DistributionCard } from "./distribution-card";
import type { DistributionInput } from "@/lib/facilities/distribution";

export function FacilityAnalysis({
  types,
  total,
  selection,
  mapState,
  locationName = "Selected location",
  distribution = null,
  presence = null,
}: {
  types: NhfrTypeCount[] | null;
  total: number;
  selection: CatalogSelection;
  mapState?: string;
  locationName?: string;
  distribution?: DistributionInput | null;
  presence?: PresenceInput | null;
}) {
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
          <PresenceCard
            key={JSON.stringify(selection)}
            input={presence}
            selection={selection}
            locationName={locationName}
            mapState={mapState}
          />
        </div>
      )}
    </section>
  );
}
