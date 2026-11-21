# Dataset card analytics and readability: implementation handoff

Prepared 21 November 2026 for the next BHW Connect session. This is a repeatable workflow and
evidence index, not approval of every visualization for every dataset. No next-card implementation
is included. Recommended next card: **NHFR / Health facilities**, subject to the owner's choice.

## Start here and establish the current baseline

1. Read [AGENTS.md](../AGENTS.md), [CLAUDE.md](../CLAUDE.md), the repository's
   [working agreement](../../../docs/working-agreement.md), then [HANDOVER.md](HANDOVER.md).
   Read any instructions below the target directory. Before Next.js code changes, read the
   relevant bundled Next documentation as required by AGENTS.md.
2. Fetch `origin/main`, inspect the working tree, current deployment and open PRs. Branch from
   `origin/main`; preserve other people's work. Confirm the selected card and its existing routes.
3. Read the original roadmap [#525](https://github.com/avincentpatrick/dev_formbuilder_app/pull/525)
   and Phase 0 audit [#527](https://github.com/avincentpatrick/dev_formbuilder_app/pull/527).
   They remain open historical drafts, not merge candidates or current authority for every rule.
   If assessment/verification files are missing on main, fetch `docs/bhw-card-analytics-assessment`
   and inspect them there. Discover earlier implementing PRs through their references and git
   history; do not infer delivery from a proposed checklist.
4. Read [BHW_READABILITY_INCREMENT.md](BHW_READABILITY_INCREMENT.md), all three acceptance records
   linked below, [BHW_AGGREGATE_PUBLICATION.md](BHW_AGGREGATE_PUBLICATION.md), and
   [UNIFIED_DATASET_CATALOG_INVENTORY.md](UNIFIED_DATASET_CATALOG_INVENTORY.md). Then inspect the
   actual accepted reader, source filters and current target-card code. Later accepted decisions
   supersede historical proposals; source-specific contracts still control their own measures.
5. Create the target assessment before editing runtime code. Classify existing work as delivered,
   unfinished, superseded or conflicting, with evidence. Do not restart an already delivered phase.

Snapshot for this handoff: fetched main is `16a83e57f4d7bcbf4957aef13b81976f3be641a5`.
Poverty import handoff [#615](https://github.com/avincentpatrick/dev_formbuilder_app/pull/615)
is open. The last recorded Poverty delivery verified release
`396476d214ecc7ea2354a64cd76972a8f031282c`, not a fresh certification of today's deployment.
No operator import completion was established in this handoff. Check #615 for later evidence.
Historical catalog PRs #420/#528 are still open; FHSIS operator handoff #476 is also open.
No dedicated NHFR readability implementation appeared in the open-PR list inspected for this
handoff. Recheck all of these before starting; this inventory is time-bound.

## What was actually reused and delivered

| Phase | BHW delivery and evidence | Poverty / Population application |
| --- | --- | --- |
| 0: understand data | #525/#527 historical assessment/audit; accepted catalog inventory and publication contracts distinguish Headcount, Profiles, populations, source dates and geographic eligibility. Audit is not a new public data source. | [Poverty assessment](POVERTY_ANALYTICS_READABILITY.md) audits municipal SAE; [official-source audit](POVERTY_OFFICIAL_SOURCE.md) separately accepts 2023 r2 Table 2; [Population assessment](POPULATION_ANALYTICS_READABILITY.md) audits POPCEN 2024 and partial parent totals. |
| 1: explain the main story | [Phase 1 evidence](BHW_PHASE_ONE_ACCEPTANCE.md): national overview, search, explicit comparison choices, map/table alternatives, privacy and cross-page continuity. Follow its delivery links; early pending snapshots are not final release status. | [#608](https://github.com/avincentpatrick/dev_formbuilder_app/pull/608): scoped Poverty explanation and uncertainty, retained exact data/sources. [#611](https://github.com/avincentpatrick/dev_formbuilder_app/pull/611): Population count, year, coverage and partial-total explanation. |
| 2: appropriate detail | [Phase 2 evidence](BHW_PHASE_TWO_ACCEPTANCE.md): training matrix #593, compensation distributions #594, paired maps/common breaks #595, peer-position/composition reconciliation #596. | [#609](https://github.com/avincentpatrick/dev_formbuilder_app/pull/609): searchable municipal estimates with intervals and missing rows. [#612](https://github.com/avincentpatrick/dev_formbuilder_app/pull/612): searchable Population map detail and scoped return. A single count does not need a distribution chart. |
| 3: interpretation and continuity | [Phase 3 evidence](BHW_PHASE_THREE_ACCEPTANCE.md): [#598](https://github.com/avincentpatrick/dev_formbuilder_app/pull/598) same-source relationships; [#599](https://github.com/avincentpatrick/dev_formbuilder_app/pull/599) explained review lists and existing locality profiles; [#600](https://github.com/avincentpatrick/dev_formbuilder_app/pull/600) audited external context and same-source two-variable maps. Supported features were verified in release `2fba84ff1cf99d16ef87eb592c8cecb484c5851a`. | #609/#612 retain scope, validated map-return snapshots, search and browser history. [#613](https://github.com/avincentpatrick/dev_formbuilder_app/pull/613) adds Poverty coverage discovery and separate official-source support. It does not add official-source maps or automatically load observations. |
| Readability follow-ups | [Readability record](BHW_READABILITY_INCREMENT.md): #601 grouped secondary detail/removed duplicate education; #602 shared definitions; #603 natural ordering; #604 searchable training topic/year table; #605 compact statistics/full labels/glossary; #606 confirmed demographic wording. | Shared definitions, formatting, named disclosures and exact data are reused. Neither BHW's category meanings nor its privacy exceptions become universal rules. |
| 4: verification before generalizing | Software checks and bounded live evidence exist per increment. Representative-reader review and Phase 4 remain owner-deferred until the upcoming enhancement; no human-comprehension sign-off. | Viewport/keyboard/browser checks do not prove physical-device acceptance. Poverty/Population reader protocols remain unperformed; do not claim a universally accepted standard. |

The final Poverty follow-up [#614](https://github.com/avincentpatrick/dev_formbuilder_app/pull/614)
refreshed the required development schema and fixed a synthetic FHSIS fixture. Its PR-head CI
passed 2,198 unit/database tests (13 skips) and 192 browser tests (one skip). Deploy
[44733976557](https://github.com/avincentpatrick/dev_formbuilder_app/actions/runs/44733976557)
confirmed release `396476d2` live on 20 November at 12:14:54 UTC. Recorded live checks at 390px
and 1440px covered BARMM coverage → province → municipality search/detail → Back, national
pending-source state, keyboard navigation and overflow. They did **not** verify populated official
rates. #615 contains the remaining private import instructions for Vincent.

## Reuse map: inspect these before adding another component

Paths below are relative to `apps/bhw-connect`. Follow the linked source rather than copying a
whole Poverty component and changing its title. Extract a shared helper only when the data and
behavior contracts actually match.

| Need | Existing implementation | Reuse boundary |
| --- | --- | --- |
| Catalog selection and locality | [catalog-content](../components/catalog/catalog-content.tsx), [location-controls](../components/catalog/location-controls.tsx), [navigation](../lib/catalog/navigation.ts), [geography](../lib/catalog/geography.ts), district controls/readers | Use canonical reporting parentage, codes and explicit eligibility. Do not derive parentage by slicing codes or allocate split-city totals. |
| Shareable URLs / Back / map return | [catalogJourneyHref](../lib/catalog/journey.ts), [map-details-navigation](../lib/catalog/map-details-navigation.ts), [catalog-return](../lib/maps/catalog-return.ts), [Poverty navigation](../lib/catalog/poverty-navigation.ts), [Population navigation](../lib/catalog/population-navigation.ts) | Validate source/dataset-specific snapshots; preserve selected scope/search where supported. Do not pass another card into BHW-only comparison builders. |
| Main figures and formatting | [FigureCard](../components/narrative/figure-card.tsx), [format helpers](../lib/format.ts), [Poverty overview](../components/catalog/poverty-overview.tsx), [Population overview](../components/catalog/population-overview.tsx) | Reuse StatValue/count/percentage formatting; raw precision remains for calculation/sort/export. Guard source, unit and status before rendering. |
| Shared plain-language definitions | [measure-notes](../lib/catalog/measure-notes.ts), [presentation](../components/catalog/presentation.ts), [catalog-results](../components/catalog/catalog-results.tsx) | Define wording once for compatible overview/exact-data surfaces. Preserve original wording and critical interpretation beside the figure. |
| Charts and exact alternatives | [FigureView](../components/charts/figure-view.tsx), [FigureTable](../components/charts/figure-table.tsx), existing Explore figures | Choose only appropriate supported visuals. FigureTable's numeric/count-share shape is not a fit for every nullable multi-column table. Use semantic tables where needed. |
| Searchable detail and status rows | [poverty-municipalities](../components/catalog/poverty-municipalities.tsx), [poverty-coverage](../components/catalog/poverty-coverage.tsx), existing facility list | Search never changes the published denominator. Preserve full names, missing rows, sort meaning, keyboard access and source links. Keep list filtering distinct from map-value filtering. |
| Maps and downloads | Existing dataset map API/renderers; [facility map](../components/facilities/facility-map.tsx), [facility downloads](../components/facilities/download-links.tsx) | Audit the target map's existing source contract. Missing geometry stays in the table. Facility points are not worker locations or evidence of service capacity. |
| NHFR-specific detail | [facility-stats](../components/facilities/facility-stats.tsx), [type-breakdown](../components/facilities/type-breakdown.tsx), [facility-list](../components/facilities/facility-list.tsx), [child-breakdown](../components/facilities/child-breakdown.tsx), [coverage-bar](../components/facilities/coverage-bar.tsx) | Assess their existing behavior before adding a parallel NHFR reader or presentation. Reuse public projections; keep personal contacts private. |
| Accepted data/source/status | [definitions](../lib/catalog/definitions.ts), [reader](../lib/catalog/reader.ts), [types](../lib/catalog/types.ts), [catalog](../lib/catalog/catalog.ts), [official Poverty adapter](../lib/catalog/poverty-official.ts) | Pin accepted releases and fail closed on incompatible data. Source-update date is not deploy time. Never make “not loaded” mean “the publisher has no estimate.” |

## Phase 0 worksheet required for each new card

Create `docs/<CARD>_ANALYTICS_READABILITY.md`. Supply a row for **every data point**, not just
headlines, including aliases, hidden secondary statistics and stored fields not approved for display.

| Required item | Record before implementation |
| --- | --- |
| Reader question | What a general reader, LGU/BHW user and analyst need to understand or do; identify the main question separately from technical detail. |
| Accepted source | Publisher, original file/URL, release/version, actual table/field, source wording, provenance and publication/reuse terms. Newly downloaded sources need a reproducible audit. |
| Meaning and population | Unit, numerator, denominator, eligibility/exclusions, unique entities versus events; whether categories overlap and whether statistics can be combined. |
| Dates | Observation period, reference date, release date and retrieval date separately; comparability across years must be established. |
| Geography | Supported levels, published parent rows versus constructed totals, source-vintage boundaries, matching/crosswalk evidence, unmatched areas and attribution limits. |
| States | Real zero; unavailable/missing; partial; failed request; suppressed/withheld; unsupported. Document source dashes/codes explicitly. Flag unexplained codes instead of guessing. |
| Publication | Allowed public columns/aggregates, privacy rules, restrictions on export and provenance. Test that rejected values do not leak through props, markup, downloads or plots. |
| Visualization eligibility | Supported headline, categories, distribution, table, map or relationship and its required inputs. Explain why unsupported options are deferred. |
| Current work | Current routes/components/tests, main SHA, open PR conflicts, delivered behavior, unfinished work and superseded proposals with links. |

Confirmed BHW meanings are limited to blood type U = Unknown and education Level = attended
without graduating. Those confirmations do not resolve another dataset's codes. BHW Headcount
and Profiles are distinct populations, not a denominator pair or consecutive time observations.

## Invariants and lessons from the Poverty pilot

- Missing local values stay unavailable. Never substitute national figures, infer a parent rate
  by averaging municipal percentages, or recover suppressed/source-null values for a visual.
- Make coverage discoverable before a user chooses an area with no values. Retain navigable areas
  with gaps, explain supported levels, and distinguish source absence from an import not yet done.
- Poverty SAE 2023 uses 90% intervals; official Table 2 2023 r2 uses 95%. Neither confidence level,
  geographic mapping nor source boundary can be copied to a new card. Preserve published bounds.
- Population 2024 matched parent totals carry partial-coverage qualifications. Do not relabel them
  as official complete parent totals or use them to manufacture a 2023 poor-person count.
- Relationships state both dates, populations, units, exclusions and matching rules. Association
  is not causation. Review lists state each inclusion reason; do not invent a need/priority score.
- Worker-location maps need approved appropriately de-identified locations and disclosure rules.
  A time slider needs comparable approved observations and reconciled boundaries. Aggregate
  centroids and different source populations do not satisfy those prerequisites.
- Reuse source notes, exact tables and accessible controls. Named disclosures can contain secondary
  detail, but essential uncertainty, denominator and coverage warnings remain beside the figure.

## Recommended next pilot: NHFR / Health facilities

NHFR is a useful contrast to a single poverty percentage and a single population count: it has
facility counts, ownership/types, beds, geographic attribution and existing lists/maps. This is
a recommendation, not a completed source audit or authorization to change its definitions.

Start with the accepted `nhfr-2026-09` snapshot, `lib/db/nhfr.ts`, catalog NHFR definitions and
the inventory's NHFR section. The documented units are facilities, beds and barangays. The four
headline types alias type-distribution measures; avoid duplication. Barangay presence is distinct
barangays with an attributed facility divided by `n_barangays`, not reporting completeness,
quality, accessibility or sufficient capacity. Absent licensing status means not stated, not
unlicensed. Unassigned facilities may count at city/municipality level without barangay attribution.
Sparse type rows alone do not prove zero; a successful total and complete type read are required.
These place counts do not inherit BHW person small-cell suppression. Public data must exclude
personal contacts. Validate all these rules against current code before implementation.

Concrete increments:

1. **Assessment:** inventory each NHFR measure/status/geography and current facility/catalog journey;
   record conflicts and one reader question: “What facilities are recorded in this place, when,
   and what does their presence tell me?” Establish a bounded live baseline without changing data.
2. **Main story:** explain snapshot date and count, distinguish facilities from beds and presence,
   remove duplicate type displays, keep attribution limitations visible and full detail accessible.
   Use shared definitions so overview and exact data agree. Test, merge green, verify release/live.
3. **Discovery/detail:** improve existing category/list search and links only where the assessment
   finds a gap. Preserve long names, missing attribution, natural category order and all exact rows.
   Keep map/list scope and Back/Forward/share behavior consistent. Ship and verify separately.
4. **Further analytics only if justified:** approve denominators and geographic/source matching
   before adding ratios, peers or relationships. No facilities-per-resident measure, need ranking,
   worker-assignment inference or time slider is pre-approved by this handoff.

UUC-PHC is another option, but its overlapping qualification routes and bounded assessment values
need distinct denominator treatment. FHSIS has source-specific units/periods and an open operator
load handoff; its synthetic CI data must not be mistaken for confirmed live availability.

## Verification matrix and release sequence

| Feature / risk | Existing tests to inspect and extend | Bounded live checkpoint |
| --- | --- | --- |
| Accepted source, geography and statuses | `lib/catalog/{reader,catalog,geography,barangay,district-catalog}.test.ts`; target reader tests | One populated place, one known gap, supported/unsupported levels; compare displayed identity/date/unit to accepted source. Do not manufacture live zero/withheld cases. |
| Headline and shared interpretation | `components/catalog/{poverty-overview,population-overview,presentation,catalog-results}.test.tsx` or `.test.ts` as present; `lib/catalog/poverty-official.test.ts` | Figure and exact data agree; critical warnings remain visible; source link resolves. |
| Search, coverage, long labels | `e2e/poverty-municipalities.spec.ts`, `e2e/poverty-coverage.spec.ts`, target list tests | Search/clear/no match; missing area remains discoverable; denominator unchanged. |
| Scope / URL / map / history | `lib/catalog/{journey,navigation,poverty-navigation,population-navigation}.test.ts`; `lib/maps/catalog-return.test.ts`; `e2e/population-map-details.spec.ts` | Open share URL, reload, map → detail → ancestor → map, Back/Forward and selected place/search restoration. |
| Accessibility and detail | `e2e/catalog.spec.ts`, `e2e/poverty-overview.spec.ts`, `e2e/population-overview.spec.ts` | Phone/desktop widths, keyboard disclosures/links, full labels, semantic tables, focus and no ordinary page overflow. Record axe separately from human screen-reader use. |
| New source import, only if needed | `ingestion/prepare-poverty-official.test.ts`, `db/load-poverty-official.test.ts` as contract examples | Operator dry run/apply, actual source rows and correct pending/loaded states. Migration success is not data-load proof. |

Use synthetic fixtures for zero, missing, failed, withheld, unsupported, malformed and incompatible
values. Verify both rejection and absence of leaked payloads, not just a placeholder label. Add
meaningful tests for changed behavior; do not merely mirror an implementation or weaken a failing
existing test. The #614 fixture fix retained the existing populated FHSIS browser check.

For each implementation increment, run the current required local lint/types/full tests,
migration lint, prefixed production build and appropriate browser checks. CI must pass every
required ownership/security/application gate, including seeded database/browser checks. Report
local skips honestly. Inspect the latest production Deploy before merging; follow the working
agreement if it failed. Never change ownership checks or shared paths to force green.

After merge, identify the actual deployed SHA and successful health check, then perform the bounded
live journey and record observed results. Reachability alone is not deployment or feature evidence.
Only then start the next implementation increment. An independent source audit can continue while
an operator load is pending, but populated source acceptance remains blocked until evidenced.

Only edit BHW-owned paths; `ops/` and `.github/` have separate approval rules. The runtime is Windows
Server / Apache `/bhw-connect` / Node / PostgreSQL / PostgREST, not hosted Supabase or Vercel.
Respect CSP and base-path helpers. Keep real observations and credentials out of this public repo.
New schema migrations are additive. After schema changes, perform the required development-copy
workflow, review its schema/version/synthetic seed and commit the refresh separately as appropriate.
Server imports/settings/rollback remain Vincent's responsibility under the repository agreement.

## Durable record and completion boundary

For each increment append to the card assessment: problem and resulting behavior, phase mapping,
source/reader changes (or none), code/test links, local results and skips, PR/commit, CI links,
merge SHA, deployed SHA/time, bounded live checks, failures and their resolution, remaining work.
Keep proposed, implemented, merged, deployed, imported and verified states distinct.

Adapt the [representative-reader protocol](BHW_READABILITY_INCREMENT.md#completion-checklist-and-human-acceptance)
to the selected card: a general reader, LGU/BHW user and analyst should locate a place, explain
the figure/date/population, distinguish zero from missing, find source/exact data, make only a
supported comparison and return to the same scope. Record confusion and assistance without
personal information. Include real phone and keyboard/screen-reader use when the owner resumes
this work; browser emulation is not physical-device or comprehension acceptance.

Open items carried forward: Poverty operator import/populated official live verification (#615),
additional audited city mappings/newer poverty vintages, owner-deferred BHW Phase 4 and representative
readers, earlier performance measurements, Headcount reuse metadata and conditional data prerequisites.
This handoff closes none of those by implication.

Next product question, one at a time: **Should NHFR / Health facilities be the next card?**
Until the owner chooses, the next-card implementation remains unstarted. Once chosen, begin with
its Phase 0 assessment and resolve only genuinely unclear source/product questions before the
dependent increment; use existing accepted contracts for routine implementation choices.
