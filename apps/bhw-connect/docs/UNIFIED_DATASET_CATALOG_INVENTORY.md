# Unified catalog: inventory and geographic capabilities

Increment 1 of [the implementation plan](UNIFIED_DATASET_CATALOG_PLAN.md).
Verified 2026-11-07 against `origin/main` at `2b0ec799` and the public pages noted below.
This is the implementation contract for the next increment, not a claim that all source
records or geographic coverage have been verified live. No ingestion or public UI changed.

## Evidence and release selection

The six logical identities are `bhw`, `nhfr`, `uuc-phc`, `fhsis`, `population`, and `poverty`.
An ingestion run, a source table, a breakdown category and a release row are not additional
datasets. Profiling Status is excluded; district mapping is navigation/provenance.

| Identity / category | Accepted source and current selector | Source date and update metadata | Existing destination |
| --- | --- | --- | --- |
| BHW / Health workforce | DOH validated profiles: `bhw-2025`, guarded by `status=active`; DOH StepZero quick-count universe: `bhw-stepzero-2026` | Separate `dim_dataset.as_of_date` and `last_updated_at` for each source; do not infer reference year from the StepZero slug (existing UI describes 2025) | `/bhw`, `/place/{level}/{code}` |
| NHFR / Health facilities | DOH NHFR September 2026 export, `nhfr-2026-09` | Snapshot month plus dataset dates | `/facilities`, `/facilities/{level}/{code}` through citymun |
| UUC-PHC / Primary care access | DOH BLHSD final 2025 list, DC 2025-0549, AO 2020-0023; `uuc-phc-2025` | List edition plus dataset dates; preserve final-list reconciliation | `/uuc-phc`, area pages through citymun, criteria/indicators/methodology pages |
| FHSIS / Health services | DOH partial annual release, `fhsis-2025`; use source-published area rows | 2025; retrieved 2026-09-06; `anc8` is Q3–Q4 only; dataset dates | `/health-services`, area pages through citymun |
| Population / Population and demographics | PSA POPCEN 2024, `psa-popcen-2024`, `census_year=2024`; accepted name-matched `agg_population` | Census reference year plus dataset dates | No standalone public population detail yet; existing BHW context is not a population detail page |
| Poverty / Socioeconomic conditions | PSA SAE 2023 release, `psa-sae-poverty-2023`, `sae_year=2023` | Release also contains 2018/2021 back-estimates, excluded from current catalog; dataset dates | No standalone detail yet; existing Explore Relationships is contextual |

Evidence: `lib/db/dataset.ts`, `population.ts`, `poverty.ts`, `stepzero.ts`, `fhsis.ts`,
`docs/POPULATION_RECONCILIATION.md`, `POVERTY_SAE.md`, `FHSIS_2025_PLAN.md`.
Companion slug lookups do **not** filter `status`. There is no generic latest-accepted-release
resolver. Keep these explicit selectors until acceptance of a replacement release is recorded;
never select the latest timestamp across unrelated rows. NHFR documents a new row per snapshot.
A load into another slug alone therefore does not refresh the current catalog. Increment 2 must
centralize these mappings; Increment 6 must verify a deliberate accepted-release switch.
A missing date stays unknown. Neither deployment time nor this audit date is a source update.

## Canonical indicator inventory

These keys describe logical measures for adapter implementation. They are not new database columns.
Count one measure once across summary, table, map, numerator/denominator display and source aliases.
A family with a finite source key set expands by that set, not by geography, release or returned rows.
The next increment must freeze/test that expansion before displaying an indicator count. Confidence
intervals, coverage denominators, flags, provenance and distribution categories are attributes of
an indicator, not independent headline indicators. No fixed global indicator count is asserted here.

### BHW

