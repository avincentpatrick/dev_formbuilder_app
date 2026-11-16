# BHW analytics — editable percentage color groups

Phase 1 increment after #548 under the #525 handoff.
Question: “How does the pattern change when I choose different percentage groups?”

BHW Headcount declared accreditation and permitted BHW Profiles shares now reuse
ClassificationControls and the existing quantile/equal-width calculation. Their
readers validate 0–100%; this does not extend to arbitrary ratios, contextual
measures or means. Barangay percentage controls remain unavailable.

Fixed percentage breaks remain the default and Reset restores them. Automatic
methods accept 2–7 requested groups, use all reported child-area shares (including
areas without shapes), and recalculate when geography changes. Ties can reduce
the actual number of groups; no valid observations produces no computed groups.
Equal-width groups span the observed minimum to maximum. These are not common
breaks for comparisons.

Custom boundaries are 1–6 finite, strictly increasing interior cut points between
0 and 100. The first group starts at 0 and the last ends at 100 inclusive. Shared
edges belong to the higher group. Custom settings persist across geography and
are explicitly exploratory, not official standards. The existing exact-value
legend formatter displays their bounds; fixed labels cannot override custom bins.

Classification does not change the range filter, complete rows, parent rate,
denominator or source intervals. Filtering never recalculates the breaks. Changing
measure/dataset resets classification with the existing notice. URLs, history and
JSON/PNG exports preserve method, requested/actual groups, custom points and the
effective percentage legend. Counts retain their open-ended custom domain.

Unit fixtures cover domain errors, endpoints, ties, missing observations and
geometry, URL round trips and export metadata. Synthetic phone/desktop browser
fixtures cover invalid/custom/equal settings, Enter, Back/Forward, sharing,
reset and downloads. These samples do not certify exhaustive geography,
physical-device or representative-reader acceptance.

Mean/contextual domains and full cross-page state remain Phase 1 follow-ups.
Boundary/license/disclosure audits and representative-reader acceptance remain
separately tracked. Synchronized comparison maps with common breaks are Phase 2.
