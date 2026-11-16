# BHW analytics — return from public comparison to the original map

Phase 1 increment after #559 under the #525 handoff.

The selected-map comparison link now carries a validated map snapshot. Public
comparison reuses the existing return panel to restore the original indicator,
area, selected child, view, range and classification. Comparison measure/place
edits, phone focus links, recovery and share links retain that snapshot. The
panel explains that comparison uses its own settings; map filters and color
groups do not change comparison figures. Returning restores the original map,
even after the comparison measure has changed.

Reuses the existing map parser/serializer, comparison URL builder and return panel.
Invalid, hidden, repeated and arbitrary destination snapshots produce no return
link and are not propagated. Invalid comparison settings can still show a valid
map return. No new readers, data prerequisites or disclosure changes. Separate
measure-detail and overview journeys remain independent follow-ups.

Unit coverage checks independence of comparison and snapshot settings and invalid
snapshots. Phone/desktop synthetic navigation visits comparison from an edited
missing-shape HUC map, reloads, changes the comparison measure, checks sharing,
reloads and restores the exact original map. This is bounded navigation coverage,
not exhaustive comparison arithmetic, geography, physical devices or readers.

Remaining Phase 1 continuity, contracts, boundary/license/disclosure work and
reader acceptance stay open. Synchronized maps/common breaks remain Phase 2.
