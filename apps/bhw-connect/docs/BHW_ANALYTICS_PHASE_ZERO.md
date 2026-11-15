# BHW analytics implementation baseline

Continuation of draft PR [#525](https://github.com/avincentpatrick/dev_formbuilder_app/pull/525), handoff commit `4c62bd8dac45a97349589df3a9b46b21ecba9421`. The assessment and live-verification documents on that branch remain the requirements; this document adds implementation evidence, not a replacement plan. Source audit: `73affc2752212155847b7885d552932514002cbb`, 2026-11-15.

**Status: Phase 0 remains open.** Disclosure policy confirmation, physical-device evaluation, historical boundary acceptance and representative-device measurements are outstanding. Small fixes to confirmed defects can proceed independently; no phase is certified complete by this audit.

## Evidence and reuse decisions

| Evidence | Classification | Implementation consequence |
| --- | --- | --- |
| Catalog location form and ancestor links omit `dataset`; map back link always uses all-locations | Confirmed defect (L04, source and prior live check) | Retain the selected dataset on PSGC navigation; keep map return scope. District mode has no selected district initially: explain the transition rather than constructing an invalid district/detail URL. |
| `getStepzeroCounts` selects one `(dataset_id, geo_code, geo_level)` row with `maybeSingle`; `getBhwCounts` selects the pinned active profile release and same geography | Existing reusable capability | National headlines read `PH/national`. Never sum all-locations availability records. Headcount unavailable stays unavailable; profiles are separate. |
| `getBhwOverview` includes a PSA population fallback; it caps displayed profiling coverage at 100% through `coverageForDisplay` | Existing capability with different semantics | Reuse headcount/profile fields, not the fallback ratio for the new source-specific map. Explain any coverage drift; no new contextual ratios in Phase 1. |
| Catalog `reader.privacy` checks for any protected cell in the release/table/category or payer before reading local values | Existing disclosure contract | Failed privacy checks fail closed. All-location counts are availability metadata, not workforce totals. |
| Legacy `getDemographics` and `getHonorarium` return local rows/flags; dashboard education removes locally suppressed rows | Disclosure mismatch (L10), not proof of a leak | Do not reuse demographic/payer charts in the new journey until policy is settled. Proposed rule: use catalog-wide withholding throughout the new journey. User question pending. Amount/distribution/sufficiency and generated insights need their own provenance audit before inclusion. |
| Catalog lists 66 BHW indicators; map adapters expose a narrower allowlist | Existing but inconsistent eligibility metadata | Search must use the intersection of source, disclosure and destination eligibility, not simply every catalog definition. See complete inventory below. |
| `GeoSearch` already supports parent chains, PSGC and districts; mixed queries are sent to geographic fuzzy search | Existing capability plus confirmed relevance defect (L03) | Parse supported measure/action intent before geographic retrieval; do not equate a nonempty fuzzy result with success. |
| `DatasetMapCard`, `DatasetCanvas`, `MapLegend`, PNG composition and exact table exist | Reuse, with missing interactions | Extend these, retaining provenance and table rows. Proportional circles, classification state and map controls remain Phase 1 work. |
| `ingestion/boundary_reconciliation.json` lists 34 missing province-level representations and 49 missing city/municipality representations | Committed boundary artifact evidence, not current live coverage | Region VII missing codes are `07306`, `07311`, `07313`; province file `public/geo/provinces/07.json` has only `07012` and `07022`. Corresponding HUC city boundary files are absent. Do not invent an alias or copy Cebu province's polygon. |
| `mapBinLabel` requests 21 significant digits | Confirmed source of L08 | Use consistent presentation precision while keeping adjacent boundaries distinct and classification unchanged; test fine breaks and interval inclusion. |
| Comparison calculations, focus selector, locator component and Explore figures exist | Disconnected capabilities | Reuse after adapter/denominator checks. Four-place editing and phone switching remain acceptance requirements, not already verified features. |

The boundary prerequisite is now a precise data blocker: obtain a versioned, licensed HUC/city boundary release and an accepted crosswalk for the three Region VII codes (and audit the other recorded gaps). Historical correctness cannot be inferred from matching codes. Meanwhile show missing geometry prominently and keep those areas selectable with their exact values; neither totals nor ranks may drop them. No server data load or boundary acceptance is claimed here.

## Headline and measure contracts

Proposed headline order follows the agreed story: **LGU-reported headcount → validated profiles → accreditation (population explicitly named) → any-honorarium receipt**. National values come from the corresponding published national aggregate, never an average of regional percentages. Counts and profile measures are not a time series and are not additive populations.

Both BHW releases have public period **2025**. `bhw-stepzero-2026` is an internal identifier only. The hidden `bhw-profiling-status-2026` release stays hidden across pages, APIs, search and exports. Source title, exact date, license and boundary-version gaps must remain visible rather than being fabricated from slugs.

Inventory rules (apply to every row below):

- `N–M` means national, region, province/HUC representation, city/municipality. `B` adds barangay. Definition levels are capabilities, not proof of observations at all places.
- Counts use the accepted count column; no denominator. Headcount accreditation is `n_registered_accredited / n_total_bhw`. Profile accreditation is `n_accredited / n_total`. Any-honorarium share is the published share among validated profiles. Years active is a published mean, never a mean of means.
- Each of 44 training topics uses `n_trained / n_total`; topics overlap and must not be summed. Three certification figures use their published percentage; catalog carries `n` but no denominator attribute, so the new visual must verify its profile denominator before reuse.
- Six demographic dimensions and four payer receipt indicators require catalog-wide privacy clearance per dimension/payer. Payers overlap. Amount figures are outside this 66-indicator catalog and remain blocked for new composition pending an explicit contract.
- Barangay analysis uses the existing counts-only map allowlist, even where catalog definition levels are broader. Never expose a rate through search just because its definition includes barangay.

| Indicator ID | Source | Unit | Definition levels | New journey eligibility |
| --- | --- | --- | --- | --- |
| `bhw.total` | Headcount 2025 | people | N–M + B | N–M + B counts |
| `bhw.registered` | Headcount 2025 | people | N–M + B | N–M + B counts |
| `bhw.registered-accredited` | Headcount 2025 | people | N–M + B | N–M + B counts |
| `bhw.nonregistered` | Headcount 2025 | people | N–M + B | N–M + B counts |
| `bhw.declared-accreditation` | Headcount 2025 | % | N–M + B | N–M only |
| `bhw.validated-profiles` | Profiles 2025 | people | N–M + B | N–M + B counts |
| `bhw.profile-accreditation` | Profiles 2025 | % | N–M + B | N–M only |
| `bhw.active-years` | Profiles 2025 | years | N–M + B | N–M only |
| `bhw.any-honorarium` | Profiles 2025 | % | N–M + B | N–M only |
| `bhw.training.burns_choking_neck_head_spinal_injuries_poisoning` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.basic_life_support` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.basic_nutrition_malnutrition_nutrition_in_emergencies_and_disasters` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.blood_pressure_bp_apparatus_measurement` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.breastfeeding_lactation` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.chronic_respiratory_diseases_chronic_obstructive_pulmonary_disease_copd_asthma` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.cardiovascular_diseases_heart_attack_stroke` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.child_growth_standard_growth_development` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.cancer` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.cardio_pulmonary_resuscitation_cpr` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.dengue` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.diabetes` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.disaster_risk_assessment_disaster_risk_preparedness` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.early_childhood_care_and_development` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.emergency_reponse_rescue` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.emerging_and_re_emerging_infectious_diseases` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.environment_sanitation_solid_ecologic_waste_management` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.filariasis` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.fire_safety` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.flu` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.food_preparation_food_safety` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.family_planning_responsible_parenthood` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.first_1000_days` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.hepatitis` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.hiv_aids` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.healthy_lifestyle` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.infant_and_young_child_feeding_practices` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.leprosy` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.mental_health` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.measles` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.malaria` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.maternal_care` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.others_please_specify` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.pneumonia` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.polio` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.rabies` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.standard_first_aid_training` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.sexually_transmitted_disease` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.traditional_alternative_herbal_medicine` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.tuberculosis` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.uhc_phc_f1_plus_for_health_sdn` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.water_sanitation_and_hygiene_wash` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.women_s_health` | Profiles 2025 | % | N–M | N–M only |
| `bhw.training.zero_open_defecation_zod` | Profiles 2025 | % | N–M | N–M only |
| `bhw.certification.tesda_nc2` | Profiles 2025 | % | N–M + B | N–M, verify denominator; not mapped |
| `bhw.certification.tesda_certified` | Profiles 2025 | % | N–M + B | N–M, verify denominator; not mapped |
| `bhw.certification.ref_manual_trained` | Profiles 2025 | % | N–M + B | N–M, verify denominator; not mapped |
| `bhw.demographics.sex` | Profiles 2025 | people | N–M + B | Privacy clearance required; no new chart pending decision |
| `bhw.demographics.civil_status` | Profiles 2025 | people | N–M + B | Privacy clearance required; no new chart pending decision |
| `bhw.demographics.bloodtype` | Profiles 2025 | people | N–M + B | Privacy clearance required; no new chart pending decision |
| `bhw.demographics.education` | Profiles 2025 | people | N–M + B | Privacy clearance required; no new chart pending decision |
| `bhw.demographics.ip_status` | Profiles 2025 | people | N–M + B | Privacy clearance required; no new chart pending decision |
| `bhw.demographics.age_band` | Profiles 2025 | people | N–M + B | Privacy clearance required; no new chart pending decision |
| `bhw.honorarium.region` | Profiles 2025 | % | N–M + B | Privacy clearance required; no new chart pending decision |
| `bhw.honorarium.province` | Profiles 2025 | % | N–M + B | Privacy clearance required; no new chart pending decision |
| `bhw.honorarium.citymun` | Profiles 2025 | % | N–M + B | Privacy clearance required; no new chart pending decision |
| `bhw.honorarium.barangay` | Profiles 2025 | % | N–M + B | Privacy clearance required; no new chart pending decision |

## Route and state mapping before changing links

| Purpose | Existing route/component to reuse | State contract |
| --- | --- | --- |
| Overview | `/bhw`, `StatHero`, `StatTile`, `DenominatorExplainer`, map card | Default national Headcount 2025. Preserve catalog methodology at `/catalog?mode=all&dataset=bhw`. Move the BHW portal entry only when the overview's disclosure contract is ready. |
| Workforce distribution | `/explore` and existing geographic figures | Explicit headcount/profile release and selected geography; ranked bars and table use the same accepted observations as the map. |
| Qualifications/training | `/explore` certification/training components | Visible population and topic. No demographic route promoted until disclosure review. |
| Support/compensation | `/explore` honorarium components | Any-honorarium share initially eligible; payer/amount views conditional on disclosure and denominator reconciliation. |
| Locality | `/place/[level]/[code]` and existing parent lookup | Resolve actual parents using `dim_geo`; do not infer HUC parentage by slicing codes. Barangay destinations retain counts-only eligibility. |
| Compare places | `/compare?geos=…`, existing comparison calculations | Two to four explicit places, same compatible measure/period; retain all places on phone switch. No silent truncation of a fifth place. |
| Map explorer | `/map`, existing `MapChoice`, parser, serializer | Retain `dataset`, `indicator`, `period`, `level`, `code`, `selected`. Add validated mode/filter/classification fields in the range-controls increment; old URLs remain valid. |
| Data/methodology | `/catalog`, `CatalogDetails` | Retain dataset and geography for PSGC forms/ancestors. “All datasets” is the deliberate way to clear dataset. |

Shared URL additions must be optional and validated at the boundary: visual mode, finite filter bounds, classification method/count/breaks, comparison places. Duplicate or unsupported values yield an explanation. Cross-route adapters must explicitly report incompatible settings. Local map drill state currently lives in component state; the return link must follow that live state rather than just server props. Back/forward restoration and all-setting exports remain open until implemented and tested.

Initial search grammar: `place`, `indicator`, `place indicator`, `compare place and place [and place …] indicator`. Match measure aliases before fuzzy place lookup; accreditation alone requires choosing LGU-reported or validated-profile accreditation. Duplicate place names require parent-chain selection. Unsupported prose, empty results and failed requests have distinct feedback. Do not introduce unrestricted conversational analytics.

## Layout and numerical budgets

Desktop overview: title/source + search; purpose navigation; compact headline row; distribution map preview next to ranked values; qualification/support summaries; methodology disclosure. Phone: same order in one column, short headline cards, map/table tabs with an always-accessible legend. No essential warning belongs only in collapsed methodology. AI text loads independently of the first useful visual.

Desktop map: use available page width, compact title/measure controls, map next to selected-place details and legend, linked ranks/table below. Phone map: compact header, map/details/table tabs or sheets, visible legend and reset controls. Locator is geographic orientation only. Circle area represents count; symbols represent aggregates, not worker locations.

Budgets below are engineering regression targets set before Phase 1, **not measured production percentiles**:

| Measure | Desktop target | Emulated phone target | Measurement |
| --- | ---: | ---: | --- |
| First meaningful non-AI visual or honest unavailable state | ≤3 s | ≤5 s | Navigation start to rendered figure/status; exclude AI completion |
| Search response after debounce | ≤1 s | ≤1.5 s | Query dispatch to results/empty/error; report debounce separately (≤300 ms) |
| Map ready (cold / warm) | ≤7 s / ≤4 s | ≤10 s / ≤6 s | Navigation start to `data-map-state=ready`; failures never count as ready |
| Filter/classification response after data load | ≤200 ms | ≤300 ms | Input to updated visual/table state |
| Ordinary horizontal overflow | 0 px | 0 px | 1440×1000 and 390×844; labeled table scrollers exempt |

Use at least five cold and five warm runs per test configuration and report each observation plus median/max, device/browser, network and CPU settings. Phone regression profile: mobile/touch, 390×844, 4× CPU slowdown, 150 ms latency, 1.6 Mbps down / 750 Kbps up. Physical phone runs are a separate acceptance gate. Revisit budgets using actual devices without calling proxy timings representative.

Existing live baseline is the handoff's 6.47/3.33/3.41 s map-ready sequence, unthrottled. A targeted continuation check on 2026-11-15 using system Chromium at 390×844 reached the national headcount map-ready marker in 5.947 s; ordinary content did not overflow; there were no unnamed buttons in that sampled page; the map canvas was `aria-hidden=true`. This single run adds no national, performance-percentile or screen-reader certification.

Accessibility acceptance: keyboard-complete search/disambiguation, classification and map alternatives; visible focus, named inputs, live loading/error status; no color-only meaning; zero serious/critical automated axe findings on changed flows. A map's hidden canvas is acceptable only with equivalent selectable places, values and actions outside it. Baseline manual/automated accessibility coverage is incomplete; record failures instead of assuming zero. Physical touch and screen-reader checks require real devices/readers.

## Remaining gates and review slices

1. **Phase 0:** confirm new-journey disclosure policy; record targeted keyboard/accessibility baseline; obtain representative device measurements and physical-device/user evaluation. Retain explicit boundary/license blockers. Inventory above covers all 66 definitions, not all live observations.
2. **Prerequisite repair PR:** fix confirmed catalog context loss without changing disclosure or routes; regression-test real form serialization and ancestor navigation. This answers “can I keep BHW while choosing my location?”
3. **Phase 1 overview PR:** reuse existing headline/visual components after disclosure gate; meaningful visual or unavailable state, source-specific populations, purposeful navigation and availability as supporting information. No claim that the whole phase is done.
4. **Phase 1 search/comparison PRs:** grammar, global place retrieval, explicit ambiguities; two-to-four-place editing and retained phone/shared state. Synthetic multiple-region and repeated-name cases required.
5. **Phase 1 map PRs:** headcount circles, visible coverage gaps with Region VII fixture, rounded nonoverlapping legend labels, labels/locator/scale/navigation, then filter/classification/state/export consistency. Boundary supply remains separately blocked until verified.

Reader evaluation proposal: one general reader, one BHW/LGU reader and one analytical reader each perform “find another-region locality,” “explain headcount versus profiles,” “compare two places,” and “share a filtered map” on phone and desktop. Record completion, wrong interpretations and obstacles without personal records. Recruitment and physical-device sessions remain pending; do not substitute emulator screenshots for them.
