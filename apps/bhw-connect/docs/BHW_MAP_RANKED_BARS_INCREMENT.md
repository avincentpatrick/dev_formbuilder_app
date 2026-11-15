# BHW analytics — linked workforce count bars

Phase 1 slice after #534 under the #525 assessment/live handoff. Question:
“How do the reported workforce counts compare across the child areas on this map?”

## Scope and reuse

The expanded BHW map's Areas and data panel now offers selectable count bars.
It uses the existing map response, supported-count eligibility (`supportsCircles`),
selected-area state, details, table, drill controls and share links. The existing
bar-chart client module gains a native-button counterpart using its `BarDatum`
contract and palette hook. The original Plot chart remains available; its spec
and Plot load together only when that chart renders. No new fetch, database
reader, dependency, public source or classification is introduced.

Supported measures are LGU total/registered/registered-and-accredited/nonregistered
counts and validated profile counts. Rates, means and contextual population or
household counts retain their existing exact-value views. Rate/mean rankings need
separate denominator, uncertainty and small-sample treatment; this slice does not
substitute sorted values for that analysis. Hidden sources remain hidden.

## Acceptance behavior

- Bars use all returned child areas with a finite nonnegative count, descending.
  Equal counts are tied; name then PSGC code make their display order deterministic.
  No top-N cutoff, fabricated rank or geographic coverage filter is applied.
- Zero is included with a printed zero and no filled length. Missing/invalid
  observations are explicitly unranked and remain in the complete table/selector.
  All bars share a zero baseline and a scale relative to the current child set.
- Region VII missing shapes 07306, 07311 and 07313 cannot remove available counts
  from the bars. Selecting an unshaped area keeps the existing missing-geometry
  message and locator behavior; it never invents a position.
- Each full-name/code bar is a touch/keyboard button with a visible Selected label
  and aria-pressed state. The existing selector and map use the same selection.
  Share links retain it. Area search changes only the selector, not the bars,
  complete table, reported total or downloads.
- Phone users reach the bars through Areas and data; desktop uses the adjacent
  panel. A labeled scroll region bounds long lists without dropping rows.
- The metric, period, unit, peer scope, missing count and tie rule are stated.
  Low workforce count alone is not described as a staffing shortage.
- Map PNG remains a map export and excludes bars (stated beside them). Existing
  JSON retains complete area values and the shared selection. Full chart/export
  composition is a tracked follow-up.

## Validation and remaining work

Unit fixtures cover the three missing-HUC codes, zero, null, equal values,
repeated names, invalid numbers and empty input. Browser fixtures cover phone and
desktop keyboard/click selection, selector synchronization, local search, missing
geometry, shared links, empty counts and absence of count bars for a rate.
Exact test/build and sampled accessibility outcomes are in the PR.

Phase 1 remains incomplete: named direct-link breadcrumbs, independent value
filters/classification, robust cross-page history and complete exports remain.
Rate/mean ranking and uncertainty presentation remain explicit follow-ups.
Physical-device and representative-reader checks, nationwide boundary coverage,
historical boundary acceptance, source licensing and legacy disclosure review
are still open; synthetic emulation does not complete those prerequisites.
