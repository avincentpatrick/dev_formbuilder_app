import type { GeoLevel } from "@/lib/filters/schema";
import type { CatalogId, CatalogSelection } from "./types";

export const AREA_LEVELS = ["national", "region", "province", "citymun"] as const;
export const BARANGAY_LEVELS = [...AREA_LEVELS, "barangay"] as const;
export type CatalogTable =
  | "agg_bhw_stepzero_counts"
  | "agg_bhw_counts"
  | "agg_training"
  | "agg_certification"
  | "agg_demographics"
  | "agg_honorarium"
  | "agg_nhfr_counts"
  | "agg_uuc_phc_counts"
  | "agg_uuc_phc_criteria"
  | "fact_fhsis_indicator"
  | "fact_fhsis_workforce"
  | "agg_population"
  | "agg_poverty";
export type IndicatorSpec = {
  id: string;
  label: string;
  unit: string;
  source: string;
  table: CatalogTable;
  column: string;
  levels: readonly GeoLevel[];
  filters?: Readonly<Record<string, string | number>>;
  suppression?: string;
  categoryColumn?: string;
  attributes?: Readonly<Partial<Record<"numerator" | "denominator" | "ciLow" | "ciHigh", string>>>;
  partialReason?: string;
};
export type DatasetSpec = {
  id: CatalogId;
  title: string;
  category: string;
  detailRoot: string | null;
  indicators: readonly IndicatorSpec[];
};
const make = (
  source: string,
  table: CatalogTable,
  prefix: string,
  rows: readonly (readonly [string, string, string, string])[],
): IndicatorSpec[] =>
  rows.map(([id, label, column, unit]) => ({
    id: `${prefix}.${id}`,
    label,
    column,
    unit,
    source,
    table,
    levels:
      table === "agg_bhw_stepzero_counts" ||
      table === "agg_bhw_counts" ||
      table === "agg_nhfr_counts" ||
      table === "agg_uuc_phc_counts"
        ? BARANGAY_LEVELS
        : AREA_LEVELS,
  }));

// These are accepted releases, not "latest timestamp" or any arbitrary active dataset.
export const ACCEPTED_RELEASES = {
  bhw: "bhw-2025",
  stepzero: "bhw-stepzero-2026",
  nhfr: "nhfr-2026-09",
  uuc: "uuc-phc-2025",
  fhsis: "fhsis-2025",
  population: "psa-popcen-2024",
  poverty: "psa-sae-poverty-2023",
} as const;
const R = ACCEPTED_RELEASES;

export const FHSIS_KEYS = [
  "fic",
  "cic",
  "anc4",
  "anc8",
  "water_basic",
  "water_level1",
  "water_level2",
  "water_level3",
  "water_safely_managed",
  "sanitation_basic",
  "sanitation_safely_managed",
  "sanitation_septic",
  "sanitation_sewer",
  "sanitation_vip",
  "tb_notified",
  "tb_dr_notified",
  "tb_presumptive_tested",
  "tb_tpt",
  "tb_tsr_dstb",
  "tb_tsr_mdrtb",
] as const;
// Workforce keys are defined by FHSIS_2025_PLAN; an absent loaded key is unavailable, never invented.
const CADRES = [
  "physician",
  "nurse",
  "midwife",
  "dentist",
  "med_tech",
  "nutritionist",
  "sanitary_engineer",
  "sanitary_inspector",
];

