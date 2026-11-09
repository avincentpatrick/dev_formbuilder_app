import { withBasePath } from "@/lib/base-path";
import type { MapLevel } from "./definitions";

/** Datasets whose barangay rows and polygons are enabled for drill-down. Widen one dataset at a time,
 * and only after its barangay rows and disclosure rules are confirmed; BHW Census and StepZero stay out
 * until their small-count suppression has been reviewed at barangay level. */
export const BARANGAY_DRILL_DATASETS: readonly string[] = ["nhfr-2026-09", "uuc-phc-2025"];

const RELEASE = /^[a-z0-9][a-z0-9.-]{2,79}$/;

/** The immutable release directory of the accepted barangay package, or null while none is configured.
 * `BHW_BARANGAY_RELEASE` is set on the server by the operator after the package is installed. Never
 * `latest`: a release is pinned so a rollback is a setting change, not a file overwrite. */
export function barangayRelease(value: string | undefined = process.env.BHW_BARANGAY_RELEASE): string | null {
  const release = value?.trim();
  return release && release !== "latest" && !release.includes("..") && RELEASE.test(release) ? release : null;
}

/** Barangay drill-down needs all three: an installed release, an enabled dataset, and an indicator whose
 * source actually carries barangay rows. Anything less keeps the municipal dashboard link. */
export function barangayDrillAvailable(
  dataset: string,
  levels: readonly MapLevel[],
  release: string | null,
): release is string {
  return release !== null && BARANGAY_DRILL_DATASETS.includes(dataset) && levels.includes("barangay");
}

/** One municipality's partition in the package (`citymun/<code>.json` under the release directory). */
export function barangayGeometryUrl(release: string, citymunCode: string): string {
  return withBasePath(`/maps/${release}/citymun/${citymunCode}.json`);
}
