# BHW search suggestions — 2026-11-17

The visual overview's BHW search form kept full search but omitted the former
as-you-type suggestions. Restore a bounded list in the current form using the
existing geography search and the same BHW indicator labels, aliases and URL
builders used by full search. Partial indicator terms are supported; recognized
place-plus-measure queries retain explicit measure choices.

After two characters, wait 250ms before requesting up to eight suggestions.
Requests are cancelled when input changes or the list closes; stale responses
cannot overwrite newer input/context. Place suggestions carry geographic level,
PSGC code and parent locality names, not profile counts or individual records.
The list is scrollable on phones. Arrow keys/Enter select, Escape closes, and
pointer/touch choices use the same links. GET form submission and the full Search
button remain available, including comparison queries and suggestion failures.
Only validated map-return settings are propagated.

Validation covers API projection, partial indicators, ambiguous measures, errors,
input bounds, context handling, phone taps, keyboard selection, Escape and full
search navigation. Emulated-device checks do not replace physical-phone acceptance.

Local checks passed: lint, types, 2,010 unit tests (55 database cases skipped),
migration lint and prefixed production build. Four suggestion browser tests passed
at phone/desktop widths, including delayed-response protection; native GET/map
context without JavaScript also passed. Full seeded search navigation runs in CI;
the local no-data server cannot verify populated geography results.