export const TRAINING_TOPICS = [
  [
    "burns_choking_neck_head_spinal_injuries_poisoning",
    "Burns/Choking/Neck-Head-Spinal Injuries/Poisoning",
  ],
  ["basic_life_support", "Basic Life Support"],
  [
    "basic_nutrition_malnutrition_nutrition_in_emergencies_and_disasters",
    "Basic Nutrition/Malnutrition, Nutrition in Emergencies and Disasters",
  ],
  ["blood_pressure_bp_apparatus_measurement", "Blood Pressure (BP) Apparatus Measurement"],
  ["breastfeeding_lactation", "Breastfeeding/Lactation"],
  [
    "chronic_respiratory_diseases_chronic_obstructive_pulmonary_disease_copd_asthma",
    "Chronic Respiratory Diseases/Chronic Obstructive Pulmonary Disease(COPD)/Asthma",
  ],
  ["cardiovascular_diseases_heart_attack_stroke", "Cardiovascular Diseases/Heart Attack/Stroke"],
  ["child_growth_standard_growth_development", "Child Growth Standard/Growth Development"],
  ["cancer", "Cancer"],
  ["cardio_pulmonary_resuscitation_cpr", "Cardio Pulmonary Resuscitation (CPR)"],
  ["dengue", "Dengue"],
  ["diabetes", "Diabetes"],
  [
    "disaster_risk_assessment_disaster_risk_preparedness",
    "Disaster Risk Assessment/Disaster Risk Preparedness",
  ],
  ["early_childhood_care_and_development", "Early Childhood Care and Development"],
  ["emergency_reponse_rescue", "Emergency Reponse/Rescue"],
  ["emerging_and_re_emerging_infectious_diseases", "Emerging and Re-emerging Infectious Diseases"],
  [
    "environment_sanitation_solid_ecologic_waste_management",
    "Environment Sanitation, Solid/Ecologic Waste Management",
  ],
  ["filariasis", "Filariasis"],
  ["fire_safety", "Fire Safety"],
  ["flu", "Flu"],
  ["food_preparation_food_safety", "Food Preparation/Food Safety"],
  ["family_planning_responsible_parenthood", "Family Planning/Responsible Parenthood"],
  ["first_1000_days", "First 1000 Days"],
  ["hepatitis", "Hepatitis"],
  ["hiv_aids", "HIV/AIDS"],
  ["healthy_lifestyle", "Healthy Lifestyle"],
  ["infant_and_young_child_feeding_practices", "Infant and Young Child Feeding Practices"],
  ["leprosy", "Leprosy"],
  ["mental_health", "Mental Health"],
  ["measles", "Measles"],
  ["malaria", "Malaria"],
  ["maternal_care", "Maternal Care"],
  ["others_please_specify", "Others please specify"],
  ["pneumonia", "Pneumonia"],
  ["polio", "Polio"],
  ["rabies", "Rabies"],
  ["standard_first_aid_training", "Standard First Aid Training"],
  ["sexually_transmitted_disease", "Sexually Transmitted Disease"],
  ["traditional_alternative_herbal_medicine", "Traditional/Alternative/Herbal Medicine"],
  ["tuberculosis", "Tuberculosis"],
  ["uhc_phc_f1_plus_for_health_sdn", "UHC, PHC, F1 Plus for Health, SDN"],
  ["water_sanitation_and_hygiene_wash", "Water Sanitation and Hygiene (WASH)"],
  ["women_s_health", "Women’s Health"],
  ["zero_open_defecation_zod", "Zero Open Defecation (ZOD)"],
] as const;

const bhw: IndicatorSpec[] = [
  ...make(R.stepzero, "agg_bhw_stepzero_counts", "bhw", [
    ["total", "Total BHWs (LGU-reported)", "n_total_bhw", "people"],
    ["registered", "Registered only (LGU-reported)", "n_registered", "people"],
    [
      "registered-accredited",
      "Registered and accredited (LGU-reported)",
      "n_registered_accredited",
      "people",
    ],
    ["nonregistered", "Nonregistered BHWs (LGU-reported)", "n_non_registered", "people"],
    [
      "declared-accreditation",
      "LGU-reported accreditation share",
      "pct_registered_accredited",
      "%",
    ],
  ]).map((spec) =>
    spec.id === "bhw.declared-accreditation"
      ? {
          ...spec,
          attributes: { numerator: "n_registered_accredited", denominator: "n_total_bhw" },
        }
      : spec,
  ),
  ...make(R.bhw, "agg_bhw_counts", "bhw", [
    ["validated-profiles", "Validated profiles", "n_total", "people"],
    ["profile-accreditation", "Accreditation among validated profiles", "pct_accredited", "%"],
    ["active-years", "Average years active", "avg_active_years", "years"],
    ["any-honorarium", "Profiles receiving any honorarium", "any_honorarium_pct", "%"],
  ]).map((spec) =>
    spec.id === "bhw.profile-accreditation"
      ? {
          ...spec,
          attributes: {
            numerator: "n_accredited",
            denominator: "n_total",
            ciLow: "ci_low",
            ciHigh: "ci_high",
          },
        }
      : spec.id === "bhw.any-honorarium"
        ? { ...spec, attributes: { denominator: "n_total" } }
        : spec,
  ),
  ...TRAINING_TOPICS.map(([key, label]): IndicatorSpec => ({
    id: `bhw.training.${key}`,
    label,
    unit: "%",
    source: R.bhw,
    table: "agg_training",
    column: "coverage_pct",
    filters: { topic_slug: key },
    levels: AREA_LEVELS,
    attributes: {
      numerator: "n_trained",
      denominator: "n_total",
      ciLow: "ci_low",
      ciHigh: "ci_high",
    },
  })),
  ...["tesda_nc2", "tesda_certified", "ref_manual_trained"].map((key): IndicatorSpec => ({
    id: `bhw.certification.${key}`,
    label: key.replaceAll("_", " "),
    unit: "%",
    source: R.bhw,
    table: "agg_certification",
    column: "pct",
    filters: { cert_type: key },
    levels: BARANGAY_LEVELS,
    attributes: { numerator: "n" },
  })),
  ...["sex", "civil_status", "bloodtype", "education", "ip_status", "age_band"].map(
    (key): IndicatorSpec => ({
      id: `bhw.demographics.${key}`,
      label: key.replaceAll("_", " "),
      unit: "people",
      source: R.bhw,
      table: "agg_demographics",
      column: "n",
      filters: { dimension: key },
      categoryColumn: "category",
      suppression: "is_suppressed",
      levels: BARANGAY_LEVELS,
    }),
  ),
  ...["region", "province", "citymun", "barangay"].map((key): IndicatorSpec => ({
    id: `bhw.honorarium.${key}`,
    label: `Honorarium receipt from ${key}`,
    unit: "%",
    source: R.bhw,
    table: "agg_honorarium",
    column: "pct_receiving",
    filters: { payer_level: key },
    suppression: "is_suppressed",
    levels: BARANGAY_LEVELS,
    attributes: { numerator: "n_receiving" },
  })),
];

