# BHW card analytics: assessment and incremental improvement plan

Status: planning draft, 2026-11-15. Implementation handoff for Sol.
Baseline: repository commit `73affc27`. No application changes are part of this document.

## 1. Goal and agreed direction

Make BHW the reference implementation for default analysis of ingested datasets. Serve general readers, BHWs, LGUs, planners, and researchers through a coherent overview and purpose-based subpages, rather than choosing one audience to the exclusion of others.

Agreed story: **workforce and distribution → qualifications and support → places needing closer examination**. Findings must be understandable without specialist training. A low count is not, by itself, evidence of a staffing shortage.

Confirmed requirements:

- Visual analysis is the entry experience; availability is supporting information.
- Search spans all supported geography and indicators within BHW, independent of the current region. It also supports structured requests such as “compare Cebu and Bohol accreditation.”
- Relevant contextual datasets are allowed when dates, geography, populations, and denominators permit a defensible interpretation.
- Provide an overview map preview and a dedicated interactive map workspace.
- Default to LGU-reported BHW headcount shown as proportional circles, with circle area proportional to count. Accreditation and other supported indicators remain selectable, using suitable visual defaults. Circles represent geographic aggregates, not worker locations.
- Allow indicator selection, value-range filtering, automatic classification, editable class count and boundaries, and reset controls.
- Include readable labels, a visible legend, locator inset of the Philippines on locality drill-down, scale bar, attribution, and navigation controls.
- Offer appropriate map and non-map views, rather than forcing every measure into a choropleth.
- Shareable links and exports suffice initially. Named saved configurations are deferred.
- Phone and desktop are equally required; adapt the interaction rather than shrinking the desktop screen.
- The initial rollout uses English only for the explanatory story and interface copy. Use plain language and explain technical terms; Filipino localization is outside the initial scope.
- Follow-up visualizations must have explicit phases and acceptance criteria.

## 2. Evidence and limits

This is a source-code assessment, supplemented by the user's reported experience. Live rendering, production data coverage, actual search relevance, touch behavior, and response times have **not yet been verified** in this assessment. Existing planning documents provide history, not proof of current behavior. No real records or production counts are reproduced here.

Paths below are relative to `apps/bhw-connect/`. Recheck the baseline before implementation.

| Surface | Evidence | Assessment |
| --- | --- | --- |
| Card details | `components/catalog/catalog-results.tsx`, `catalog-content.tsx` | Default all-locations details explicitly show an availability summary and indicator table. Location controls precede content. This answers “what is available?” before “what does it show?” |
| Location browsing | `components/catalog/location-controls.tsx` | Hierarchical child selection starts with regions. Links/form navigate the catalog without carrying the selected dataset, risking loss of BHW context. |
| Existing BHW dashboard | `app/bhw/page.tsx` | Already assembles workforce, profile, certification, education, honorarium and insight components. Improve and integrate this investment rather than assuming BHW has no analysis. |
| Existing deeper analysis | `app/explore/page.tsx`, `components/explore/` | Includes training, demographic, geographic comparison, distribution, relationship, cohort, workload and compensation components. Their availability in code does not establish usability or suitability for the new story. |
| Existing place/comparison journeys | `app/place/`, `components/place/`, `components/compare/` | Reuse candidates for locality context, benchmarks and comparison. A locator-map component already exists; assess it before building another. |
| Map search | `lib/maps/area-search.ts`, `components/maps/dataset-map-card.tsx` | Matches names/codes only among loaded child rows. Search narrows the area selector, explicitly leaving the map, totals and table unchanged. This is a local list filter, not card-wide discovery. |
| Broader geographic search | `components/home/geo-search.tsx` | Existing geography/district search, disambiguation and recent-place behavior are reuse candidates. Do not describe the entire application's search as region-only. Indicator/action discovery still needs integration. |
| Map controls and legend | `components/maps/dataset-map-card.tsx` | Indicator selection, area selection, drill navigation, legend, table and exports already exist. Their presence is not evidence that users can easily find or interpret them. |
| Map drawing | `components/maps/dataset-canvas.tsx` | Draws fills and boundary/selection outlines, fits initial bounds and handles polygon clicks. No symbol label layer, locator inset, scale bar or explicit zoom/reset controls is created here. |
| Map size | `app/map/page.tsx`, `dataset-canvas.tsx` | Workspace is constrained by `max-w-6xl`; enlarged canvas is `55vh`, embedded canvas `h-64`. “Full map” is not a viewport-filling workspace. |
| Navigation continuity | `app/map/page.tsx`, `lib/maps/subjects.ts` | Back link returns to all-locations dataset details; scope is not preserved by that link. Some release transitions clamp unsupported geography. Make transitions explicit and preserve valid intent. |
| Measure contracts | `lib/catalog/definitions.ts`, `lib/maps/definitions.ts`, `lib/releases/visibility.ts` | Accepted releases, indicator units, supported levels and release visibility already provide important boundaries. Extend these instead of bypassing them. |

