# BHW analytics — national locator in PNG exports

Phase 1 export-composition increment after #546 under the #525 handoff.
Question: “Where in the Philippines is the area shown in this downloaded map?”

The PNG uses the live national locator's existing projected country/highlight paths
and small-shape ring. It introduces no alternate projection, boundary source or
inferred coordinates. The export labels whether the highlight is a selected supplied
boundary or available child shapes rather than a complete parent outline.

The locator reports ready/null results to a ref keyed by map query and selected
area. Export takes a matching snapshot and retains existing selection-version
checks. Missing, failed or loading locators are explicitly omitted; selecting a
Region VII HUC without a shape must never reuse the previous locality's locator.
National views with no selected locality do not add an unnecessary inset.

Drawing uses Canvas Path2D with the same paths as the existing SVG, fixed colors
and bounded 240px layout. Source and historical-compatibility notes remain. No
external image/font loading, new dependency, schema or disclosure changes.

Unit cases check path/ring reuse, invalid bounds and existing missing-shape rules.
Synthetic phone/desktop PNG cases wait for the live locator, while a missing-shape
case requires no locator and still permits export. Exact checks and inspected
artifacts are recorded in the PR; real records are not committed.

Full cross-page state and editable non-count domains remain Phase 1 work. Export
acceptance still requires representative-reader/physical-device evaluation; these
samples are not exhaustive geographic or historical-boundary certification.
