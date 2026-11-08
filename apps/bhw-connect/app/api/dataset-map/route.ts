import { INCOME_SOURCE_SLUG, joinIncomeRows } from "@/lib/maps/income-class";
import { joinPovertyRows } from "@/lib/maps/poverty";
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
  const metric =
    choice && prototypeIndicators(choice.dataset).find((i) => i.id === choice.indicator);
  const spec = metric?.catalogSpec;
  const income = choice?.dataset === "lgu-income-class";
  if (!choice || !metric || (!spec && !income))
    return NextResponse.json({ state: "unsupported" }, { status: 400 });
  try {
    const [source, geo] = await Promise.all([
      choice.dataset === "bhw-2025"
        ? readDatasetBySlug(choice.dataset, true)
        : readDatasetBySlug(income ? INCOME_SOURCE_SLUG : choice.dataset),
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
    const poverty = choice.dataset === "psa-sae-poverty-2023";
    const valueSupport = metric.levels.includes(childLevel) ? "supported" : "navigation-only";
    let rows = children.data.map((child) => ({
      code: child.geo_code,
      name: child.geo_name,
      value: null,
    })) as MapPayload["rows"];
    let total: number | null = null;
    if (income && valueSupport === "supported") {
      const values = await db
        .from("dim_lgu_income_reclass")
        .select("geo_code,new_class,dof_kind,converted")
        .eq("dataset_id", source.value.datasetId)
        .eq("geo_level", childLevel)
        .in(
          "geo_code",
          children.data.map((c) => c.geo_code),
        )
        .limit(1000);
      if (values.error || !values.data || values.data.length >= 1000)
        throw new Error("Classifications unavailable");
      rows = joinIncomeRows(children.data, values.data);
    } else if (poverty && valueSupport === "supported") {
      // No province/national aggregate query and no averaging municipal percentages.
      const values = await db
        .from("agg_poverty")
        .select("geo_code,poverty_incidence,ci_low,ci_high")
        .eq("dataset_id", source.value.datasetId)
        .eq("sae_year", 2023)
        .eq("geo_level", "citymun")
        .in(
          "geo_code",
          children.data.map((c) => c.geo_code),
        )
        .limit(1000);
      if (values.error || !values.data || values.data.length >= 1000)
        throw new Error("Values unavailable");
      rows = joinPovertyRows(children.data, values.data);
    } else if (!poverty && !income && spec) {
      // Apply each accepted measure's source filters to BOTH children and the parent.
      // In particular, population is PSA 2024, never a StepZero fallback or another census year.
      const projection = `geo_code,${spec.column}`;
      let childQuery = db
        .from(spec.table)
        .select(projection)
        .eq("dataset_id", source.value.datasetId)
        .eq("geo_level", childLevel)
        .in(
          "geo_code",
          children.data.map((c) => c.geo_code),
        )
        .limit(1000);
      let parentQuery = db
        .from(spec.table)
        .select(projection)
        .eq("dataset_id", source.value.datasetId)
        .eq("geo_level", choice.level)
        .eq("geo_code", choice.code)
        .limit(2);
      for (const [column, value] of Object.entries(spec.filters ?? {})) {
        childQuery = childQuery.eq(column, value);
        parentQuery = parentQuery.eq(column, value);
      }
      const [values, parent] = await Promise.all([childQuery, parentQuery]);
      if (
        values.error ||
        parent.error ||
        !values.data ||
        values.data.length >= 1000 ||
        !parent.data
      )
        throw new Error("Values unavailable");
      rows = joinMapRows(
        children.data,
        values.data as unknown as Record<string, unknown>[],
        spec.column,
      );
      total = joinMapRows(
        [{ geo_code: choice.code, geo_name: geo.geoName }],
        parent.data as unknown as Record<string, unknown>[],
        spec.column,
      )[0].value;
    }
    const payload: MapPayload = {
      state: "available",
      rows,
      total,
      area: geo.geoName,
      childLevel,
      valueSupport,
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
