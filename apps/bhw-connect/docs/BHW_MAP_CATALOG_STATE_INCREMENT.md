# BHW analytics — return from dataset details to the previous map

Phase 1 increment after #551 under the #525 handoff, extending L04 navigation
continuity beyond the geography-only return link in #542.

The BHW map's dataset link carries a public analysis snapshot in the catalog URL.
Dataset details offer an explicit “Return to previous BHW map” link that restores
release/period, indicator, map scope, selected child, view, range and classification.
It survives reloading or sharing that catalog URL. The map link reads current
validated URL state, including native history edits, rather than initial props.

The snapshot is a query string, not an arbitrary destination. It is validated with
the existing map parser, restricted to public BHW Headcount/Profiles releases,
and rebuilt with mapHref. Invalid, duplicate, hidden or unsupported settings get
an explanation and no return link. Next Link supplies the application prefix.

Catalog figures retain their own scope and semantics; this link explicitly returns
to the previous map and does not apply map filters to catalog figures. The ordinary
Open full map action still starts a fresh map at the catalog location. Further
catalog navigation starts a new catalog journey and does not carry this snapshot.
No local storage, account save, data load, new release or disclosure change is added.

Synthetic phone/desktop cases edit the filter and selected missing-shape child,
visit details, reload, return and use Back/Forward. Unit cases cover count, share
and mean settings, invalid inputs and canonical destinations. These are bounded
samples, not physical-device, reader or exhaustive geography acceptance.

Continuity across other purpose pages, contextual measure contracts and remaining
boundary/license/disclosure and reader-acceptance work remain Phase 1 follow-ups.
Synchronized comparison maps and common comparison breaks remain Phase 2.
