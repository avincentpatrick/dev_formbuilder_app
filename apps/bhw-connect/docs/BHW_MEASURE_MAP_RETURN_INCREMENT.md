# BHW analytics — map return from measure details

Phase 1 increment after #561 under the #525 assessment and live-verification handoff.

Comparison's measure-detail links carry the validated original map snapshot. The
measure page reuses the shared return panel and retains that snapshot when opening
its selected place in comparison or returning to the national overview. Measure
figures keep their selected indicator and place; map filters and color groups do
not apply. A valid map return remains available if the measure selection fails.

Reuses the existing map validator, measure/comparison URL builders and return panel.
Invalid, repeated, hidden-source and arbitrary destination snapshots are explained
without a return link and are not propagated. Disclosure and data readers are
unchanged. Search and the all-indicators catalog link remain separate journeys.

Unit coverage checks canonical snapshot propagation, independent measure scope,
invalid measure recovery, invalid snapshot rejection and suppressed values. A
phone/desktop synthetic browser journey covers comparison → measure details with
reload → comparison, Back → overview, checking the original map destination.
These are bounded navigation checks, not exhaustive geography, physical devices,
comparison arithmetic or reader acceptance.

Phase 1 remains in progress: remaining navigation continuity, contracts,
boundary/license/disclosure work and reader acceptance stay open. Synchronized
maps and common breaks remain Phase 2.
