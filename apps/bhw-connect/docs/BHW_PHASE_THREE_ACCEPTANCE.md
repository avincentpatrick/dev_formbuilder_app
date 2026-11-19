# BHW Phase 3 — context and closer examination

19 November 2026. Original assessment #525 §6 defines contextual scatterplots,
multi-indicator locality profiles and explainable review lists. Implementation,
required checks, actual deployment and bounded live observations are separate
stages. This document does not claim completion before those stages pass.

## First increment: same-source relationships

Question: how does LGU-reported BHW headcount vary with the population or households
reported by the same accepted Headcount 2025 source? `/bhw/context` plots the current
parent's returned child areas. Overview purpose navigation opens the current scope;
a barangay opens its municipality with that barangay selected. URLs preserve scope,
context and selection. Selecting a point or exact-table link opens locality details,
with links to the full multi-indicator dashboard and supported child scopes.

Reuse: the existing Explore SVG scatter drawing is extracted into
`RelationshipScatter`, used by both existing Explore and the new view. Reuse
`FigureCard`, Spearman rank correlation, approved map reader/response retry,
accepted-release definitions, source metadata, geographic navigation and scoped
dashboard links. No legacy database reader, raw records, migration, new export,
external script or permission expansion. The view requests two approved public
aggregate responses concurrently; it does not refetch them for a table selection.

Both requests select `bhw-stepzero-2026`, publicly named BHW Headcount 2025,
`agg_bhw_stepzero_counts`: `n_total_bhw` against `population` or `households`.
They require matching accepted source/date, parent, child level and complete exact
code/name roster. The accepted source date is 2025-01-01. No fuzzy matching,
spatial join, changing population, national substitution or cross-release fallback.
This is a comparison of the source's reported geography, not certification of every
historical boundary. Separately reported cities remain province-level areas.

Zero stays zero. Unavailable/invalid or source-flagged values are excluded from
pairs and counted explicitly; all returned areas remain in the exact table.
Missing geometry does not exclude an observation. Invalid, duplicate, mismatched,
failed or unsupported responses block the figure. Counts are not averaged into a
parent total. Equal-size dots represent areas, not workers' locations. The chart
states units, source, period, population, exclusions and matching; correlation
requires ten paired places with variation and is not presented as causation,
individual workload or staffing need. Large-area scale can affect both counts.

The existing `lib/db/stepzero.ts` households-per-BHW reader was assessed: it rounds
the ratio and omits nonpositive inputs; its enclosing overview also has a separate
census population path. This increment reuses neither that legacy reader nor its
population fallback. Showing the two reported counts directly preserves zero and
avoids implying assigned workload or an approved staffing denominator.

## Bounded pre-implementation audit

Unmocked public API reads on 19 November used national PH, region 07, province
01028 and province 07022. The returned child counts were 18, 5, 23 and 50. In all
four samples, headcount and each same-release context had matching usable values
for those returned children, common source and date. Values/screenshots were kept
outside this public repository. These are bounded API consistency observations,
not independent source truth or nationwide coverage.

PSA POPCEN 2024 returned values at those four scopes (source date 2024-07-01).
PSA SAE poverty 2023 was navigation-only at national/region scopes and returned
municipal values in the two sampled provinces (source date 2023-12-31). Matching
current codes alone is not proof of historical geography compatibility. Their
presence is not permission to label a mixed-year join as same-year change.

## Remaining Phase 3 work and prerequisites

| Item | Required next evidence/action |
| --- | --- |
| Explainable review lists | Implemented follow-up: separate reporting gaps, reported zero headcounts and below-median headcount with above-median context. Uses at least four eligible pairs for the contrast, raw values, strict inequalities and alphabetical order; overlapping lists are not a score. Checks and delivery evidence belong in its PR. |
| Multi-indicator locality inspection | Relationship details reuse the full locality dashboard for workforce, qualifications and support. Follow-up browser checks cover the selected province, section presence and Back to its relationship/list context. No profile/headcount ratio or duplicate reader is added. Live evidence must verify actual deployed behavior. |
| Other contextual sources | Before enabling PSA population/poverty or NHFR facility relationships, document accepted release, observation dates, source geographic vintage, changes/crosswalk and treatment of unmatched codes. The code owner can audit existing ingestion contracts; missing evidence or source inputs require the established authorized data-supply process. Do not infer comparability from code equality alone. |
| Bivariate map | Requires a justified pair, joint classification/legend, same matched universe and a readable phone/non-color alternative. Evaluate against the actual relationship use case; no unsupported bivariate claim is made by this scatter increment. |
| Worker density/point maps | No approved individual-location dataset is supplied by these aggregate APIs. Requires authorized appropriately de-identified locations, geographic accuracy, disclosure rules and a defensible density denominator; aggregate centroids are not worker locations. |
| Time sliders | Requires at least two comparable accepted observations with documented definitions, populations and boundary matching. Headcount and Profiles are different populations, not consecutive years; no comparable series established here. |

Phase 1 owner-deferred physical-device performance measurement and Headcount reuse
metadata remain open. Prior owner-reported iPhone/Chrome VoiceOver acceptance is
retained; no new physical test is invented. Profiles/boundary licensing does not
grant Headcount reuse terms. Reader evaluation for Phase 4 remains separate.

Verification results and actual delivery belong in the implementing PR. Required
local lint/types/unit/build/extractor and seeded CI must pass. Synthetic browser
cases cover zero, missing, flagged values, exact-table selection, point keyboard
activation, context switching, refresh/Back, failure/retry and invalid scope.
Live checks must repeat bounded source consistency and navigation after the
actual release SHA is observed. No main workflow may be displaced to accelerate
this work.

## Delivery ledger

- [#598](https://github.com/avincentpatrick/dev_formbuilder_app/pull/598): same-source
  scatter/table/navigation and source audit. Required CI 44445978798 passed with
  2,122 seeded unit tests / 13 skipped and 167 browser cases / one skipped. Merged
  `6793a0b2` on 19 November. Consult the PR's delivery note for actual release and
  live observations; a merge is not evidence that it is deployed.
- The review-list follow-up adds no data requests. It uses the chart's joined rows
  and gives every listed place an explicit reason and locality link. Zero and
  unavailable groups are distinct. Equal-to-median values are not flagged, and a
  small peer universe does not generate a comparative list. Nothing here measures
  workload, quality, staffing need or priority.

The later source review found existing reconciliation contracts in
`POPULATION_RECONCILIATION.md`, `POVERTY_SAE.md`, `ingestion/ingest_population.py`
and `ingestion/build_poverty.py`. These document canonical reporting geography,
NIR remapping and residuals, so external context is not wholly blocked by lack of
a join contract. A separate increment should evaluate those accepted inputs with
explicit differing observation dates, residual exclusions and no parent fallback.
The historical documents' legacy fallback descriptions do not authorize a fallback
in the new public relationship view. Poverty back-estimates for 2018/2021/2023
exist, but they do not create comparable BHW workforce history.
