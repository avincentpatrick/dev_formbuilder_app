# BHW analytics — workforce count ranges

Phase 1 increment after #536; the deployment entry repair #538 is also merged.
Question: “Which child areas have a workforce count within my chosen range?”

## Scope and existing components

The existing BHW map, ranked bars, complete table, selection, breadcrumb links,
share links and JSON/PNG export are extended with an inclusive count range.
Controls are in Areas and data, separate from the existing view/classification
legend. Eligible measures reuse `supportsCircles`: total, registered,
registered-and-accredited, nonregistered and validated-profile counts. The range
accepts nonnegative safe whole numbers, with either limit optional. Zero and
identical endpoints are valid; reversed, fractional, nonfinite, duplicate URL
parameters and unsupported measures are rejected.

Rates, means and contextual measures do not gain range filtering in this slice.
Their domain/denominator treatment remains a separate follow-up. Public release
and indicator guards remain in force; no source access or data readers are added.

## Inclusion and comparison contract

- Both endpoints are included. Missing/invalid observations remain unavailable,
  not zero or merely excluded. Shapes are never an eligibility condition, so
  Region VII's missing HUC geometries cannot drop matching workforce counts.
- Excluded supplied shapes are white with dashed outlines. They have no count
  circle or label. Included zeros retain their hollow marker. The complete table
  adds Included / Excluded by range / Unavailable alongside the independent
  geometry status. All names remain selectable, including excluded places.
- Bars show the included counts. Symbol sizes, bar scale and color breaks use
  the full child set, so filtering cannot silently change classification or
  relative magnitudes. The existing national locator remains geographic context.
- Published parent totals and every complete-table value stay unchanged. The UI
  reports included, excluded and unavailable area counts; no filtered subtotal
  is represented as the published total. Empty results are explicit.
- Local name search continues to narrow only the selector. Count filtering is
  independent of that search. Selection is retained and its filter status is
  stated beside the map; reset removes the range.
- Range changes reuse the already fetched aggregate response. The renderer resets
  its ready/capture state while rebuilding the filtered map, and export-version
  checks include the range to reject a stale image during a changed selection.

## URLs, transitions and exports

Validated `valueMin` and `valueMax` parameters round-trip through the existing
map URL contract. Shared links and parent breadcrumbs carry them. Geography/view
changes retain the same measure's limits; changing indicator or dataset resets
limits (explained in the controls, and indicator changes show a reset notice).
The existing global national reset also resets the range.

JSON retains every original row with separate filter and geometry states, bounds,
coverage counts, complete-set scales and unchanged source/parent values. PNG
adds bounds, included/excluded/unavailable counts and interpretation notes to
its existing provenance. The map PNG still excludes the separate bar list and
national locator. No named saved configuration is introduced.

## Verification and remaining work

Unit fixtures cover zero, equal/one-sided bounds, invalid numbers, empty results,
unsupported/duplicate share parameters, missing HUC shapes, independent parent
totals, stable scales and export notes. Phone/desktop browser fixtures exercise
apply/reset, exact table states, selected missing-shape sharing, JSON, empty and
invalid ranges and indicator reset. The PR records exact checks and synthetic
accessibility/visual samples; these are not exhaustive or physical-device tests.

Phase 1 remains incomplete. Editable classification/group boundaries are next;
non-count range domains, complete cross-page history and full export composition
remain tracked. Historical boundary coverage/licensing, legacy disclosure review
and representative-reader/physical-device evaluation remain open prerequisites.
Windows deployment confirmation for #538 is independent of these local checks.