| Canonical measure(s) | Accepted read / definition and units |
| --- | --- |
| `bhw.total`, `bhw.registered`, `bhw.registered-accredited`, `bhw.nonregistered` | `agg_bhw_stepzero_counts`: total and the three disjoint LGU-declared buckets; persons. Registered-only excludes the registered-and-accredited bucket. |
| `bhw.registered-universe` | Registered + registered-and-accredited; persons eligible for profiling, distinct from total including nonregistered. Preserve null inputs/partial reporting. |
| `bhw.validated-profiles` | `agg_bhw_counts.n_total`; validated individuals, never labeled total workforce. |
| `bhw.profile-coverage` | Validated profiles / registered universe × 100; `getBhwOverview`; positive denominator, raw ratio and exceeds-base flag retained even if presentation caps at 100. |
| `bhw.profile-accreditation` | `n_accredited` / validated profiles × 100; count and Wilson interval accompany the same measure. |
| `bhw.declared-accreditation` | StepZero registered-and-accredited / total workforce × 100; separate population from profile accreditation. |
| `bhw.active-years` | `agg_bhw_counts.avg_active_years`; years, not sum or average of regional averages. |
| `bhw.any-honorarium` | `any_honorarium_pct`; profiles with any honorarium record / validated profiles × 100. |
| `bhw.households-per-worker` | StepZero households / StepZero total workforce; existing helper requires positive inputs. |
| `bhw.per-1000-residents` | StepZero total × 1,000 / StepZero population, PSA census fallback only when StepZero population is null; existing helper requires positive inputs. Preserve denominator source. |
| `bhw.demographics.{dimension}` | `agg_demographics`; six distributions: sex, civil_status, bloodtype, education, ip_status, age_band. Counts / all validated profiles, so non-null categories need not sum to 100%. |
| `bhw.training.{topic_slug}` | `agg_training`; topic set in `ingestion/build_aggregates.sql` `topics` CTE. Trained profiles / validated profiles × 100; median training year and Wilson interval are attributes. |
| `bhw.certification.{cert_type}` | `agg_certification`; `tesda_nc2`, `tesda_certified`, `ref_manual_trained`; count and percent of validated profiles. |
| `bhw.honorarium.{payer_level}` | `agg_honorarium`, payer keys `region`, `province`, `citymun`, `barangay`; recipient count and percent of validated profiles; normalized monthly PHP amounts/distribution and frequency belong to that payer's measure. Payers overlap. |

Evidence: `lib/db/indicators.ts`, `stepzero.ts`, `ingestion/build_aggregates.sql`.
Existing extended workload/cohort/honorarium inequality analyses remain in their existing details;
they are not extra catalog previews or counted a second time as datasets. Freeze the initial
catalog inventory explicitly in Increment 2 rather than count arbitrary aggregate table columns.
Do not use FHSIS's BHW cadre or Profiling Status as a replacement for either BHW universe.

### NHFR

Canonical measures: `nhfr.facilities`, `nhfr.government`, `nhfr.private`,
`nhfr.type.{facility_type}`, `nhfr.beds`, `nhfr.barangay-presence`.
`agg_nhfr_counts` supplies total, ownership, four headline types (BHS, RHU, hospital, birthing home),
beds and barangay presence; `agg_nhfr_by_type` supplies the type distribution. Those four columns
are aliases of their type measures, not four additional indicators. Units are facilities, beds,
and barangays; presence share = distinct barangays with an attributed facility / `n_barangays`.
The denominator describes service presence, not completeness of source reporting.
No licensing percentage: absent licensing status means not stated, not unlicensed.
Read `lib/db/nhfr.ts` and `20260905090200_agg_nhfr_counts.sql` / `20260905090300_agg_nhfr_by_type.sql`
under `supabase/migrations/`. Place counts have no person small-cell suppression. Personal contact
fields must remain outside the public projection. A sparse type table alone cannot establish zero;
use the successfully loaded total row and a complete type read.

### UUC-PHC

Canonical measures: `uuc.listed` (count/share, or boolean membership at barangay),
`uuc.route.ip`, `uuc.route.conflict`, `uuc.route.fourPs`, `uuc.route.health` (qualifying counts/shares),
and source supporting measurements under `uuc.assessment.*`.
`agg_uuc_phc_counts.n_listed / n_barangays` is the listed share. Not listed does not mean assessed
and found well served. `fact_uuc_phc_barangay` membership must be read successfully before a
missing membership can mean false. The final publication has a documented unresolved source row.

`agg_uuc_phc_criteria` routes overlap: never sum them as a partition. Routes a–c use listed
barangays as denominator; health uses `n_health_evaluable`, excluding unusable provincial
benchmarks. Use recorded qualification, not a newly recomputed eligibility decision.
Supporting measurements include physical access, IP, conflict, displacement, 4Ps percentages and
seven health readings: `imr`, `ufmr`, `abr`, `fic`, `pre_natal`, `sba`, `water`.
The first two health rates are per 1,000 live births, ABR per 1,000 women aged 10–19; the other
four are percentages. ELCAC designation is a flag, not a rate. Source numerators/denominators
are not available for recomputing these readings. Preserve `capped`, missing values and benchmark
quality; no averages, sums or rolled-up coverage rates from bounded readings.
These are listing-assessment evidence, not competing FHSIS current service statistics: retain
source-specific identity, link shared concepts, and do not count an alias twice.

