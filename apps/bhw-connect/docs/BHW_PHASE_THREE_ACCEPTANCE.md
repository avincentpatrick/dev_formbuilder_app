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
| Explainable review lists | Implement transparent named criteria using the same eligible universe, show why each locality appears, separate missing reporting from low recorded headcount, avoid a composite need score; verify phone/desktop navigation and exact values. |
| Multi-indicator locality inspection | Connect the same-source relationship details to the existing scoped qualification/support dashboard; verify its scope and return journey, and add supporting same-source indicators where useful. |
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
