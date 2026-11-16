# BHW analytics — compare the selected map place

Phase 1 increment after #558 under the #525 handoff.

Question answered: can a reader compare a selected map area using the same BHW
indicator? The selected-area panel now links to the existing public comparison
with that indicator and the loaded child code. The reader chooses a second place
there. The link explains that comparison uses published values, without the map's
range or color groups; synchronized/common-break maps remain Phase 2.

Reuses the public measure catalog, comparison URL builder, and comparison page.
Requires a loaded selected row, supported child level, matching public BHW source
and 2025 period. No link is invented for map-only context indicators, hidden or
non-BHW releases, navigation-only values or unloaded selections. Missing values
and missing geometry do not remove a supported place from comparison. Existing
public comparison readers enforce disclosure and same-level eligibility.

Unit cases cover source/period/level guards and map-only settings. Synthetic
phone/desktop journeys select the missing-shape Region VII HUC, open active-years
comparison, reload, and return with Back to the exact map state before continuing
the overview/methodology journey. This checks navigation, not comparison arithmetic
or exhaustive geography, live data, physical devices or representative readers.

No new data prerequisites or readers. Remaining Phase 1 continuity, contracts,
boundary/license/disclosure work and reader acceptance remain follow-ups.