## 3. Analytical gaps and priorities

### Critical: a fragmented story

The catalog inventories measures, the dashboard provides summaries, Explore provides deeper figures, and maps form another journey. The primary deficiency is the connection between these capabilities. Establish one BHW entry point and shared context across subpages. Preserve existing links with compatible routing; do not remove mature analysis just to simplify the overview.

Recommended overview:

1. BHW title, concise purpose, card-wide search, active geography and source period.
2. A few meaningful headline figures distinguishing LGU-reported headcount from individually validated profiles.
3. Geographic distribution visual, ranked comparison and a short factual interpretation.
4. Qualification and support summaries linking to focused analysis.
5. Places worth examining, with the indicator, comparison basis and data limitations stated.
6. Expandable methodology, source coverage and detailed data access.

The first usable visual must not depend on opening an availability panel or waiting for AI text. Keep essential interpretation warnings next to the affected figure, even when longer methodology is collapsed.

### Critical: discovery does not match user intent

Search needs to retrieve places, measures and supported analysis actions. “Cebu accreditation” should resolve geography and the relevant accreditation measure; it must distinguish LGU-reported accreditation from accreditation among profiles. “Compare Cebu and Bohol accreditation” should open a comparable view with visible interpretation chips, not silently choose ambiguous geography or denominator.

Use a defined grammar/actions for initial comparison requests; unrestricted conversational analytics is not a prerequisite. Support aliases, common terminology, PSGC codes and typo tolerance where reliable. Distinguish an empty result, unsupported query and failed request. Search public aggregate metadata and permitted measures, never individual personal records.

### High: maps need interpretation and useful next actions

Colored boundaries alone do not explain a pattern. Every map needs a question, metric, units, reference period, classification method, readable legend, selected-place details and a linked exact-value view. A user should be able to locate a place, understand its value, compare it and share the result.

Locator inset should show the selected area's position within the country, using an outline or marker appropriate to its size. It is distinct from the thematic map and must not imply a second measure. Scale bar must update with zoom. Labels must prioritize readability and avoid collisions; tooltips cannot be the only means of identifying a place on a phone.

### High: counts, rates and gaps need different interpretation

- Counts describe workforce volume; use proportional-symbol maps and ranked bars where appropriate. Symbol area, not radius, represents magnitude; state that symbols represent area totals, not worker addresses.
- Shares require their own numerator, denominator and missingness. Do not average local percentages to produce a national rate.
- Headcount and profiles are different populations, not additive totals or automatically a time series. The internal `bhw-stepzero-2026` slug is not evidence of a 2026 observation; preserve the established public 2025 naming.
- Honorarium receipt, payer-specific receipt and payment amounts answer different questions. Do not merge their denominators or treat overlapping payers as exclusive categories.
- Training topics overlap. A topic matrix can reveal variation; summing topic coverage does not measure uniquely trained workers.
- Contextual ratios need documented numerator/denominator provenance and compatible geography. Existing households-per-BHW work must be evaluated before introducing a population-based alternative. Neither establishes workload or a shortage by itself.
- Trends require comparable observations over time. Release labels alone do not justify trends. Hidden profiling-status data stays hidden unless separately authorized.

## 4. Proposed information architecture

