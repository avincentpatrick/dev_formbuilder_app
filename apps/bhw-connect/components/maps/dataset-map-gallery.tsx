import { MapActivityProvider } from "./map-activity";
import { DATASET_MAPS } from "@/lib/maps/definitions";
import { DatasetMapCard } from "./dataset-map-card";
/** Every release keeps independent state; the gallery shares only a two-map resource limit. */
export function DatasetMapGallery() {
  return (
    <section aria-label="Independent dataset maps" className="space-y-5">
      <h2 className="text-2xl font-semibold">Dataset maps</h2>
      <p className="text-sm text-muted">
        Each dataset has its own map and selection. BHW profile counts, StepZero, PSA population,
        poverty, LGU income class, UUC and NHFR maps are available; other map slots show their
        readiness.
      </p>
      <MapActivityProvider>
        <div className="grid gap-5 md:grid-cols-2">
          {DATASET_MAPS.filter((d) => d.slug !== "bhw-profiling-status-2026").map((d) => (
            <DatasetMapCard key={d.slug} dataset={d.slug} />
          ))}
        </div>
      </MapActivityProvider>
    </section>
  );
}
