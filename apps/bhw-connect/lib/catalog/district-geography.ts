import "server-only";
import { createSupabaseServerClient } from "@/lib/db/supabase";
import type { CatalogSelection } from "./types";

export type DistrictSelection = Extract<CatalogSelection, { mode: "district" }>;
export type DistrictPlace = {
  code: string;
  name: string;
  level: "citymun" | "barangay";
  parentCode: string | null;
};
export type DistrictReceipt = {
  districtCode: string;
  code: string;
  level: "citymun" | "barangay";
  sourceKind: string;
  sourceRef: string;
  retrievedAt: string;
  corroboration: string;
};
export type DistrictCity = DistrictPlace & {
  whole: boolean;
  barangays: DistrictPlace[];
  allBarangays: DistrictPlace[];
};
export type DistrictScope = { cities: DistrictCity[]; units: DistrictPlace[]; issues: string[] };
export type DistrictChoice = { code: string; name: string; congress: number };
export type DistrictGeography =
  | {
      state: "available";
      choices: DistrictChoice[];
      current: DistrictChoice | null;
      scope: DistrictScope;
      receipts: DistrictReceipt[];
      ownGaps: number;
      globalGaps: { cities: number; barangays: number };
      selection: DistrictSelection;
    }
  | { state: "failed" | "not-found" };

/** Expand whole cities into a set of leaves, then choose disjoint whole-city or leaf units. */
export function normalizeDistrict(
  districtCode: string,
  receipts: DistrictReceipt[],
  places: DistrictPlace[],
): DistrictScope {
  const byCode = new Map(places.map((p) => [p.code, p]));
  const own = receipts.filter((r) => r.districtCode === districtCode);
  const groups = new Map<string, { whole: boolean; explicit: Set<string> }>();
  const issues: string[] = [];
  for (const member of own) {
    const place = byCode.get(member.code);
    if (!place || place.level !== member.level) {
      issues.push("A mapped place could not be resolved at its recorded level.");
      continue;
    }
    if (member.corroboration === "conflict")
      issues.push("A membership source conflict remains unresolved.");
    const cityCode = place.level === "citymun" ? place.code : place.parentCode;
    if (!cityCode || byCode.get(cityCode)?.level !== "citymun") {
      issues.push("A barangay's municipal parent could not be verified.");
      continue;
    }
    const group = groups.get(cityCode) ?? { whole: false, explicit: new Set<string>() };
    if (place.level === "citymun") group.whole = true;
    else group.explicit.add(place.code);
    groups.set(cityCode, group);
  }
  const cities: DistrictCity[] = [];
  for (const [code, group] of groups) {
    const allBarangays = places.filter((p) => p.level === "barangay" && p.parentCode === code);
    const barangays = allBarangays.filter((p) => group.whole || group.explicit.has(p.code));
    if (!barangays.length)
      issues.push("A constituent municipality has no verified barangay roster.");
    // A complete explicit roster can establish a whole unit, but only by exact leaf identity.
    // A matching row count alone (including duplicates or foreign leaves) proves nothing.
    const whole =
      group.whole ||
      (allBarangays.length > 0 && allBarangays.every((b) => group.explicit.has(b.code)));
    cities.push({ ...byCode.get(code)!, whole, barangays, allBarangays });
  }
  const leaves = new Set(cities.flatMap((c) => c.barangays.map((b) => b.code)));
  const cityCodes = new Set(cities.map((c) => c.code));
  if (
    receipts.some(
      (r) =>
        r.districtCode !== districtCode &&
        (r.level === "citymun" ? cityCodes.has(r.code) : leaves.has(r.code)),
    )
  )
    issues.push(
      "A constituent place is also assigned to another district. Totals are withheld until the conflict is resolved.",
    );
  if (!own.length) issues.push("No live members are available for this district.");
  cities.sort((a, b) => a.name.localeCompare(b.name));
  return {
    cities,
    units: cities.flatMap((c) =>
      c.whole
        ? [{ code: c.code, name: c.name, level: "citymun" as const, parentCode: c.parentCode }]
        : c.barangays,
    ),
    issues: [...new Set(issues)],
  };
}

