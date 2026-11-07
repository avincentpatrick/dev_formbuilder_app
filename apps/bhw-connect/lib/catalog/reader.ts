import "server-only";
import { createSupabaseServerClient } from "@/lib/db/supabase";
import { readDatasetBySlug, type DatasetRead } from "@/lib/db/dataset";
import { ACCEPTED_RELEASES, CATALOG, type IndicatorSpec, type CatalogTable } from "./definitions";
import { createBarangayReader } from "./barangay";
import type { CatalogSelection, CatalogIndicator } from "./types";

export type Row = Record<string, unknown>;
export type RowsRead =
  | {
      state: "available";
      rows: Row[];
      note?: { reason: string; partial?: boolean; coverage?: CatalogIndicator["coverage"] };
    }
  | { state: "failed" };
export type ProbeRead =
  { state: "available"; count: number; hasSuppressed: boolean } | { state: "failed" };
export interface CatalogReader {
  source(slug: string): Promise<DatasetRead>;
  privacy(spec: IndicatorSpec, datasetId: number): Promise<"clear" | "suppressed" | "failed">;
  rows(
    spec: IndicatorSpec,
    datasetId: number,
    selection: Extract<CatalogSelection, { mode: "psgc" }>,
  ): Promise<RowsRead>;
  probe(spec: IndicatorSpec, datasetId: number): Promise<ProbeRead>;
  fhsisDefinitions(): Promise<RowsRead>;
}

const specs = CATALOG.flatMap((d) => d.indicators);
/** One allowlisted projection per aggregate. Never SELECT * or request a service-role client. */
export function columns(table: CatalogTable): string {
  return [
    ...new Set(
      specs
        .filter((s) => s.table === table)
        .flatMap((s) => [
          s.column,
          ...Object.keys(s.filters ?? {}),
          ...Object.values(s.attributes ?? {}),
          ...(s.suppression ? [s.suppression] : []),
          ...(s.categoryColumn ? [s.categoryColumn] : []),
          ...(s.table === "agg_uuc_phc_criteria" ? ["n_listed"] : []),
          ...(s.table === "fact_fhsis_indicator" ? ["numerator_exact", "denominator_exact"] : []),
        ]),
    ),
  ].join(",");
}

/** Per-request caches deduplicate metadata and selected-area queries (including training topics).
 * All-locations uses HEAD exact counts: no profiles, rows, or example values leave the database.
 */
export function createCatalogReader(): CatalogReader {
  const barangay = createBarangayReader();
  const privacy = new Map<string, Promise<"clear" | "suppressed" | "failed">>();
  const sources = new Map<string, Promise<DatasetRead>>();
  const rows = new Map<string, Promise<RowsRead>>();
  let definitions: Promise<RowsRead> | undefined;
  return {
    source(slug) {
      if (!sources.has(slug))
        sources.set(slug, readDatasetBySlug(slug, slug === ACCEPTED_RELEASES.bhw));
      return sources.get(slug)!;
    },
    privacy(spec, datasetId) {
      if (!spec.suppression) return Promise.resolve("clear");
      const key = JSON.stringify([datasetId, spec.table, spec.filters]);
      if (!privacy.has(key))
        privacy.set(
          key,
          (async () => {
            try {
              let query = createSupabaseServerClient()
                .from(spec.table)
                .select(spec.suppression!, { head: true, count: "exact" })
                .eq("dataset_id", datasetId)
                .eq(spec.suppression!, true);
              for (const [column, value] of Object.entries(spec.filters ?? {}))
                query = query.eq(column, value);
              const result = await query;
              if (
                result.error ||
                result.count === null ||
                !Number.isSafeInteger(result.count) ||
                result.count < 0
              )
                return "failed";
              return result.count > 0 ? "suppressed" : "clear";
            } catch {
              return "failed";
            }
          })(),
        );
      return privacy.get(key)!;
    },
    rows(spec, datasetId, selection) {
      if (
        selection.level === "barangay" &&
        ["agg_nhfr_counts", "agg_uuc_phc_counts", "agg_uuc_phc_criteria"].includes(spec.table)
      )
        return barangay(spec.table, datasetId, selection.code);
      const key = JSON.stringify([spec.table, datasetId, selection.level, selection.code]);
      if (!rows.has(key))
        rows.set(
          key,
          (async (): Promise<RowsRead> => {
            try {
              // Each selected geography has at most 44 topic rows or a small category breakdown.
              // Read 1,000; reaching the cap fails closed instead of silently claiming completeness.
              const { data, error } = await createSupabaseServerClient()
                .from(spec.table)
                .select(columns(spec.table))
                .eq("dataset_id", datasetId)
                .eq("geo_code", selection.code)
                .eq("geo_level", selection.level)
                .limit(1000);
              if (error || !data || data.length >= 1000) return { state: "failed" };
              return { state: "available", rows: data as unknown as Row[] };
            } catch {
              return { state: "failed" };
            }
          })(),
        );
      return rows.get(key)!;
    },
    async probe(spec, datasetId) {
      try {
        let query = createSupabaseServerClient()
          .from(spec.table)
          .select(spec.column, { head: true, count: "exact" })
          .eq("dataset_id", datasetId)
          .in("geo_level", [...spec.levels])
          .not(spec.column, "is", null);
        for (const [key, value] of Object.entries(spec.filters ?? {})) query = query.eq(key, value);
        if (spec.suppression) query = query.eq(spec.suppression, false);
        const { count, error } = await query;
        if (error || count === null || !Number.isSafeInteger(count) || count < 0)
          return { state: "failed" };
        let hasSuppressed = false;
        if (spec.suppression) {
          let hidden = createSupabaseServerClient()
            .from(spec.table)
            .select(spec.suppression, { head: true, count: "exact" })
            .eq("dataset_id", datasetId)
            .in("geo_level", [...spec.levels])
            .eq(spec.suppression, true);
          for (const [key, value] of Object.entries(spec.filters ?? {}))
            hidden = hidden.eq(key, value);
          const result = await hidden;
          if (result.error || result.count === null) return { state: "failed" };
          hasSuppressed = result.count > 0;
        }
        return { state: "available", count, hasSuppressed };
      } catch {
        return { state: "failed" };
      }
    },
    fhsisDefinitions() {
      definitions ??= (async (): Promise<RowsRead> => {
        try {
          const { data, error } = await createSupabaseServerClient()
            .from("ref_fhsis_indicator")
            .select("indicator_key,label,unit,numerator_def,denominator_def,source_workbook");
          return error || !data ? { state: "failed" } : { state: "available", rows: data };
        } catch {
          return { state: "failed" };
        }
      })();
      return definitions;
    },
  };
}
