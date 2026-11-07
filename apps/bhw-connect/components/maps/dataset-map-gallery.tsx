import { DATASET_MAPS } from "@/lib/maps/definitions";
import { DatasetMapCard } from "./dataset-map-card";
/** Exactly two adapters currently initialize WebGL; all remaining slots show availability. */
export function DatasetMapGallery() {
  return (
    <section aria-label="Independent dataset maps" className="space-y-5">
      <h2 className="text-2xl font-semibold">Dataset maps</h2>
      <p className="text-sm text-muted">
        Each dataset has its own map and selection. UUC and NHFR maps are available in this
        prototype; other map slots show their readiness.
      </p>
      <div className="grid gap-5 md:grid-cols-2">
        {DATASET_MAPS.filter((d) => d.slug !== "bhw-profiling-status-2026").map((d) => (
          <DatasetMapCard key={d.slug} dataset={d.slug} />
        ))}
      </div>
    </section>
  );
}
