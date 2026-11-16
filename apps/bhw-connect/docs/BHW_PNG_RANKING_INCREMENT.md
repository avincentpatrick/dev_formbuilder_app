# BHW analytics — ranked workforce bars in PNG exports

Phase 1 export-composition increment after #544 under the #525 handoff.
Question: “Can the downloaded map show the exact count comparison beside its visual story?”

The PNG now includes up to 20 included child areas, largest count first, for the
existing eligible workforce count measures in either circle or choropleth view.
It reuses rankedCounts, valueFilterState, supportsCircles and symbolMaximum.
Equal counts retain name/code ordering. The shared scale uses all child areas,
independently of filtering; geometry never gates inclusion. Zero has no filled bar.
Missing values remain unranked and navigation-only/rate/mean measures get no chart.

The section states included, omitted, range-excluded and unavailable counts. It
labels missing shapes and the selected area when shown. Long labels wrap, and
space is measured before rendering under the existing image-height limit. The
20-row cap keeps the image readable; it does not alter the complete JSON rows or
published parent total. A selected area outside the summary retains its existing
selected-value provenance note. No ranking number breaks ties or implies shortage.

Unit fixtures cover filtered full-set scales, missing shapes, ties, zero, empty
results, navigation-only data, eligibility and explicit truncation. Existing PNG,
classification and filter browser fixtures supply synthetic phone/desktop checks;
PNG artifacts are inspected outside git. Exact validation is recorded in the PR.
No new dependency, source, disclosure or database contract is introduced.

Locator export composition, full cross-page state and editable non-count domains
remain Phase 1 work. These samples do not establish exhaustive geographic,
physical-device or reader validation; boundary/license/disclosure prerequisites
remain separate.
