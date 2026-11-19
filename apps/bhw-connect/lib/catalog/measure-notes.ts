import { demographicSourceNote } from "@/lib/bhw/demographic-labels";
import { CATALOG } from "./definitions";
import type { CatalogIndicator } from "./types";

const definitions: Record<string, string> = {
  "bhw.total":
    "BHWs reported by local government units (LGUs), including registered-only, registered-and-accredited and nonregistered workers. This count covers a different group from the checked individual profiles.",
  "bhw.registered":
    "Workers reported by local governments as registered but not accredited. Workers who are both registered and accredited are counted separately.",
  "bhw.registered-accredited":
    "Workers reported by local governments as both registered and accredited.",
  "bhw.nonregistered": "Workers reported by local governments as not registered.",
  "bhw.declared-accreditation":
    "The percentage of all BHWs reported by local governments who are both registered and accredited.",
  "bhw.validated-profiles":
    "Individual BHW records included after data checks in BHW Profiles 2025. This is a different group from the workforce reported by local governments.",
  "bhw.profile-accreditation":
    "The percentage of checked BHW profiles recorded as accredited. The base is all checked profiles, not the local-government headcount.",
  "bhw.active-years":
    "Average recorded years active as a BHW, using checked profiles with a usable value. Profiles without a usable value are excluded. Do not add averages from different places.",
  "bhw.any-honorarium":
    "The percentage of all checked profiles with an allowance (honorarium) record. This does not establish the amount received or whether it is enough.",
  "nhfr.facilities":
    "Registered facilities in the accepted NHFR snapshot, counted once per facility.",
  "nhfr.government":
    "Registered facilities classified as government-owned in the accepted snapshot.",
  "nhfr.private": "Registered facilities classified as privately owned in the accepted snapshot.",
  "nhfr.beds": "Sum of recorded bed capacity; missing capacity is not evidence of zero beds.",
  "nhfr.barangay-presence":
    "Distinct barangays with an attributed registered facility. The denominator describes service presence, not source reporting completeness.",
  "uuc.listed":
    "Barangays on the accepted final UUC-PHC list. At barangay level, 1 means listed and 0 means not listed; not listed does not mean assessed and well served.",
  "uuc.route.ip":
    "Listed barangays qualifying through the recorded indigenous-peoples route under AO 2020-0023. Denominator: listed barangays.",
  "uuc.route.conflict":
    "Listed barangays qualifying through the recorded conflict route under AO 2020-0023. Denominator: listed barangays.",
  "uuc.route.four_ps":
    "Listed barangays qualifying through the recorded 4Ps route under AO 2020-0023. Denominator: listed barangays.",
  "uuc.route.health":
    "Listed barangays qualifying through recorded health criteria. Denominator: health-evaluable listed barangays; unusable provincial benchmarks are excluded.",
  "population.residents":
    "PSA POPCEN 2024 resident population in accepted matched census rows. Higher-level totals sum matched municipalities and may be partial; Manila retains its published province-node figure.",
  "poverty.incidence":
    "PSA 2023 small-area estimate of the percentage of people below the poverty threshold. The published 90% confidence interval describes estimate uncertainty. No higher-level or district rate is inferred.",
};
const specs = new Map(CATALOG.flatMap((d) => d.indicators.map((s) => [s.id, s] as const)));

const statisticExplanations: Record<string, string> = {
  mean: "Average (mean): add the recorded values, then divide by the number of usable records.",
  median:
    "Middle value (median): half the recorded values are at or below it and half are at or above it.",
  p10: "10th percentile: roughly 10 in 100 recorded values are at or below this point. Tied values can increase that share.",
  p25: "25th percentile: roughly 25 in 100 recorded values are at or below this point. Tied values can increase that share.",
  p75: "75th percentile: roughly 75 in 100 recorded values are at or below this point. Tied values can increase that share.",
  p90: "90th percentile: roughly 90 in 100 recorded values are at or below this point. Tied values can increase that share.",
  min: "The lowest recorded monthly amount in this group; it is not a standard payment rate.",
  max: "The highest recorded monthly amount in this group; it is not a standard payment rate.",
  "busiest-share":
    "The percentage of recorded household assignments held by the group ranked in the highest tenth for assigned households. Ties can make this group larger than 10% of workers.",
  gini: "Gini describes how uneven recorded allowances are: 0 means everyone in this group has the same amount; values closer to 1 mean greater inequality.",
  ratio:
    "The amount at the 90th percentile divided by the amount at the 10th percentile. This compares two points in the payment distribution, not the average payments of the highest- and lowest-paid groups.",
};

function statisticExplanation(id: string): string {
  return statisticExplanations[id.split(".").at(-1)!] ?? "";
}

