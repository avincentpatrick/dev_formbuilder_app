# BHW analytics — map workspace browser history

Phase 1 increment after #540 under the #525 assessment/live handoff.
Question: “Can I undo a map edit, return to it, or reload without losing my analysis?”

The dedicated BHW map workspace records committed indicator, view, area selection,
geography, range and classification changes in browser history. Back/Forward
restores validated URL settings; reload uses the same existing map parser. Equal
settings do not add duplicate entries. Local search text, open panels and map camera
position are not serialized analysis settings.

Reuse: existing MapChoice, mapHref, parseMapChoice, data fetching and capture
versioning, plus Next's supported native History API. No database, new dependency,
release, disclosure or source contract changes. Embedded previews do not own or
rewrite their containing page's URL. Other dataset maps retain existing behavior.

History restoration clears the transient drill trail so it cannot present a trail
from a different navigation sequence; the existing source-backed parent navigation
continues to supply locality context. Cross-route and dataset entries are handled
by Next's router. No new saved-account feature is introduced.

Acceptance fixtures at 390px and 1440px cover custom cut points, area selection,
Back/Forward, preserved count range, reload, and return from a dataset switch.
Existing classification/filter/navigation browser cases cover regressions. Exact
check results are recorded in the PR. These are synthetic emulated-browser samples,
not physical-device, reader or exhaustive geographic verification.

Remaining Phase 1 work includes continuity across all purpose pages (including
contextual destination links), editable non-count domains and export composition.
This increment does not establish those broader acceptance criteria. Phase 0
boundary/license/disclosure and reader-evaluation prerequisites remain tracked.
