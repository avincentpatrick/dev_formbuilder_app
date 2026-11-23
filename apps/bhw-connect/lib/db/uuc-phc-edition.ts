import "server-only";
import { cache } from "react";
import { getDatasetBySlug } from "./dataset";
import { EDITION_PARAM, UUC_EDITIONS, pickEdition, type UucEdition } from "@/lib/uuc-phc/editions";

/**
 * Which UUC for PHC editions this deployment can show, and which one a request is for.
 *
 * "Available" means the edition's `dim_dataset` row exists. The list ships as migrations that write
 * only where the geography is loaded (see 20261124090000_seed_dim_dataset_uuc_phc_2026.sql), so the
 * CI and local databases carry only 2025 while the testing server carries both. Deciding from the
 * row rather than from the code keeps every page on real data in both places: the section opens on
 * the newest edition that is there, and the switch offers only editions that are.
 */
export const getAvailableUucEditions = cache(async (): Promise<UucEdition[]> => {
  const rows = await Promise.all(UUC_EDITIONS.map((e) => getDatasetBySlug(e.slug)));
  return UUC_EDITIONS.filter((_, i) => rows[i] !== null);
});

export type ResolvedUucEdition = {
  /** The edition this request shows. */
  edition: UucEdition;
  /** The edition a link without `?edition=` opens — the newest available. */
  defaultEdition: UucEdition;
  /** Every edition available, newest first — what the switch offers. */
  available: UucEdition[];
};

type SearchParams = Record<string, string | string[] | undefined> | undefined;

/** The `?edition=` value from a page's search params, or null. */
export function editionParam(searchParams: SearchParams): string | null {
  const raw = searchParams?.[EDITION_PARAM];
  const value = Array.isArray(raw) ? raw[0] : raw;
  return value ?? null;
}

/** The edition for one request, from its search params. Never throws. */
export async function resolveUucEdition(
  searchParams?: SearchParams | Promise<SearchParams>,
): Promise<ResolvedUucEdition> {
  const [available, params] = await Promise.all([getAvailableUucEditions(), searchParams]);
  const defaultEdition = pickEdition(null, available);
  return {
    edition: pickEdition(editionParam(params), available),
    defaultEdition,
    available: available.length > 0 ? available : [defaultEdition],
  };
}

/** The default edition alone, for surfaces outside the section (context chips, OG images). */
export async function defaultUucEdition(): Promise<UucEdition> {
  return pickEdition(null, await getAvailableUucEditions());
}
