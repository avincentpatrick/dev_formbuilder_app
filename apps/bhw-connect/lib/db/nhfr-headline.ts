import "server-only";
import { createSupabaseServerClient } from "./supabase";
import { readDatasetBySlug, DATASET_SLUGS } from "./dataset";
import { getGeoByCode } from "./geo";
import { getDistrictGeography } from "@/lib/catalog/district-geography";
import type { CatalogSelection } from "@/lib/catalog/types";
import { headlineSchema } from "@/lib/facilities/headline";

export async function readNhfrHeadline(selection: CatalogSelection, types: string[]) {
  if (selection.mode === "all") return null;
  let roots: string[];
  let locationName: string;
  if (selection.mode === "psgc") {
    const geo = await getGeoByCode(selection.code);
    if (!geo || geo.geoLevel !== selection.level) return null;
    roots = [selection.code];
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
    roots = geo.scope.units.map((u) => u.code);
    const city = geo.scope.cities.find((c) => c.code === selection.citymunCode);
    const barangay = city?.barangays.find((b) => b.code === selection.barangayCode);
    locationName = [geo.current.name, city?.name, barangay?.name].filter(Boolean).join(" · ");
  }
  const source = await readDatasetBySlug(DATASET_SLUGS.nhfr);
  if (source.state !== "available") return null;
  const db = createSupabaseServerClient();
  const loaded = await db
    .from("fact_nhfr_facility")
    .select("dataset_id", { head: true, count: "exact" })
    .eq("dataset_id", source.value.datasetId);
  if (loaded.error || !loaded.count) return null;
  const result = await db.rpc("nhfr_headline_summary", {
    p_dataset_id: source.value.datasetId,
    p_roots: roots,
    p_types: types,
  });
  if (result.error) return null;
  const parsed = headlineSchema.safeParse(result.data);
  if (!parsed.success || types.some((t) => !parsed.data.types.includes(t))) return null;
  return { ...parsed.data, locationName };
}