export async function getDistrictGeography(
  selection: DistrictSelection,
): Promise<DistrictGeography> {
  try {
    const db = createSupabaseServerClient();
    const choices: DistrictChoice[] = [];
    for (let offset = 0; ; offset += 1000) {
      const result = await db
        .from("dim_legislative_district")
        .select("district_code,district_name,congress_no")
        .neq("status", "rejected")
        .order("district_code")
        .range(offset, offset + 999);
      if (result.error || !result.data) throw new Error("Districts unavailable");
      choices.push(
        ...result.data.map((r) => ({
          code: r.district_code,
          name: r.district_name,
          congress: r.congress_no,
        })),
      );
      if (result.data.length < 1000) break;
    }
    if (!selection.districtCode)
      return {
        state: "available",
        choices,
        current: null,
        scope: { cities: [], units: [], issues: [] },
        receipts: [],
        ownGaps: 0,
        globalGaps: { cities: 0, barangays: 0 },
        selection,
      };
    const current = choices.find((d) => d.code === selection.districtCode);
    if (!current) return { state: "not-found" };
    const receipts: DistrictReceipt[] = [];
    // Read all live memberships to detect both direct and whole-city/leaf cross-district conflicts.
    for (let offset = 0; ; offset += 1000) {
      const result = await db
        .from("geo_district_map")
        .select(
          "district_code,geo_code,geo_level,source_kind,source_ref,retrieved_at,corroboration",
        )
        .is("superseded_by", null)
        .neq("status", "rejected")
        .order("id")
        .range(offset, offset + 999);
      if (result.error || !result.data) throw new Error("Membership unavailable");
      receipts.push(
        ...result.data.map((r) => ({
          districtCode: r.district_code,
          code: r.geo_code,
          level: r.geo_level as "citymun" | "barangay",
          sourceKind: r.source_kind,
          sourceRef: r.source_ref,
          retrievedAt: r.retrieved_at,
          corroboration: r.corroboration,
        })),
      );
      if (result.data.length < 1000) break;
    }
    const places = new Map<string, DistrictPlace>();
    const readPlaces = async (codes: string[]) => {
      for (let i = 0; i < codes.length; i += 100) {
        const result = await db
          .from("dim_geo")
          .select("geo_code,geo_name,geo_level,parent_code")
          .in("geo_code", codes.slice(i, i + 100));
        if (result.error || !result.data) throw new Error("Places unavailable");
        for (const r of result.data)
          if (r.geo_level === "citymun" || r.geo_level === "barangay")
            places.set(r.geo_code, {
              code: r.geo_code,
              name: r.geo_name,
              level: r.geo_level,
              parentCode: r.parent_code,
            });
      }
    };
    await readPlaces([
      ...new Set(
        receipts.filter((r) => r.districtCode === selection.districtCode).map((r) => r.code),
      ),
    ]);
    await readPlaces([
      ...new Set(
        [...places.values()]
          .filter((p) => p.level === "barangay" && p.parentCode)
          .map((p) => p.parentCode!),
      ),
    ]);
    const cityCodes = [...places.values()].filter((p) => p.level === "citymun").map((p) => p.code);
    for (let i = 0; i < cityCodes.length; i += 100) {
      for (let offset = 0; ; offset += 1000) {
        const result = await db
          .from("dim_geo")
          .select("geo_code,geo_name,parent_code")
          .eq("geo_level", "barangay")
          .in("parent_code", cityCodes.slice(i, i + 100))
          .order("geo_code")
          .range(offset, offset + 999);
        if (result.error || !result.data) throw new Error("Barangays unavailable");
        for (const r of result.data)
          places.set(r.geo_code, {
            code: r.geo_code,
            name: r.geo_name,
            level: "barangay",
            parentCode: r.parent_code,
          });
        if (result.data.length < 1000) break;
      }
    }
    const currentCongress = new Set(
      choices.filter((d) => d.congress === current.congress).map((d) => d.code),
    );
    const scope = normalizeDistrict(
      selection.districtCode,
      receipts.filter((r) => currentCongress.has(r.districtCode)),
      [...places.values()],
    );
    const city = selection.citymunCode
      ? scope.cities.find((c) => c.code === selection.citymunCode)
      : null;
    if (selection.citymunCode && !city) return { state: "not-found" };
    if (
      selection.barangayCode &&
      (!city || !city.barangays.some((b) => b.code === selection.barangayCode))
    )
      return { state: "not-found" };
    if (city)
      scope.units = selection.barangayCode
        ? city.barangays.filter((b) => b.code === selection.barangayCode)
        : city.whole
          ? [{ code: city.code, name: city.name, level: "citymun", parentCode: city.parentCode }]
          : city.barangays;
    const [own, global] = await Promise.all([
      db.rpc("district_gap_members", { p_district_code: selection.districtCode }),
      db.rpc("district_dataset_gaps"),
    ]);
    if (own.error || !own.data || global.error || !global.data?.[0])
      throw new Error("Mapping gaps unavailable");
    return {
      state: "available",
      choices,
      current,
      scope,
      receipts: receipts.filter((r) => r.districtCode === selection.districtCode),
      ownGaps: own.data.length,
      globalGaps: {
        cities: global.data[0].uncovered_citymun_count,
        barangays: global.data[0].unplaced_barangay_count,
      },
      selection,
    };
  } catch {
    return { state: "failed" };
  }
}
