import "server-only";
import { cache } from "react";
import { z } from "zod";
import { getNhfrCounts, getNhfrChildren, getNhfrChildGeos } from "./nhfr";
import { getGeoByCode } from "./geo";
import { getDatasetIdBySlug, DATASET_SLUGS } from "./dataset";
import { createSupabaseServerClient } from "./supabase";
import { presenceSchema, type PresenceInput } from "@/lib/facilities/presence";
import type { DistributionLevel } from "@/lib/facilities/distribution";
import { facilityPlaces } from "@/lib/facilities/analysis";
const count = z.number().int().nonnegative().max(Number.MAX_SAFE_INTEGER);
const summarySchema = z.object({
  types: z.array(z.string().min(1)),
  rows: z.array(
    z.object({
      code: z.string(),
      total: count,
      allPresent: count,
      present: count,
      barangays: count,
    }),
  ),
});
export const getNhfrPresence = cache(
  async (
    code: string,
    level: DistributionLevel,
    types: string[] = [],
  ): Promise<PresenceInput | null> => {
    try {
      const [geo, parent, children, roster, datasetId] = await Promise.all([
        getGeoByCode(code),
        getNhfrCounts(code, level),
        getNhfrChildren(code, level),
        getNhfrChildGeos(code, level),
        getDatasetIdBySlug(DATASET_SLUGS.nhfr),
      ]);
      if (
        !geo ||
        geo.geoLevel !== level ||
        !parent ||
        !datasetId ||
        !facilityPlaces(
          children,
          parent.nFacilities,
          roster.map((g) => g.geoCode),
        ) ||
        children.some(
          (c) => !roster.some((g) => g.geoCode === c.geoCode && g.geoLevel === c.geoLevel),
        ) ||
        children.reduce((s, c) => s + c.nBarangays, 0) !== parent.nBarangays ||
        children.reduce((s, c) => s + c.nBarangaysWithFacility, 0) !== parent.nBarangaysWithFacility
      )
        return null;
      const db = createSupabaseServerClient();
      const { data, error } = await db.rpc("nhfr_presence_summary", {
        p_dataset_id: datasetId,
        p_root: code,
        p_types: types,
      });
      const parsed = summarySchema.safeParse(data);
      if (error || !parsed.success) return null;
      const summary = parsed.data;
      if (
        summary.rows.length !== children.length ||
        new Set(summary.rows.map((r) => r.code)).size !== children.length ||
        types.some((t) => !summary.types.includes(t))
      )
        return null;
      const rows = [];
      for (const child of children) {
        const row = summary.rows.find((r) => r.code === child.geoCode);
        if (
          !row ||
          row.total !== child.nFacilities ||
          row.allPresent !== child.nBarangaysWithFacility ||
          row.barangays !== child.nBarangays ||
          row.present > row.allPresent ||
          (!types.length && row.present !== row.allPresent)
        )
          return null;
        rows.push({
          code: child.geoCode,
          name: child.geoName,
          level: child.geoLevel,
          present: row.present,
          barangays: row.barangays,
        });
      }
      const input = presenceSchema.safeParse({
        code,
        level,
        name: geo.geoName,
        types: summary.types,
        selectedTypes: types,
        rows,
      });
      return input.success ? input.data : null;
    } catch {
      return null;
    }
  },
);
