# BHW analytics — overview and comparison map continuity

Phase 1 increment after #560 under the #525 assessment and live-verification handoff.

Both national overview Compare places links now carry the validated map snapshot.
The public comparison's BHW overview link carries it back, so visiting comparison
no longer loses the original map return. The overview remains national and the
comparison starts with its own headcount measure and no selected places. Neither
inherits map filters or color groups as data scope.

Reuses the existing comparison URL builder, map validator and return panel. A
small overview URL builder discards invalid, repeated, hidden-source and arbitrary
destination snapshots. No new data readers, prerequisites or disclosure changes.

Unit coverage checks both overview links and canonical/rejected overview return
settings. The existing phone/desktop synthetic journey adds overview → comparison
→ overview with reloads and exact original map return verification. This is bounded
navigation coverage, not exhaustive geography, physical-device or reader acceptance.

Phase 1 remains in progress. Separate measure-detail continuity and remaining
contracts, boundary/license/disclosure work and reader acceptance remain open.
Synchronized maps and common breaks remain Phase 2.
