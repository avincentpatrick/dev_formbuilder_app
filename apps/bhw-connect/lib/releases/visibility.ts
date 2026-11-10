/**
 * Releases kept off public pages until the owner decides to present them.
 *
 * No 2026 BHW data is presented yet (owner decision, 2026-11-10; docs/DATASET_HUB_PLAN.md), so the
 * 2026 profiling-status snapshot is hidden: its section and export answer 404, it leaves the sitemap
 * and the map picker, and the map API refuses it. Its data, code and admin-only views stay. To
 * present it, remove its slug from this list. NHFR September 2026 is deliberately not listed.
 */
export const HIDDEN_RELEASES: readonly string[] = ["bhw-profiling-status-2026"];

export function isReleasePublic(slug: string): boolean {
  return !HIDDEN_RELEASES.includes(slug);
}

export const PROFILING_STATUS_SLUG = "bhw-profiling-status-2026";