Purpose-based destinations: **Overview**, **Workforce distribution**, **Qualifications and training**, **Support and compensation**, **Compare places**, and **Map explorer**. A data/methodology destination supports technical readers without becoming the landing experience.

Treat these as product destinations, not a requirement to invent six unrelated route systems. Sol should document the mapping to `/bhw`, `/explore`, `/place`, `/compare`, `/map` and catalog routes before changing links.

Shared state should describe dataset, release/period, geography and parent chain, indicator, comparison places, visual mode, filter range and classification. Serialize public analysis settings into validated shareable URLs. Browser back/forward and cross-subpage navigation must restore valid state. Unsupported settings need a visible explanation, not a silent jump to national data.

## 5. Interactive map and range contract

**Two separate controls:** filtering chooses included values/areas; classification chooses how included or displayed values are colored. Label both clearly. Filtering must not silently redefine a published geographic total. If a filtered subtotal is shown, identify it as such and include the excluded/missing counts.

Classification supports source-appropriate defaults, equal intervals, quantiles, configurable group count and custom boundaries. Show the method and actual bounds in the legend. Validate ascending, finite, nonoverlapping boundaries; define interval inclusion consistently, including the maximum. Handle duplicate quantiles, identical values, empty results and out-of-domain inputs explicitly.

Default rates may use meaningful fixed breaks. Custom breaks are exploratory choices, not official standards. Preserve common breaks for comparisons; make any recalculation for the current area explicit. Percentage controls must respect the indicator's valid domain rather than assuming every ratio is bounded by 100.

Distinguish zero, missing, withheld, failed load, missing geometry and excluded-by-filter areas. Include textual/shape cues as needed; color alone is insufficient. Missing shapes remain discoverable in the linked table.

Changing indicators updates title, units, legend, notes and allowable views together. Retain compatible geography; reset or translate incompatible ranges transparently. Share/export captures all settings, source/period and explanatory legend. No saved-account configuration feature is required initially.

## 6. Phased delivery and acceptance criteria

Each phase may use several small PRs. Dependencies are explicit; completion means passing its acceptance scenarios on phone and desktop, not merely adding components.

### Phase 0 — verify and design the baseline

- Walk the live card → overview → search → locality → map → comparison journey on phone and desktop. Record screenshots and reproducible issues without personal records.
- Audit supported indicators, denominators, geographic levels, sources, boundary coverage and disclosure rules. Check existing figures and readers for reuse.
- Produce overview/map layouts and a route/state mapping. Verify national headline inputs rather than deriving them from overlapping geographic records.
- Acceptance: an evidence matrix distinguishes confirmed defects, existing-but-disconnected capabilities, missing features and data blockers. Establish observed performance and accessibility baselines; record numerical budgets before Phase 1 work.

### Phase 1 — coherent overview, search and useful map

Suggested incremental PRs:

1. Visuals-first BHW overview and purpose navigation, reusing validated existing components.
2. Card-wide place/indicator search and supported comparison intents connected to the existing comparison capability.
3. Map workspace with selectable measures, choropleth/proportional-symbol alternatives, linked ranked bars/table, labels, locator inset, scale, visible legend and zoom/reset controls.
4. Separate filter/classification controls, editable ranges and groups, robust shared state and exports.

Acceptance:

- Opening BHW yields meaningful visuals without choosing a region first; unavailable observations are honestly represented.
- Searching a municipality/barangay from another region works; duplicate place names show their parent chain. An indicator-only query and combined place/indicator query reach relevant analysis.
- A supported comparison query opens the requested places and measure; ambiguous terms require selection within the search flow. Basic comparison is included here; synchronized comparison maps follow in Phase 2.
- Map indicator switching, drill-down, reset, filtering and custom group edits work with touch and keyboard-accessible alternatives. Controls and legend remain available in expanded view.
- A fresh map opens on BHW headcount with proportional circles and a size legend; a shared link restores its explicit indicator/view instead. Zero counts are distinguishable from missing observations through the linked details/table and legend. Source/coverage checks must not substitute profile counts for unavailable headcount.
- Phone layout provides a usable map with panels/tabs or sheets; desktop uses available width with adjacent details. Ordinary content does not overflow horizontally; wide tables use labeled scroll regions.
- Map, bars and table agree on values and inclusion rules. Share links restore settings and exports include the effective legend and provenance.
- No source becomes publicly accessible merely because search or a new visual supports it.