export const CATALOG: readonly DatasetSpec[] = [
  { id: "bhw", title: "BHW", category: "Health workforce", detailRoot: "/bhw", indicators: bhw },
  {
    id: "nhfr",
    title: "NHFR",
    category: "Health facilities",
    detailRoot: "/facilities",
    indicators: make(R.nhfr, "agg_nhfr_counts", "nhfr", [
      ["facilities", "Registered facilities", "n_facilities", "facilities"],
      ["government", "Government facilities", "n_government", "facilities"],
      ["private", "Private facilities", "n_private", "facilities"],
      ["type.bhs", "Barangay health stations", "n_barangay_health_station", "facilities"],
      ["type.rhu", "Rural health units", "n_rural_health_unit", "facilities"],
      ["type.hospital", "Hospitals", "n_hospital", "facilities"],
      ["type.birthing-home", "Birthing homes", "n_birthing_home", "facilities"],
      ["beds", "Recorded bed capacity", "total_bed_capacity", "beds"],
      [
        "barangay-presence",
        "Barangays with an attributed facility",
        "n_barangays_with_facility",
        "barangays",
      ],
    ]).map((s) =>
      s.id === "nhfr.barangay-presence" ? { ...s, attributes: { denominator: "n_barangays" } } : s,
    ),
  },
  {
    id: "uuc-phc",
    title: "UUC-PHC",
    category: "Primary care access",
    detailRoot: "/uuc-phc",
    indicators: [
      {
        ...make(R.uuc, "agg_uuc_phc_counts", "uuc", [
          ["listed", "Listed barangays", "n_listed", "barangays"],
        ])[0],
        attributes: { denominator: "n_barangays" },
      },
      ...["ip", "conflict", "four_ps", "health"].map((key): IndicatorSpec => ({
        id: `uuc.route.${key}`,
        label: `Qualifying route: ${key.replaceAll("_", " ")}`,
        unit: "barangays",
        source: R.uuc,
        table: "agg_uuc_phc_criteria",
        column: `n_route_${key}`,
        levels: BARANGAY_LEVELS,
        attributes: { denominator: key === "health" ? "n_health_evaluable" : "n_listed" },
      })),
    ],
  },
  {
    id: "fhsis",
    title: "FHSIS",
    category: "Health services",
    detailRoot: "/health-services",
    indicators: [
      ...FHSIS_KEYS.map((key): IndicatorSpec => ({
        id: `fhsis.${key}`,
        label: key.replaceAll("_", " "),
        unit: ["tb_notified", "tb_dr_notified"].includes(key) ? "per 100,000" : "%",
        source: R.fhsis,
        table: "fact_fhsis_indicator",
        column: "rate_pct",
        levels: AREA_LEVELS,
        filters: { indicator_key: key, breakdown: "total" },
        attributes: { numerator: "numerator", denominator: "denominator" },
        partialReason:
          key === "anc8" ? "Q3–Q4 2025 only; not comparable with full-year 4ANC." : undefined,
      })),
      ...CADRES.map((key): IndicatorSpec => ({
        id: `fhsis.workforce.${key}`,
        label: `${key.replaceAll("_", " ")} workforce`,
        unit: "people",
        source: R.fhsis,
        table: "fact_fhsis_workforce",
        column: "total",
        filters: { cadre: key },
        levels: AREA_LEVELS,
      })),
    ],
  },
  {
    id: "population",
    title: "Population",
    category: "Population and demographics",
    detailRoot: null,
    indicators: [
      {
        id: "population.residents",
        label: "PSA 2024 residents",
        unit: "people",
        source: R.population,
        table: "agg_population",
        column: "population",
        levels: AREA_LEVELS,
        filters: { census_year: 2024 },
      },
    ],
  },
  {
    id: "poverty",
    title: "Poverty",
    category: "Socioeconomic conditions",
    detailRoot: null,
    indicators: [
      {
        id: "poverty.incidence",
        label: "PSA SAE 2023 poverty incidence",
        unit: "%",
        source: R.poverty,
        table: "agg_poverty",
        column: "poverty_incidence",
        levels: ["citymun"],
        filters: { sae_year: 2023 },
        attributes: { ciLow: "ci_low", ciHigh: "ci_high" },
      },
    ],
  },
];

/** Never turn a scoped request into an unscoped or whole-city link. */
export function catalogDetailHref(spec: DatasetSpec, selection: CatalogSelection): string | null {
  if (!spec.detailRoot || selection.mode === "district") return null;
  if (selection.mode === "all" || selection.level === "national") return spec.detailRoot;
  if (selection.level === "barangay") return null;
  const root = spec.id === "bhw" ? "/place" : spec.detailRoot;
  return `${root}/${selection.level}/${encodeURIComponent(selection.code)}`;
}
