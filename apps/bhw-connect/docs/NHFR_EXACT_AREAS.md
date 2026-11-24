# Exact geographic health facility figures

The catalog's geographic distribution card now includes a closed “View exact area
figures” disclosure. It replaces the separate child-area table in the catalog while
preserving legacy facility dashboards and the independent barangay-presence card.

## Scope and measures

The default table combines area links, facility counts, share of the full parent's
facilities, barangays with a recorded facility / all barangays, and share of barangays.
Government/private counts are optional visible detail. Desktop uses aligned columns;
narrow containers use compact entries with labelled measures and ownership counts.

Both measures follow the distribution card's multiple area and facility-type
selections. Types use OR and barangay presence counts distinct barangays once.
Facility share keeps all matching-type facilities in the full parent as denominator.
Barangay share keeps each area's own full barangay total. Area filtering and table
search change neither denominator. The footer shows the full matching facility count
and combined barangay numerator/denominator, including on every presentation page.

Name search narrows only the table. Chart area/type selections and chart order are
retained. Table ordering can follow the chart or use highest/lowest facility count,
highest/lowest raw barangay share, or alphabetical name. Unavailable barangay shares
sort after available shares in percentage orders. A verified zero facility denominator
has no facility percentage; zero presence with a positive barangay denominator is 0%.
Missing data never becomes a verified zero.

## Verification and loading

The existing distribution reader verifies the full child roster and reconciles every
area/type/ownership margin. The existing presence reader independently reconciles the
full roster, unfiltered facilities, presence and barangay totals before accepting the
filtered union. The joined model then checks root, level, complete roster, selected
types and that no area's selected presence exceeds its selected facility count.
These checks run before display filtering or search, including for hidden areas.

The verified initial all-type presence is reused from the server-rendered data.
Filtered union presence is fetched only while the exact disclosure is open. Requests
are debounced, aborted when superseded and validated against the requested types and
initial geography/denominators. Pending/failed joins hide exact rows and downloads,
with a retry action. The independently valid count chart remains usable. The separate
presence card retains its own filters and loading behavior.

Empty searches explicitly report no matches and disable table exports/presentation.
Unavailable combined data is explicit rather than an omitted or partial table.
Technical definitions, attribution limitations and both denominator rules are inside
“About this figure.” Source and snapshot remain visible.

## Presenting and downloading

While exact figures are open, the card's Export and Present controls use matching
table rows, the selected sort and optional ownership detail. This is explained in the
visible disclosure. Maximize retains every selection and table setting. Present
shows eight areas per page, full filter/search context and shared-series precision.
Drill-down links retain the catalog's NHFR and map-return journey.

A dedicated export route independently reloads and verifies both datasets before
joining. PNG/SVG/PDF/PPTX show the combined exact table and both denominator labels.
PDF/PPTX paginate all matching areas; optional PDF notes appear on the last page.
CSV/XLSX preserve raw facility/presence shares, both exact denominators, ownership
counts, remainder, availability statuses, codes, sort, search, selections and source.
Ownership counts remain in data exports even when optional visual detail is hidden.
XLSX includes methodology; CSV protects formula-like text. The existing chart export
route retains its contract. Bundled fonts are traced for the new table export route.
No dependency, migration, source-data load or shared deployment change is needed.

## Validation

Model tests cover mismatched/partial joins, hidden-area validation, overlapping-type
selection, independent denominators, case-insensitive search, raw-share sorting and
zero/unavailable states. Export tests cover all six real formats, raw percentages,
selection metadata, malformed/unknown requests, invalid data and formula escaping.
SVG pagination preserves all 18 synthetic areas and full selection context.

Seven new browser cases cover 320/390/768/1440px, shared area/type selections,
independent search/order, ownership detail, view state, download URLs, map-return
links, empty/recovery states, request failure/races, zeroes and three presentation
pages. The 14 previous distribution/presence cases also pass.

The real local database → catalog → filtered counts plus union presence → six
exports flow passed using committed synthetic facts and temporary matching national/
regional aggregate margins. Original development counts and type aggregates were
restored in a finally block. Axe WCAG 2 A/AA and 2.1 AA found no violations at four
widths in inline, maximized and presentation modes. Horizontal overflow, phone and
laptop table screenshots, and the rendered PNG export were checked visually.

The full local unit/database suite passed (2,310 tests, 13 skipped), followed by the
additional zero/status/formula-escaping export case. Lint retains only the existing
intentional base-path defect fixture warning. TypeScript, migration lint and prefixed
no-data and seeded production builds passed, including standalone extraction checks. Only generated synthetic data is used;
no production records, screenshots or temporary aggregate fixtures are committed.
