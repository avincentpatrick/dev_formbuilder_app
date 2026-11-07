import "server-only";
import { createSupabaseServerClient } from "@/lib/db/supabase";
import { attributedFacilities, barangayMembership } from "./barangay";
import {
  columns,
  createCatalogReader,
  type CatalogReader,
  type Row,
  type RowsRead,
} from "./reader";
import type { DistrictScope } from "./district-geography";

/** Batch public aggregates; never call person-level district aggregates with overlapping members. */
export function createDistrictReader(scope: DistrictScope): CatalogReader {
  const base = createCatalogReader();
  const cache = new Map<string, Promise<RowsRead>>();
  const loaded = new Map<string, Promise<boolean>>();
  const memo = (key: string, read: () => Promise<RowsRead>) => {
    if (!cache.has(key))
      cache.set(
        key,
        read().catch(() => ({ state: "failed" })),
      );
    return cache.get(key)!;
  };
  const hasSource = (table: "fact_nhfr_facility" | "fact_uuc_phc_barangay", datasetId: number) => {
    const key = `${table}:${datasetId}`;
    if (!loaded.has(key))
      loaded.set(
        key,
        (async () => {
          const result = await createSupabaseServerClient()
            .from(table)
            .select("dataset_id")
            .eq("dataset_id", datasetId)
            .limit(1);
          if (result.error || !result.data) throw new Error("Source unavailable");
          return result.data.length > 0;
        })(),
      );
    return loaded.get(key)!;
  };
  return {
    ...base,
    async rows(spec, datasetId, selection) {
      const unit = scope.units.find(
        (u) => u.code === selection.code && u.level === selection.level,
      );
      if (!unit) return { state: "failed" };
      const key = JSON.stringify([spec.table, datasetId, unit.level, unit.code]);
      return memo(key, async () => {
        const db = createSupabaseServerClient();
        if (unit.level === "barangay" && spec.table === "agg_nhfr_counts") {
          if (!(await hasSource("fact_nhfr_facility", datasetId)))
            return { state: "available", rows: [] };
          const city = scope.cities.find((c) => c.barangays.some((b) => b.code === unit.code));
          if (!city) return { state: "failed" };
          const all = await memo(`nhfr-city:${datasetId}:${city.code}`, async () => {
            const rows: Row[] = [];
            // Bounded pages, stable identity order, fail closed at the ceiling.
            for (let offset = 0; offset < 10_000; offset += 1000) {
              const result = await db
                .from("fact_nhfr_facility")
                .select("barangay_geo_code,facility_type,ownership_major,bed_capacity")
                .eq("dataset_id", datasetId)
                .eq("geo_code", city.code)
                .order("id")
                .range(offset, offset + 999);
              if (result.error || !result.data) return { state: "failed" };
              rows.push(...result.data);
              if (result.data.length < 1000) return { state: "available", rows };
            }
            return { state: "failed" };
          });
          if (all.state !== "available") return all;
          const valid = new Set(city.allBarangays.map((b) => b.code));
          const unassigned = all.rows.filter((r) => !valid.has(String(r.barangay_geo_code))).length;
          return attributedFacilities(
            all.rows.filter((r) => r.barangay_geo_code === unit.code),
            all.rows.length,
            unassigned,
          );
        }
        const peers = scope.units.filter((u) => u.level === unit.level);
        const index = peers.findIndex((u) => u.code === unit.code);
        const codes = peers
          .slice(Math.floor(index / 10) * 10, Math.floor(index / 10) * 10 + 10)
          .map((u) => u.code);
        if (
          unit.level === "barangay" &&
          (spec.table === "agg_uuc_phc_counts" || spec.table === "agg_uuc_phc_criteria")
        ) {
          if (!(await hasSource("fact_uuc_phc_barangay", datasetId)))
            return { state: "available", rows: [] };
          const members = await memo(`uuc-members:${datasetId}:${codes.join(",")}`, async () => {
            const result = await db
              .from("fact_uuc_phc_barangay")
              .select("geo_code")
              .eq("dataset_id", datasetId)
              .in("geo_code", codes)
              .limit(1000);
            return result.error || !result.data || result.data.length >= 1000
              ? { state: "failed" }
              : { state: "available", rows: result.data };
          });
          if (members.state !== "available") return members;
          const matched = members.rows.filter((r) => r.geo_code === unit.code);
          if (matched.length > 1) return { state: "failed" };
          let criteria: Row | undefined;
          if (matched.length && spec.table === "agg_uuc_phc_criteria") {
            const read = await memo(`uuc-routes:${datasetId}:${codes.join(",")}`, async () => {
              const result = await db
                .from("ref_uuc_phc_list")
                .select(
                  "geo_code,route_ip,route_conflict,route_four_ps,route_health,health_evaluable",
                )
                .eq("dataset_id", datasetId)
                .in("geo_code", codes)
                .limit(1000);
              return result.error || !result.data || result.data.length >= 1000
                ? { state: "failed" }
                : { state: "available", rows: result.data };
            });
            if (read.state !== "available") return read;
            const matches = read.rows.filter((r) => r.geo_code === unit.code);
            if (matches.length > 1) return { state: "failed" };
            criteria = matches[0];
          }
          return barangayMembership(matched.length === 1, criteria, spec.table);
        }
        const read = await memo(
          `aggregate:${spec.table}:${datasetId}:${unit.level}:${codes.join(",")}`,
          async () => {
            const result = await db
              .from(spec.table)
              .select(`geo_code,${columns(spec.table)}`)
              .eq("dataset_id", datasetId)
              .eq("geo_level", unit.level)
              .in("geo_code", codes)
              .limit(1000);
            return result.error || !result.data || result.data.length >= 1000
              ? { state: "failed" }
              : { state: "available", rows: result.data as unknown as Row[] };
          },
        );
        return read.state === "available"
          ? { state: "available", rows: read.rows.filter((r) => r.geo_code === unit.code) }
          : read;
      });
    },
  };
}
