import "server-only";
import { cache } from "react";
import { createSupabaseServerClient } from "./supabase";
import { getDatasetIdBySlug } from "./dataset";

/**
 * What changed between one UUC for PHC edition and the one before it (`/uuc-phc/changes`).
 *
 * Two different kinds of change, kept apart because they answer different questions:
 *
 *   - **Membership** — barangays that joined or left the list. Read from
 *     `ref_uuc_phc_list_changes`, which compares the two editions' fact tables by geography code.
 *   - **Recorded values** — barangays on both lists whose indicator values or criterion (d) score
 *     differ. Read from `ref_uuc_phc_indicator_changes`. These are values *as loaded*, i.e. after
 *     cleaning bounded them, so a value capped to 100 in both years reads as unchanged.
 *
 * Neither is a judgement about why a barangay moved. The list records presence, not the assessment
 * behind it (see the methodology), so this page says what moved and nothing about the reason.
 */

export type UucChangeKind = "added" | "removed";

export type UucListChange = {
  change: UucChangeKind;
  geoCode: string;
  barangayName: string;
  citymunCode: string;
  citymunName: string;
  provinceCode: string;
  provinceName: string;
  regionCode: string;
  regionName: string;
};

export type UucValueChange = {
  geoCode: string;
  barangayName: string;
  citymunName: string;
  provinceCode: string;
  provinceName: string;
  regionCode: string;
  regionName: string;
  /** Column names, as in `fact_uuc_phc_indicators` (`health_indicators` is the criterion (d) score). */
  changedIndicators: string[];
};

/** Rows are read in pages: PostgREST caps a response at 1,000 rows. */
const PAGE = 1000;

/**
 * Barangays that joined or left the list between `slug`'s edition and the one before it, ordered by
 * region, province, city/municipality and barangay. Null on a read failure — which the page must
 * show as "could not be read", never as "nothing changed".
 */
export const getUucPhcListChanges = cache(async (slug: string): Promise<UucListChange[] | null> => {
  const datasetId = await getDatasetIdBySlug(slug);
  if (datasetId === null) return null;

  const supabase = createSupabaseServerClient();
  const out: UucListChange[] = [];
  for (let from = 0; ; from += PAGE) {
    const { data, error } = await supabase
      .from("ref_uuc_phc_list_changes")
      .select(
        "change, geo_code, geo_name, citymun_code, citymun_name, province_code, province_name, region_code, region_name",
      )
      .eq("dataset_id", datasetId)
      .order("region_code")
      .order("province_name")
      .order("citymun_name")
      .order("geo_name")
      .range(from, from + PAGE - 1);
    if (error || !data) return null;
    for (const row of data) {
      // A view cannot declare `not null`. A row missing its change kind or its code names no
      // barangay, so it is dropped rather than shown under a guessed heading.
      if ((row.change !== "added" && row.change !== "removed") || !row.geo_code) continue;
      out.push({
        change: row.change,
        geoCode: row.geo_code,
        barangayName: row.geo_name ?? row.geo_code,
        citymunCode: row.citymun_code ?? "",
        citymunName: row.citymun_name ?? row.citymun_code ?? "",
        provinceCode: row.province_code ?? "",
        provinceName: row.province_name ?? row.province_code ?? "",
        regionCode: row.region_code ?? "",
        regionName: row.region_name ?? row.region_code ?? "",
      });
    }
    if (data.length < PAGE) break;
  }
  return out;
});

/** Barangays on both lists whose recorded values differ. Null on a read failure. */
export const getUucPhcValueChanges = cache(
  async (slug: string): Promise<UucValueChange[] | null> => {
    const datasetId = await getDatasetIdBySlug(slug);
    if (datasetId === null) return null;

    const supabase = createSupabaseServerClient();
    const out: UucValueChange[] = [];
    for (let from = 0; ; from += PAGE) {
      const { data, error } = await supabase
        .from("ref_uuc_phc_indicator_changes")
        .select(
          "geo_code, geo_name, citymun_name, province_code, province_name, region_code, region_name, changed_indicators",
        )
        .eq("dataset_id", datasetId)
        .order("region_code")
        .order("province_name")
        .order("citymun_name")
        .order("geo_name")
        .range(from, from + PAGE - 1);
      if (error || !data) return null;
      for (const row of data) {
        if (!row.geo_code) continue;
        out.push({
          geoCode: row.geo_code,
          barangayName: row.geo_name ?? row.geo_code,
          citymunName: row.citymun_name ?? "",
          provinceCode: row.province_code ?? "",
          provinceName: row.province_name ?? row.province_code ?? "",
          regionCode: row.region_code ?? "",
          regionName: row.region_name ?? row.region_code ?? "",
          changedIndicators: row.changed_indicators ?? [],
        });
      }
      if (data.length < PAGE) break;
    }
    return out;
  },
);

export type UucChangeAreaSummary = {
  code: string;
  name: string;
  added: number;
  removed: number;
};

/** Added and removed counts per region (or per province), in first-seen order. Pure. */
export function summariseChanges(
  changes: readonly UucListChange[],
  by: "region" | "province",
): UucChangeAreaSummary[] {
  const map = new Map<string, UucChangeAreaSummary>();
  for (const c of changes) {
    const code = by === "region" ? c.regionCode : c.provinceCode;
    const name = by === "region" ? c.regionName : c.provinceName;
    const row = map.get(code) ?? { code, name, added: 0, removed: 0 };
    row[c.change] += 1;
    map.set(code, row);
  }
  return [...map.values()];
}

/**
 * One province's changes, grouped by city/municipality — the shape the page's table prints, with
 * barangay names in each group. Pure.
 */
export type UucProvinceChanges = {
  provinceCode: string;
  provinceName: string;
  regionName: string;
  added: { citymunName: string; barangays: string[] }[];
  removed: { citymunName: string; barangays: string[] }[];
  nAdded: number;
  nRemoved: number;
};

export function groupChangesByProvince(changes: readonly UucListChange[]): UucProvinceChanges[] {
  const provinces = new Map<string, UucProvinceChanges>();
  for (const c of changes) {
    const p =
      provinces.get(c.provinceCode) ??
      ({
        provinceCode: c.provinceCode,
        provinceName: c.provinceName,
        regionName: c.regionName,
        added: [],
        removed: [],
        nAdded: 0,
        nRemoved: 0,
      } satisfies UucProvinceChanges);
    const groups = c.change === "added" ? p.added : p.removed;
    let group = groups.find((g) => g.citymunName === c.citymunName);
    if (!group) {
      group = { citymunName: c.citymunName, barangays: [] };
      groups.push(group);
    }
    group.barangays.push(c.barangayName);
    if (c.change === "added") p.nAdded += 1;
    else p.nRemoved += 1;
    provinces.set(c.provinceCode, p);
  }
  return [...provinces.values()].sort((a, b) => a.provinceName.localeCompare(b.provinceName));
}

/** How many barangays had each recorded value change, largest first. Pure. */
export function countValueChanges(changes: readonly UucValueChange[]): [string, number][] {
  const counts = new Map<string, number>();
  for (const c of changes)
    for (const key of c.changedIndicators) counts.set(key, (counts.get(key) ?? 0) + 1);
  return [...counts.entries()].sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]));
}
