import { NextRequest, NextResponse } from "next/server";
import { withBasePath } from "@/lib/base-path";
import { readDatasetBySlug } from "@/lib/db/dataset";
import { createSupabaseServerClient } from "@/lib/db/supabase";
import {
  DISTRICT_MAP_SLUG,
  assignMunicipalities,
  compareDistricts,
  type DistrictRef,
  type MunicipalityDistricts,
} from "@/lib/maps/district-view";

/**
 * The legislative-district map's reads (docs/DECISIONS.md, 2026-11-11). Membership only — which
 * district each city or municipality is in. Figures are never read here: the district view asks
 * `/api/dataset-map` for a province's municipal figures, so a district shows exactly the numbers
 * every other map shows, and no district total is ever computed.
 *
 *   GET                       the pickers: every province, every district and its province
 *   GET ?province=01028       that province's municipalities and their districts
 *   GET ?district=bohol-3rd   the same, for the province holding most of that district's members
 */
export type DistrictMapIndex = {
  state: "available";
  provinces: { code: string; name: string }[];
  districts: (DistrictRef & { province: string | null })[];
};
export type DistrictMapProvince = {
  state: "available";
  province: { code: string; name: string };
  municipalities: MunicipalityDistricts[];
  districts: DistrictRef[];
  /** For `?district=`: other provinces that also hold members of it. */
  otherProvinces: string[];
  source: string;
  date: string | null;
  license: string | null;
  geometryUrl: string;
};

const LIMIT = 1000; // PostgREST's own cap; reaching it means the read was cut, never "that is all".
const live = <T extends { neq: (c: string, v: string) => T; is: (c: string, v: null) => T }>(
  q: T,
) => q.neq("status", "rejected").is("superseded_by", null);

/** A district's province from its recorded parent: a province, or a city/municipality inside one. */
function provinceOf(parent: string | null): string | null {
  return parent && /^\d{5}(\d{2})?$/.test(parent) ? parent.slice(0, 5) : null;
}

export async function GET(request: NextRequest) {
  const params = request.nextUrl.searchParams;
  const province = params.get("province");
  const district = params.get("district");
  if (params.getAll("province").length > 1 || params.getAll("district").length > 1)
    return NextResponse.json({ state: "unsupported" }, { status: 400 });
  if (
    (province && !/^\d{5}$/.test(province)) ||
    (district && !/^[a-z0-9][a-z0-9-]{1,80}$/.test(district))
  )
    return NextResponse.json({ state: "unsupported" }, { status: 400 });
  const db = createSupabaseServerClient();
  const headers = { "Cache-Control": "no-store" };
  try {
    if (!province && !district) {
      const [provinces, districts] = await Promise.all([
        db
          .from("dim_geo")
          .select("geo_code,geo_name")
          .eq("geo_level", "province")
          .order("geo_name")
          .limit(LIMIT),
        db
          .from("dim_legislative_district")
          .select("district_code,district_name,parent_geo_code")
          .neq("status", "rejected")
          .limit(LIMIT),
      ]);
      if (provinces.error || districts.error || !provinces.data || !districts.data)
        throw new Error();
      if (provinces.data.length >= LIMIT || districts.data.length >= LIMIT) throw new Error();
      const body: DistrictMapIndex = {
        state: "available",
        provinces: provinces.data.map((p) => ({ code: p.geo_code, name: p.geo_name })),
        districts: districts.data
          .map((d) => ({
            code: d.district_code,
            name: d.district_name,
            province: provinceOf(d.parent_geo_code),
          }))
          .sort(compareDistricts),
      };
      return NextResponse.json(body, { headers });
    }

    let provinceCode = province;
    let otherProvinces: string[] = [];
    if (district) {
      const [members, record] = await Promise.all([
        live(db.from("geo_district_map").select("geo_code").eq("district_code", district)).limit(
          LIMIT,
        ),
        db
          .from("dim_legislative_district")
          .select("parent_geo_code")
          .eq("district_code", district)
          .neq("status", "rejected")
          .maybeSingle(),
      ]);
      if (members.error || record.error || (members.data?.length ?? 0) >= LIMIT) throw new Error();
      if (!record.data) return NextResponse.json({ state: "unavailable" }, { headers });
      const counts = new Map<string, number>();
      for (const m of members.data ?? []) {
        const p = m.geo_code.slice(0, 5);
        counts.set(p, (counts.get(p) ?? 0) + 1);
      }
      const ranked = [...counts.entries()].sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]));
      provinceCode = ranked[0]?.[0] ?? provinceOf(record.data.parent_geo_code);
      otherProvinces = ranked.slice(1).map(([p]) => p);
      if (!provinceCode) return NextResponse.json({ state: "unavailable" }, { headers });
    }

    const [source, geo, children, membership] = await Promise.all([
      readDatasetBySlug(DISTRICT_MAP_SLUG),
      db
        .from("dim_geo")
        .select("geo_code,geo_name,geo_level")
        .eq("geo_code", provinceCode!)
        .maybeSingle(),
      db
        .from("dim_geo")
        .select("geo_code,geo_name")
        .eq("geo_level", "citymun")
        .eq("parent_code", provinceCode!)
        .order("geo_name")
        .limit(LIMIT),
      live(
        db
          .from("geo_district_map")
          .select("district_code,geo_code,geo_level")
          .like("geo_code", `${provinceCode}%`),
      ).limit(LIMIT),
    ]);
    if (source.state === "failed") throw new Error();
    if (geo.error || children.error || membership.error) throw new Error();
    if (!geo.data || geo.data.geo_level !== "province")
      return NextResponse.json({ state: "unsupported" }, { status: 400, headers });
    if (
      !children.data ||
      children.data.length >= LIMIT ||
      !membership.data ||
      membership.data.length >= LIMIT
    )
      throw new Error();

    const codes = [
      ...new Set([...membership.data.map((m) => m.district_code), ...(district ? [district] : [])]),
    ];
    const names = codes.length
      ? await db
          .from("dim_legislative_district")
          .select("district_code,district_name")
          .in("district_code", codes)
          .neq("status", "rejected")
          .limit(LIMIT)
      : { data: [], error: null };
    if (names.error || !names.data) throw new Error();
    const districts = names.data
      .map((d) => ({ code: d.district_code, name: d.district_name }))
      .sort(compareDistricts);
    const known = new Set(districts.map((d) => d.code));
    const body: DistrictMapProvince = {
      state: "available",
      province: { code: geo.data.geo_code, name: geo.data.geo_name },
      municipalities: assignMunicipalities(
        children.data,
        membership.data.filter((m) => known.has(m.district_code)),
        districts,
      ),
      districts,
      otherProvinces,
      source:
        source.state === "available"
          ? (source.value.sourceName ?? source.value.name)
          : "Public legislative-district mapping",
      date: source.state === "available" ? source.value.asOfDate : null,
      license: source.state === "available" ? source.value.license : null,
      geometryUrl: withBasePath(`/geo/citymun/${provinceCode}.json`),
    };
    return NextResponse.json(body, { headers });
  } catch {
    return NextResponse.json({ state: "failed" }, { status: 503, headers });
  }
}
