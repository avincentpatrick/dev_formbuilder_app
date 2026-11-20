# Poverty analytics and readability

19 November 2026. Baseline: main `2bcf33ab` (#606). Follows the historical
analytics roadmap #525, Phase 0 audit #527, BHW acceptance records and
`BHW_READABILITY_INCREMENT.md`. Those historical draft PRs remain unmerged.
This is a Poverty-specific application, not Phase 4 acceptance of a universal standard.

## Phase 0: source and current implementation assessment

Reader questions: what does the estimate for my municipality mean; how uncertain
is it; which places can be compared; where are the exact values and source limits?

| Data point                | Accepted meaning and contract                                                                                                                                                                                                                                                                            |
| ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Poverty incidence         | `poverty.incidence`; PSA SAE 2023, `psa-sae-poverty-2023`, `agg_poverty`, `sae_year=2023`. Estimated percentage of people below the poverty threshold, not households or an exact poor-person count. No supplied numerator/denominator for deriving counts. Valid estimate domain 0–100.                 |
| Published interval        | `ci_low` / `ci_high`, 90% confidence bounds; percentage units. Preserve published bounds outside 0–100, never clamp. Missing or reversed bounds do not justify fabricating an interval.                                                                                                                  |
| Source metadata           | Actual source name, reference date, update date and license from accepted metadata. Unknown remains unknown. Never use deploy time as source freshness.                                                                                                                                                  |
| Geography                 | Municipal observations only; no unweighted parent rates or barangay estimates. Existing ingestion reconciles source-vintage PSGC and scoped names to reporting geography. Manila groupings differ; HUCs, SGA, Pateros and Kalayaan have documented gaps. Scope is not nationwide coverage certification. |
| Other stored fields/years | Ingestion stores CV, SE and 2018/2021 back-estimates. They are not additional accepted public catalog measures and are not newly exposed here. A trend requires a separately accepted comparison contract.                                                                                               |
| States/publication        | Public aggregate source; retain available/partial/unavailable/failed/suppressed/unsupported. Zero is a reported estimate, not absence. BHW's aggregate-publication exception and individual privacy thresholds do not transfer to Poverty. No source-null recovery, raw records or new grants.           |

Evidence: `lib/catalog/{definitions,types,catalog,reader}.ts`, `lib/maps/poverty.ts`,
`docs/POVERTY_SAE.md`, `ingestion/build_poverty.py`. Historical population fallback
or BHW density readers are not used. No unexplained source code is relabelled.

Existing: accepted-source catalog, scoped location controls, municipal poverty map,
exact table, source metadata, and BHW's audited contextual scatter (#600).
Gap: Poverty's card opens an availability table without an explanatory main story.
The shared definition mentions uncertainty but does not explain precision or guard
against interpreting estimated differences as demonstrated differences.

Open PR review: #607 changes map-only rendering and exploration; this work does not
edit that renderer. #528 is an old catalog-continuity draft; main already has later
continuity work. #420 is historical catalog planning. No Poverty implementation PR
was open at audit time. #476 concerns the separate FHSIS operator load.

## First increment: Phase 1 overview and Phase 2 readable detail

Add a scoped explanation to the existing Poverty catalog detail journey. Reuse
FigureCard, StatValue, percentageFormatter, the already-loaded public catalog,
location controls and full-map link. Put the exact table/availability/source record
inside a named native disclosure; essential interpretation remains visible.
One shared definition serves both overview and exact-data descriptions.

The estimate and interval form one related percentage series. No extra request,
new source, migration, dependency, calculation or publication rule. Unsupported
levels remain unsupported and missing values remain unavailable. No mean, median,
box plot or worker-style composition is appropriate for this one public measure.

## Phase 3: navigation and interpretation

Retain existing catalog URLs and geographic controls, refresh/Back and all-data
return links. Map navigation remains the existing separately scoped workspace;
this increment does not claim to add persistent Poverty map snapshots or generic
BHW comparison support. Never pass a Poverty measure into BHW-only comparison
builders. A municipal comparison/search increment must separately audit approved
rows, uncertainty, actual parentage and source matching. No need score or causal
claim. No worker-location map or workforce time series is introduced.

## Phase 4: evidence and remaining work

Targeted rendered/model checks cover zero, all rejected states with sentinel
payloads, incompatible units/releases, unsupported scopes, absent/reversed and
out-of-range published intervals, exact-data retention and shared explanations.
Browser checks must cover phone/desktop, keyboard disclosures, long names, scope,
refresh/Back, sources, map entry and no ordinary horizontal overflow.
Required lint/types/full unit suite/migration lint/prefixed build and seeded CI
remain delivery gates. Record actual deployment SHA and bounded live results in
the implementing PR; implementation is not deployment evidence.

Follow-up: municipal search/comparison and a meaningful visual exploration entry,
Poverty map-return/history contract, and any additional measures only after their
source audit. Do not mark these delivered by this first readability increment.

Representative-reader protocol adapted from BHW: a general reader, LGU/BHW user
and analyst locate a municipality, explain the estimate/interval, distinguish zero
from missing, inspect exact data/source, compare only supported places and return
to the same scope. Include phone and keyboard/screen-reader use; record confusion
and assistance, not personal information. This protocol is prepared, not run.
Human comprehension and representative-device acceptance remain open; owner-deferred
BHW performance/Headcount metadata are not closed by this pilot.

### Candidate verification

`npm ci`, full lint (one pre-existing intentional-fixture warning), types,
2,117 unit tests passed / 55 database-dependent skips, migration lint and prefixed
no-data production build/extractor passed. Eight new rendered/model cases cover
rejected payloads, zero/uncertainty, scope and retained detail. Both 390/1440px
browser journeys passed using a private synthetic REST fixture, including keyboard
expansion, locality navigation, refresh and Back/Forward. Initial keyboard tests
lost the focused server node during hydration; reacquiring the native summary
fixed the harness. No application interaction was bypassed.

Additional synthetic zero/missing/failed source cases passed at both widths.
Expanded-detail axe scans reported no WCAG A/AA violations, page errors or ordinary
horizontal overflow. Long locality names and phone/desktop screenshots were
inspected. No live source values/screenshots are committed. Seeded CI, actual
deployment and bounded live verification are still pending at this snapshot.

Delivery: [PR #608](https://github.com/avincentpatrick/dev_formbuilder_app/pull/608).
First seeded CI run passed 178 browser cases / one skip but failed the existing
all-card smoke test, which expected the Poverty exact table to be visible without
opening its new disclosure. Updated that journey to open the named disclosure
while retaining all table, definition and accepted-source assertions. The generic
availability-detail journey also opens it after refresh. Targeted local browser
checks use the final standalone candidate; final required CI remains authoritative.

## Increment 2 — municipal discovery and map return

Baseline: PR #608 merged as `6335382bae34c8d004cbb3cafe5eb023f40ecd4c`;
its main CI and both subsequent deployment runs passed. Its PR records the
actual live release and bounded verification. Historical open #525/#527 are
not merge candidates; #528 is an older catalog-context proposal, not a new
Poverty implementation. No newer Poverty implementation was open at this audit.

### Phase mapping and decisions

- **Phase 0:** reuse the accepted `/api/dataset-map` Poverty branch and its
  `joinPovertyRows` contract. No raw-record reader, new release, denominator,
  calculation, migration or source-data change. Resolve province from catalog
  geography's actual current/ancestor records, never by slicing municipality codes.
  Only province and city/municipality catalog scopes show the municipal list.
  Manila (13806) explains the source/reporting mismatch instead of comparing a
  partial set as a complete municipal universe. HUC and other absent rows stay
  unavailable. Request failure, source unavailability, withholding and unsupported
  scope have separate messages; zero remains a usable estimate.
- **Phases 1–2:** retain the main explanation and exact-data disclosure; add an
  alphabetical, searchable municipal table with estimate, published 90% interval,
  readable full labels, PSGC code, selected-place marker and source/date/license.
  Search affects the list only, includes missing rows, and neither ranks need nor
  calculates a provincial average. Use one percentage precision across the whole
  series, independent of search. Retain negative/above-100 interval bounds.
  Reuse `FigureCard`, `percentageFormatter`, catalog links, map choice serializers
  and `readMapResponse` (bounded transient retry and abort). A native table follows
  the existing searchable training-table presentation: `FigureTable` is unsuitable
  here because its numeric-only rows/count-share columns cannot preserve missing
  estimates and separate confidence bounds. No new generic chart API is needed.
- **Phase 3:** municipal detail links preserve the list search, which is bounded
  and scoped to its actual province in the URL. Refresh and Back/Forward restore
  it. The map opens that province and selected municipality. Poverty now opts into
  the existing map-history mechanism; a map-to-details link carries a validated
  snapshot, and catalog ancestor navigation retains the return link. Only the
  approved Poverty release is accepted; arbitrary URLs and invalid map settings
  are rejected. List search can travel through map/detail links but is not an
  estimate or map-value filter. BHW-only filters/classification are not enabled.
- **Phase 4 engineering:** navigation unit checks cover selected/parent scope,
  validated snapshots, cross-dataset rejection and bounded list search. Browser
  cases cover synthetic zero/missing/published bounds, source-status distinctions,
  long names, phone/desktop widths, keyboard links, search/clear/no match,
  reload/Back/Forward, map selection and returning through an ancestor scope.
  Existing map/API/poverty-join and overview tests remain applicable.

Delivery gates: required lint/types/full unit suite/migration lint/prefixed build,
seeded CI, green merge, actual deployment SHA, and bounded unmocked live checks.
The implementing PR records final results; no synthetic browser result is called
live-source evidence. No live source values or screenshots enter this repository.

Remaining: representative-reader/physical-device acceptance and any broader
visualization or new measure requiring its own source audit. The BHW owner-deferred
Phase 4, performance measurements, Headcount metadata and conditional source
prerequisites remain open. This increment does not sign them off.

Candidate verification: lint passed with the pre-existing intentional base-path
fixture warning; types, 2,122 unit tests (55 database-dependent skips), migration
lint and the prefixed standalone build/extractor passed. Seven targeted browser
journeys passed at 390/1440px with synthetic fixtures. Expanded exact-data and
municipal-list WCAG A/AA scans found no violations, page errors or ordinary
horizontal overflow. Synthetic phone/desktop screenshots were inspected; the
phone table heading was shortened while keeping its full meaning in the caption.
The first local map run exposed an incomplete standalone asset copy; copying the
worker and static assets correctly restored rendering without application changes.
Seeded CI and actual deployment/live checks remain separate delivery gates.
