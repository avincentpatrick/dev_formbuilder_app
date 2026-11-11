import type { CatalogId, CatalogSelection } from "@/lib/catalog/types";
import { isReleasePublic } from "@/lib/releases/visibility";
import { clampBarangayScope } from "./barangay-support";
import { getDatasetMap } from "./definitions";
import { initialMapChoice, mapHref, prototypeIndicators, type MapChoice } from "./prototype";

/**
 * Each catalog dataset's maps, in the order its full map page offers them (docs/DATASET_HUB_PLAN.md).
 * The per-release definitions stay the contract underneath: each release keeps its own period,
 * denominator, disclosure and legend, and switching releases never carries values across.
 */
export type MapSubject = { id: CatalogId | "districts"; title: string; releases: readonly string[] };

export const MAP_SUBJECTS: readonly MapSubject[] = [
  { id: "bhw", title: "BHW", releases: ["bhw-stepzero-2026", "bhw-2025", "bhw-profiling-status-2026"] },
  { id: "nhfr", title: "NHFR", releases: ["nhfr-2026-09"] },
  { id: "uuc-phc", title: "UUC-PHC", releases: ["uuc-phc-2025"] },
  { id: "fhsis", title: "FHSIS", releases: ["fhsis-2025"] },
  { id: "population", title: "Population", releases: ["psa-popcen-2024"] },
  { id: "poverty", title: "Poverty", releases: ["psa-sae-poverty-2023", "lgu-income-class"] },
  { id: "districts", title: "Legislative districts", releases: ["ph-legislative-districts"] },
];

/** The releases a subject shows today: defined and public. */
export function subjectReleases(subject: MapSubject): string[] {
  return subject.releases.filter((slug) => getDatasetMap(slug) && isReleasePublic(slug));
}

export function subjectOfRelease(slug: string): MapSubject | undefined {
  return MAP_SUBJECTS.find((s) => s.releases.includes(slug));
}

/**
 * The full map for a catalog dataset, opened on its first release at the place chosen on the catalog.
 * A place opens with its own children shown; a barangay opens its municipality with the barangay
 * selected, and falls back to the province where the indicator has no barangay figures.
 */
export function subjectMapHref(id: CatalogId, selection: CatalogSelection): string | null {
  const subject = MAP_SUBJECTS.find((s) => s.id === id);
  const release = subject && subjectReleases(subject)[0];
  if (!release) return null;
  const choice: MapChoice = initialMapChoice(release);
  if (selection.mode === "psgc" && prototypeIndicators(release).length) {
    if (selection.level === "barangay") {
      choice.level = "citymun";
      choice.code = selection.code.slice(0, 7);
      choice.selected = selection.code;
    } else if (selection.level !== "national") {
      choice.level = selection.level;
      choice.code = selection.code;
    }
  }
  return mapHref(clampBarangayScope(choice));
}
