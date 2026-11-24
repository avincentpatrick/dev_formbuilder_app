import "server-only";
import { cache } from "react";
import { getNhfrCounts, getNhfrChildren, getNhfrTypes, getNhfrChildGeos } from "./nhfr";
import { getGeoByCode } from "./geo";
import { getDatasetIdBySlug, DATASET_SLUGS } from "./dataset";
import { createSupabaseServerClient } from "./supabase";
import {
  validateDistribution,
  type DistributionInput,
  type DistributionCell,
  type DistributionLevel,
} from "@/lib/facilities/distribution";

/** Complete ordered pages, including the request after an exact 1,000-row page. */
export async function readDistributionCells(
  codes: string[],
  level: "region" | "province" | "citymun",
  datasetId: number,
  typeCount: number,
): Promise<DistributionCell[] | null> {
  const db = createSupabaseServerClient();
  const cells: DistributionCell[] = [];
  for (let start = 0; start < codes.length; start += 25) {
    const batch = codes.slice(start, start + 25);
    let read = 0;
    for (let offset = 0; ; offset += 1000) {
      const { data, error } = await db
        .from("agg_nhfr_by_type")
        .select("geo_code,facility_type,n_facilities,n_government,n_private")
        .eq("dataset_id", datasetId)
        .eq("geo_level", level)
        .in("geo_code", batch)
        .order("geo_code")
        .order("facility_type")
        .range(offset, offset + 999);
      if (error || !data) return null;
      read += data.length;
      if (read > batch.length * typeCount) return null;
      cells.push(
        ...data.map((r) => ({
          code: r.geo_code,
          type: r.facility_type,
          count: r.n_facilities,
          government: r.n_government,
          private: r.n_private,
        })),
      );
      if (data.length < 1000) break;
    }
  }
  return cells;
}
export const getNhfrDistribution = cache(
  async (code: string, level: DistributionLevel): Promise<DistributionInput | null> => {
    try {
      const [geo, counts, children, types, roster, datasetId] = await Promise.all([
        getGeoByCode(code),
        getNhfrCounts(code, level),
        getNhfrChildren(code, level),
        getNhfrTypes(code, level),
        getNhfrChildGeos(code, level),
        getDatasetIdBySlug(DATASET_SLUGS.nhfr),
      ]);
      if (
        !geo ||
        geo.geoLevel !== level ||
        !counts ||
        !types ||
        !datasetId ||
        !roster.length ||
        children.length !== roster.length ||
        new Set(children.map((c) => c.geoCode)).size !== children.length ||
        children.some(
          (c) => !roster.some((g) => g.geoCode === c.geoCode && g.geoLevel === c.geoLevel),
        )
      )
        return null;
      const input: DistributionInput = {
        code,
        level,
        name: geo.geoName,
        total: {
          count: counts.nFacilities,
          government: counts.nGovernment,
          private: counts.nPrivate,
        },
        areas: children.map((c) => ({
          code: c.geoCode,
          name: c.geoName,
          level: c.geoLevel,
          count: c.nFacilities,
          government: c.nGovernment,
          private: c.nPrivate,
        })),
        types: types.map((t) => ({
          type: t.facilityType,
          count: t.nFacilities,
          government: t.nGovernment,
          private: t.nPrivate,
        })),
        cells: [],
      };
      // Reject inconsistent base totals before requesting the sparse cross-tab.
      const partition = (rows: { count: number; government: number; private: number }[]) =>
        rows.reduce(
          (s, r) => ({
            count: s.count + r.count,
            government: s.government + r.government,
            private: s.private + r.private,
          }),
          { count: 0, government: 0, private: 0 },
        );
      for (const total of [partition(input.areas), partition(input.types)])
        if (
          total.count !== input.total.count ||
          total.government !== input.total.government ||
          total.private !== input.total.private
        )
          return null;
      const childLevel = { national: "region", region: "province", province: "citymun" }[level] as
        "region" | "province" | "citymun";
      const cells = await readDistributionCells(
        roster.map((g) => g.geoCode),
        childLevel,
        datasetId,
        types.length,
      );
      if (!cells) return null;
      input.cells = cells;
      return validateDistribution(input) ? input : null;
    } catch {
      return null;
    }
  },
);
