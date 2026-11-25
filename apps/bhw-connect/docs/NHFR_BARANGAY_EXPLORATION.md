# Barangay map and facility list

Implementation plan revised 2026-11-25 following the owner's instruction to proceed
with the available barangay data. Exact facility coordinates are optional future
work, not a dependency. Planning PR #642 remains unmerged; this implementation
uses a separate branch from main.

## Delivered increments

1. Complete facility retrieval: deterministic facility-code pagination with exact
   totals, duplicate detection and rejection of changing/partial reads. Reconcile
   facility and barangay-roster lengths with accepted aggregate totals before
   enabling exploration. A failed read is not a reported zero.
2. Barangay exploration: OR-type selection intersected with ownership, full roster
   including zero-count and unmapped barangays, numeric symbol legend and persistent
   selection from map or keyboard-accessible table. Expose missing geometry and
   unmatched assignment separately. No false facility coordinates.
3. Connected list: selected barangay narrows the list; clearing it includes unmatched
   assignments again. Existing URL-based name/type/barangay search remains list-only.
   Sort by name, type or barangay; display 25 facilities per page and mobile cards.
   Barangay table has independent name search, count/name sort and 20-row pages.
4. Presentation and downloads: Maximize and Present retain section filters and
   selected barangay. PNG/SVG/PDF/PPTX map downloads retain type/ownership scope,
   source, geometry attribution, legend and unplaced totals. They show the full
   extent with the available simplified outline, without a transient selection highlight, rather
   than capturing a user's pan/zoom. CSV/XLSX provide all filtered barangays, including
   zeroes and missing geometry. Separate facility CSV/XLSX include all matching rows
   across pages, selected barangay and list search. A zero-row CSV retains metadata
   in a blank data row. XLSX has an About sheet.
5. Verification: synthetic reconciliation, over-1000 pagination and later-page
   failures, actual export formats, catalog/legacy search/history, responsive map,
   selection, dialog and fallback journeys. See the implementation PR for results.

## Scope contracts

- Section filters do not change headline, mix, distribution, presence or report
  filters. Full-location reports retain their all-area/all-type scope.
- List search does not change map or barangay counts. Its separate facility downloads
  explicitly follow the search. Barangay-table search does not change downloads.
- Barangay selection highlights one point and narrows the list, without shrinking
  the map or changing its denominator. Zero-count barangays stay selectable.
- These are recorded inventory counts, not proof of physical absence, access,
  service availability, adequacy or current licensing compliance.
- The map canvas is decorative; zoom/reset controls are keyboard accessible and
  the complete barangay table provides selection without canvas interaction.
- Missing geometry or a failed map library/worker leaves tables usable. An invalid
  inventory or incomplete barangay roster disables the entire explorer explicitly.
- Both catalog and legacy municipality routes use the same reader/component/export
  model. No new schema, data load, external basemap or deployment setting is required.

## Deferred

Exact facility coordinates, cross-snapshot identity and full facility profiles,
travel times, catchments and service adequacy remain separate future work. Do not
infer these from representative barangay points. The loaded inventory is September
2026; representative points derive from faeldon/philippines-json-maps, 2023 PSGC
series, with the existing repository geography reconciliation.