Evidence: `lib/db/uuc-phc.ts`, `uuc-phc-criteria.ts`, `uuc-phc-indicators.ts`,
`uuc-phc-indicator-dist.ts`, `uuc-phc-list.ts`, `docs/UUC_PHC_2025_CLEANING_REPORT.md` and
`supabase/migrations/20260828180000_uuc_phc_final_list_alignment.sql`.
Distribution summaries describe recorded/bounded assessment values, not an area's health rate.

### FHSIS

The concrete inventory is `ref_fhsis_indicator.indicator_key`, with definitions in its
`numerator_def`, `denominator_def`, `unit` and `source_workbook` fields. The repository dictionary
in `supabase/seed.sql` has these 20 keys (metadata evidence only; its fact rows are synthetic):

| Keys | Denominator / unit rule |
| --- | --- |
| `fic`, `cic` | Projected previous-cohort population 0–12 months; CIC subtracts previous FIC; percent |
| `anc4` | Total deliveries; percent |
| `anc8` | Women delivered and tracked during pregnancy, Q3–Q4 only; percent |
| `water_basic`, `water_level1`, `water_level2`, `water_level3`, `water_safely_managed` | Projected households; percent |
| `sanitation_basic`, `sanitation_safely_managed`, `sanitation_septic`, `sanitation_sewer`, `sanitation_vip` | Projected households; percent |
| `tb_notified`, `tb_dr_notified` | Projected all-age population; cases per 100,000 |
| `tb_presumptive_tested` | Projected all-age population; percent per dictionary (do not relabel as percent of presumptives) |
| `tb_tpt` | Eligible contacts: notified TB cases × 4 × 70%; percent |
| `tb_tsr_dstb`, `tb_tsr_mdrtb` | Respective registered TB cases, not population; percent |

Use `fact_fhsis_indicator`, `breakdown=total`, the exact area and level; retain published rate,
numerator and denominator. Above-100 percentages remain uncapped with the existing explanatory
note; TB notification rates above 100 are normal for their unit. Missing rate is distinct from
missing row. `ref_fhsis_reconciliation` holds parent/child differences, not authority to replace
published parent values. Do not infer extra inventory from examples such as `pnc2` in older plans.
Non-BHW workforce is a separate `fhsis.workforce.{cadre}` family from `fact_fhsis_workforce`;
LGU/DOH-hired counts are components, source population/households contextual denominators.
The plan enumerates physician, nurse, midwife, dentist, med_tech, nutritionist,
sanitary_engineer and sanitary_inspector; verify their loaded keys before freezing the adapter
inventory, since the plan is not live-load evidence. Never include `bhw`. Staff ratios require a positive staff count
and explicitly named BHW numerator. No workforce preview until the live load is verified.

### Population and poverty

`population.residents` is PSA POPCEN 2024 persons (`agg_population.population`), independent of
the StepZero denominator used by BHW. StepZero and FHSIS population fields remain source-specific
context, not rival PSA census values or additional generic population indicators. The accepted
higher-level census rows sum matched municipality leaves; they are **Partial total** where
coverage is incomplete. Do not present them as PSA's published national total. Manila's census
row is stored at province node `13806`; do not repeat it at the constituent district/citymun
nodes. Household census data is documented as not loaded, so it is not a preview.

`poverty.incidence` is `agg_poverty.poverty_incidence` (percent of population below threshold).
`ci_low`, `ci_high` (90% interval), standard error and coefficient of variation describe the
estimate, not additional indicators. No population weighting or higher-level rate is approved.
The noHUC source excludes HUCs; SGA, Pateros, Kalayaan and unmatched Manila units have documented
gaps. Missing estimate is not zero. See `docs/POVERTY_SAE.md`; do not reuse old historic match
counts as current coverage after geography changes.

## Geographic capability matrix

**P** = source-published value at that grain; **C** = existing accepted calculation or safe
calculation under the stated rule; **U** = unsupported by accepted source/measure;
**V** = awaiting verification before publication. P/C describe capability, not live availability
or complete coverage. Every numeric read still needs its own available/partial/failed state.