### Phase 2 — comparisons and qualification/support depth

Depends on Phase 1 shared state and validated data contracts.

- Synchronized side-by-side maps for compatible places/indicators/releases, with linked navigation where meaningful and explicit independent units. On phones provide an equivalent A/B switch with retained state.
- Qualification/registration composition charts, including 100% stacked bars only for mutually exclusive categories with an appropriate denominator.
- Training heatmap of places × topics, with readable values, missingness, sorting and a non-color-dependent accessible table.
- Distribution plots and peer comparisons showing the selected locality's position; integrate existing distribution components when suitable.
- Dedicated compensation story connecting receipt, payer and amounts without confusing overlapping populations.
- Acceptance: map colors use common breaks for the same measure; incompatible populations/periods cannot be presented as like-for-like change. Training percentages are not summed across topics. Rankings identify peer universe, exclusions and ties. Suppressed values cannot be recovered from composition or exports.

### Phase 3 — contextual relationships and evidence for closer examination

Depends on documented geography/date matching and availability of context.

- Contextual scatterplots, initially evaluating workforce measures against population, households, facilities or socioeconomic context as supported.
- Multi-indicator locality profiles and explainable lists of places worth reviewing. Avoid a composite “need score” without an explicitly agreed methodology.
- More advanced visual options only where data supports them: bivariate maps require a readable joint legend and usable alternatives; density/point maps require appropriate location data; time sliders require genuinely comparable history.
- Acceptance: each relationship states units, dates, population, exclusions and joined geography; observed association is not described as causation. Users can select a point to inspect its locality. Lists explain why each place appears and distinguish low reporting from low workforce presence.
- Unmet data prerequisites produce named blocked tasks and precise requirements, not invented values or an untracked “later” note.

### Phase 4 — establish the reusable dataset-card standard

Depends on evaluating BHW with representative readers on both device classes.

- Extract shared navigation, search result types, visual metadata, state handling, map controls, provenance and accessibility behavior.
- Define dataset-specific adapters for supported measures, questions, denominators, geography, privacy and appropriate visual choices.
- Apply the standard to a contrasting dataset before general rollout; verify that BHW-specific assumptions do not leak into population, facility or poverty cards.
- Acceptance: each card has a meaningful overview and supported purpose views; unsupported visuals explain their prerequisites. Shared behavior is consistent without requiring identical charts or fabricated national summaries.

## 7. Verification and implementation safeguards

Run repository-required lint, types, tests and prefixed build for application changes as specified in `CLAUDE.md`; add targeted behavioral tests for search resolution, classification boundaries, state restoration, aggregation semantics and cross-view consistency. Browser checks must cover phone/desktop, touch/keyboard, loading, failure, suppression and missing geometry. Static screenshots alone do not verify interactions.

Use synthetic fixtures including multiple populated regions, repeated place names, zeros, withheld/missing observations, uneven denominators and boundaries absent for some rows. The existing one-region development figures are insufficient to prove global comparison behavior.

Maintain public aggregate-only access, release visibility, source-specific suppression, `/bhw-connect` URL handling and current CSP. Hosting is the existing Windows/PostgreSQL/PostgREST arrangement, not Supabase or Vercel. Keep changes inside the BHW-owned tree; follow the working agreement for any shared-path dependencies.

Before each implementation PR, Sol should state: user question answered, existing code reused, data prerequisites, exact phase acceptance cases covered, checks performed and remaining blockers. Do not mark a phase complete while its agreed features are merely deferred.

## 8. Remaining planning decisions

- Live review and baseline measurements remain outstanding.
- Decide overview headline order after the source/coverage audit. The default map is agreed: BHW headcount with proportional circles.
- Set bounded comparison limits and search grammar using realistic phone and desktop tasks.
- Choose a lightweight evaluation exercise for general readers, LGU users and analytical users.

Named saved configurations remain intentionally outside the early phases. The follow-up visualizations in Phases 2–3 are explicitly tracked scope, subject to their stated data prerequisites.
