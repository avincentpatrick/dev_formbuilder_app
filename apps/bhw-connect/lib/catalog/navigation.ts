import type { CatalogId, CatalogSelection } from "./types";

export type CatalogParams = Record<string, string | string[] | undefined>;
const IDS: readonly CatalogId[] = ["bhw", "nhfr", "uuc-phc", "fhsis", "population", "poverty"];
export type CatalogRequest = { selection: CatalogSelection; dataset: CatalogId | null };

/** One canonical URL shape for forms, links, refresh and back/forward. No time selector. */
export function parseCatalogParams(params: CatalogParams): CatalogRequest | null {
  const mode = params.mode ?? "all";
  if (
    Array.isArray(mode) ||
    Array.isArray(params.location) ||
    Array.isArray(params.dataset) ||
    [params.district, params.citymun, params.barangay].some(Array.isArray)
  )
    return null;
  const dataset = params.dataset ?? null;
  if (dataset !== null && !IDS.includes(dataset as CatalogId)) return null;
  if (mode === "district") {
    if (params.location) return null;
    const district = params.district as string | undefined;
    const citymun = params.citymun as string | undefined;
    const barangay = params.barangay as string | undefined;
    if (
      (district && !/^[a-zA-Z0-9_-]{1,100}$/.test(district)) ||
      (citymun && !/^\d{2,10}$/.test(citymun)) ||
      (barangay && !/^\d{2,10}$/.test(barangay)) ||
      (!district && (citymun || barangay || dataset)) ||
      (barangay && !citymun)
    )
      return null;
    return {
      selection: {
        mode: "district",
        districtCode: district ?? "",
        ...(citymun ? { citymunCode: citymun } : {}),
        ...(barangay ? { barangayCode: barangay } : {}),
      },
      dataset: dataset as CatalogId | null,
    };
  }
  if (params.district || params.citymun || params.barangay) return null;
  if (mode === "all") {
    if (params.location) return null; // Switching modes must clear the old location.
    return { selection: { mode: "all" }, dataset: dataset as CatalogId | null };
  }
  if (mode !== "psgc" || !params.location) return null;
  const match = /^(national|region|province|citymun|barangay):(PH|\d{2,10})$/.exec(params.location);
  if (!match || (match[1] === "national") !== (match[2] === "PH")) return null;
  return {
    selection: {
      mode: "psgc",
      level: match[1] as "national" | "region" | "province" | "citymun" | "barangay",
      code: match[2],
    },
    dataset: dataset as CatalogId | null,
  };
}

export function catalogHref(selection: CatalogSelection, dataset?: CatalogId | null): string {
  const query = new URLSearchParams();
  if (selection.mode === "all") query.set("mode", "all");
  else if (selection.mode === "psgc") {
    query.set("mode", "psgc");
    query.set("location", `${selection.level}:${selection.code}`);
  } else {
    query.set("mode", "district");
    if (selection.districtCode) query.set("district", selection.districtCode);
    if (selection.citymunCode) query.set("citymun", selection.citymunCode);
    if (selection.barangayCode) query.set("barangay", selection.barangayCode);
  }
  if (dataset) query.set("dataset", dataset);
  return `/catalog?${query}`;
}
