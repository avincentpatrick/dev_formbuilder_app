# BHW analytics — overview distribution preview

Phase 1 increment after the catalog continuity fix. Closes the missing overview
map/ranking preview from #525; the existing overview had only a link to the map.

Reuses DatasetMapCard, its lazy public map request, circle legend, responsive
map/data panels, RankedMapBars, exact table, disclosure and missing-geometry cues.
The preview starts with national LGU-reported headcount, independent of a saved
return map. Its edits do not rewrite the overview URL or national headlines.
Enlarge carries the explicit current preview settings into the dedicated workspace.
No new data reader, population substitution or disclosure policy is introduced.

Synthetic phone/desktop checks verify default headcount circles, visible linked
rankings, local selection without URL mutation and explicit enlargement. These
checks are not exhaustive geographic or physical-device acceptance. Consolidated
Phase 1 acceptance evidence and external gates are tracked separately.

Integration checks also ran against the committed development database: catalog
navigation retains its context when the privacy contract withholds seeded BHW
figures. The preview's multi-region behavior remains covered by explicit synthetic
browser fixtures; one populated development region is not nationwide coverage.
