# BHW analytics — count map display increment

Next reviewable Phase 1 map slice after #531, under the #525 assessment and live
verification. This is not completion of the map workspace or Phase 1.

## Reader question and reuse

“Where are larger reported BHW counts, and which places cannot be drawn?”
The existing dataset-map page/card, MapLibre canvas, accepted public map adapter,
area selector, complete table and PNG/JSON provenance remain the implementation.
No new data reader, boundary alias, release, sensitive measure or dependency.

Fresh BHW headcount and profile-count maps default to proportional circles. A
positive count's radius is `24 × sqrt(value / maximum)`, where maximum is the
largest published count in the complete area list, including areas missing shapes.
There is no minimum positive radius that would distort area ratios. Zero uses a
separate fixed hollow marker; an unavailable count has no symbol. Both retain exact
textual states in the table. Counts are not replaced with profile counts when absent.

Symbols are placed at approximate centers of supplied boundary bounds, which can
fall outside multipart polygons. These are area-total markers, not worker locations.
Missing geometry produces no invented position. Historical geometry acceptance and
license gaps remain unchanged. The Region VII fixture keeps `07306`, `07311` and
`07313` in the selector/table and reported totals, with no substitute polygons.

## State, controls and exports

- `view=circles|choropleth` is validated with the existing measure/period/scope URL.
  Circles are restricted to supported BHW counts; shares and means retain choropleths.
  Invalid, duplicate or incompatible view values fail validation. Explicit shared
  choices restore their view; absence of a view uses the new source-appropriate default.
- Selecting a non-count measure removes an incompatible circle view and visibly
  explains that the measure uses color. Existing classification semantics remain;
  configurable classification and value filters are a separate follow-up.
- Zoom in/out and reset-viewport buttons give a keyboard/touch control surface.
  A live metric scale bar follows zoom. The existing area selector/table remains
  the accessible path to values and drill actions if WebGL or boundaries fail.
- Missing-shape counts appear beside the visual. Table place names select their
  associated details, including places without shapes. An empty accepted boundary
  collection finishes loading instead of showing a permanent spinner; PNG capture
  remains unavailable when nothing can be drawn.
- BHW map pages use more desktop width; their back link retains the requested
  geographic scope in BHW catalog details. Other dataset return links are unchanged.
- JSON includes the effective view, count maximum, size-reference values/radii and
  area-versus-radius interpretation. PNG notes retain those semantics and source,
  denominator and coverage context. Pixel radii in JSON describe the live canvas
  before PNG resizing. The DOM scale bar is live-only and is not burned into PNG.

## Verification and remaining work

Tests check area ratios, zero-only/missing lists, explicit view restoration,
incompatible links, boundary positioning and Region VII HUC export retention.
Phone/desktop browser cases exercise controls, view switching, share/reload,
selection through missing-shape table rows, JSON export and the HUC regression.
Exact test/build/browser results are recorded in the PR. Synthetic cases and the
committed development database do not certify national coverage or physical devices.

The following agreed tasks remain: full workspace panel layout with controls and
legend in the first viewport; linked ranked bars; readable map labels; national
locator inset; named direct-link breadcrumbs; shared history for every in-page
change; separate filtering and classification with validated custom breaks and
stable comparison breaks; complete export treatment of those settings. Floating
point formatting in existing choropleth quantile labels remains part of that
classification task. Do not mark these done because the count default uses circles.

Boundary reconciliation, metadata licensing and the broader legacy disclosure audit
remain tracked prerequisites. No new suppressed demographic or payer-specific source
is enabled by this display change. Synchronized comparison maps remain Phase 2.
