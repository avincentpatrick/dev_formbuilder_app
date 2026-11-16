# BHW analytics — Phase 1 acceptance record

Requirements remain the assessment and live-verification handoff in [#525](https://github.com/avincentpatrick/dev_formbuilder_app/pull/525) and the
Phase 0 audit in [#527](https://github.com/avincentpatrick/dev_formbuilder_app/pull/527). This record consolidates evidence; it does not replace the
agreed scope or treat sampled observations as exhaustive coverage.

## Completion boundary

Implementation and automated acceptance are recorded separately from physical
phone/desktop reader evaluation, representative production measurements and data
supply/metadata acceptance. A missing observation or shape must remain honest;
passing a UI test cannot establish national data correctness or historical
boundary compatibility. Do not mark final acceptance complete while a required
external gate lacks evidence.

## Scenario matrix

| Phase 1 criterion | Implementation and reproducible evidence |
| --- | --- |
| Meaningful national visuals without choosing a region; honest unavailable states | `/bhw` uses the public catalog reader and existing figure/presentation components. Overview tests cover cleared, zero, missing and withheld values; the distribution preview uses the existing map and ranked bars. |
| Global place, indicator and mixed searches; duplicate names show parents | `lib/bhw/search`, exact PSGC lookup and existing fuzzy geography search; search unit/browser fixtures cover aliases, duplicate parent chains, distinct failed/empty/unsupported states and explicit populations. No map region constrains the search query. |
| Structured comparison resolves ambiguity explicitly | Existing comparison resolver requires place and measure choices; browser tests exercise ambiguous accreditation and explicit two-place choices. |
| Add/replace/remove up to four; phone focus and share state | Public comparison controls and validated URL builder; browser tests exercise two-to-four places, replacements, deletion, phone switching, refresh and over-limit requests without silent truncation. |
| Map selection, drill, reset, filters and custom groups; keyboard/touch alternatives | Existing DatasetMapCard and dedicated controls; separate count/percentage/mean filter and classification suites, history tests, named controls and phone panels. |
| Fresh headcount circles; explicit shared views; zero distinct from missing | Source-specific map defaults and size legend; symbol, value-filter and release-switch tests. No profile fallback for unavailable headcount. |
| Phone/desktop usable layout, available legend and controls | Responsive map/data panels and existing table scroll regions; panel/overview browser tests and separate accessibility/overflow audit. |
| Map, bars and table agree; share/export preserves settings and provenance | Shared `valueFilterState`, `rankedCounts`, classification/parser contracts; PNG size-legend/ranking/locator/scale tests and complete JSON export tests. PNG ranking truncation is explicitly labeled; full JSON remains complete. |
| Readable boundaries, named breadcrumbs, prominent missing shapes | Legend precision tests, parent lookup and Region VII 07306/07311/07313 fixtures. Missing shapes never remove rows or invent positions. |
| No new disclosure from discovery/visuals | New overview/measure/comparison use the catalog-wide disclosure contract; privacy, release-visibility and sentinel tests fail closed. Hidden profiling status stays hidden. |
| Cross-page and browser-history continuity | Overview, map, comparison, measure, search and catalog builders retain validated snapshots. Catalog forms/ancestors retain BHW. All datasets deliberately clears the selected dataset; unrelated datasets discard BHW return settings. |

## Scope and prerequisite dispositions

- The public period remains 2025 for headcount and profiles. Populations remain
  separate; percentages are not averaged across places, training topics/payers
  are not summed and low headcount is not labeled a staffing shortage.
- New paths do not fall back to legacy demographic/payer/amount readers or cached
  AI narratives. Deeper qualification/compensation integration remains Phase 2;
  contextual ratios/relationships remain Phase 3 with their original contracts.
- Synchronized maps/common comparison breaks, named saved configurations and
  localization remain outside Phase 1 as agreed.
- Historical boundary acceptance, missing licensed HUC geometry/crosswalks and
  source-license metadata remain unresolved data prerequisites. The current UI
  and exports disclose these gaps; neither filenames nor matching codes establish
  historical correctness. No replacement shapes or source permissions are invented.

## Reader evaluation checklist

Run on a physical phone and desktop. Record device/browser, task completion,
wrong interpretations and obstacles; omit personal records and production values.
The Phase 0 proposal calls for general-reader, BHW/LGU and analytical-reader views.

1. Open BHW and explain which figure is LGU-reported headcount versus validated
   profiles. Find the regional distribution without choosing a region first.
2. Find a municipality/barangay outside the current map region, including a repeated
   name; use parent names and codes to choose the intended place and measure.
3. Compare two places for an explicit accreditation population, replace/add/remove
   places up to four, switch phone focus and reopen the share link.
4. On the map, identify zero, missing and missing-shape areas; use the exact table.
   Exercise touch and keyboard alternatives for selection, drill and reset.
5. Filter values separately from color classification, edit boundaries, then explain
   why the published total is unchanged. Share/reload and export the result.
6. Visit overview, search, measure, catalog and comparison; return to the original
   map and verify indicator, area, selected place, view, range and color groups.
7. With a screen reader, identify controls, status/errors and the table alternative;
   record obstacles. An automated axe scan is not a substitute for this task.

Reader results: pending. Physical-device evidence cannot be supplied by the cloud
browser or inferred from emulator screenshots.

## Delivery and acceptance status — 2026-11-16

Phase 1 is **not signed off**. Catalog continuity (#565), the overview map/ranking
preview (#566) and source-specific license/accessibility fixes (#567) are merged.
The search navigation follow-up (#569) passed application CI but cannot merge
without Boundary. Map renderer reuse and this evidence record are the final
prepared increment, subject to browser/CI results below.

GitHub's Boundary workflow could not start twice. Its annotation states a
scheduled `pull_request_target` protection brownout for affected public
repositories until **2026-11-16 18:00 UTC**. See [run 43904894155](https://github.com/avincentpatrick/dev_formbuilder_app/actions/runs/43904894155).
Re-run the normal main-owned Boundary check after the window, then merge only
when every required check passes. Do not weaken the workflow or ownership rules.
The last successful completed production deploy checked before these merges was
[43900568706](https://github.com/avincentpatrick/dev_formbuilder_app/actions/runs/43900568706),
for #564. Newer skipped runs are not proof that #565–567 are live. A public HTTP
200 check of `/bhw` establishes reachability only, not verification of these releases.

## Engineering observations

Local prefixed production builds, Chromium 156.0.8078.79 headless on Debian 13.
Desktop 1440×1000;
phone 390×844, mobile/touch, 4× CPU slowdown, 150 ms latency, 1.6 Mbps download /
750 Kbps upload. Five new browser contexts per metric/profile, each with a cold
browser visit followed by a warm repeat. Server caches/process were not reset.
No Playwright network routes were installed for timing: request interception
would disable browser HTTP cache and invalidate the warm comparison.

A private synthetic HTTP fixture supplied one aggregate row and deliberately
withheld profile detail; actual committed boundary assets were used. This is a
bounded regression sample, not a population/coverage audit or representative
production measurement. Multi-region, missing-shape, zero, unavailable and uneven
value behavior is covered separately by explicit browser fixtures.

Durations below are milliseconds. Visual: navigation start to the rendered first
non-AI figure/status. Search: submit dispatch to visible results, with no debounce
(submit-only search). Map: navigation start to the renderer's ready marker; no
failure was counted as ready. No concurrent local build/browser suite ran during
this sample, but this shared cloud machine is not controlled physical hardware.

### After search navigation optimization, before map renderer reuse

| Profile | Metric | Cache | Five observations (ms) | Median | Maximum | Target |
| --- | --- | --- | --- | ---: | ---: | ---: |
| Desktop | visual | Cold | 1582, 1211, 791, 1538, 1017 | 1211 | 1582 | 3000 |
| Desktop | visual | Warm | 614, 574, 1030, 1057, 1261 | 1030 | 1261 | 3000 |
| Desktop | search | Cold | 173, 212, 197, 134, 118 | 173 | 212 | 1000 |
| Desktop | search | Warm | 250, 137, 155, 136, 107 | 137 | 250 | 1000 |
| Desktop | map | Cold | 2937, 3322, 2968, 2898, 2048 | 2937 | 3322 | 7000 |
| Desktop | map | Warm | 2601, 3174, 1773, 1762, 1882 | 1882 | 3174 | 4000 |
| Phone | visual | Cold | 1120, 1250, 1297, 1262, 1079 | 1250 | 1297 | 5000 |
| Phone | visual | Warm | 728, 690, 704, 1994, 1078 | 728 | 1994 | 5000 |
| Phone | search | Cold | 1170, 465, 345, 337, 744 | 465 | 1170 | 1500 |
| Phone | search | Warm | 332, 733, 736, 529, 1141 | 733 | 1141 | 1500 |
| Phone | map | Cold | 12527, 11368, 8607, 13503, 9180 | 11368 | 13503 | 10000 |
| Phone | map | Warm | 4768, 18996, 4177, 6788, 5246 | 5246 | 18996 | 6000 |

Every search and first-visual observation met its target in this sample. Phone
map readiness did not: three of five cold and two of five warm observations
exceeded the targets. A subsequent diagnostic trace measured 8,673/5,450 ms
cold/warm, with renderer-bundle download and main-thread long tasks visible; that
single trace does not erase the failures or establish a root cause for every outlier.
Cold-map performance remains open for controlled/representative measurement.

Before the search change, one phone search reached 1,907 ms against its 1,500 ms
target. An earlier timing attempt using Playwright request interception was
excluded from comparisons because it disabled HTTP caching; its warm timings
were not valid warm-cache measurements.

### Filter/classification response before renderer reuse

After data load: browser-dispatched Apply to the updated exact-table/legend state
plus a subsequent animation frame. This does not measure final GPU paint. Five
cold and five warm page loads per profile; the synthetic count was 100, filter
minimum 101, and custom boundaries 25/50. Classification followed filtering on
each loaded page.

| Profile | Metric | Cache | Five observations (ms) | Median | Maximum | Target |
| --- | --- | --- | --- | ---: | ---: | ---: |
| Desktop | filter | Cold | 224, 69, 62, 45, 103 | 69 | 224 | 200 |
| Desktop | filter | Warm | 36, 66, 49, 34, 68 | 49 | 68 | 200 |
| Desktop | classification | Cold | 26, 58, 136, 37, 66 | 58 | 136 | 200 |
| Desktop | classification | Warm | 34, 69, 62, 43, 38 | 43 | 69 | 200 |
| Phone | filter | Cold | 202, 583, 287, 393, 1447 | 393 | 1447 | 300 |
| Phone | filter | Warm | 334, 135, 256, 363, 166 | 256 | 363 | 300 |
| Phone | classification | Cold | 227, 582, 525, 171, 772 | 525 | 772 | 300 |
| Phone | classification | Warm | 180, 389, 312, 846, 172 | 312 | 846 | 300 |

These misses motivated retaining the existing renderer during exploration edits.
They are not classified as passed because their medians happen to be lower.

## Bounded automated accessibility evidence

Ten page/width combinations (overview, comparison search, four-place comparison,
scoped catalog and Region VII map at 390 and 1440 px) had zero axe violations,
zero captured page errors and zero ordinary horizontal overflow after the license,
link-name and renderer-lifecycle fixes. Synthetic map fixtures included the three missing HUC shapes.
This does not certify all states, actual touch use or screen-reader interpretation.
The new native search-navigation test verifies GET URL/prefix/map-state retention
without JavaScript; the existing streamed result boundary still requires JavaScript
to reveal results, so full no-JavaScript support is not claimed.

## Final code checks

- npm ci, lint and types passed. Lint retains one existing warning in the
  deliberate base-path census defect fixture.
- Full unit suite: 2,000 passed, 55 database-dependent skips. After removing the
  parent canvas remount key, all 201 map-focused unit checks passed again.
- Migration lint, prefixed no-data production build and standalone extractor
  verification passed. No migration or shared-path edit was needed.
- The selected browser suite ran 55 map/overview cases against the committed
  development database with explicit API fixtures where multi-area values were
  needed. The first run passed 53: one desktop initial render exceeded the
  existing five-second assertion, and one percentage-classification test raced
  a same-URL client refresh. That test now opens the share URL as a fresh
  navigation; both affected desktop cases passed the targeted rerun. This is
  not reported as an uninterrupted 55/55 run, and the render timeout remains
  part of the performance evidence.
- Retained-canvas assertions passed for filter/classification edits. A held
  boundary response verified loading, a synthetic failure, disabled zoom, and
  successful replacement. Existing rate/mean behavior, PNG/JSON exports,
  history, missing shapes, phone panels and overview preview checks passed.
- Production verification of the final release and physical-reader results are
  still pending. The signed-off engineering state cannot be inferred from a
  reachable URL or synthetic browser results.

## Interaction observations after renderer reuse

Same synthetic fixture, selectors and timing procedure as above, on the final
local renderer build. No concurrent local browser suite or build ran during this
sample. Map readiness is awaited before each interaction pair. These observations
measure table/legend response, not final GPU paint or production percentiles.

| Profile | Metric | Cache | Five observations (ms) | Median | Maximum | Target |
| --- | --- | --- | --- | ---: | ---: | ---: |
| Desktop | filter | Cold | 37, 125, 31, 47, 65 | 47 | 125 | 200 |
| Desktop | filter | Warm | 32, 36, 103, 36, 40 | 36 | 103 | 200 |
| Desktop | classification | Cold | 260, 92, 139, 77, 79 | 92 | 260 | 200 |
| Desktop | classification | Warm | 81, 195, 167, 69, 80 | 81 | 195 | 200 |
| Phone | filter | Cold | 1142, 494, 218, 232, 460 | 460 | 1142 | 300 |
| Phone | filter | Warm | 102, 164, 63, 99, 59 | 99 | 164 | 300 |
| Phone | classification | Cold | 276, 2275, 157, 78, 200 | 200 | 2275 | 300 |
| Phone | classification | Warm | 84, 105, 78, 163, 93 | 93 | 163 | 300 |

Warm phone interactions met the target in all ten observations. Cold-page phone
filter outliers and a desktop classification outlier still exceeded the targets;
the optimization is not a complete performance pass. Preserve these results,
repeat on controlled/representative devices, and resolve budget misses before
signoff. A smaller median does not waive the maximum or physical-device gates.

## Resume order after the external check window

1. Re-evaluate the latest production deploy and the required main-owned Boundary
   check after 18:00 UTC. The earlier skipped deploys do not count as healthy releases.
2. Merge #569 only with all checks green, then merge the renderer/evidence increment
   on the same basis. These branches preserve their dependency ancestry.
3. Verify the actual deployed release and rerun the affected live journeys without
   turning sampled public data into national coverage certification.
4. Complete the reader checklist above on physical phone/desktop and record the
   results alongside controlled performance observations. Keep data licensing,
   historical boundaries and missing HUC supply follow-ups explicit.
