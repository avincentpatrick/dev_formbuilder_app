# BHW Phase 3 — context and closer examination

19 November 2026. Original assessment #525 §6 defines contextual scatterplots,
multi-indicator locality profiles and explainable review lists. Implementation,
required checks, actual deployment and bounded live observations are separate
stages. This document does not claim completion before those stages pass.
The current delivery notes in [#598](https://github.com/avincentpatrick/dev_formbuilder_app/pull/598),
[#599](https://github.com/avincentpatrick/dev_formbuilder_app/pull/599) and
[#600](https://github.com/avincentpatrick/dev_formbuilder_app/pull/600) record actual
release SHAs, checks and live observations. The final Phase 3 reconciliation in
#600 supersedes implementation-only status snapshots below; pending or failed
checks are never treated as passed.

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
| Other contextual sources | The context-options follow-up implements PSA population/poverty using the existing documented ingestion reconciliations and explicit dates, scope restrictions and residual exclusions. Additional facility relationships need the separate prerequisites below. |
| Bivariate map | The context-options follow-up implements a same-source joint median map with the identical complete-pair universe, a two-axis legend and exact per-area group table. External poverty remains outside workforce maps. |
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
- [#599](https://github.com/avincentpatrick/dev_formbuilder_app/pull/599): review lists and locality-profile journey. Required CI 44447085811 passed with 2,125 seeded unit tests / 13 skipped and 169 browser cases / one skipped. Merged `ca597d27` on 19 November; actual deployment/live evidence belongs in its delivery note.
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

## Audited context and joint-map follow-up — #600

The context-options follow-up implements the documented source matching instead
of declaring all external context unavailable. Headcount remains the accepted
2025 LGU-reported count. PSA population explicitly uses POPCEN 2024 (source date
2024-07-01); poverty explicitly uses SAE 2023 (2023-12-31). Labels and captions
state both periods. These are cross-sectional comparisons of differently dated
observations on canonical reporting geography, never same-year conditions,
workforce changes or causal findings. No per-capita ratio is introduced.

The API reads only the accepted source/year filters already used by the public
map contract. `ingestion/ingest_population.py` documents province-scoped matching
of source names, NIR parentage and rollups from matched municipal leaves. The
view does not repeat name matching or claim that these matched reporting totals
are PSA's published national totals. Municipalities absent from the BHW reporting
roster are outside its universe. No barangay population or Manila-district parent
fallback is introduced.

`ingestion/build_poverty.py` documents source-vintage code and scoped-name mapping,
NIR remapping, overrides and unmatched records. Poverty is offered only among
municipal peers in a province scope; no province/national rate is manufactured.
The Manila province `13806` is not offered because the source and reporting
systems group its districts differently. Known HUC, SGA, Pateros and Kalayaan
source gaps remain unavailable. Published 90% confidence bounds remain visible,
including bounds outside 0–100%; they are not clamped. Only estimates inside the
valid 0–100% domain can enter the plot. Percentages use the shared series formatter
and calculations keep raw precision. The existing decision to keep poverty out
of workforce maps is retained: external contexts use the scatter view.

For same-release population/household counts, the optional **Two-variable map**
reuses DatasetCanvas and the exact same complete pairs as the scatter and review
lists. Two raw median splits create four joint groups, A–D. Values equal to a
median belong to the lower group. The joint table legend names both thresholds,
units and group counts; an exact per-area group table provides a keyboard/non-color
alternative alongside the source-value table. A minimum of four eligible pairs
is required. Empty groups and all-zero values are handled explicitly. Missing or
flagged pairs have no group; eligible areas lacking a shape remain in both tables.
Colours are exploratory groups, never a workload, quality or staffing-need score.
The shared URL retains view, context, geographic scope and selected locality.
An invalid external-context/bivariate URL is refused, not silently changed.

### Conditional data prerequisites retained

- **Worker point/density mapping:** aggregate counts do not contain approved worker
  locations. Needs an authorized, appropriately de-identified location dataset,
  accuracy/disclosure rules and a defensible density area/population basis. The
  data owner/operator must supply accepted private inputs through the existing
  process; aggregate centroids cannot substitute. No location-data request or new
  export permission is implied by this UI work.
- **BHW time slider:** needs at least two accepted comparable workforce observations,
  documented measurement/population definitions and reconciled geographic vintage.
  Headcount and Profiles are distinct 2025 populations. Existing poverty back-
  estimates do not create BHW history. No workforce trend is fabricated.
- **Additional facility relationships:** require an explicit facility definition,
  accepted reference date and geographic/attribution audit against workforce scope.
  A facility count is not evidence of BHW assignment or service workload. Population,
  household and socioeconomic contexts cover the initial Phase 3 question; no
  unevaluated NHFR relationship is claimed here.

Required checks, release SHAs and bounded live verification for each increment
must be added to the delivery ledger/PRs. These implementation notes alone do not
sign off Phase 3, certify nationwide source truth or close the deferred Phase 1
performance and licensing items.
