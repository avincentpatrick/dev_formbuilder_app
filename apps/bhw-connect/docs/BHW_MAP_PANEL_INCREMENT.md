# BHW analytics — responsive map panels

Next reviewable Phase 1 slice after #532, under the #525 assessment and live
verification handoff. This addresses L05's hierarchy and responsive-panel task;
it does not complete the map workspace or Phase 1.

## Reader question and reuse

“How can I read the map and legend, then inspect a place without losing my selection?”

The existing dataset-map page, card, MapLibre canvas, size/color legends, area
search/selector, complete table, source notes and PNG/JSON exports remain in use.
The change adds no reader, source, indicator, dependency, migration or geometry.
The established public aggregate eligibility and disclosure rules still apply.

## Behavior and acceptance

- Expanded BHW Headcount and Profiles pages use compact dataset/indicator/view
  controls. The legend appears above the map. Count magnitude samples retain the
  same pixel radii as the live symbols; zero/missing and aggregate-location meaning
  remain visible. Extra circle methodology is expandable.
- Below the desktop breakpoint, labeled native buttons switch between Map and
  legend and Areas and data. Both panels stay mounted, preserving selection,
  selector search and map viewport. Hidden controls leave the tab order. The
  selected place/value also appears below the map; details and drill-down remain
  in the Areas and data panel.
- Desktop displays map and details in adjacent columns. Long details scroll in a
  named region; the existing complete table retains its own labeled scroll region.
- Indicator and view controls remain available in either panel. Dataset changes
  retain their existing scope rules. Panel visibility is a local presentation
  choice; URLs and exports continue to record the effective analysis selection.
  PNG download stays in the visible map panel on phones so a hidden canvas cannot
  be captured; JSON remains available from either panel.
- Failed boundary rendering retains the area/table route. Retry is available in
  the map panel. No geometry is invented for unmatched areas, including the
  Region VII HUC cases retained from #532.
- Expanded BHW canvases use a phone height with a 240px floor and a taller desktop
  height. Embedded cards and other dataset layouts retain their prior canvas sizing.
  This is a responsive workspace, not browser fullscreen.

Browser checks cover initial controls/legend inside the 844px viewport at sampled
320/390/1440px widths, a usable canvas, adjacent desktop details, touch and keyboard
panel switching, retained missing-shape selection/search, one mounted map and one
aggregate request across switches, zero values, and boundary failure/retry.
Existing map suites verify release changes, values/denominators, exports and district
links. Exact outcomes are recorded in the PR; these are synthetic/emulated checks,
not physical-device or exhaustive geographic certification.

## Remaining accepted scope

Readable map labels, national locator inset, linked ranked bars, recognizable
named direct-link breadcrumbs, independent filtering/classification, custom and
stable comparison breaks, browser history for all analysis changes, and their
export treatment remain explicit follow-ups. This slice does not claim them.

The historical-boundary/license prerequisites, missing HUC boundary reconciliation,
legacy disclosure audit and representative-reader/physical-device evaluation remain
open as recorded in the handoff. No source eligibility is relaxed to support layout.
