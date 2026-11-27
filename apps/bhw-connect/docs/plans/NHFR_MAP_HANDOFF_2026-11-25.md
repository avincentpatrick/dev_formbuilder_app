# Health facilities map: planning handoff

Status: planning only, 2026-11-25. This document's draft PR must remain unmerged.
The user will start a new session to execute the work. No map, database, ingestion,
profile or deployment change is implemented by this planning PR.

## Start here in the next session

Read `apps/bhw-connect/AGENTS.md`, `apps/bhw-connect/CLAUDE.md`, the repository's
`docs/working-agreement.md`, and `apps/bhw-connect/docs/HANDOVER.md` first. Paths in
this document's implementation inventory are relative to `apps/bhw-connect/`.

Suggested continuation prompt:

> Read docs/plans/NHFR_MAP_HANDOFF_2026-11-25.md from the unmerged planning PR.
> Continue the health-facilities map work from this handoff. First identify and
> assess the facility-coordinate source: the user believes exact facility plots
> exist. Distinguish recorded/verified facility locations from approximate points
> and barangay representative points. Preserve the completed NHFR improvements.
> Plan individual facility profiles later; do not implement full profiles now.
> Explain the source findings and resulting map recommendation before committing
> to a location model that the data cannot support.

Fetch current `origin/main` and inspect the planning PR before implementing. The
planning branch is `docs/nhfr-map-planning-handoff`; it starts from main commit
`2f3a62cb7e4e9d240cd0208dc768f8f59320293f`. Main can advance after this note. Read
and carry this document into an implementation branch as appropriate; do not merge
this planning PR merely because CI becomes green. The user's explicit unmerged
instruction overrides the repository's normal merge-on-green workflow for this PR.

## User intent and how the discussion changed

The user has been improving the NHFR Health facilities card section by section:
assess the existing content and data first, recommend improvements, then implement
when instructed. They want understandable epidemiological explanations, effective
visuals, optional technical notes, useful downloads/presentation, and layouts that
fit phones, tablets and laptops. Facility-type filters must support multiple choices.

The latest map discussion is still planning. The user said:

> "this is also a health facility with exact plots as far as i know. how do
> maximize that data? also, we will plan for individual facility profile data later on"

The user's belief that exact plots exist is an important source lead, not a fact
verified in this session. They did not identify the exact website, export, API or
file supplying those plots. The assistant's earlier statement that the registry
has no coordinates was too broad: it described the loaded dataset/current map,
not necessarily the NHFR website or another available location source. Do not
repeat that broad claim as a settled limitation of every source.

Explicit preferences:

- Investigate and make good use of facility-level locations if available.
- Keep the facility-map work distinct from later individual facility profiles.
- Preserve multi-select types, readable explanations, responsive behavior, optional
  technical detail and appropriate viewing/export controls established elsewhere.
- Save the plan, context and pending work in a committed, pushed, unmerged PR.

The map designs below are recommendations from this session. They have not yet
been implemented or fully locked following a verified coordinate-source audit.

## Already merged: build on these changes

