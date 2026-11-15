# BHW analytics — editable workforce count classification

Phase 1 increment after #539 under the #525 assessment/live handoff. Question:
“How does the count pattern look using different color-group boundaries?”

## Scope and reuse

Count choropleths now offer quantiles, equal-width intervals and custom cut
points. Requested automatic group counts are 2–7; custom mode accepts 1–6 finite,
positive, strictly increasing cut points (2–7 groups). The first custom group
starts at zero and the last has no upper bound, so custom choices never exclude
counts. They are exploratory choices, not official standards.

This extends the existing map choice, count eligibility, quantile builder,
sequential palette, ColorBin contract, shared map legend and JSON/PNG export.
No new reader, dependency, data, source release or disclosure path is introduced.
Rates, means and contextual measures keep their existing source-appropriate
classification; their editable domains remain a separate follow-up.

## Behavior and acceptance

- Classification changes color groups, never values, published totals, selected
  areas or range eligibility. All reported child-area counts supply automatic
  boundaries; range filtering does not recompute them. Missing shapes remain
  included in that input. Missing/invalid observations are not classified as zero.
- Shared edges belong to the higher group. The final finite maximum is included;
  custom final groups are open-ended. Quantile ties can reduce the actual group
  count. Identical observations yield one group; absent observations yield none.
  The legend and explanatory text state method and actual/requested group count.
- Automatic boundaries recalculate when geography changes and explicitly are not
  common comparison breaks. Custom cut points remain fixed across geography.
  Public share links validate and retain method, group count/cut points, filter,
  measure and view. Invalid, duplicated or incompatible settings are rejected.
- Controls appear in Areas and data for eligible count choropleths. Circle view
  retains color settings without applying them to symbol sizes. Reset returns to
  the default five requested quantile groups and leaves the value filter intact.
  Changing indicator or dataset resets classification and range; supported
  geography remains governed by the existing map contract.
- JSON exports the effective bins, interval inclusion, method and group metadata
  without dropping complete rows. PNG includes the existing legend plus the
  classification explanation. In circle mode, stored color settings are labeled
  as applying only to choropleths. Renderer/capture versioning includes settings
  to prevent an export from mixing old map rendering with new metadata.

## Verification and remaining scope

Unit fixtures cover all methods, interval edges/maxima, duplicate/invalid custom
breaks, unsupported share links, ties, identical/empty data, independent filters,
missing geometry, unchanged totals and exported legends/notes. Phone/desktop
browser fixtures edit automatic/custom settings, reject reversed breaks, submit
with Enter, share/reset while preserving a range, change indicators and handle
empty data. Exact checks and synthetic visual/axe samples are recorded in the PR.

Phase 1 remains incomplete: editable non-count domains, full cross-page history
and export composition remain tracked. Synchronized comparison maps/common breaks
remain Phase 2 work. Physical-device/reader evaluation, nationwide/historical
boundary acceptance, licensing and the broader legacy disclosure audit are not
completed by these synthetic checks. No saved-account configuration is added.
