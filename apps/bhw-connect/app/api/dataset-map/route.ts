import { CENSUS_ACTIVE_YEARS_ID, joinCensusActiveYears } from "@/lib/maps/census-active-years";
import { CENSUS_HONORARIUM_ID, isCensusProfileRate, joinCensusAccreditation, joinCensusTraining, joinCensusHonorarium } from "@/lib/maps/census-accreditation";
import { STEPZERO_ACCREDITATION_ID, joinStepzeroAccreditation } from "@/lib/maps/stepzero-accreditation";
import { joinUucRouteRows } from "@/lib/maps/uuc-routes";
import { PROFILING_COLUMNS, joinProfilingRows } from "@/lib/maps/profiling";
import { INCOME_SOURCE_SLUG, joinIncomeRows } from "@/lib/maps/income-class";
import { joinPovertyRows } from "@/lib/maps/poverty";
import { NextRequest, NextResponse } from "next/server";
import { createSupabaseServerClient } from "@/lib/db/supabase";
import { readDatasetBySlug } from "@/lib/db/dataset";
import { getGeoByCode } from "@/lib/db/geo";
import { withBasePath } from "@/lib/base-path";
import { barangayDrillAvailable, barangayGeometryUrl, barangayRelease } from "@/lib/maps/barangay-release";
import { readBarangayRows } from "@/lib/maps/barangay-reader";
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
  const profiling = choice?.dataset === "bhw-profiling-status-2026";
  const income = choice?.dataset === "lgu-income-class";
  if (!choice || !metric || (!spec && !income && !profiling))
    return NextResponse.json({ state: "unsupported" }, { status: 400 });
  // Barangay polygons exist only once an accepted package is installed and this dataset is enabled.
  const release = barangayRelease();
  const barangayDrill = barangayDrillAvailable(choice.dataset, choice.indicator, metric.levels, release);
  if (choice.level === "citymun" && !barangayDrill)
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
      choice.level === "national"
        ? "region"
        : choice.level === "region"
          ? "province"
          : choice.level === "province"
            ? "citymun"
            : "barangay";
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
    const declaredAccreditation = choice.dataset === "bhw-stepzero-2026" && choice.indicator === STEPZERO_ACCREDITATION_ID;
    const censusRate = choice.dataset === "bhw-2025" && isCensusProfileRate(choice.indicator);
    const censusHonorarium = censusRate && choice.indicator === CENSUS_HONORARIUM_ID;
    const censusTraining = censusRate && spec?.table === "agg_training";
    const censusMean = choice.dataset === "bhw-2025" && choice.indicator === CENSUS_ACTIVE_YEARS_ID;
    let parentMean: number | null = null;
    let parentRate: number | null = null;
    let total: number | null = null;
    let totalNote: string | undefined;
    let unattributed = 0;
    if (profiling) {
      const values = await db
        .from("agg_bhw_profiling_status")
        .select(PROFILING_COLUMNS)
        .eq("dataset_id", source.value.datasetId)
        .eq("geo_level", childLevel)
        .in(
          "geo_code",
          children.data.map((c) => c.geo_code),
        )
        .limit(1000);
      if (values.error || !values.data || values.data.length >= 1000)
        throw new Error("Snapshot unavailable");
      rows = joinProfilingRows(children.data, values.data, choice.indicator);
    } else if (income && valueSupport === "supported") {
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
      const uucRoute = spec.table === "agg_uuc_phc_criteria";
      const projection = uucRoute
        ? `geo_code,${spec.column},n_listed,n_health_evaluable`
        : censusMean ? "geo_code,avg_active_years,n_total"
        : censusHonorarium ? "geo_code,any_honorarium_pct,n_total"
        : censusTraining ? "geo_code,coverage_pct,n_trained,n_total,ci_low,ci_high"
        : censusRate ? "geo_code,pct_accredited,n_accredited,n_total,ci_low,ci_high"
        : declaredAccreditation ? `geo_code,${spec.column},n_registered_accredited,n_total_bhw`
          : `geo_code,${spec.column}`;
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
      // Barangay values come straight from the source tables (the summaries stop at municipalities).
      const barangayRead = choice.level === "citymun";
      const [values, parent] = await Promise.all([
        barangayRead
          ? readBarangayRows(db, {
              dataset: choice.dataset,
              column: spec.column,
              datasetId: source.value.datasetId,
              citymunCode: choice.code,
              children: children.data,
            }).then((read) => {
              unattributed = read.unattributed;
              return { data: read.data, error: null };
            })
          : childQuery,
        parentQuery,
      ]);
      if (
        values.error ||
        parent.error ||
        !values.data ||
        values.data.length >= 1000 ||
        !parent.data
      )
        throw new Error("Values unavailable");
      const join = censusMean ? joinCensusActiveYears : censusHonorarium ? joinCensusHonorarium : censusTraining ? joinCensusTraining : censusRate ? joinCensusAccreditation : uucRoute ? joinUucRouteRows : declaredAccreditation ? joinStepzeroAccreditation : joinMapRows;
      rows = join(children.data, values.data as unknown as Record<string, unknown>[], spec.column);
      const parentRow = join(
        [{ geo_code: choice.code, geo_name: geo.geoName }],
        parent.data as unknown as Record<string, unknown>[],
        spec.column,
      )[0];
      if (censusMean) parentMean = parentRow.value;
      else if (declaredAccreditation || censusRate) parentRate = parentRow.value;
      else total = parentRow.value;
      totalNote = parentRow.note;
    }
    if (unattributed > 0)
      totalNote = [totalNote, `${unattributed.toLocaleString()} of this municipality's facilities have no barangay recorded, so barangay values do not add up to the municipal total.`]
        .filter(Boolean)
        .join(" ");
    const payload: MapPayload = {
      state: "available",
      rows,
      total,
      ...(censusMean ? { parentMean } : {}),
      ...((declaredAccreditation || censusRate) ? { parentRate } : {}),
      ...(totalNote ? { totalNote } : {}),
      area: geo.geoName,
      childLevel,
      valueSupport,
      source: source.value.sourceName ?? source.value.name,
      date: source.value.asOfDate,
      license: source.value.license,
      ...(choice.level === "province" && barangayDrill ? { barangayDrill: true } : {}),
      geometryUrl:
        choice.level === "citymun"
          ? barangayGeometryUrl(release!, choice.code)
          : choice.level === "national"
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
