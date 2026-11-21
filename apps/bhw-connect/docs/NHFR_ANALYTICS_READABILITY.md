# NHFR analytics and readability

## Assessment — 21 November 2026

Owner selected NHFR / Health facilities to execute the workflow in
[#616](https://github.com/avincentpatrick/dev_formbuilder_app/pull/616).
Base: `16a83e57f4d7bcbf4957aef13b81976f3be641a5`. The open-PR review found no
competing NHFR implementation. #525/#527 are historical draft assessments;
accepted BHW Phase 1–3 and readability records supersede their proposed rules.
Poverty import #615 is independent and remains open.

Reader question: **What facilities are recorded in this place, when, and what
does their presence tell me?** General readers need the count/date and limits;
LGU/BHW readers need locality and attribution; analysts need exact measures,
source identity, missing states and compatible geography.

## Source and field worksheet

Accepted source: DOH National Health Facility Registry, `nhfr-2026-09`, public
export `nhfr_universe_as_of_September_2026.xlsx` from https://nhfr.doh.gov.ph,
retrieved 2026-09-05. Snapshot label September 2026; stored reference date
2026-09-01. These are not today's observations or deployment dates. A separate
publisher release date is not established. No new download or import is needed.
The existing owner decision permits public use with citation; display source
license metadata as supplied, without inventing broader reuse terms.

Evidence: [original source audit](NHFR_2026_PLAN.md),
[catalog inventory](UNIFIED_DATASET_CATALOG_INVENTORY.md),
`ingestion/clean_nhfr.py`, `lib/db/nhfr.ts`, `lib/catalog/definitions.ts`,
`lib/catalog/reader.ts`, `lib/catalog/barangay.ts` and
`lib/catalog/district-catalog.ts`.

| Data point / stored fields | Meaning, population and public use |
| --- | --- |
| `n_facilities` / `nhfr.facilities` | Count of unique registered places in the accepted snapshot; no person denominator. Zero requires a successful accepted read. Not staffing, visits, quality or sufficient service capacity. |
| `n_government`, `n_private` | Facility ownership categories, each in facilities. Optional ownership shares use the same area's total facilities; not resident shares. |
| `n_barangay_health_station`, `n_rural_health_unit`, `n_hospital`, `n_birthing_home` | Four selected types; aliases of the corresponding `agg_nhfr_by_type` rows, not additional measures or an exhaustive partition. |
| `agg_nhfr_by_type.facility_type`, `n_facilities`, `n_government`, `n_private` | Complete type distribution and ownership within type. Sparse rows alone cannot establish zero; require successful total and complete type read. |
| `total_bed_capacity`, facility `bed_capacity` | Recorded beds, not facilities or currently available beds. Cleaning maps blank capacity to zero, so the stored total cannot establish completeness of capacity or actual absence of beds. Never derive occupancy. |
| `n_barangays_with_facility`, `n_barangays` | Distinct barangays with attributed facilities / all barangays in the selected reporting geography. Facility presence, not reporting completeness, travel access, quality or adequacy. A zero denominator has no percentage. |
| `coveragePct`, `coverageFraction` | Legacy derived representations of the preceding measure, not extra indicators. Overview should use the raw numerator/denominator with shared formatters; never average area percentages. |
| `facility_code`, `facility_code_short`, `facility_name` | Registry identity, not people. Public list uses full name and unique code; short code is stored but not needed in the new overview. |
| `facility_major_type`, `facility_type`, `ownership_major`, `ownership_sub` | Source categories. Major type and ownership subtype are stored detail, not new headline measures. Unknown categories retain source wording. |
| `geo_code`, `barangay_geo_code` | Resolved municipality and optional barangay attribution. Unassigned facilities remain in municipal totals. No inferred barangay assignment. |
| `source_{region,province,citymun,barangay}_{psgc,name}` | Source-vintage lineage; public navigation uses canonical geography. The existing list also displays source barangay name. Names alone do not certify attribution. |
| `service_capability` | Stored source description, not an audited service-availability measurement; no new public analytics use. |
| `licensing_status`, `license_validity_date` | Source status/date. Missing means not stated, never unlicensed. No licensing percentage or current compliance verdict; validity date not added to overview. |
| `id`, `dataset_id` | Internal identity/join metadata, no additional measure. |
| Contacts, street/building address, postcode, old names, website | Excluded from ingestion/public projection; no new props, markup, export or plots may introduce them. |

## Geography and states

National/region/province/municipality use accepted aggregate rows. Canonical
parentage includes the documented Sulu mapping to BARMM despite source Region IX
names. Barangay adapters count attributed facility records only. District adapters
sum compatible non-overlapping members; split-city contributions exclude unassigned
facilities. Retain partial state, coverage and reasons beside any displayed count.
Never allocate a municipal total to a barangay or manufacture a per-resident ratio.

Unavailable, failed, withheld and unsupported states stay distinct from zero.
“All locations” is availability discovery, not a national total. Incompatible
release/unit, malformed counts and multiple observations cannot form a headline.
Missing geometry leaves exact rows accessible; existing points represent barangay
centroids, not surveyed facility or worker locations.

## Current work and dispositions

| Existing work | Disposition |
| --- | --- |
| Catalog selection, canonical geography, guarded reads, nine NHFR measures, exact table/source dates, full map link | Delivered; reuse. |
| `/facilities` and region/province/municipality dashboards, types, child tables, facility lists, map/downloads | Delivered; inspect and improve rather than add parallel readers. |
| Plain-language selected-card overview | Unfinished: first implementation increment. Lead with facilities, beds and barangay presence; ownership/types remain in exact detail without duplicate type tiles. |
| Legacy empty facility/type reads return `[]` | Conflict: can describe a failed read as no facilities. Detail increment must carry success/failure distinctly and test failure, true zero and partial lists. |
| Legacy presence wording “none” / “no ... at all” | Conflict with missing attribution. Detail increment must qualify the claim consistently. |
| Existing list search, scoped map-return/history | Assess in the detail increment; preserve full names, all rows and selected scope. |
| New ratios, peers, time slider, workforce assignment or need ranking | Not justified by this source audit; no implementation authorized by the handoff. |

## First increment and verification

Reuse FigureCard, StatValue, shared measure definitions and the current catalog
contract. Add an explanatory NHFR overview; keep exact data/source notes in a named
keyboard-accessible disclosure. Essential date, unit, partial-coverage and attribution
limits remain outside disclosures. No schema, source observation or reader change.

Unit/render tests: accepted zero, partial totals, absent/zero/invalid denominator,
failed/unavailable/suppressed/unsupported states, all-locations, incompatible
release/unit, malformed/multiple values, absence of rejected sentinel values,
shared definitions, exact data and existing navigation. Browser tests: 390/1440px,
selected scope, native disclosure keyboard access, reload/history and overflow.
Required local lint/types/full unit tests, migration lint, prefixed production build
and appropriate browser checks precede PR/CI. Record database skips honestly.

Baseline: latest production Deploy run
[44745549130](https://github.com/avincentpatrick/dev_formbuilder_app/actions/runs/44745549130)
completed successfully for main `16a83e57`. An unmocked HTTP request to the live
NHFR catalog returned 200 with CSP on 21 November. This establishes reachability,
not populated-source or release verification. Further live checks and release
logs must be recorded after merge before the next implementation increment.

## Delivery ledger

Assessment prepared; first implementation in progress. No merge/deploy/import or
representative-reader acceptance is claimed. Record local results, PR/CI, merge
and actual release SHA, then bounded live navigation/figure checks here.

## Remaining work

After first increment is deployed and verified: legacy list/type failure states,
qualified attribution wording and justified search/navigation improvements in a
separate increment. Adapt the BHW reader protocol to finding a place, explaining
snapshot/count/presence, distinguishing zero from missing, finding exact data and
returning to scope. Physical phone/screen-reader and representative-reader evidence
remain open; browser emulation is not that acceptance. Existing BHW Phase 4,
performance and Headcount reuse prerequisites and Poverty import #615 remain open.

### First increment local evidence

- Lint passed (one existing warning in the deliberate base-path defect fixture);
  typecheck passed; full Vitest: 2,160 passed, 56 database-dependent skips.
- Migration lint passed. No migrations or source records changed.
- Bounded pre-change live Chromium check at 390px: national PH, region 01,
  municipality 0102801 and barangay 0102801001 each exposed selected scope and
  an exact table, without page overflow. No production observations committed.
- React review: server-rendered overview, native disclosures, direct imports,
  no additional fetches/client state/dependencies. Shared formatting and definitions.
- Production build and seeded browser verification in progress; CI/deployment
  remain pending. These results do not establish human comprehension acceptance.
