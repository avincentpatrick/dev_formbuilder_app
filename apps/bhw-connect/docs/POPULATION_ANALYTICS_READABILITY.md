# Population analytics and readability

20 November 2026. Baseline `bb426099` (BHW implementation through #609).
Applies the BHW Phase 0–3 approach and Poverty pilot's shared presentation pattern
without treating their populations, denominators or publication rules as Population rules.
Historical #525/#527 remain open drafts. Later catalog inventory and accepted reader
contracts take precedence over old reconciliation prose and fallback helpers.

## Phase 0 — accepted data contract

Reader questions: how many people are recorded for this place; which year does the
count describe; is it complete; where are the exact figures and source limits?

| Item                    | Accepted contract                                                                                                                                                                                                                                                                                          |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Measure                 | `population.residents`, `agg_population.population`, source `psa-popcen-2024`, `census_year=2024`, unit people. One count, no household/age/sex breakdown or new rate.                                                                                                                                     |
| Population and dates    | Accepted PSA 2024 Census of Population rows matched to reporting geography. Reference year stays 2024; actual dataset source/update dates remain in the source record. Neither deployment date nor today's date changes the reference year.                                                                |
| Denominator             | None for this count. StepZero programme population and FHSIS projections remain separate source-specific inputs. No new BHW ratio or 2023 poverty count is derived.                                                                                                                                        |
| Geographic eligibility  | Accepted national/region/province/citymun rows only. Parent totals are coverage-qualified partial totals; do not label them as PSA's published full-area totals. Manila's published city count is at province node 13806 only. No repetition at child district/citymun nodes and no barangay substitution. |
| District aggregation    | Existing `combineDistrictIndicator` allows Population only for complete compatible non-overlapping mapped constituents with verified mapping. Split-city municipal counts are not allocated to barangays. Display its existing aggregation and coverage; never reconstruct a total in the UI.              |
| Missing and publication | Available/partial values remain separate from unavailable, failed, suppressed and unsupported states. Zero remains a count. No national or parent fallback, suppression recovery, new grant or transfer of BHW-specific publication exceptions.                                                            |
| Other years/details     | Stored 2020 census data are not an accepted trend in this card. Household data are documented as not loaded. No additional measure, time slider or demographic category is inferred.                                                                                                                       |

Evidence: `lib/catalog/definitions.ts`, `catalog.ts`, `reader.ts`, `district-catalog.ts`,
`docs/UNIFIED_DATASET_CATALOG_INVENTORY.md` Population section,
`docs/POPULATION_RECONCILIATION.md`, and `ingestion/ingest_population.py`.
The ingestion sums matched rows using reporting parentage and preserves the Manila
node. Historical reconciliation counts are not current live coverage certification.
Its old child-area fallback description is superseded for this card by the later
catalog contract and tests. `lib/db/population.ts` is a legacy BHW denominator helper,
not the reader for this card.

Current/open work: #608/#609 delivered the Poverty pilot; no Population implementation
PR was open at this audit. #528 is the older catalog-context proposal, #420 the historical
catalog plan. The latest main change only updates Meridian bookkeeping; it does not
change BHW's instructions or source readers. No competing card work is overwritten.

## Increment 1 — Phases 1 and 2

Reuse the existing scoped catalog, `FigureCard`, `StatValue`, `formatCount`, map link,
source notes and exact table. Show the accepted count with its year and people unit.
Place **Partial total** and coverage warnings beside parent figures; retain the Manila
exception and original reader reason/coverage. Share the definition through
`measureDefinition`, so overview and exact-data descriptions agree.

Use named native disclosures for secondary coverage details and full exact data,
availability and sources. Essential coverage and comparison limitations stay visible.
No extra requests, hooks, new chart, route, source calculation, dependency or migration.
A single count does not need a distribution chart. Existing map and exact-data alternatives
remain reachable. Presentation guards reject incompatible sources/units, non-integer
counts, multiple observations and rejected-state payloads.

## Phase 3 — navigation and interpretation

Preserve selected geography, dataset, refresh, browser Back/Forward and the existing
Population map destination. Do not add parent and child populations together, mix
reference years, imply current population or infer causes from cross-area differences.
This increment does not generalize BHW-only comparison builders or claim new Population
map history/snapshots. A later geographic comparison increment requires an audit of
child completeness, overlap, Manila and source-vintage matching before implementation.

## Phase 4 — verification and remaining work

Rendered/model checks: zero, missing/failed/withheld/unsupported sentinel payloads,
wrong release/unit, invalid counts, parent partial labels, Manila-only exception,
approved district-sum guard, shared definition and retained exact table/map link.
Existing catalog/district tests verify accepted 2024 filtering, no StepZero/parent
substitution and complete compatible district inputs. Browser journeys cover phone
and desktop widths, keyboard disclosures, locality changes, refresh/Back/Forward,
source accessibility and map href. Required full checks and seeded CI gate merging;
actual deployment SHA and bounded live evidence belong in the implementing PR.

Representative-reader review is prepared, not performed: general reader, BHW/LGU reader
and analyst each locate a place, explain census year versus today, distinguish partial
from full coverage and zero from unavailable, find exact data/source, and return to the
same locality. Include a physical phone and keyboard/screen-reader user; record confusion
and assistance without personal information or live values in this public repository.
Automated browsers do not establish human comprehension or physical-device acceptance.
The owner-deferred BHW Phase 4, performance, Headcount metadata and conditional data
prerequisites remain open. This pilot does not sign off a generalized standard.

### Reusable implementation and history references

- [Catalog definitions](../lib/catalog/definitions.ts), [accepted reader contract](../lib/catalog/catalog.ts)
  and [catalog regression cases](../lib/catalog/catalog.test.ts): foundational
  [#422](https://github.com/avincentpatrick/dev_formbuilder_app/pull/422).
- [District aggregation](../lib/catalog/district-catalog.ts) and
  [district regression cases](../lib/catalog/district-catalog.test.ts):
  [#425](https://github.com/avincentpatrick/dev_formbuilder_app/pull/425).
- [Scoped catalog and exact data](../components/catalog/catalog-results.tsx):
  [#427](https://github.com/avincentpatrick/dev_formbuilder_app/pull/427), with the
  later [#608](https://github.com/avincentpatrick/dev_formbuilder_app/pull/608)
  readable-detail pattern reused here.
- [Population overview](../components/catalog/population-overview.tsx),
  [shared explanations and display guard](../lib/catalog/population-reading.ts),
  [rendered checks](../components/catalog/population-overview.test.tsx) and
  [browser journey](../e2e/population-overview.spec.ts) implement this increment.

Candidate verification: lint (one existing intentional-fixture warning), types,
2,129 unit tests / 55 database-dependent skips, migration lint and prefixed standalone
build/extractor passed. Seven browser journeys passed against the final build at
390/1440px using private synthetic fixtures. Eight synthetic scope/state cases at each
width covered parent partial totals, zero, missing, failed, Manila, its missing child,
and unsupported barangay scope. Expanded exact-data axe scans reported no WCAG A/AA
violations, page errors or ordinary horizontal overflow. Phone/desktop visuals were
reviewed; repeated parent coverage explanations were consolidated without changing
the source reason retained in exact data. Seeded CI and actual deployment/live evidence
remain separate delivery gates, recorded in the implementing PR.

Delivery and final evidence:
[PR #611](https://github.com/avincentpatrick/dev_formbuilder_app/pull/611).

## Increment 2 — searchable map details and scoped return

Baseline: #611, live `84f3c192b54b380bde34b3009fcf160691ebe154`.
No competing Population implementation PR was open at this audit; #525/#527/#528
remain historical drafts. Read the current map reader before extending the view:
`app/api/dataset-map/route.ts` applies the accepted release, `census_year=2024`,
source geography and authoritative child membership to both parent and child reads.
It does not rebuild the parent's total from the displayed children. No new source,
join, allocation, denominator, trend, grant or aggregate is introduced here.

Reader question: which named areas have accepted counts, which are unavailable,
and how can I open one area's exact sources then return to the same map?
Reuse `DatasetMapCard`'s existing table, `searchMapAreas`, count formatting, selectors,
map parser and native browser history. Population's area table is alphabetical;
search filters the selector and table only, never the map, stored total or download.
Clearing search restores all authoritative rows, including missing values/shapes.
Zero remains zero. Counts use readable grouping; labels reuse the overview's
Population coverage helper. Parent figures and region/province rows are partial;
Manila's province-node exception is explicit. No ranked need score or inferred
coverage percentage is calculated. These are source-qualified side-by-side counts,
not a standardized peer comparison or causal relationship.

A bounded, scope-specific search travels in shared URLs and validated map snapshots.
Selection edits use the existing workspace history mechanism. Both map detail and
header links open the selected child's catalog scope; ancestor navigation retains
an independent return snapshot. Changing geographic scope clears an irrelevant
search. Other releases cannot become Population return snapshots. The small
`mapDetailsHref` helper is shared with Poverty; its existing tests protect Poverty's
scope/search behavior. Embedded maps do not take ownership of page history.

Verification mapping: `population-navigation.test.ts` covers snapshot validation,
search bounds/scope, shared navigation, and geographic labels; the existing overview
and map route tests cover accepted sources, zero/missing and rejected payloads.
`population-map-details.spec.ts` covers synthetic zero/missing/long-label rows,
alphabetical order, search/no results/clear, refresh/share, keyboard table access,
Back/Forward and details/ancestor/map return at 390/1440px, plus partial provinces
and the Manila exception. Existing Poverty tests run against the shared return helper.
No production records enter git. Full checks, actual release and bounded live
verification are recorded in the implementing PR. Representative-reader and physical
device acceptance, trend prerequisites and all previously deferred work stay open.

Increment 2 delivery and final evidence:
[PR #612](https://github.com/avincentpatrick/dev_formbuilder_app/pull/612).
