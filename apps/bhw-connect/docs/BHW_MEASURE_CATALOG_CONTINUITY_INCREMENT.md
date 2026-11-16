# BHW analytics — measure to catalog continuity

Phase 1 increment after #562 under the #525 assessment and live-verification handoff.

The measure page's All BHW indicators link now retains the validated original map
snapshot. The catalog keeps the measure page's selected location and BHW dataset;
its location does not change to the map's area. The existing catalog return panel
restores the original map settings after reload.

A scoped BHW catalog URL builder reuses the existing catalog serializer and map
validator. National overview methodology delegates to the same builder, retaining
its national scope. Invalid, repeated, hidden-source and arbitrary destination
snapshots are discarded. No data-reader or disclosure changes.

Unit coverage checks independent catalog/map locations, canonical settings and
invalid snapshot rejection, plus measure-page propagation. The existing synthetic
phone/desktop journey adds measure → catalog → reload → Back, checking the original
map destination and the catalog location. This is bounded navigation coverage, not
exhaustive geography, physical devices or reader acceptance.

Phase 1 remains in progress. Search and catalog-internal navigation continuity,
remaining contracts, boundary/license/disclosure work and reader acceptance stay
open. Synchronized maps and common breaks remain Phase 2.
