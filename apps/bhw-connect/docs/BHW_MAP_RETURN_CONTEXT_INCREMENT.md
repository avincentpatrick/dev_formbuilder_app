# BHW analytics — current-area dataset return link

Phase 1 follow-up to #541 under the #525 handoff, addressing navigation finding L04.
Question: “Can I return to BHW dataset details for the area I am viewing?”

The map's dataset return link now reads validated current URL state. Previously it
was rendered once by the server, so in-page drilling could leave it pointing at the
original geography even though the map and address bar had moved elsewhere.

This reuses the existing map parser, release-to-subject mapping, catalogHref and
Next Link. BHW Headcount and Profiles return to the BHW catalog with the current
map scope. Selecting a child without drilling keeps the parent scope. Invalid
map choices retain the portal fallback; other subjects keep their existing return
destinations. The route prefix is still supplied by Next Link.

Synthetic 390px/1440px browser cases exercise child selection, drilling, Back and
Forward, following the return link, and returning to the map. Existing breadcrumb
and map-history cases remain regression coverage. Exact validation is in the PR.
No production records, new data source, database change or dependency is needed.

This fixes geographic context only. Catalog does not accept map indicator, range
or classification settings; those remain in the map history/share URL. Full shared
state across every purpose page, editable non-count domains and export composition
remain Phase 1 work. Physical-device/reader evaluation and exhaustive boundary,
license and disclosure checks are not certified by these samples.
