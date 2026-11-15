"use client";
import { useCallback, useRef } from "react";
import { useRouter } from "next/navigation";
import { getDatasetMap } from "@/lib/maps/definitions";
import { MAP_SUBJECTS, subjectReleases } from "@/lib/maps/subjects";
import {
  initialMapChoice,
  mapHref,
  prototypeIndicators,
  type MapChoice,
} from "@/lib/maps/prototype";
import { clampBarangayScope } from "@/lib/maps/barangay-support";
import { supportsCircles } from "@/lib/maps/symbols";
import { DISTRICT_MAP_SLUG, type DistrictView } from "@/lib/maps/district-view";
import { DatasetMapCard } from "./dataset-map-card";
import { DistrictMapCard } from "./district-map-card";
export function DatasetMapPage({
  choice,
  districtView,
}: {
  choice: MapChoice | null;
  districtView?: DistrictView;
}) {
  const router = useRouter();
  const workspace = choice?.dataset === "bhw-stepzero-2026" || choice?.dataset === "bhw-2025";
  const current = useRef(choice);
  const trackChoice = useCallback((value: MapChoice) => {
    current.current = value;
  }, []);
  return (
    <div className={workspace ? "space-y-2" : "space-y-5"}>
      <label className={workspace ? "flex min-w-0 items-center gap-3 text-sm" : "block"}>
        Dataset
        <select
          aria-label="Map dataset"
          className={
            workspace
              ? "min-w-0 flex-1 rounded border border-border bg-background p-2"
              : "mt-2 w-full rounded border border-border bg-background p-3"
          }
          value={choice?.dataset ?? ""}
          onChange={(e) => {
            const next = initialMapChoice(e.target.value);
            if (current.current && prototypeIndicators(next.dataset).length) {
              next.code = current.current.code;
              next.level = current.current.level;
              next.selected = current.current.selected;
              if (
                current.current.view === "choropleth" ||
                (current.current.view === "circles" && supportsCircles(next))
              )
                next.view = current.current.view;
            }
            router.push(mapHref(clampBarangayScope(next)));
          }}
        >
          <option value="" disabled>
            Choose a dataset
          </option>
          {/* Grouped by dataset: BHW offers its Headcount and its Profiles side by side. */}
          {MAP_SUBJECTS.map((subject) => (
            <optgroup key={subject.id} label={subject.title}>
              {subjectReleases(subject).map((slug) => (
                <option key={slug} value={slug}>
                  {getDatasetMap(slug)!.title}
                </option>
              ))}
            </optgroup>
          ))}
        </select>
      </label>
      {!workspace && (
        <>
          <p className="text-sm text-muted">
            Switching between available maps retains the supported area. Pending maps open their
            national availability view; they do not inherit another dataset’s values. Legislative
            districts open their own view, through member cities and municipalities.
          </p>
        </>
      )}
      {choice?.dataset === DISTRICT_MAP_SLUG && districtView ? (
        <DistrictMapCard initial={districtView} />
      ) : choice ? (
        <DatasetMapCard
          key={mapHref(choice)}
          dataset={choice.dataset}
          initial={choice}
          expanded
          onChoiceChange={trackChoice}
        />
      ) : (
        <p role="status">
          Choose a dataset to open its map. If a shared link has an unsupported indicator, period, geography or
          count range, select the dataset again.
        </p>
      )}
    </div>
  );
}
