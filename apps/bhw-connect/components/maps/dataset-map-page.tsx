"use client";
import { useCallback, useRef } from "react";
import { useRouter } from "next/navigation";
import { DATASET_MAPS } from "@/lib/maps/definitions";
import {
  initialMapChoice,
  mapHref,
  prototypeIndicators,
  type MapChoice,
} from "@/lib/maps/prototype";
import { clampBarangayScope } from "@/lib/maps/barangay-support";
import { DatasetMapCard } from "./dataset-map-card";
export function DatasetMapPage({ choice }: { choice: MapChoice | null }) {
  const router = useRouter();
  const current = useRef(choice);
  const trackChoice = useCallback((value: MapChoice) => {
    current.current = value;
  }, []);
  return (
    <div className="space-y-5">
      <label className="block">
        Dataset
        <select
          aria-label="Map dataset"
          className="mt-2 w-full rounded border border-border bg-background p-3"
          value={choice?.dataset ?? ""}
          onChange={(e) => {
            const next = initialMapChoice(e.target.value);
            if (current.current && prototypeIndicators(next.dataset).length) {
              next.code = current.current.code;
              next.level = current.current.level;
              next.selected = current.current.selected;
            }
            router.push(mapHref(clampBarangayScope(next)));
          }}
        >
          <option value="" disabled>
            Choose a dataset
          </option>
          {DATASET_MAPS.map((d) => (
            <option key={d.slug} value={d.slug}>
              {d.title}
            </option>
          ))}
        </select>
      </label>
      <p className="text-sm text-muted">
        Switching between available maps retains the supported area. Pending maps open their
        national availability view; they do not inherit another dataset’s values.
      </p>
      {choice ? (
        <DatasetMapCard
          key={mapHref(choice)}
          dataset={choice.dataset}
          initial={choice}
          expanded
          onChoiceChange={trackChoice}
        />
      ) : (
        <p role="status">
          Choose a dataset to open its map. If a shared link has an unsupported indicator, period or
          geography, select the dataset again.
        </p>
      )}
    </div>
  );
}