| Measure family | National | Region | Province | City/municipality | Barangay | Congressional district |
| --- | --- | --- | --- | --- | --- | --- |
| BHW StepZero counts | C | C | C | C | P | V: leaf completeness and null handling |
| BHW profile counts/accreditation/active years/any honorarium | C | C | C | C | C | C: existing `agg_bhw_by_district`, mapping gaps apply |
| BHW demographic distributions / payer honorarium | C | C | C | C | C: suppression | V: no matching district aggregate |
| BHW topic training | C | C | C | C | U: aggregate not built | V |
| BHW certification | C | C | C | C | C | V |
| BHW profile coverage / workload / per-capita | C | C | C | C | C: available inputs only | V: distinct source coverage/denominators |
| NHFR counts, types, beds | C | C | C | C | C: attributed facilities only; new adapter needed | V: complete municipal units or attributed leaf facilities |
| NHFR barangay presence | C | C | C | C | C: attributed presence only | V: mapping/attribution completeness |
| UUC listed / routes | C | C | C | C | P: membership/recorded qualification, not inherited parent rate | V: deduplicated accepted membership |
| UUC supporting health/assessment readings | U: distributions only | U: distributions only | U: benchmark context only | U: distributions only | P: retain bounded/missing flags | U: no rate aggregation |
| FHSIS service indicators / non-BHW workforce | P | P | P | P | U | V for whole units; U for split-city municipal figures |
| PSA population | C: partial | C: coverage-qualified | C: coverage-qualified; Manila source row exception | P where matched | U | V: complete whole units only; split-city U |
| Poverty incidence | U | U | U | P where matched | U | U: no approved rate calculation |

Do not infer unknown values from a parent's rate, turn an absent sparse BHW row into zero, sum
percentages, average municipal rates, or average medians. Existing person-level calculations
stay server-side in approved aggregates; the public adapter must not fetch raw individuals.
FHSIS has published higher-level rows: prefer them, and defer any new district recomputation
until compatible numerators/denominators, period, membership and full coverage are proven.
NHFR unassigned facilities remain included at citymun but cannot be allocated to a barangay or
split-city district. A successfully counted zero means no **attributed registered** facilities,
with the attribution limitation visible; it does not establish absence of all facilities.

## Geography and reconciliation requirements

- `dim_geo` is the accepted join target, not a complete current PSA gazetteer. Read hierarchy
  and ancestor fields rather than slice PSGC strings. `getChildGeos` in `lib/db/geo.ts` currently
  hardcodes region → province → citymun → barangay and can hide a direct city child. Increment 3
  must support actual child levels and skip non-applicable province selection. Existing HUC
  province-level nodes are representations, not evidence that a city belongs to a neighboring
  province. Do not fabricate parents or change accepted geographic codes in this work.
- `docs/PSGC_CROSSWALK.md`: direct hit then vintage crosswalk, otherwise unresolved. NIR prefix
  changes are explicitly supported; pre-NIR Bacolod is a known exception. Population and poverty
  have additional source-specific name reconciliation. Do not replace those with a generic fuzzy
  join. `ingestion/patch_dim_geo_nhfr_gap.py` extends the earlier BHW-derived geography, so older
  reconciliation counts and newly loaded aggregate denominators can differ.
- Public NHFR and UUC overview reads on this audit date expose **different national barangay
  denominators**. Do not combine their coverage fractions or assert a shared refreshed roster.
  Increment 2 must carry source-specific denominators; Increment 4 must verify the roster used
  by a new calculation. Rebuilding legacy aggregates is separate from silently changing values.
- `geo_district_map`: live rows have `superseded_by IS NULL` and `status <> 'rejected'`.
  Preserve provenance (`source_kind`, `source_ref`, `retrieved_at`, review history), Congress and
  unresolved membership. `docs/LEGISLATIVE_DISTRICTS.md` records independent-city exceptions,
  unplaced barangays, and source limitations; province ancestry does not decide voting membership.
- Expand whole-municipality members to leaf barangays and combine with explicit barangay members
  as a **set**. Reject cross-district conflicts and deduplicate within a district. The existing
  `20260905050000_agg_bhw_by_district.sql` uses `UNION ALL` and guards only
  `count(distinct district_code)>1`; that guard does not detect repeated leaves in the same
  district. Increment 5 must test both cases; this audit does not assert live duplicates exist.