/** Public interpretation only; calculations and source authority remain in the adapters. */
export function measureDefinition(indicator: CatalogIndicator): string {
  if (indicator.definition) {
    return `${indicator.definition.numerator} / ${indicator.definition.denominator}. Unit: ${indicator.unit}. Published rate retained; source workbook: ${indicator.definition.sourceWorkbook}.`;
  }
  if (definitions[indicator.id]) return definitions[indicator.id];
  const spec = specs.get(indicator.id);
  if (indicator.id.startsWith("bhw.training-recency."))
    return "The middle recorded training year (median) among profiles recorded as trained in this topic. Missing years are unavailable. This does not mean everyone trained in that year or that their training or certification is up to date.";
  if (indicator.id.startsWith("bhw.honorarium-amount."))
    return `${statisticExplanation(indicator.id)} Amounts converted to pesos per month, using available amount records for recipients from this government source. A worker can receive allowances from several sources; do not add their averages or percentile values to estimate a worker’s total. These are 2025 records, not current payroll.`;
  switch (spec?.table) {
    case "agg_cohorts":
      return "Profiles in the 2025 dataset, grouped by the year of registration, accreditation or first service. These counts do not show how many BHWs worked in each past year or how many stayed in service. A missing year does not mean zero workers.";
    case "agg_workload":
      return `${statisticExplanation(indicator.id)} Uses checked profiles with more than zero assigned households recorded; zero and missing assignments are excluded. These are assignments reported by individual BHWs, not total households in an area divided by its worker count. Household assignments do not measure every part of a BHW’s work.`;
    case "agg_honorarium_cumulative":
      return `${indicator.id.endsWith(".median") ? `${statisticExplanations.median} ` : ""}Each profile’s recorded positive allowances are converted to monthly amounts and added across government sources. This measure includes all checked profiles, including those with no amount above zero recorded. Missing amounts do not confirm non-payment. The ₱2,040/month comparison is a historical analysis reference, not a legal minimum or a current standard for what is enough.`;
    case "agg_honorarium_inequality":
      return `${statisticExplanation(indicator.id)} Uses each recipient’s recorded allowances added across sources and converted to monthly amounts. Only profiles with a recorded total above zero are included; other profiles are excluded. These 2025 records do not describe current payroll.`;
    case "agg_training":
      return "The percentage of all checked profiles recorded as trained in this topic. One BHW can have several training topics, so do not add the percentages. This describes recorded training, not a test of current skills.";
    case "agg_certification":
      return "The percentage of all checked profiles with this training or certification recorded. Training and certification are separate measures; a worker can appear in more than one.";
    case "agg_demographics":
      return `Counts of checked profiles in each recorded category. Categories may not add up to the profile total when source information is missing. All stored category counts are shown, including zero. Values missing from the source stay unavailable; they are not treated as zero. ${demographicSourceNote(indicator.id)}`.trim();
    case "agg_honorarium":
      return "The percentage of all checked profiles recorded as receiving an allowance from this government source. A worker may receive allowances from several sources, so do not add these percentages. A receipt record does not establish the amount paid.";
    case "agg_nhfr_counts":
      return `Registered facilities classified as ${indicator.label.toLowerCase()} in the accepted NHFR snapshot. These headline types are not an exhaustive partition of all facilities.`;
    case "fact_fhsis_workforce":
      return "Total recorded staff in this non-BHW cadre in the accepted DOH FHSIS release, including recorded LGU and DOH components.";
    case "fact_fhsis_indicator":
      return "The accepted FHSIS dictionary must supply this service measure’s numerator, denominator and unit before a figure can be displayed. No definition or rate is inferred from the key.";
    default:
      return "Definition unavailable.";
  }
}

export function derivationNote(indicator: CatalogIndicator): string | null {
  if (indicator.aggregation === "sum")
    return "District calculation: sum of disjoint accepted reporting units. Missing units remain disclosed as partial coverage.";
  if (indicator.aggregation === "ratio")
    return "District calculation: sum of compatible numerators divided by sum of denominators, multiplied by 100. Municipal percentages are not averaged.";
  if (indicator.id.startsWith("fhsis.") && !indicator.id.startsWith("fhsis.workforce.")) {
    if (indicator.id === "fhsis.anc8")
      return "Source period: Q3–Q4 2025; not comparable with full-year 4ANC.";
    if (/^fhsis\.(water_|sanitation_)/.test(indicator.id)) return "Source period: Q4 2025.";
    return "Source period: annual 2025.";
  }
  if (indicator.id.startsWith("nhfr."))
    return "Barangay figures include only facilities attributed to that barangay. Unassigned or invalidly attributed facilities remain in municipal totals; zero does not establish absence of all facilities.";
  if (indicator.id.startsWith("uuc.route."))
    return "Uses recorded qualification, not a new eligibility decision. Routes overlap and must not be summed.";
  return null;
}
