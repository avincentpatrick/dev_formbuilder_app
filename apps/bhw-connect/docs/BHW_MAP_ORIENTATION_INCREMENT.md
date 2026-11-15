# BHW analytics — place labels and national locator

Next reviewable Phase 1 slice after #533, under the #525 assessment and live
verification handoff. This implements the place-label and national-orientation
parts of L06; the map workspace and Phase 1 remain incomplete.

## Reader question and reuse

“Which places am I looking at, and where are these boundaries in the Philippines?”

The existing dataset map canvas now draws BHW place names from the same accepted
area rows used by its selector/table. The existing `buildLocatorMap` projection
and place-thumbnail SVG are reused for a small national locator. Pure projection
code moves to `lib/geo/locator-shapes.ts`; server filesystem access remains in
`lib/geo/locator.ts`, whose public exports stay compatible with existing callers.
No new database reader, measure, dependency, migration, font service or source is
introduced. Only already supplied public boundaries and approved aggregate names
are involved. Historical boundary acceptance and source-license gaps are not solved.

## Behavior and acceptance

- BHW Headcount and Profiles maps show collision-managed names at approximate
  centers of supplied boundary bounds. Missing observations and zero counts retain
  names if their shape exists. Rows without shapes receive no fabricated label
  position. Larger zoom levels allow more names; the existing selector/table
  remains the complete accessible path to every name and value.
- The installed MapLibre version draws glyphs locally when no glyph URL is set.
  Label styling uses a local sans-serif font, white halo, variable placement and
  overlap prevention. No CSP change or external font origin is needed. Label
  clicks select the same area as its polygon or count symbol.
- Expanded BHW locality maps show a national locator using the same boundary
  collection already loaded by the main map. A selected row highlights only its
  supplied shape. Without a selection, the locator highlights available child
  shapes for the current scope; it explicitly does not claim a complete parent
  outline. A ring makes a very small supplied shape visible.
- Region VII's missing `07306`, `07311` and `07313` shapes remain unplaced. Selecting
  one removes the locator highlight and states that no position is inferred. The
  values, complete table, source total and shared selection remain intact.
- National-context loading failures have their own retry button and do not disable
  the thematic map or table. Missing main-map boundaries cannot produce a locator.
- Labels are part of the WebGL canvas and therefore appear in captured map PNGs,
  subject to collision/zoom placement. The separate SVG locator, like the live DOM
  scale bar, is not included in PNG in this slice. The UI and PNG notes state this
  limit. JSON retains all accepted names/values and geometry statuses; no new
  user-selectable map setting is introduced by orientation.

## Verification and remaining scope

Pure tests cover labels for zero/missing values, deduplication, tiny-shape markers,
constant country context, selected versus available-child outlines, empty geometry,
and the required missing-HUC cases. Browser cases cover phone/desktop locality
selection, missing-shape shared links, national-context failure/retry and empty
boundary collections. Existing map suites cover panels, controls, exports,
release switching, rates/means and district links. Screenshot/PNG inspection is
needed to assess rendered name placement; readiness alone does not verify labels.
Exact outcomes are recorded in the PR. Synthetic and emulated samples are not
exhaustive national coverage or physical-device validation.

Linked ranked bars, named direct-link breadcrumbs, independent value filters and
classification/custom breaks, stable comparison breaks, complete analysis history,
and full export treatment remain explicit follow-ups. Representative-reader and
physical-device evaluation, boundary reconciliation/licensing, and the broader
legacy disclosure audit remain tracked prerequisites. Suppressed sources stay
suppressed; this increment adds no alternative access path.
