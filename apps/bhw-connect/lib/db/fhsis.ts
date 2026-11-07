import "server-only";
import { cache } from "react";
import type { Database } from "./database.types";
import { createSupabaseServerClient } from "./supabase";
import { DATASET_SLUGS, getDatasetIdBySlug } from "./dataset";
import { getChildGeos, type GeoOption } from "./geo";
import type { GeoLevel } from "@/lib/filters/schema";

export const FHSIS_BRAND_LABEL = "Health services";
export const FHSIS_RELEASE_LABEL = "FHSIS 2025 (partial release)";
export const FHSIS_OVER_100_NOTE =
  "† The source recorded more services than its stated denominator; for population-based coverage this is a projected eligible population. The true coverage is unknown.";
export const FHSIS_HEADLINES = ["fic", "anc4", "anc8", "water_basic", "tb_tsr_dstb"];
export type IndicatorDefinition = Database["public"]["Tables"]["ref_fhsis_indicator"]["Row"];
export type IndicatorRow = Database["public"]["Tables"]["fact_fhsis_indicator"]["Row"];
export type WorkforceRow = Database["public"]["Tables"]["fact_fhsis_workforce"]["Row"];
export type FhsisIndicator = IndicatorDefinition & {
  reported: boolean;
  numerator: number | null;
  denominator: number | null;
  rate: number | null;
  over100: boolean;
};
export type FhsisArea = { indicators: FhsisIndicator[]; workforce: WorkforceRow[] };
export type FhsisChild = GeoOption & { fic: FhsisIndicator };

export function isFhsisLevel(level: string): level is "region" | "province" | "citymun" {
  return ["region", "province", "citymun"].includes(level);
}
export function fhsisAreaHref(level: GeoLevel, code: string): string {
  return level === "national" ? "/health-services" : `/health-services/${level}/${code}`;
}
export function toFhsisIndicator(
  definition: IndicatorDefinition,
  row?: IndicatorRow,
): FhsisIndicator {
  const percentage = definition.unit.startsWith("percent");
  return {
    ...definition,
    reported: row !== undefined,
    numerator: row?.numerator ?? null,
    denominator: row?.denominator ?? null,
    rate: row?.rate_pct ?? null,
    // TB notifications use per 100,000: a value above 100 is not excess coverage.
    over100: percentage && !!row && (row.over_100 || (row.rate_pct !== null && row.rate_pct > 100)),
  };
}
export function fhsisRateLabel(indicator: FhsisIndicator): string {
  if (!indicator.reported) return "Not reported in 2025";
  if (indicator.rate === null) return "Rate not reported";
  const value = indicator.rate.toLocaleString("en-PH", { maximumFractionDigits: 1 });
  return `${value}${indicator.unit.startsWith("percent") ? "%" : " per 100,000"}${indicator.over100 ? "†" : ""}`;
}
export function fhsisCaption(indicator: FhsisIndicator, area: string): string {
  const n =
    indicator.denominator === null ? "not reported" : indicator.denominator.toLocaleString("en-PH");
  return `${indicator.label} · ${area} · ${FHSIS_RELEASE_LABEL} · Denominator N = ${n}: ${indicator.denominator_def}`;
}
export function workforceRatio(bhwCount: number | null, staffCount: number | null): number | null {
  return bhwCount === null || staffCount === null || staffCount <= 0 ? null : bhwCount / staffCount;
}
export function sortFhsisChildren(children: FhsisChild[]): FhsisChild[] {
  return [...children].sort(
    (a, b) =>
      (a.fic.rate ?? Infinity) - (b.fic.rate ?? Infinity) || a.geoName.localeCompare(b.geoName),
  );
}

export const getFhsisDefinitions = cache(async (): Promise<IndicatorDefinition[] | null> => {
  try {
    const { data, error } = await createSupabaseServerClient()
      .from("ref_fhsis_indicator")
      .select("*")
      .order("program_area")
      .order("indicator_key");
    return error || !data?.length ? null : data;
  } catch {
    return null;
  }
});

/** null means a failed/unavailable read; an empty successful fact read means not reported. */
export const getFhsisArea = cache(
  async (code: string, level: GeoLevel): Promise<FhsisArea | null> => {
    try {
      const [id, definitions] = await Promise.all([
        getDatasetIdBySlug(DATASET_SLUGS.fhsis),
        getFhsisDefinitions(),
      ]);
      if (id === null || !definitions) return null;
      const client = createSupabaseServerClient();
      const [facts, workforce] = await Promise.all([
        client
          .from("fact_fhsis_indicator")
          .select("*")
          .eq("dataset_id", id)
          .eq("geo_code", code)
          .eq("geo_level", level)
          .eq("breakdown", "total"),
        client
          .from("fact_fhsis_workforce")
          .select("*")
          .eq("dataset_id", id)
          .eq("geo_code", code)
          .eq("geo_level", level)
          .neq("cadre", "bhw")
          .order("cadre"),
      ]);
      if (facts.error || workforce.error || !facts.data || !workforce.data) return null;
      const byKey = new Map(facts.data.map((row) => [row.indicator_key, row]));
      return {
        indicators: definitions.map((def) => toFhsisIndicator(def, byKey.get(def.indicator_key))),
        workforce: workforce.data,
      };
    } catch {
      return null;
    }
  },
);

export const getFhsisChildren = cache(
  async (code: string, level: GeoLevel): Promise<FhsisChild[] | null> => {
    if (level === "citymun" || level === "barangay") return [];
    try {
      const [id, definitions, children] = await Promise.all([
        getDatasetIdBySlug(DATASET_SLUGS.fhsis),
        getFhsisDefinitions(),
        getChildGeos(code, level),
      ]);
      const definition = definitions?.find((d) => d.indicator_key === "fic");
      if (id === null || !definition) return null;
      if (!children.length) return [];
      // Direct children only, and one indicator: comfortably below PostgREST's row cap.
      const { data, error } = await createSupabaseServerClient()
        .from("fact_fhsis_indicator")
        .select("*")
        .eq("dataset_id", id)
        .eq("indicator_key", "fic")
        .eq("breakdown", "total")
        .eq("geo_level", children[0].geoLevel)
        .in(
          "geo_code",
          children.map((g) => g.geoCode),
        );
      if (error || !data) return null;
      const rows = new Map(data.map((row) => [row.geo_code, row]));
      return sortFhsisChildren(
        children.map((g) => ({ ...g, fic: toFhsisIndicator(definition, rows.get(g.geoCode)) })),
      );
    } catch {
      return null;
    }
  },
);

export const getFhsisReconciliation = cache(async (code: string) => {
  try {
    const id = await getDatasetIdBySlug(DATASET_SLUGS.fhsis);
    if (id === null) return null;
    const { data, error } = await createSupabaseServerClient()
      .from("ref_fhsis_reconciliation")
      .select("*")
      .eq("dataset_id", id)
      .eq("parent_geo_code", code)
      .eq("breakdown", "total")
      .order("indicator_key");
    return error ? null : data;
  } catch {
    return null;
  }
});
