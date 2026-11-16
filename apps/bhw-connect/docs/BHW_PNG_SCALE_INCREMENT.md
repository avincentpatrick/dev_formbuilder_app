# BHW analytics — metric scale in PNG exports

Phase 1 export-composition follow-up to #543 under the #525 handoff.
Question: “What approximate ground distance does the exported map show?”

The shared PNG capture now reads the existing metric MapLibre ScaleControl's
label and rendered width during the same render event as the map image. The
composer draws that bar below the viewport, multiplying its width by the same
CSS-to-export ratio used for the circle-size key. No second geographic distance
formula or external service is introduced.

MapLibre's installed implementation computes this scale near the map center;
the export explicitly describes it as approximate and location-dependent. It is
not a universal scale for every point on the map. Invalid, zero-width, missing or
nonmetric controls are omitted with an explicit BHW fallback note, never replaced
by an invented distance. The national locator omission remains disclosed.

Unit cases cover high-DPI capture, width scaling, invalid/missing controls,
rendered bar length and the approximation/fallback notes. Browser fixtures at
390px/1440px and device scale factor 2 export before and after zooming and verify
the live distance changes. Synthetic PNG artifacts are inspected outside git;
exact checks are in the PR. These are not physical-device or exhaustive tests.

No data, schema, dependency or disclosure changes. Locator and ranked-bar export
composition, full cross-page state and non-count editable domains remain Phase 1
work. Boundary/license/disclosure audits and representative-reader evaluation
remain separate prerequisites.