- District population metadata (`dim_legislative_district.psa_population`) has mixed historical
  vintages in the reconciliation report. Do not use it as the current POPCEN 2024 preview without
  verified vintage and coverage. District BHW totals are validated profiles, not StepZero total
  workforce. Keep district scope when navigating a partial city; current whole-city detail routes
  cannot represent it and must not be used to imply scoped figures.

## Preview selection and privacy gate

| Card | Initial eligible headlines | Rule |
| --- | --- | --- |
| BHW | Total BHWs; validated profiles; profile accreditation | Independent StepZero/profile sources and explicit denominators; no substitution between totals |
| NHFR | Facilities; hospitals; barangays with an attributed facility | Existing count/type aliases; service presence and unassigned attribution labeled |
| UUC-PHC | Listed barangays; listed share | Accepted list / its recorded geography denominator; zero preserved; barangay uses membership |
| FHSIS | FIC; 4ANC; basic safe water (remaining existing headlines in detail) | Published rows only; currently unavailable in observed live pages; no fabricated preview |
| Population | PSA 2024 residents | Matched census figure; higher-level partial total and coverage; never StepZero/FHSIS substitution |
| Poverty | PSA SAE 2023 poverty incidence with 90% interval | Citymun only, published estimate; no higher-level extrapolation |

All locations presents source summaries and verified coverage, not these values mislabeled as
national figures. National is an explicit `PH` selection. No new time selector.

BHW `agg_demographics` suppresses barangay cells with 0 < n < 5 (n and percent nulled).
`agg_honorarium` suppresses distribution quantiles/min/max for 1–4 recipients; other extended
aggregates have their own flags. Preserve those flags even where another field remains numeric.
Reuse `applyComplementarySuppression` for breakdowns with one suppressed category, but its
`unprotectable` result is a warning, not permission to publish a reconstructable total.
Withhold conflicting totals/breakdowns from the public response when necessary, including
across cards, details and parent/child totals. Test the composed response in Increment 4/7.
No raw BHW rows, internal documents, admin notes or whole admin `getAreaProfile` payload may
enter the catalog. NHFR/UUC place counts are not person-characteristic cells; preserve zero.
PSA published population/poverty and FHSIS public aggregate values keep their source limitations;
no invented blanket small-cell rule replaces established policies.

## Live evidence, unknowns and next increment

Read-only HTTP GETs on 2026-11-07, without sign-in, returned 200 for:

| Public path under `/bhw-connect` | Observation | What it does not establish |
| --- | --- | --- |
| `/bhw` | Total workforce, validated profiles and accreditation rendered | Every area, raw source reconciliation, or every suppression combination |
| `/facilities` | Facility/type/ownership counts and regional coverage rendered | Complete barangay attribution or equal coverage denominators across datasets |
| `/uuc-phc` | Listed count/share and regional breakdown rendered, including a zero-listed region | Every source record resolved or all assessment measurements usable |
| `/districts` | Mapping, member counts and BHW figures rendered; known gaps disclosed | General six-dataset district aggregation or current-year population |
| `/health-services`, `/health-services/region/01` | “Health services data is not available right now” | Whether load is absent, permissions hide records, or another query/target mismatch exists |

Population and poverty live row availability remains **unverified** in this audit. No direct
production database connection is available in the configured environment. Schema, dictionary,
old load reports and synthetic seed are not evidence of current live facts. The FHSIS operator
check is already prepared in `db/inspect-fhsis.mjs` and documented in `docs/FHSIS_DIAGNOSIS.md`;
its read-only results are still needed before diagnosing a cause. No credentials or source
records were requested or copied into this document.

Increment 2 can proceed without that operator result: use explicit unavailable/failed states.
Existing `getDatasetBySlug`, NHFR/UUC and poverty helpers collapse missing rows and query errors;
FHSIS also returns null for failed probes. A public adapter cannot reconstruct the distinction
from null alone. Extend typed read results at the accepted query boundary, preserving current
callers, instead of inventing a second reconciliation or interpreting null as “not reported”.
Freeze finite indicator inventories (including topic/cadre/type aliases), retain source-specific
coverage, and test error/zero/null/partial/suppressed cases before any overview count is shown.

Validation for this documentation increment is recorded in the plan's progress entry. No new
runtime tests or schema changes are needed for the inventory itself. End-to-end catalog claims
remain reserved for Increment 7.
