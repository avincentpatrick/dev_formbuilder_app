# Phase 2 locality position among peers

18 November 2026. Implementation increment; checks, actual deployment and bounded
live evidence belong in the implementing PR. This document is not a sign-off.

In the BHW map workspace, Areas and data now includes the selected locality’s
position among the returned same-level child areas of the current parent. A dot
strip shows the spread of eligible public values; its marker follows the map’s
selection. A semantic exact-value table offers the same selection without a
canvas. National scope therefore compares regions; region scope compares its
province-level areas (including separately reported HUCs); province scope
compares its reported cities/municipalities. Supported barangay count maps use
their reported barangays. Labels describe the map’s actual child unit rather
than inventing a new nationwide peer universe.

Reuses `DotStrip`, `peerRankSentence`, map URL selection, the value-range helper,
percentage formatting and the already-loaded public map payload. No additional
request, legacy peer-rank database reader, migration or export is introduced.
The compact overview preview does not load this extra panel.

Eligibility is explicit: exclude absent/invalid public values, source-flagged
small samples and values outside the chosen inclusive range. These mutually
exclusive counts are reported in that order. Missing shapes do not exclude
values. Largest raw value ranks first; equal raw values share a competition
rank and the next position skips the tied places. Names/code order tied rows.
Rounded display values never create ties. No rank means no eligible value, not
zero. Zero remains a real value, including an all-zero distribution. Duplicate
area codes and navigation-only responses do not produce a comparison.

The dot strip is a geographic distribution of observations, not a distribution
of individual workers. Overlapping dots can represent ties; the exact table
lists every eligible area. The parent total/rate/mean is unchanged. No averages
of local rates, inferred hidden values, staffing-need score, quality rating or
time-change claim are produced. The same accepted source, measure, period and
population as the map apply; source date and geographic universe stay visible.

Bounded model/render/browser samples cover zero, ties before rounding,
missing/invalid values, small samples, filters, missing shapes, selection,
refresh/Back and labeled table access at phone/desktop widths. They do not
certify nationwide reporting, physical-phone performance, or new VoiceOver
acceptance. Owner-accepted prior VoiceOver checks and deferred Phase 1 items
retain their existing status.

## Existing qualification composition evidence

At 13:42 UTC on 18 November, the already-live qualification/registration flow
was sampled at 390px for national PH, region 01, province 01028, city/municipality
0102801 and barangay 0102801001. For each scope, four independent exact-measure
pages (reported total and three exclusive registration categories) reconciled;
the overview’s accessible composition labels matched those category counts.
The overlapping-qualification warning remained visible. No page overflow or
browser page errors were observed. No production values/screenshots were added
to the repository. These five scopes are bounded samples, not national coverage.

Existing tests in `lib/bhw/overview.test.ts` and
`components/bhw/overview.test.tsx` also verify incomplete/nonreconciling splits,
zero totals and withholding of rejected categories from charts/presentation
props. `StackedMiniBar` draws no proportion when the total is zero. Certification
uses separate bars with the overlap warning; it is not a 100% stack. These
observations support the existing composition requirement, independently of
whether this new peer-position feature has deployed.

The same five-scope composition check also passed at 1440 × 900: another 20
exact-measure page comparisons, with no ordinary overflow or page errors. This
extends the automated desktop layout sample; it is not a physical desktop-reader
session. Current deployment/live results for this peer increment are maintained
in [#596](https://github.com/avincentpatrick/dev_formbuilder_app/pull/596).
