import "server-only";
import { cache } from "react";
import { createSupabaseServerClient } from "./supabase";

export type DatasetInfo = {
  datasetId: number;
  slug: string;
  name: string;
  sourceName: string | null;
  license: string | null;
  asOfDate: string | null;
  lastUpdatedAt: string;
};

/**
 * The single active dataset for v1 (`bhw-2025`). Returns null on any read
 * failure so callers (footer, etc.) can degrade gracefully rather than crash
 * a page that doesn't otherwise depend on the database.
 *
 * Pinned to `slug = DATASET_SLUGS.profiled` (`bhw-2025`), not "whichever row is
 * `status='active'`". The slug is unique, so no other dim_dataset row can ever
 * win this lookup — a reference/provenance dataset accidentally registered as
 * `active` (E4.3 did exactly this: see #44) can no longer hijack the active
 * dataset and scope every figure to a dataset_id with zero aggregate rows.
 * `status='active'` is retained as a guard so the primary dataset can still be
 * intentionally retired (returns null → graceful degrade) rather than as the
 * selector. Swapping the v1 primary to a future dataset is a deliberate edit
 * here, by design.
 *
 * Wrapped in React's per-request `cache()` — nearly every query helper calls
 * this for the dataset_id FK, so a page composing many figures (home, place,
 * insights grid) would otherwise re-run the identical lookup dozens of times
 * per render.
 */
export const getActiveDataset = cache(async (): Promise<DatasetInfo | null> => {
  const result = await readDatasetBySlug(DATASET_SLUGS.profiled, true);
  return result.state === "available" ? result.value : null;
});

/** Convenience accessor for query functions that only need the numeric FK. */
export async function getActiveDatasetId(): Promise<number | null> {
  const dataset = await getActiveDataset();
  return dataset?.datasetId ?? null;
}

/** Canonical dataset slugs. The per-person "validated profile" dataset drives
 * every individual-level figure; the StepZero quick-count is the aggregate
 * BHW universe used only as the total/denominator. */
export const DATASET_SLUGS = {
  /** DOH 2025 annual health services release, partial at retrieval. */
  fhsis: "fhsis-2025",
  profiled: "bhw-2025",
  stepzero: "bhw-stepzero-2026",
  /** PSA 2024 Census of Population (E4.2). StepZero's own self-reported population is
   * preferred as the per-capita denominator (owner decision, 2026-09-06); this dataset
   * fills in only where StepZero has no population row for a geo. */
  popcen2024: "psa-popcen-2024",
  /** 2026 encoding-status snapshot — how far individual profiling has progressed
   * (Encode → Validate → Certify). Read only by slug; kept separate from the 2025
   * datasets (see lib/db/profiling-status.ts). */
  profilingStatus: "bhw-profiling-status-2026",
  /** 2025 list of Unserved and Underserved Communities for Primary Health Care
   * (DC No. 2025-0549). A targeting list of barangays, not a BHW measure — read
   * only by slug, like the other companion datasets (see lib/db/uuc-phc.ts). */
  uucPhc: "uuc-phc-2025",
  /** The 20th Congress legislative district mapping — derived from public sources, not published
   * by PSA or COMELEC. Read only by slug like the other companion datasets, and the one dataset
   * whose `last_updated_at` moves from inside the running site: an accepted public correction
   * bumps it (`lib/db/district-correction-changelog.ts`, plan D2.6). */
  legislativeDistricts: "ph-legislative-districts",
  /** DOH National Health Facility Registry, September 2026 snapshot. A register of *places* —
   * health infrastructure alongside the BHW workforce — read only by slug like the other
   * companion datasets (see lib/db/nhfr.ts). NHFR is a live registry rather than a periodic
   * publication, so the slug carries the snapshot month: a later export is a new dataset row,
   * not an edit of this one. */
  nhfr: "nhfr-2026-09",
} as const;

export type DatasetRead =
  { state: "available"; value: DatasetInfo } | { state: "unavailable" | "failed" };

/** Typed read boundary for callers that must distinguish absent metadata from a failed read.
 * Existing nullable accessors retain their behavior. Never expose database errors or credentials.
 */
export const readDatasetBySlug = cache(
  async (slug: string, requireActive = false): Promise<DatasetRead> => {
    try {
      let query = createSupabaseServerClient()
        .from("dim_dataset")
        .select("dataset_id, slug, name, source_name, license, as_of_date, last_updated_at")
        .eq("slug", slug);
      if (requireActive) query = query.eq("status", "active");
      const { data, error } = await query.maybeSingle();
      if (error) return { state: "failed" };
      if (!data) return { state: "unavailable" };
      return {
        state: "available",
        value: {
          datasetId: data.dataset_id,
          slug: data.slug,
          name: data.name,
          sourceName: data.source_name,
          license: data.license,
          asOfDate: data.as_of_date,
          lastUpdatedAt: data.last_updated_at,
        },
      };
    } catch {
      return { state: "failed" };
    }
  },
);

/**
 * One dataset by slug regardless of `status`. Used to reach the companion
 * datasets that are intentionally not `status = 'active'` — StepZero,
 * profiling status, UUC for PHC — so `getActiveDataset()` keeps returning only
 * the per-person `bhw-2025` dataset. Returns null on any read failure.
 * Per-request `cache()`d for the same reason as `getActiveDataset`.
 *
 * `lastUpdatedAt` is what makes this more than an id lookup: it is the version
 * string the AI caches key on, and reading it *per dataset* is what stops a BHW
 * ingestion from invalidating UUC answers and — the direction that matters — a
 * UUC republication from leaving stale UUC answers in place
 * (`UUC_PHC_2025_PLAN.md` §9 U8).
 */
export const getDatasetBySlug = cache(async (slug: string): Promise<DatasetInfo | null> => {
  const result = await readDatasetBySlug(slug);
  return result.state === "available" ? result.value : null;
});

/** Convenience accessor for the many query helpers that only need the numeric FK. */
export async function getDatasetIdBySlug(slug: string): Promise<number | null> {
  return (await getDatasetBySlug(slug))?.datasetId ?? null;
}