| PR                                                                      | Completed work                                                                                    | Repository context                             |
| ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- | ---------------------------------------------- |
| [#633](https://github.com/avincentpatrick/dev_formbuilder_app/pull/633) | Headline: multiple types, plain-language readings, technical disclosures, viewing/export controls | [Headline contract](../NHFR_HEADLINE.md)       |
| [#635](https://github.com/avincentpatrick/dev_formbuilder_app/pull/635) | Facility mix and ownership views, multiple types, sorting, presentation and exports               | [Facility mix](../NHFR_FACILITY_MIX.md)        |
| [#636](https://github.com/avincentpatrick/dev_formbuilder_app/pull/636) | Geographic distribution, government/private bars, area/type filters and full-parent denominator   | [Distribution](../NHFR_DISTRIBUTION.md)        |
| [#637](https://github.com/avincentpatrick/dev_formbuilder_app/pull/637) | Barangay presence: distinct OR-type unions, per-area denominators, controls and exports           | [Presence](../NHFR_PRESENCE.md)                |
| [#638](https://github.com/avincentpatrick/dev_formbuilder_app/pull/638) | Exact type figures integrated into facility mix; independent lookup search                        | [Exact type figures](../NHFR_EXACT_FIGURES.md) |
| [#639](https://github.com/avincentpatrick/dev_formbuilder_app/pull/639) | Exact area figures integrated into distribution; jointly verified counts and presence             | [Exact areas](../NHFR_EXACT_AREAS.md)          |
| [#641](https://github.com/avincentpatrick/dev_formbuilder_app/pull/641) | Full-location reports replace the old growing/truncated summary PNG                               | [Location report](../NHFR_LOCATION_REPORT.md)  |

These PRs were confirmed merged. Do not redo their implementations. Merge state
is not evidence that the latest change is live: recheck the deployment and site
before making a deployment claim. PR #641's required checks passed; its local
verification recorded 2,323 unit/database tests passed, 13 skipped, both prefixed
production builds, actual city/national report flows and four-width accessibility
checks. Those results are baseline evidence, not tests of the pending map changes.

The reviewed [Deploy run](https://github.com/avincentpatrick/dev_formbuilder_app/actions/runs/45534680267)
completed successfully for PR #641's merge commit `2f3a62cb7e4e9d240cd0208dc768f8f59320293f`.
This is deployment-run evidence; this handoff did not independently inspect the live
site. Recheck current runs and the relevant pages in the next session.

Preserve the existing contracts:

- Each card's filters have an explicit scope. The headline, mix, distribution and
  separate presence card retain their own selections. Exact figures follow their
  parent card; table search does not silently change chart selections.
- Type selections use OR. Distinct barangay presence must not be summed across
  overlapping types. A barangay containing several selected types counts once.
- Geographic facility shares retain the full matching-type parent facility total.
  Barangay presence retains all barangays in each area's own denominator.
- Zero, unavailable, unmapped and unassigned are different states. Incomplete reads
  do not become zero counts. Partial rosters do not become complete comparisons.
- Figure exports retain their selection and source context. The general location
  report deliberately ignores individual card filters and list search.
- Location reports provide PDF, PPTX, complete aggregate CSV/XLSX, and PNG/SVG ZIP
  bundles of separate pages. They are not individual facility directories.

## Verified current implementation and its limits

| Area                   | Current evidence                                                                                                                                                                                            | Consequence                                                                                                                                  |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| Loaded facility schema | `db/baseline/schema.sql` defines `fact_nhfr_facility` without latitude/longitude. The schema has a unique `(dataset_id, facility_code)` constraint.                                                         | No exact coordinates are available through this loaded shape. Do not treat the surrogate row ID as a permanent identity across snapshots.    |
| Source import contract | `ingestion/clean_nhfr.py` lists the expected September 2026 workbook headers. That documented header has no latitude/longitude fields. Street/building fields are excluded from the cleaned public extract. | This says what the existing import supports, not what other exports or the current website might supply. Recheck before extending ingestion. |
| Facility reader        | `lib/db/nhfr.ts` projects code, name, type, ownership, barangay attribution, bed capacity and licensing status, not coordinates. `getNhfrFacilities` is capped at 1,000 records.                            | Any broader map/list implementation needs complete, deterministic pagination or explicit failure; do not plot a truncated list as complete.  |
| Existing geometry      | `lib/geo/barangay-centroids.ts` reads province-keyed representative-point files generated from barangay boundaries.                                                                                         | These points locate barangays, not buildings. Missing point files resolve to an empty map.                                                   |
| Existing map model     | `lib/geo/facility-points.ts` groups facilities by barangay code, then draws one point per barangay, with zero-count barangays included.                                                                     | It is a barangay-presence/count map. Multiple facilities at its one point do not imply co-located buildings.                                 |
| Rendering              | `components/maps/point-map.tsx` uses MapLibre, a local outline, filled/hollow circles, zoom/reset, hover/tap tooltips and cooperative gestures.                                                             | There is currently no facility-marker layer, source accuracy classification, persistent selection or per-map type filtering.                 |
| Accessibility          | The map canvas is `aria-hidden`; map controls have `tabIndex=-1`. The facility list is described as its accessible alternative.                                                                             | The list does not represent zero-facility barangays or provide equivalent spatial selection. Add a usable keyboard/DOM experience.           |
| Catalog entry          | `components/catalog/nhfr-details.tsx` builds a map only at city/municipality level, after a full facility-list count check. It renders the map only when at least one point exists.                         | With no drawable points, the map can disappear without a dedicated explanation.                                                              |

Other useful source context:

- `components/facilities/facility-map.tsx`: current title, legend, location caveat and
  unplaced totals. Some comments/text make overly definite absence/location claims.
- `components/facilities/facility-list.tsx`: existing searchable facility list.
- `components/maps/load-maplibre.ts`: existing library/worker loading convention.
- `ingestion/ingest_nhfr.py`: facility import and canonical geography mapping.
- [Centroid reconciliation](../BARANGAY_CENTROID_RECONCILIATION.md) and
  [Boundary reconciliation](../BOUNDARY_RECONCILIATION.md): source geometry limits.
- [Original NHFR plan](../NHFR_2026_PLAN.md) and
  [Linked context](../NHFR_LINKED_CONTEXT.md): earlier assumptions and supported joins.

There was no coordinate-bearing source file identified in this workspace audit.
The known official registry site, <https://nhfr.doh.gov.ph/>, is a lead for the next
audit, not a coordinate feed verified in this session. The cleaner refers to
`ingestion/data/nhfr_universe_as_of_September_2026.xlsx`; this audit inspected its
import contract rather than a coordinate-bearing source workbook.
The original external location source remains unresolved. Existing comments that
say "no lat/long" must be interpreted as the current snapshot/import's limitation.

## Pending work, in execution order

### 1. Coordinate-source audit: first checkpoint

- [ ] Inspect available repository/workspace evidence and relevant official source
      material before asking for information that may already be available.
- [ ] Identify the source the user means: URL/export/API/file, publisher, version,
      retrieval date, coordinate fields, reference system and reuse/attribution terms.
- [ ] If it remains unidentified, ask the user which source/site supplies the exact
      plots. That question is outstanding from the previous session.
- [ ] Determine whether points are recorded facility coordinates, verified building
      positions, approximate address geocodes, barangay representative points, or a
      mixture. A displayed pin does not establish building-level accuracy.
- [ ] Join on facility identifiers with explicit snapshot/version handling. Audit
      unmatched identifiers, duplicate codes, retired/new facilities and code changes.
      Names alone are not a certified match.
- [ ] Check missing/zero/out-of-range coordinates, swapped axes, repeated points,
      apparent regional/head-office centroids and administrative mismatches. Flag
      issues; do not silently guess corrections or shift points to fit an outline.
- [ ] Record aggregate coverage counts by type/geography and by location quality,
      plus source evidence and remaining uncertainty. Keep raw source records out
      of this public repository.
- [ ] Present the findings and revised map recommendation. Do not relabel current
      barangay points as exact while waiting for the real source.

If a coordinate source has a different date from the September 2026 NHFR inventory,
preserve both vintages. Do not silently claim that a later location record proves
where a facility stood in the older snapshot.

### 2. Conditional location-data foundation

If trustworthy facility coordinates exist, design an additive, versioned location
representation before wiring markers. Candidate information to preserve: facility
code/dataset association, latitude/longitude and reference system, location kind,
quality/verification status, source reference/version, source/retrieved/verified
dates, and match method. Exact columns/tables remain a design decision after audit.

Use the existing dataset/code uniqueness for snapshot joins. Assess a durable
cross-snapshot facility identity and code lineage for future profiles; do not assume
an auto-increment row ID or a name is stable. Keep precise and approximate locations
distinguishable, and retain unmapped facilities in inventory/list totals.

A real data load, schema migration, source publication and public field exposure
are separate reviewable steps under repository rules. Do not restore excluded
personal contacts or street-address fields merely because a map is being improved.
The coordinate source's content and public suitability need to be assessed directly.

### 3. Recommended map modes and interactions

Conditional primary mode: **Facility locations**, if usable facility coordinates are
confirmed. Keep **Barangay presence** as a separate analytical mode.

- [ ] Cluster facility markers with counts at wider zooms; reveal individual
      facilities as users zoom in. Explore broader geographic scales only after
      source coverage and loading behavior are verified.
- [ ] Preserve distinct facilities sharing a coordinate. Show a co-location chooser
      or list; do not jitter markers into invented distinct positions.
- [ ] Support multiple facility-type selections, name search and useful ownership
      filtering. Define/reset scope explicitly; do not silently change other cards.
- [ ] Link selection between map and list. Use a persistent details panel on tap/click
      rather than relying on hover, especially on phones.
- [ ] Keep the immediate panel compact: facility name, type, ownership, location
      description and source/accuracy context where relevant. This is not the later
      full facility profile.
- [ ] Assess a contextual basemap/outline choice, required attribution and CSP/network
      implications. Preserve local worker loading and cooperative page gestures.
- [ ] Show how many selected facilities are represented and how many lack usable
      locations. Distinguish missing assignment from missing/invalid coordinates.

For the barangay mode:

- [ ] Retain one symbol per barangay, with filled/hollow distinction and a numeric
      size legend. Reassess the current minimum-radius scaling; do not describe its
      current formula as a strictly area-proportional quantitative scale.
- [ ] Use a title such as "Where are facilities recorded by barangay?" and wording
      such as "No facility recorded for this barangay," not proof of no facility.
- [ ] Keep "Each circle represents a barangay, not an exact facility location"
      visible whenever representative points are used. Longer methods belong in a
      closed "About this map" section.
- [ ] Add an exact barangay table including zeroes and mapping status, with appropriate
      search/sorting. The facility list alone does not replace this table.
- [ ] Make counts/legends/totals follow the same selected types. Maintain complete
      denominators and explicitly reconcile plotted, approximate and unplaced totals.
- [ ] Explain fully unavailable geometry explicitly rather than omitting the map.

Across both modes:

- [ ] Keyboard-accessible controls and DOM selection/list equivalents; no reliance
      on tiny circle taps, hover or colour alone. Prevent tooltip/panel overflow.
- [ ] Phone/tablet/laptop layouts, Maximize and Present; preserve selections and mode.
- [ ] Appropriate PNG/SVG/PDF/PPTX and CSV/XLSX exports, showing the same selected
      scope, symbols/size legend, source dates, quality and unplotted totals. Basemap
      credits must survive exports. Do not export representative points as exact
      facility coordinates.
- [ ] Preserve count and percentage formatting from `lib/format.ts` and map-return
      navigation through the existing catalog journey helpers.

Reliable coordinates could later support clearly labelled straight-line proximity
analysis. Travel times, routing, referral networks, service catchments or adequacy
claims need additional validated evidence and are not part of this approved plan.

### 4. Searchable facility list: assessment still pending

The next section after the map is the searchable individual facility list. Some
scope/name/availability fixes and regression tests already exist, but this session
has not completed its next design assessment. Assess it alongside map selection,
complete pagination, sorting, type/ownership filters, exact/download scope, mobile
readability and future profile links. Do not interpret this as approval to build
new full profiles or expose more source fields now.

### 5. Individual facility profiles: explicitly later

Reserve a future phase for individual profiles, their field inventory, publication
rules, source completeness, status/date interpretation and profile navigation.
Establishing identifier lineage now should make that phase possible without
implementing it prematurely. A source-reported licence field must not become an
unsupported compliance verdict; blank licensing status is not "unlicensed."
Services, beds and licensing fields need their own source/meaning review when the
profile phase is planned.

## Implementation validation checklist for the next session

- [ ] Synthetic cases for exact versus approximate/representative/unmapped locations,
      missing assignments, duplicate IDs, co-located facilities, invalid coordinates,
      no drawable points and fully verified zero selections.
- [ ] Reconcile all selected facilities, not just records fitting a query limit.
      Reject duplicate/partial data rather than hiding negative shortfalls with a clamp.
- [ ] Verify multi-type OR behavior, complete barangay denominators, type/ownership
      filtering, mode changes, selected details, failed reads and superseded requests.
- [ ] Keyboard, touch and screen-reader/DOM equivalents; four-width overflow checks
      (320/390/768/1440px), Maximize/Present, selected panel and exact-table behavior.
- [ ] Real export files: scope, location quality, source/attribution, all rows/pages,
      exact values and explicit unavailable statuses; no invented precision.
- [ ] Run relevant unit/database/browser tests, lint, TypeScript and required prefixed
      builds. Regress headline, mix, presence, exact figures, report and facility-list
      flows. Use applicable skills for implementation; do not infer hosted Supabase
      or Vercel behavior from legacy package names.

Existing test anchors: `lib/geo/facility-points.test.ts`,
`e2e/nhfr-facility-list.spec.ts`, `e2e/nhfr-card-entry.spec.ts`,
`e2e/nhfr-headline.spec.ts`, `e2e/nhfr-mix-card.spec.ts`,
`e2e/nhfr-presence-card.spec.ts`, `e2e/nhfr-exact-areas.spec.ts`, and
`e2e/nhfr-report.spec.ts`.

Local scratch logs/images from the prior session may exist under `/workspace/outputs`,
but they are not a durable handoff dependency. Recreate fixtures and setup from the
repository in a fresh environment. Docker services may be stopped after a restart;
start only BHW's own project from `apps/bhw-connect/` and preserve existing volumes.
There is no production database or self-hosted server access in this workspace.

## Scope and shipping reminders

This repository is public. Only BHW-owned paths can be changed without the other
owner's approval; shared paths and `ops/` follow their own boundary rules. Keep raw
records, credentials and generated source files out of git. Use synthetic test data.
Migrations are additive; future source-data loads/server changes require the
appropriate operator rather than pretending a local development fixture is live.

This PR is a planning artifact only: commit, push, open as draft and leave unmerged.
For a future implementation PR, follow the user's then-current instructions and the
repository's normal verification/deployment workflow. Do not claim a change is live
from its merge state alone.
