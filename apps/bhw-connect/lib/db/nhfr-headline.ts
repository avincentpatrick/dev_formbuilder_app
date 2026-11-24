import "server-only";
import { createSupabaseServerClient } from "./supabase";
import { readDatasetBySlug, DATASET_SLUGS } from "./dataset";
import { getGeoByCode } from "./geo";
import { getDistrictGeography } from "@/lib/catalog/district-geography";
import type { CatalogSelection } from "@/lib/catalog/types";
import { headlineSchema } from "@/lib/facilities/headline";
import { createCatalogReader } from "@/lib/catalog/reader";
import { CATALOG } from "@/lib/catalog/definitions";
import type { GeoLevel } from "@/lib/filters/schema";

export async function readNhfrHeadline(selection: CatalogSelection, types: string[]) {
  if (selection.mode === "all") return null;
  let units: { code: string; level: GeoLevel }[];
  let locationName: string;
  if (selection.mode === "psgc") {
    const geo = await getGeoByCode(selection.code);
    if (!geo || geo.geoLevel !== selection.level) return null;
    units = [{ code: selection.code, level: selection.level }];
    locationName = geo.geoName;
  } else {
    const geo = await getDistrictGeography(selection);
    if (
      geo.state !== "available" ||
      !geo.current ||
      geo.scope.issues.length ||
      !geo.scope.units.length
    )
      return null;
    // Incomplete district mapping must retain the catalog's partial state.
    if (
      !selection.barangayCode &&
      (!selection.citymunCode ||
        !geo.scope.cities.find((c) => c.code === selection.citymunCode)?.whole) &&
      (geo.ownGaps || geo.globalGaps.cities || geo.globalGaps.barangays)
    )
      return null;
    units = geo.scope.units;
    const city = geo.scope.cities.find((c) => c.code === selection.citymunCode);
    const barangay = city?.barangays.find((b) => b.code === selection.barangayCode);
    locationName = [geo.current.name, city?.name, barangay?.name].filter(Boolean).join(" · ");
  }
  const source = await readDatasetBySlug(DATASET_SLUGS.nhfr);
  if (source.state !== "available") return null;
  // The API/export boundary must honor the same availability and partial states
  // as the catalog, including barangays with unattributed municipal facilities.
  const reader = createCatalogReader();
  const spec = CATALOG.find((d) => d.id === "nhfr")!.indicators[0];
  const expected = {
    total: 0,
    count: 0,
    government: 0,
    private: 0,
    beds: 0,
    present: 0,
    barangays: 0,
    unattributed: 0,
    types: [] as string[],
  };
  for (let offset = 0; offset < units.length; offset += 4) {
    const reads = await Promise.all(
      units
        .slice(offset, offset + 4)
        .map((unit) => reader.rows(spec, source.value.datasetId, { mode: "psgc", ...unit })),
    );
    for (const read of reads) {
      if (read.state !== "available" || read.note?.partial || read.rows.length !== 1) return null;
      const r = read.rows[0];
      const row = headlineSchema.safeParse({
        total: r.n_facilities,
        count: r.n_facilities,
        government: r.n_government,
        private: r.n_private,
        beds: r.total_bed_capacity,
        present: r.n_barangays_with_facility,
        barangays: r.n_barangays,
        unattributed: 0,
        types: [],
      });
      if (!row.success) return null;
      for (const field of [
        "total",
        "count",
        "government",
        "private",
        "beds",
        "present",
        "barangays",
      ] as const)
        expected[field] += row.data[field];
    }
  }
  if (!headlineSchema.safeParse(expected).success) return null;
  const db = createSupabaseServerClient();
  const loaded = await db
    .from("fact_nhfr_facility")
    .select("dataset_id", { head: true, count: "exact" })
    .eq("dataset_id", source.value.datasetId);
  if (loaded.error || !loaded.count) return null;
  const result = await db.rpc("nhfr_headline_summary", {
    p_dataset_id: source.value.datasetId,
    p_roots: units.map((u) => u.code),
    p_types: types,
  });
  if (result.error) return null;
  const parsed = headlineSchema.safeParse(result.data);
  if (!parsed.success || types.some((t) => !parsed.data.types.includes(t))) return null;
  if (parsed.data.total !== expected.total || parsed.data.barangays !== expected.barangays)
    return null;
  if (
    !types.length &&
    !(["count", "government", "private", "beds", "present"] as const).every(
      (k) => parsed.data[k] === expected[k],
    )
  )
    return null;
  return { ...parsed.data, locationName };
}
