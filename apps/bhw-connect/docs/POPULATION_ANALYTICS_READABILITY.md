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
