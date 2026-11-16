# BHW analytics — preserve map return through national methodology

Phase 1 increment after #554 under the #525 handoff.

Question answered: can a reader leave a configured map, inspect the overview's
national indicator details, and still return to that map? The methodology link
now carries the validated map snapshot into the existing catalog return panel.
Catalog scope remains national:PH, matching its label and the overview figures.
The local snapshot does not filter national data or imply local totals.

Reuses the catalog route builder, map parser/serializer and return panel. No new
data prerequisites, readers or disclosure changes. Invalid, repeated, non-BHW and
hidden snapshots are not propagated; the overview's invalid-state feedback stays.
Fresh overview visits retain the existing national catalog destination.

Synthetic phone/desktop regression journeys cover edited filters, classification,
selected missing-shape HUC, overview sections, national methodology, reload and
return to the exact map URL, plus browser history. Unit checks verify national
scope and rejection of unsupported snapshots. This is bounded navigation coverage,
not exhaustive geography, live-data validation, physical-device or reader acceptance.

Remaining cross-page journeys, contextual contracts, boundary/license/disclosure
work and reader acceptance remain Phase 1 follow-ups. Comparison synchronization
remains Phase 2; Phase 1 is not declared complete.
