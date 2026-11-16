# BHW analytics — editable mean active-years color groups

Phase 1 increment after #550 under the #525 handoff.
Question: “How does the mean active-years pattern change with different color groups?”

BHW Profiles mean active years now reuses ClassificationControls and the existing
nonnegative numeric classification calculation. Quantiles remain the default;
Reset returns to five requested quantile groups. Equal-width intervals span the
observed minimum and maximum. Automatic settings use every reported child-area
mean, including areas without drawable shapes; filtering does not recalculate
breaks. Geography changes explicitly recalculate automatic breaks, so these are
not common comparison breaks. Ties can reduce the actual number of groups, and
no valid observations yields zero computed groups.

Custom settings accept 1–6 strictly increasing positive decimal cut points in
years, using the existing numeric safety ceiling (Number.MAX_SAFE_INTEGER).
There is no percentage cap. Groups start at zero, shared edges belong to the
higher group and the last custom group is open-ended. Custom points persist
across geography and are explicitly exploratory, not official standards.

Published parent means, denominators, complete rows and value filters remain
unchanged. Child means are never averaged; contributing count and confidence
interval remain unavailable. Mean charts gain no count rankings or proportional
circles. Barangay means and unrelated contextual measure domains remain excluded.
Indicator/dataset changes reset classification. URLs, history and JSON/PNG
exports retain requested/actual groups, cut points and the effective year legend.

Unit fixtures cover domain errors, decimal points above 100, endpoints, ties,
missing observations and shapes, URL round trips and export metadata. Synthetic
phone/desktop browser fixtures cover invalid/custom/equal settings, Enter,
Back/Forward, sharing, reset and downloads. They do not establish exhaustive
geography, physical-device or representative-reader acceptance.

Contextual measure contracts and full cross-page state remain Phase 1 follow-ups.
Boundary/license/disclosure audits and reader acceptance remain separately
tracked; synchronized comparison maps with common breaks belong to Phase 2.
