# BHW analytics — active overview navigation verification

Phase 1 regression repair after #556 under the #525 handoff.

Main CI run 43820385893 failed in the 1440px map-to-overview journey at
`#methodology summary`: Playwright found two matching copies after reload and
Back/Forward navigation. The 390px case also reported the same strict-selector
failure during retries. PR CI had passed, so a single green sample did not detect
the intermittent duplicate-DOM condition.

Scope the methodology summary and section viewport checks to visible elements.
Keep strict matching (no first/nth selection), all URL and return-state assertions,
the existing timeout, and keyboard Enter coverage in the fresh-overview journey.
This asks which summary the reader can actually interact with; it does not accept
two visible summaries or remove the history steps that expose the failure.

Verification repeats both phone/desktop overview suites, including comparison
entry and map → overview → national methodology → map. Synthetic fixtures and
repeated runs assess this navigation regression only; they do not certify all
geographies, physical devices, live data or representative-reader acceptance.
No product behavior, data access, disclosure or shared infrastructure is changed.
Remaining Phase 1 work and acceptance stay open under the original handoff.
