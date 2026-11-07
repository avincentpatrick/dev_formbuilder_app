import type { CatalogId, CatalogSelection } from "./types";

export type CatalogParams = Record<string, string | string[] | undefined>;
const IDS: readonly CatalogId[] = ["bhw", "nhfr", "uuc-phc", "fhsis", "population", "poverty"];
export type CatalogRequest = { selection: CatalogSelection; dataset: CatalogId | null };

/** One canonical URL shape for forms, links, refresh and back/forward. No time selector. */
export function parseCatalogParams(params: CatalogParams): CatalogRequest | null {
  const mode = params.mode ?? "all";
  if (Array.isArray(mode) || Array.isArray(params.location) || Array.isArray(params.dataset))
    return null;
  const dataset = params.dataset ?? null;
  if (dataset !== null && !IDS.includes(dataset as CatalogId)) return null;
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
  } else throw new Error("District catalog navigation is not implemented yet.");
  if (dataset) query.set("dataset", dataset);
  return `/catalog?${query}`;
}
