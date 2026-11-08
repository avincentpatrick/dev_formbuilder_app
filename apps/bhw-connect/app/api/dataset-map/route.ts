import { NextRequest, NextResponse } from "next/server";
import { createSupabaseServerClient } from "@/lib/db/supabase";
import { readDatasetBySlug } from "@/lib/db/dataset";
import { getGeoByCode } from "@/lib/db/geo";
import { withBasePath } from "@/lib/base-path";
import {
  joinMapRows,
  parseMapChoice,
  prototypeIndicators,
  type MapPayload,
} from "@/lib/maps/prototype";

export async function GET(request: NextRequest) {
  const choice = parseMapChoice(request.nextUrl.searchParams);
  const spec =
    choice &&
    prototypeIndicators(choice.dataset).find((i) => i.id === choice.indicator)?.catalogSpec;
  if (!choice || !spec) return NextResponse.json({ state: "unsupported" }, { status: 400 });
  try {
    const [source, geo] = await Promise.all([
      readDatasetBySlug(choice.dataset),
      getGeoByCode(choice.code),
    ]);
    if (source.state !== "available")
      return NextResponse.json(
        { state: source.state },
        { status: source.state === "failed" ? 503 : 200 },
      );
    if (!geo || geo.geoLevel !== choice.level)
      return NextResponse.json({ state: "unsupported" }, { status: 400 });
    const childLevel =
      choice.level === "national" ? "region" : choice.level === "region" ? "province" : "citymun";
    const db = createSupabaseServerClient();
    let query = db
      .from("dim_geo")
      .select("geo_code,geo_name")
      .eq("geo_level", childLevel)
      .order("geo_name")
      .limit(1000);
    if (choice.level !== "national") query = query.eq("parent_code", choice.code);
    const children = await query;
    if (children.error || !children.data?.length || children.data.length >= 1000)
      throw new Error("Geography unavailable");
    const [values, parent] = await Promise.all([
      db
        .from(spec.table)
        .select(`geo_code,${spec.column}`)
        .eq("dataset_id", source.value.datasetId)
        .eq("geo_level", childLevel)
        .in(
          "geo_code",
          children.data.map((c) => c.geo_code),
        )
        .limit(1000),
      db
        .from(spec.table)
        .select(`geo_code,${spec.column}`)
        .eq("dataset_id", source.value.datasetId)
        .eq("geo_level", choice.level)
        .eq("geo_code", choice.code)
        .limit(2),
    ]);
    if (values.error || parent.error || !values.data || values.data.length >= 1000 || !parent.data)
      throw new Error("Values unavailable");
    const rows = joinMapRows(
      children.data,
      values.data as unknown as Record<string, unknown>[],
      spec.column,
    );
    const total = joinMapRows(
      [{ geo_code: choice.code, geo_name: geo.geoName }],
      parent.data as unknown as Record<string, unknown>[],
      spec.column,
    )[0].value;
    const payload: MapPayload = {
      state: "available",
      rows,
      total,
      area: geo.geoName,
      childLevel,
      source: source.value.sourceName ?? source.value.name,
      date: source.value.asOfDate,
      license: source.value.license,
      geometryUrl:
        choice.level === "national"
          ? withBasePath("/geo/regions.json")
          : choice.level === "region"
            ? withBasePath(`/geo/provinces/${choice.code}.json`)
            : withBasePath(`/geo/citymun/${choice.code}.json`),
    };
    return NextResponse.json(payload, { headers: { "Cache-Control": "no-store" } });
  } catch {
    return NextResponse.json({ state: "failed" }, { status: 503 });
  }
}
