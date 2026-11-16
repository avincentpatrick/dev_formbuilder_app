# BHW analytics — return from the overview to the previous map

Phase 1 increment after #552 under the #525 handoff.

The BHW map's Open dashboard link now carries its current public analysis snapshot
into the BHW overview. A Return to previous BHW map link restores release/period,
indicator, map scope, selected child, view, range and classification after reload,
sharing or Back/Forward. It uses the same validated parser/serializer as the catalog
round trip. The return panel is shared by both destinations.

Overview figures remain national and the panel says so explicitly. Local map
filters are not applied to national headlines and no child means/rates are
aggregated. This is navigation continuity, not a new locality overview. Existing
purpose/search links start their own journeys and do not carry this snapshot.

Only public BHW Headcount/Profiles settings can be restored. Invalid, duplicate,
hidden or unsupported settings show feedback without a return link. Arbitrary
destinations cannot be supplied. Next Link retains the application prefix. The
panel renders independently of the existing Suspense boundary for overview data.

Synthetic phone/desktop tests edit filters and a selected missing-shape child,
visit the overview, reload, return and use history. Catalog round-trip and existing
map-history cases remain regression coverage. The national overview scope and
invalid-state feedback are checked explicitly. These samples do not certify
physical devices, exhaustive geography or representative-reader acceptance.

Other purpose-page continuity, contextual contracts and remaining boundary,
license, disclosure and reader-acceptance work remain Phase 1 follow-ups.
Synchronized comparison maps/common breaks remain Phase 2.
