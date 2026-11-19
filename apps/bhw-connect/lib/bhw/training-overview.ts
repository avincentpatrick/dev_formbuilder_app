import { ACCEPTED_RELEASES } from "@/lib/catalog/definitions";
import type { CatalogDataset } from "@/lib/catalog/types";
import { publicValues, measureStatus } from "./overview";
import { trainingComparisonRows, type TrainingCell } from "./training-comparison";

// Editorial display labels only. Original source labels remain searchable and visible.
const labels: Record<string, string> = {
  burns_choking_neck_head_spinal_injuries_poisoning:
    "Burns, choking, head/neck/spinal injuries and poisoning",
  basic_nutrition_malnutrition_nutrition_in_emergencies_and_disasters:
    "Nutrition and malnutrition, including emergencies and disasters",
  blood_pressure_bp_apparatus_measurement: "Measuring blood pressure",
  breastfeeding_lactation: "Breastfeeding and lactation",
  chronic_respiratory_diseases_chronic_obstructive_pulmonary_disease_copd_asthma:
    "Long-term lung conditions, including COPD and asthma",
  cardiovascular_diseases_heart_attack_stroke: "Heart disease, heart attack and stroke",
  child_growth_standard_growth_development: "Child growth and development",
  cardio_pulmonary_resuscitation_cpr: "Cardiopulmonary resuscitation (CPR)",
  disaster_risk_assessment_disaster_risk_preparedness:
    "Assessing disaster risks and preparing for disasters",
  emergency_reponse_rescue: "Emergency response and rescue",
  emerging_and_re_emerging_infectious_diseases: "New and returning infectious diseases",
  environment_sanitation_solid_ecologic_waste_management:
    "Environmental sanitation and waste management",
  food_preparation_food_safety: "Safe food preparation and handling",
  family_planning_responsible_parenthood: "Family planning and responsible parenthood",
  first_1000_days: "First 1,000 days",
  infant_and_young_child_feeding_practices: "Feeding babies and young children",
  maternal_care: "Maternal care",
  others_please_specify: "Other training topics",
  sexually_transmitted_disease: "Sexually transmitted diseases",
  traditional_alternative_herbal_medicine: "Traditional, alternative and herbal medicine",
  tuberculosis: "Tuberculosis (TB)",
  uhc_phc_f1_plus_for_health_sdn: "Health-system programs: UHC, PHC, F1 Plus for Health and SDN",
  water_sanitation_and_hygiene_wash: "Water, sanitation and hygiene (WASH)",
  zero_open_defecation_zod: "Ending open defecation (ZOD)",
};
export type OverviewTrainingRow = {
  id: string;
  yearId: string;
  label: string;
  sourceLabel: string;
  coverage: TrainingCell;
  year: number | null;
  yearStatus: string;
};
export function trainingOverviewRows(dataset: CatalogDataset): OverviewTrainingRow[] {
  return trainingComparisonRows([dataset]).map((row) => {
    const topic = row.id.slice("bhw.training.".length);
    const yearId = `bhw.training-recency.${topic}`;
    const item = dataset.indicators.find((i) => i.id === yearId);
    const values = publicValues(item);
    const year =
      item?.sourceSlug === ACCEPTED_RELEASES.bhw &&
      item.unit === "calendar year" &&
      values.length === 1 &&
      Number.isFinite(values[0].value)
        ? values[0].value
        : null;
    return {
      id: row.id,
      yearId,
      label: labels[topic] ?? row.label,
      sourceLabel: row.label,
      coverage: row.cells[0],
      year,
      yearStatus:
        year === null
          ? measureStatus(item) || "No recorded training year is available."
          : (item?.reason ?? ""),
    };
  });
}
export function filterTrainingOverview<T extends OverviewTrainingRow>(
  rows: T[],
  query: string,
  byCoverage: boolean,
) {
  const term = query.trim().toLocaleLowerCase("en");
  return rows
    .filter((r) => `${r.label} ${r.sourceLabel}`.toLocaleLowerCase("en").includes(term))
    .sort((a, b) => {
      if (byCoverage && a.coverage.value !== b.coverage.value) {
        if (a.coverage.value === null) return 1;
        if (b.coverage.value === null) return -1;
        return b.coverage.value - a.coverage.value;
      }
      return a.label.localeCompare(b.label, "en");
    });
}
