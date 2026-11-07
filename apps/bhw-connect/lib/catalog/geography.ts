import "server-only";
import { createSupabaseServerClient } from "@/lib/db/supabase";
import type { CatalogSelection } from "./types";

export type CatalogPlace = {
  code: string;
  name: string;
  level: "national" | "region" | "province" | "citymun";
  parentCode: string | null;
};
export type CatalogGeography =
  | {
      state: "available";
      current: CatalogPlace | null;
      ancestors: CatalogPlace[];
      children: CatalogPlace[];
    }
  | { state: "failed" | "not-found" };
const LEVELS = ["region", "province", "citymun"] as const;
const NATIONAL: CatalogPlace = {
  code: "PH",
  name: "Philippines",
  level: "national",
  parentCode: null,
};
const COLUMNS = "geo_code,geo_name,geo_level,parent_code";

/** Actual parentage, including mixed province/city children. No code slicing or artificial parents. */
export async function getCatalogGeography(selection: CatalogSelection): Promise<CatalogGeography> {
  if (
    selection.mode === "district" ||
    (selection.mode === "psgc" && selection.level === "barangay")
  )
    return { state: "not-found" };
  try {
    const db = createSupabaseServerClient();
    const readPlace = async (code: string): Promise<CatalogPlace | null> => {
      const { data, error } = await db
        .from("dim_geo")
        .select(COLUMNS)
        .eq("geo_code", code)
        .maybeSingle();
      if (error) throw new Error("Geography unavailable");
      if (!data) return null;
      if (!LEVELS.some((level) => level === data.geo_level)) throw new Error("Unsupported parent");
      return {
        code: data.geo_code,
        name: data.geo_name,
        level: data.geo_level as CatalogPlace["level"],
        parentCode: data.parent_code,
      };
    };
    const current =
      selection.mode === "all"
        ? null
        : selection.level === "national"
          ? NATIONAL
          : await readPlace(selection.code);
    if (selection.mode === "psgc" && (!current || current.level !== selection.level))
      return { state: "not-found" };
    const ancestors: CatalogPlace[] = [];
    const visited = new Set([current?.code]);
    let parent = current?.parentCode;
    while (parent && parent !== "PH") {
      if (visited.has(parent) || visited.size > 4) throw new Error("Invalid hierarchy");
      visited.add(parent);
      const place = await readPlace(parent);
      if (!place) throw new Error("Missing parent");
      ancestors.unshift(place);
      parent = place.parentCode;
    }
    if (current && current.level !== "national") ancestors.unshift(NATIONAL);
    const children: CatalogPlace[] = [];
    if (current?.level !== "citymun") {
      // Paginate so a growing geography cannot silently truncate the choices.
      for (let offset = 0; ; offset += 1000) {
        let query = db
          .from("dim_geo")
          .select(COLUMNS)
          .in("geo_level", LEVELS)
          .order("geo_name")
          .order("geo_code")
          .range(offset, offset + 999);
        query =
          !current || current.level === "national"
            ? query.eq("geo_level", "region")
            : query.eq("parent_code", current.code);
        const { data, error } = await query;
        if (error || !data) throw new Error("Children unavailable");
        children.push(
          ...data.map((r) => ({
            code: r.geo_code,
            name: r.geo_name,
            level: r.geo_level as CatalogPlace["level"],
            parentCode: r.parent_code,
          })),
        );
        if (data.length < 1000) break;
      }
    }
    return { state: "available", current, ancestors, children };
  } catch {
    return { state: "failed" };
  }
}
