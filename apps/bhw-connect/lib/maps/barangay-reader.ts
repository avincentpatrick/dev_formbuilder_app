import type { createSupabaseServerClient } from "@/lib/db/supabase";

type Db = ReturnType<typeof createSupabaseServerClient>;
type Child = { geo_code: string; geo_name: string };
type Fact = Record<string, unknown>;

const PAGE = 1000;
const MAX_ROWS = 20000;

/** All rows of one query, a page at a time, failing closed rather than returning a partial list. */
async function readAll(
  build: (from: number, to: number) => PromiseLike<{ data: unknown[] | null; error: unknown }>,
): Promise<Fact[]> {
  const all: Fact[] = [];
  for (let from = 0; from < MAX_ROWS; from += PAGE) {
    const { data, error } = await build(from, from + PAGE - 1);
    if (error || !data) throw new Error("Barangay source unavailable");
    all.push(...(data as Fact[]));
    if (data.length < PAGE) return all;
  }
  throw new Error("Barangay source exceeds the read cap");
}

/** The NHFR definitions of lib/catalog's agg_nhfr_counts, applied to one barangay's facilities
 * (supabase/migrations/20260905090200_agg_nhfr_counts.sql). */
const NHFR_COLUMN: Record<string, (facilities: Fact[]) => number> = {
  n_facilities: (f) => f.length,
  n_government: (f) => f.filter((x) => x.ownership_major === "Government").length,
  n_private: (f) => f.filter((x) => x.ownership_major === "Private").length,
  n_barangay_health_station: (f) => f.filter((x) => x.facility_type === "Barangay Health Station").length,
  n_rural_health_unit: (f) => f.filter((x) => x.facility_type === "Rural Health Unit").length,
  n_hospital: (f) => f.filter((x) => x.facility_type === "Hospital").length,
  n_birthing_home: (f) => f.filter((x) => x.facility_type === "Birthing Home").length,
  total_bed_capacity: (f) => f.reduce((sum, x) => sum + (Number.isSafeInteger(x.bed_capacity) ? (x.bed_capacity as number) : 0), 0),
  n_barangays_with_facility: (f) => (f.length > 0 ? 1 : 0),
};

/** Per-barangay rows shaped like the aggregate rows the municipality-level join expects
 * (`{ geo_code, [column]: n }`). A barangay with no facility, or not on the list, reads zero: a zero is a
 * finding here, as in the municipality summaries. `unattributed` counts facilities of the municipality
 * that cannot be placed in a listed barangay (no barangay recorded, or a code the geography does not
 * list), which the municipality total still includes. */
export async function readBarangayRows(
  db: Db,
  input: { dataset: string; column: string; datasetId: number; citymunCode: string; children: Child[] },
): Promise<{ data: Fact[]; unattributed: number }> {
  const { dataset, column, datasetId, citymunCode, children } = input;
  const codes = children.map((c) => c.geo_code);
  if (dataset === "nhfr-2026-09") {
    const value = NHFR_COLUMN[column];
    if (!value) throw new Error("Unsupported barangay column");
    const facilities = await readAll((from, to) =>
      db
        .from("fact_nhfr_facility")
        .select("barangay_geo_code,ownership_major,facility_type,bed_capacity")
        .eq("dataset_id", datasetId)
        .eq("geo_code", citymunCode)
        .order("id")
        .range(from, to),
    );
    const byBarangay = new Map<string, Fact[]>(codes.map((code) => [code, []]));
    let unattributed = 0;
    for (const facility of facilities) {
      const bucket = byBarangay.get(String(facility.barangay_geo_code ?? ""));
      if (bucket) bucket.push(facility);
      else unattributed += 1;
    }
    return { data: codes.map((code) => ({ geo_code: code, [column]: value(byBarangay.get(code)!) })), unattributed };
  }
  if (dataset === "uuc-phc-2025" && column === "n_listed") {
    const listed = await readAll((from, to) =>
      db
        .from("fact_uuc_phc_barangay")
        .select("geo_code")
        .eq("dataset_id", datasetId)
        .in("geo_code", codes)
        .order("geo_code")
        .range(from, to),
    );
    const onList = new Set(listed.map((row) => String(row.geo_code)));
    return { data: codes.map((code) => ({ geo_code: code, n_listed: onList.has(code) ? 1 : 0 })), unattributed: 0 };
  }
  throw new Error("Unsupported barangay indicator");
}
