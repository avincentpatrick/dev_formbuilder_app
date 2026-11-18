# Switching BHW map views without restarting the map

18 November 2026. Phase 1 performance and viewport follow-up.

Changing circles to choropleth previously changed the aggregate-request key and
the renderer lifecycle key. It reread unchanged values/boundaries, replaced the
canvas and fitted the initial bounds again, losing the current viewport. The
reverse transition behaved the same way.

View now remains a share/export setting but is excluded from the aggregate key.
The existing canvas keeps its symbol layers and switches their visibility,
polygon colors and label offset through `applyDatasetStyle`. Click handling reads
the current view. Export invalidation still includes view, and data/geometry
changes retain their loading, failure and retry lifecycle. Values, coordinate
files, circle sizing, filter semantics and disclosure rules are unchanged.

The new browser regression failed on the unchanged build at both widths because
the original canvas was replaced. It passes with this change: start in choropleth
at 390 px or circles at 1440 px, switch both ways, retain selection and the same
canvas, and issue no extra aggregate/boundary requests. The existing replacement
failure test now changes indicator (a genuine data change), holds/fails the new
boundary response, then verifies retry. Styling tests cover hidden/visible
positive and zero circles and the corresponding label offset.

Validation: npm ci, lint (existing deliberate-fixture warning), typecheck,
2,029 unit tests with 55 database-dependent skips, migration lint, prefixed
no-data build and standalone extractor verification passed. All 23 selected
browser cases passed, including both new regressions, circle/zero values,
classification/filter settings, PNG export, map-only and automatic geographic
zoom. Seeded full-browser CI and deployment verification are separate gates.

This removes a demonstrated reload/viewport regression. It does not by itself
close the broader map-readiness or interaction-budget findings; initialization
readiness is not a measure of every subsequent GPU paint. The preceding font
trial and timing limits remain in [DATASET_MAP_PERFORMANCE.md](DATASET_MAP_PERFORMANCE.md).
