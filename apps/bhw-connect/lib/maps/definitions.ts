import { STEPZERO_ACCREDITATION_ID, STEPZERO_ACCREDITATION_DENOMINATOR } from "./stepzero-accreditation";
import { UUC_ROUTE_LABELS } from "./uuc-routes";
import { CATALOG, type IndicatorSpec } from "@/lib/catalog/definitions";
import type { GeoLevel } from "@/lib/filters/schema";

export type MapLevel = GeoLevel | "district";
export type MapValueState = "available" | "missing-report" | "unavailable" | "suppressed";
/** Geometry availability is independent of value availability; keep complete table totals. */
export type MapObservation = {
  code: string;
  value:
    | { state: "available"; value: number }
    | { state: Exclude<MapValueState, "available">; reason: string };
  geometry: "available" | "missing" | "incomplete";
};
export type MapIndicator = {
  id: string;
  label: string;
  unit: string;
  period: string;
  levels: readonly MapLevel[];
  denominator: string | null;
  legend: "count" | "rate" | "categorical";
  /** An allowlisted aggregate read specification, never a caller-supplied table/column. */
  catalogSpec?: IndicatorSpec;
};
export type DatasetMapDefinition = {
  slug: string;
  title: string;
  source: string;
  attribution: string;
  defaultIndicator: string | null;
  indicators: readonly MapIndicator[];
  adapter: "catalog" | "profiling-status" | "district" | "income-class" | "pending";
  join: "psgc-crosswalk" | "district-membership" | "none";
  /** Null until reconciliation accepts a pinned release for this historical dataset. */
  boundaryVersion: string | null;
  availability: "requires-coverage-check" | "planned";
  disclosure: string;
  dependencies: readonly string[];
};
const periods: Record<string, string> = {
  "bhw-2025": "2025",
  "bhw-stepzero-2026": "2026",
  "nhfr-2026-09": "September 2026",
  "uuc-phc-2025": "2025",
  "fhsis-2025": "2025",
  "psa-popcen-2024": "2024",
  "psa-sae-poverty-2023": "2023",
};
function indicator(spec: IndicatorSpec): MapIndicator {
  return {
    id: spec.id,
    label: UUC_ROUTE_LABELS[spec.id] ?? spec.label,
    unit: spec.unit,
    period:
      spec.id === "fhsis.anc8"
        ? "Q3–Q4 2025"
        : /^fhsis\.(water_|sanitation_)/.test(spec.id)
          ? "Q4 2025"
          : periods[spec.source],
    levels: spec.levels,
    denominator:
      (spec.id === STEPZERO_ACCREDITATION_ID ? STEPZERO_ACCREDITATION_DENOMINATOR : spec.attributes?.denominator) ??
      (spec.unit === "%" ? "Required from accepted source definition" : null),
    legend:
      spec.unit === "%" || spec.unit.startsWith("per ") || spec.unit === "years" ? "rate" : "count",
    catalogSpec: spec,
  };
}
// Map-only source context: do not reuse the dashboard population fallback or add ratios.
const stepzeroContext: IndicatorSpec[] = ["population", "households"].map((column) => ({
  id: `stepzero.${column}`,
  label: column === "population" ? "Source population (StepZero)" : "Source households (StepZero)",
  unit: column === "population" ? "people" : "households",
  source: "bhw-stepzero-2026",
  table: "agg_bhw_stepzero_counts",
  column,
  levels: ["national", "region", "province", "citymun", "barangay"],
}));
function catalogMap(
  slug: string,
  title: string,
  source: string,
  disclosure: string,
): DatasetMapDefinition {
  const indicators = CATALOG.flatMap((d) => d.indicators)
    .filter((s) => s.source === source)
    .concat(source === "bhw-stepzero-2026" ? stepzeroContext : [])
    .map(indicator);
  return {
    slug,
    title,
    source,
    attribution: `Accepted source ${source}; source name, license and exact date must come from release metadata.`,
    defaultIndicator: indicators[0]?.id ?? null,
    indicators,
    adapter: "catalog",
    join: "psgc-crosswalk",
    boundaryVersion: null,
    availability: "requires-coverage-check",
    disclosure,
    dependencies: [
      "Bounded public coverage check",
      "Historical PSGC crosswalk and boundary reconciliation",
    ],
  };
}
function pending(
  slug: string,
  title: string,
  source: string,
  adapter: DatasetMapDefinition["adapter"],
  disclosure: string,
  indicators: MapIndicator[] = [],
): DatasetMapDefinition {
  return {
    slug,
    title,
    source,
    attribution: source,
    adapter,
    indicators,
    defaultIndicator: indicators[0]?.id ?? null,
    join:
      adapter === "district"
        ? "district-membership"
        : adapter === "pending"
          ? "none"
          : "psgc-crosswalk",
    boundaryVersion: null,
    availability: "planned",
    disclosure,
    dependencies: [
      "Approved measure adapter and public coverage check",
      "Versioned boundary reconciliation",
    ],
  };
}
/** One definition per dataset, including unpublished maps. BHW is never a fallback. */
export const DATASET_MAPS: readonly DatasetMapDefinition[] = [
  catalogMap(
    "bhw-2025",
    "BHW Census 2025",
    "bhw-2025",
    "Public aggregates only. Preserve small-cell and complementary suppression, category and payer filters, and measure-specific denominators. Never expose personal records.",
  ),
  catalogMap(
    "bhw-stepzero-2026",
    "StepZero 2026",
    "bhw-stepzero-2026",
    "LGU-declared counts are distinct from validated profiles. Population and households use only StepZero source columns; missing values are not replaced with PSA figures or another release.",
  ),
  pending(
    "bhw-profiling-status-2026",
    "Profiling status 2026",
    "bhw-profiling-status-2026",
    "profiling-status",
    "Stages are mutually exclusive; shares use total BHWs to profile, not pipeline totals.",
    ["encoded", "validated", "attested", "notEncoded"].map((id) => ({
      id,
      label: (
        {
          encoded: "Encoded, awaiting validation",
          validated: "Validated, awaiting attestation",
          attested: "Attested",
          notEncoded: "Not yet encoded",
        } as Record<string, string>
      )[id],
      unit: "%",
      period: "2026",
      levels: ["national", "region", "province", "citymun", "barangay"],
      denominator: "totalBhw",
      legend: "rate",
    })),
  ),
  catalogMap(
    "uuc-phc-2025",
    "UUC for PHC 2025",
    "uuc-phc-2025",
    "Official list membership is not an inferred classification. Qualification routes overlap. Preserve cleaning rules; never average source percentages.",
  ),
  catalogMap(
    "nhfr-2026-09",
    "NHFR September 2026",
    "nhfr-2026-09",
    "Barangay aggregates or labeled representative points only; no exact facility coordinates exist. Keep unplaced facilities in authoritative totals.",
  ),
  pending(
    "ph-legislative-districts",
    "Legislative districts",
    "20th Congress public district mapping",
    "district",
    "District slugs are not PSGC codes. Incomplete member geometry must remain incomplete; only validated district measures may be published.",
  ),
  {
    ...catalogMap(
      "fhsis-2025",
      "FHSIS 2025",
      "fhsis-2025",
      "Accepted indicator dictionary controls units, numerator and denominator. No barangay rates or averaged percentages.",
    ),
    dependencies: [
      "FHSIS_DIAGNOSIS.md server-side read-only check",
      "Indicator-specific child reader",
      "Accepted dictionary and historical geography",
    ],
  },
  catalogMap(
    "psa-popcen-2024",
    "PSA population 2024",
    "psa-popcen-2024",
    "Census population only; never substitute StepZero population. Verify actual geography coverage.",
  ),
  catalogMap(
    "psa-sae-poverty-2023",
    "PSA SAE poverty 2023",
    "psa-sae-poverty-2023",
    "City/municipality estimates and published uncertainty only. HUC exclusions are missing coverage. No barangay estimates, unweighted rollups or implicit back-estimate periods.",
  ),
  pending(
    "lgu-income-class",
    "LGU income classification",
    "DOF DO 074-2024 / BLGF; effective 2025-01-01",
    "income-class",
    "Read dim_lgu_income_reclass, not fallback historical classes. Barangays can show labeled parent context only.",
    [
      {
        id: "income-class",
        label: "Published income class",
        unit: "class",
        period: "Effective 2025-01-01",
        levels: ["province", "citymun"],
        denominator: null,
        legend: "categorical",
      },
    ],
  ),
  pending(
    "ways-forward",
    "Ways forward",
    "Roadmap; no ingested source",
    "pending",
    "Availability slot only. No invented measures or shaded values.",
  ),
];
export const PLANNED_MAP_WORK = [
  {
    dataset: "fhsis-2025",
    work: "F3–F6: register approved indicators, periods and availability before publication",
  },
  {
    dataset: "uuc-phc-2025",
    work: "Further criteria and source indicators: preserve cleaning and denominators",
  },
  {
    dataset: "ph-legislative-districts",
    work: "Complete validated municipal/barangay member geometry",
  },
] as const;
export function getDatasetMap(slug: string): DatasetMapDefinition | undefined {
  return DATASET_MAPS.find((d) => d.slug === slug);
}
/** Refuse invalid URL choices rather than silently switching dataset or period. */
export function validMapChoice(
  slug: string,
  indicatorId: string,
  period: string,
  level: MapLevel,
): boolean {
  return (
    getDatasetMap(slug)?.indicators.some(
      (i) => i.id === indicatorId && i.period === period && i.levels.includes(level),
    ) ?? false
  );
}
