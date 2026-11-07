import { CATALOG } from "./definitions";
import type { CatalogIndicator } from "./types";

const definitions: Record<string, string> = {
  "bhw.total":
    "All LGU-declared BHWs, including registered-only, registered-and-accredited and nonregistered workers. This is distinct from validated profiles.",
  "bhw.registered":
    "LGU-declared registered-only workers; excludes the registered-and-accredited bucket.",
  "bhw.registered-accredited": "LGU-declared workers who are both registered and accredited.",
  "bhw.nonregistered": "LGU-declared workers recorded as nonregistered.",
  "bhw.declared-accreditation":
    "Registered-and-accredited LGU-declared workers divided by all LGU-declared BHWs, multiplied by 100.",
  "bhw.validated-profiles":
    "Validated individual profiles in the accepted BHW census; not the entire LGU-declared workforce.",
  "bhw.profile-accreditation":
    "Accredited validated profiles divided by all validated profiles, multiplied by 100.",
  "bhw.active-years":
    "Mean recorded years active among validated profiles with a usable value; averages are not added across locations.",
  "bhw.any-honorarium":
    "Validated profiles with any honorarium record divided by all validated profiles, multiplied by 100.",
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

/** Public interpretation only; calculations and source authority remain in the adapters. */
export function measureDefinition(indicator: CatalogIndicator): string {
  if (indicator.definition) {
    return `${indicator.definition.numerator} / ${indicator.definition.denominator}. Unit: ${indicator.unit}. Published rate retained; source workbook: ${indicator.definition.sourceWorkbook}.`;
  }
  if (definitions[indicator.id]) return definitions[indicator.id];
  const spec = specs.get(indicator.id);
  switch (spec?.table) {
    case "agg_training":
      return "Validated profiles recorded as trained in this topic divided by all validated profiles, multiplied by 100. Topics overlap and are not summed.";
    case "agg_certification":
      return "Validated profiles with this recorded certification or training, as a percentage of all validated profiles.";
    case "agg_demographics":
      return "Counts of validated profiles in each recorded category. Missing categories need not sum to the profile total. Small groups and reconstructable breakdowns are withheld across catalog locations.";
    case "agg_honorarium":
      return "Validated profiles receiving honorarium from this payer level, as a percentage of all validated profiles. Payers overlap; their shares must not be added. Privacy restrictions apply.";
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
  if (indicator.id.startsWith("nhfr."))
    return "Barangay figures include only facilities attributed to that barangay. Unassigned or invalidly attributed facilities remain in municipal totals; zero does not establish absence of all facilities.";
  if (indicator.id.startsWith("uuc.route."))
    return "Uses recorded qualification, not a new eligibility decision. Routes overlap and must not be summed.";
  return null;
}
