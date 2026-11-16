# BHW analytics — preserve map context within overview sections

Phase 1 increment after #553 under the #525 handoff.

Question answered: can a reader browse workforce, qualifications, support and
methodology after leaving a configured map, then return to that same analysis?
The overview's four section links previously navigated to `/bhw#…`, dropping
`mapState`. They now use fragment-only Next Links to retain the current route and
query while scrolling to the existing section. No new reader or data input is
needed. National overview figures and disclosure rules remain unchanged.

The existing synthetic map-to-overview browser journey now visits all four
sections at 390px and 1440px, checks the destination and visible section, reloads,
uses Back/Forward, and returns to the edited map. The existing fresh-overview
journey covers navigation without a snapshot. These checks cover section-state
continuity, not exhaustive geography, physical devices or representative readers.

This supersedes #553's new-journey behavior for links within the overview only.
Separate search, comparison, map and catalog journeys remain follow-up work where
appropriate; this change does not claim complete cross-page state continuity.
Remaining Phase 1 contracts, boundary/license/disclosure work and reader acceptance
remain tracked by the handoff. Synchronized comparison maps remain Phase 2.
