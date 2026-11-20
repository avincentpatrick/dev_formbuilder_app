export type GlossaryTermSlug = keyof typeof GLOSSARY;

/**
 * Every technical term referenced anywhere in the UI must have an entry here
 * (BUILD_PLAN.md §5) — `components/glossary/glossary-term.tsx` throws for an
 * unknown slug, so a reference to a term that isn't defined here fails the
 * page that renders it (and `next build`'s static generation, for any
 * statically-rendered page that uses it) rather than silently drifting.
 */
export const GLOSSARY = {
  accredited: {
    term: "Accredited",
    definition:
      "A BHW recorded as accredited in the relevant source. Headcount and individual profiles are separate sources; their accreditation figures use different populations.",
  },
  suppressed: {
    term: "Suppressed",
    definition:
      "A value withheld under the publication rules for that measure. Withheld does not mean zero. Rules differ across measures, so a public total does not establish that every related amount or breakdown can also be shown.",
  },
  honorarium: {
    term: "Honorarium",
    definition:
      "A cash allowance paid to a BHW by a local government unit (region, province, city/municipality, or barangay) in recognition of their service. A BHW can receive honorarium from more than one level at once.",
  },
  honorarium_sufficiency: {
    term: "Historical honorarium reference",
    definition:
      "Combined recorded monthly allowances compared with the historical reference of ₱2,040 per month (₱68 per day using 30 days). This is not a legal minimum or evidence that an allowance meets living costs. The base is all checked profiles, including profiles with no positive recorded amount. Missing amount records do not confirm non-payment.",
  },
  active_years: {
    term: "Years of service",
    definition:
      "Recorded years active as a BHW. The average uses checked profiles with a usable value; missing values are excluded. It is not the number of years continuously employed.",
  },
  ip_status: {
    term: "IP status",
    definition:
      "The Indigenous Peoples identification recorded in the source profile. Retain the source categories; an unexplained or missing code should not be interpreted as a negative response.",
  },
  psgc: {
    term: "PSGC",
    definition:
      "Philippine Standard Geographic Code — the government's official numeric coding system for regions, provinces, cities/municipalities, and barangays.",
  },
  huc: {
    term: "HUC",
    definition:
      "Highly Urbanized City — a city that is administratively independent of the province it's geographically part of.",
  },
  total_bhw: {
    term: "Total BHWs",
    definition:
      "The local-government count of Barangay Health Workers in BHW Headcount 2025: registered-only, registered-and-accredited, and nonregistered workers combined. Checked individual profiles are a separate source and population; do not add the two totals.",
  },
  validated_profile: {
    term: "Validated profile",
    definition:
      "A Barangay Health Worker who has been individually profiled and validated — one anonymized person-level record in the detailed dataset. Every per-person figure on this site (accreditation, demographics, training, honorarium, service years) is computed from these validated profiles, not from the BHW Headcount total.",
  },
  profiling_coverage: {
    term: "Profiling coverage",
    definition:
      "Checked individual profiles divided by the registered headcount (registered-only plus registered-and-accredited). These are distinct sources, without person-by-person matching. This ratio does not establish which registered workers have a profile.",
  },
  registered_bhw: {
    term: "Registered BHW",
    definition:
      "A Barangay Health Worker recorded as registered with the DOH, whether or not they are also accredited. In the BHW Headcount this covers the 'registered' and 'registered & accredited' buckets.",
  },
  non_registered_bhw: {
    term: "Non-registered BHW",
    definition:
      "A worker reported as not registered in the local-government headcount. This category alone does not establish whether the worker appears in the separate individual-profile source.",
  },
  stepzero: {
    term: "BHW Headcount 2025",
    definition:
      "A DOH barangay-level aggregate headcount of Barangay Health Workers (registered, accredited, and non-registered) with population and household context. It is a reported area tally, distinct from the checked individual-profile dataset.",
  },
  census_population: {
    term: "Census population",
    definition:
      "An official resident count from the Philippine Statistics Authority (PSA) census. Read the figure's source and year: census population and population reported with BHW headcounts are different sources. Context views retain the selected source and do not silently replace a missing count.",
  },
  households_per_bhw: {
    term: "Households per BHW",
    definition:
      "The area's reported household count divided by its reported BHW count, using BHW Headcount 2025. This is an area-level comparison, not a worker's recorded assignments or actual visits. It does not by itself establish a staffing shortage. Areas without a usable source count have no ratio.",
  },
  ref_manual_trained: {
    term: "BHW Reference Manual Training",
    definition:
      "Whether a BHW has completed training on the DOH's official BHW Reference Manual, as recorded in the source dataset's training fields.",
  },
  tesda_nc2: {
    term: "TESDA BHS NC2 Training",
    definition:
      "Whether a BHW has taken TESDA's Barangay Health Services (BHS) National Certificate II training/assessment. Distinct from having actually earned the NC II certification (see 'TESDA BHS NC II Certification').",
  },
  tesda_certified: {
    term: "TESDA BHS NC II Certification",
    definition:
      "Whether a BHW holds an official TESDA National Certificate II (NC II) in Barangay Health Services — the certified outcome of the BHS NC2 training/assessment.",
  },
  ai_generated: {
    term: "AI-generated",
    definition:
      "Written by an AI model, not a person — but every number in it is looked up from this site's own database at the moment it was written, then automatically checked and stripped if it can't be traced back to that lookup. See the methodology page for how this works.",
  },
  bhw_per_1000: {
    term: "BHWs per 1,000 residents",
    definition:
      "The area's reported BHW count divided by the population named for that figure, multiplied by 1,000. It compares reported workforce size with population size. Read the source and year before comparing places. A higher ratio does not by itself show better access, enough staff, or more services delivered.",
  },
  poverty_incidence: {
    term: "Poverty incidence",
    definition:
      "The share of a city or municipality's population living below the official poverty threshold. From the Philippine Statistics Authority's 2023 Small Area Estimates — model-based estimates that combine the census, income surveys, and administrative data to reach the city/municipality level, each published with a confidence interval. Highly Urbanized Cities are estimated separately and are not covered here. Used only as a comparison variable on the Explore relationships scatter, never as a BHW-workforce figure.",
  },
  lgu_reported_accreditation: {
    term: "LGU-reported accreditation",
    definition:
      "The share of all BHWs an area's own BHW Headcount reports as accredited. It counts the whole BHW universe, unlike the verified accreditation rate, which counts only individually validated profiles. The two use different sources and denominators, so they're shown side by side, never averaged.",
  },
  confidence_interval: {
    term: "confidence interval",
    definition:
      "A range expressing uncertainty around an estimated percentage. Wider intervals generally mean less precision. Where this site labels an interval as 95% Wilson, that names the statistical method and confidence level; it is not a range of individual workers' values.",
  },
  data_completeness: {
    term: "Data completeness",
    definition:
      "How much of each BHW profile's information is actually filled in. The grade (A ≥95%, B ≥85%, C below) is the average completeness across the tracked fields, each counted equally; a low grade means figures that rely on the missing fields are less reliable here. See the data-quality page for the field-by-field breakdown.",
  },
  gini: {
    term: "Gini coefficient",
    definition:
      "A single number from 0 to 1 for how unevenly something is shared out. 0 means everyone gets exactly the same; 1 means one person gets everything. Used here for how evenly (or not) honorarium amounts are spread among the BHWs who receive one.",
  },
  income_class: {
    term: "LGU income class",
    definition:
      "A government classification of a local government's yearly income, from 1st class (highest income) to 5th class (lowest). The source is the DOF's 2024 reclassification under Department Order 074-2024 and RA 11964, effective 2025. It does not show the budget available for BHW allowances or establish why payments differ.",
  },
  adjusted_rate: {
    term: "Adjusted rate",
    definition:
      "A rate from a place with very few BHWs is nudged toward its parent area's rate, because a handful of people can swing a small percentage wildly. The adjustment moves noisy small-area rates toward what's typical nearby, leaving large, well-measured areas almost unchanged. Raw rates are shown by default; the adjusted view is an opt-in.",
  },
} as const;
