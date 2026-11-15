# BHW analytics — named map breadcrumbs

Phase 1 slice after #535, addressing L09 from #525's assessment/live handoff.
Question: “Where is this area, and how can I return to its parent without losing
my selected measure?”

The expanded BHW locality map reuses the site's `Breadcrumbs` renderer and the
existing `getGeoAncestors` reader. The map response now carries resolved parent
names/codes. A directly opened province or municipality map therefore has the
same named hierarchy as a browsed map. Parent links use the existing validated
`mapHref` format and preserve dataset, indicator, period and visual mode; the
child selection is cleared when moving to a parent. Next links retain the base
path, and back/forward between those links restores the URL's valid selection.
The current place is marked with `aria-current`. National reset retains its
compact layout.

Only resolved geography records are used. Region/province ancestors are selected
by their recorded level; self records are excluded. Missing or failed ancestor
lookups leave the thematic map available and explain that some parent names are
unavailable. No PSGC-prefix parent, neighboring locality, or invented hierarchy
is substituted. This does not reconcile missing shapes or certify historical
geography. Non-BHW map navigation is unchanged.

## Acceptance and verification

Unit fixtures cover direct province/municipality ancestry, self exclusion,
missing parents and wrong-level records. Route fixtures check parent metadata
and that a failed parent lookup does not lose the independent reported total.
Phone/desktop browser fixtures open direct province links, verify named parents,
retain measure/view through keyboard navigation, restore the selected child on
Back, and keep rendering when names are incomplete. Exact checks and sampled
accessibility outcomes are recorded in the PR.

The increment changes no source visibility, measures, database schema, ownership
boundary or deployment configuration. Ancestor lookup reuses public geography
metadata and runs alongside the existing source/geography reads; no workforce
records are fetched for breadcrumbs.

Phase 1 remains incomplete. Independent value filtering/classification, full
cross-subpage analysis history and complete export composition remain next.
Existing drill actions still use local component state; this increment only
claims URL history restoration for breadcrumb links. Rate/mean ranking also
remains tracked. Physical-device/reader evaluation, national coverage, boundary
reconciliation/licensing and the legacy disclosure review are not completed by
synthetic browser samples.
