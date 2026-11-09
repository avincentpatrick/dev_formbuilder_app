import "server-only";
import { createSupabaseServerClient } from "@/lib/db/supabase";
import type { CatalogTable } from "./definitions";
import type { Row, RowsRead } from "./reader";

const validCount = (n: unknown): n is number => Number.isSafeInteger(n) && Number(n) >= 0;

/** Pure calculation over explicitly attributed public facilities, never over people or rates. */
export function attributedFacilities(
  facilities: Row[],
  total: number,
  unassigned: number,
): RowsRead {
  if (
    !validCount(total) ||
    !validCount(unassigned) ||
    unassigned > total ||
    facilities.length > total - unassigned ||
    facilities.some(
      (r) =>
        !validCount(r.bed_capacity) ||
        typeof r.facility_type !== "string" ||
        typeof r.ownership_major !== "string",
    )
  )
    return { state: "failed" };
  const row: Row = {
    n_facilities: facilities.length,
    n_government: facilities.filter((r) => r.ownership_major === "Government").length,
    n_private: facilities.filter((r) => r.ownership_major === "Private").length,
    n_barangay_health_station: facilities.filter(
      (r) => r.facility_type === "Barangay Health Station",
    ).length,
    n_rural_health_unit: facilities.filter((r) => r.facility_type === "Rural Health Unit").length,
    n_hospital: facilities.filter((r) => r.facility_type === "Hospital").length,
    n_birthing_home: facilities.filter((r) => r.facility_type === "Birthing Home").length,
    total_bed_capacity: facilities.reduce((sum, r) => sum + Number(r.bed_capacity), 0),
    n_barangays_with_facility: facilities.length > 0 ? 1 : 0,
    n_barangays: 1,
  };
  return {
    state: "available",
    rows: [row],
    note: {
      partial: unassigned > 0,
      reason: `Calculated from facilities explicitly attributed to this barangay in the accepted NHFR release. ${unassigned} of ${total} registered facilities in its municipality/city have no valid barangay attribution and are excluded. Zero means no attributed registered facilities, not proof that no facilities exist. Recorded beds are summed; rates are not averaged.`,
      coverage: {
        label: "Municipal facilities with barangay attribution",
        covered: total - unassigned,
        total,
      },
    },
  };
}

/** Membership and qualifying routes are distinct: absence from the list is not an assessment. */
export function barangayMembership(
  listed: boolean,
  criteria: Row | undefined,
  table: CatalogTable,
): RowsRead {
  if (table === "agg_uuc_phc_counts")
    return {
      state: "available",
      rows: [{ n_listed: listed ? 1 : 0, n_barangays: 1 }],
      note: {
        reason: listed
          ? "This barangay is on the accepted 2025 UUC-PHC list."
          : "This barangay is not on the accepted 2025 UUC-PHC list. Not listed does not mean assessed and found well served.",
      },
    };
  if (!listed || !criteria)
    return {
      state: "available",
      rows: [],
      note: {
        reason:
          "No accepted qualification record is available for this barangay. A municipality's qualification rate is not inherited.",
      },
    };
  if (
    ["route_ip", "route_conflict", "route_four_ps", "route_health", "health_evaluable"].some(
      (key) => typeof criteria[key] !== "boolean",
    )
  )
    return { state: "failed" };
  return {
    state: "available",
    rows: [
      {
        n_listed: 1,
        n_route_ip: Number(criteria.route_ip),
        n_route_conflict: Number(criteria.route_conflict),
        n_route_four_ps: Number(criteria.route_four_ps),
        n_route_health: criteria.health_evaluable ? Number(criteria.route_health) : null,
        n_health_evaluable: criteria.health_evaluable ? 1 : 0,
      },
    ],
    note: {
      reason:
        "Recorded qualifying routes for this listed barangay; routes overlap. Health qualification is unavailable when provincial benchmarks cannot support evaluation. These are membership flags, not inherited municipal rates.",
    },
  };
}

/** Per-request leaf reads. A nonempty accepted source must be established before absence means zero. */
export function createBarangayReader() {
  const loaded = new Map<string, Promise<boolean>>();
  const reads = new Map<string, Promise<RowsRead>>();
  return async (table: CatalogTable, datasetId: number, code: string): Promise<RowsRead> => {
    const key = JSON.stringify([table, datasetId, code]);
    if (!reads.has(key))
      reads.set(
        key,
        (async (): Promise<RowsRead> => {
          try {
            const db = createSupabaseServerClient();
            const sourceTable =
              table === "agg_nhfr_counts" ? "fact_nhfr_facility" : "fact_uuc_phc_barangay";
            const sourceKey = `${sourceTable}:${datasetId}`;
            if (!loaded.has(sourceKey))
              loaded.set(
                sourceKey,
                (async () => {
                  const result = await db
                    .from(sourceTable)
                    .select("dataset_id", { head: true, count: "exact" })
                    .eq("dataset_id", datasetId);
                  if (result.error || !validCount(result.count))
                    throw new Error("Source read failed");
                  return result.count > 0;
                })(),
              );
            if (!(await loaded.get(sourceKey))) return { state: "available", rows: [] };
            const geo = await db
              .from("dim_geo")
              .select("geo_code,parent_code")
              .eq("geo_code", code)
              .eq("geo_level", "barangay")
              .maybeSingle();
            if (geo.error || !geo.data?.parent_code) return { state: "failed" };
            if (table === "agg_nhfr_counts") {
              const city = geo.data.parent_code;
              const [leaf, total, assigned] = await Promise.all([
                db
                  .from("fact_nhfr_facility")
                  .select("facility_type,ownership_major,bed_capacity")
                  .eq("dataset_id", datasetId)
                  .eq("geo_code", city)
                  .eq("barangay_geo_code", code)
                  .limit(1000),
                db
                  .from("fact_nhfr_facility")
                  .select("dataset_id", { head: true, count: "exact" })
                  .eq("dataset_id", datasetId)
                  .eq("geo_code", city),
                db
                  .from("fact_nhfr_facility")
                  .select(
                    "dataset_id,barangay:dim_geo!fact_nhfr_facility_barangay_geo_code_fkey!inner(geo_code)",
                    { head: true, count: "exact" },
                  )
                  .eq("dataset_id", datasetId)
                  .eq("geo_code", city)
                  .eq("barangay.geo_level", "barangay")
                  .eq("barangay.parent_code", city),
              ]);
              if (
                leaf.error ||
                total.error ||
                assigned.error ||
                !leaf.data ||
                leaf.data.length >= 1000 ||
                !validCount(total.count) ||
                !validCount(assigned.count)
              )
                return { state: "failed" };
              return attributedFacilities(leaf.data, total.count, total.count - assigned.count);
            }
            const membership = await db
              .from("fact_uuc_phc_barangay")
              .select("geo_code")
              .eq("dataset_id", datasetId)
              .eq("geo_code", code)
              .limit(2);
            if (membership.error || !membership.data || membership.data.length > 1)
              return { state: "failed" };
            let criteria: Row | undefined;
            if (table === "agg_uuc_phc_criteria" && membership.data.length) {
              const result = await db
                .from("ref_uuc_phc_list")
                .select("route_ip,route_conflict,route_four_ps,route_health,health_evaluable")
                .eq("dataset_id", datasetId)
                .eq("geo_code", code)
                .limit(2);
              if (result.error || !result.data || result.data.length > 1)
                return { state: "failed" };
              criteria = result.data[0];
            }
            return barangayMembership(membership.data.length === 1, criteria, table);
          } catch {
            return { state: "failed" };
          }
        })(),
      );
    return reads.get(key)!;
  };
}
