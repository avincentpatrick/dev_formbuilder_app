# Full-location health facility report

The general download section now offers a verified report for the full selected
national, regional, provincial or city/municipality location. Its location, source
snapshot and all-type scope are visible before opening the download chooser.
Individual figure selections and facility-list search never change this report.
It contains aggregate tables rather than a facility directory.

## Verification and availability

Opening the chooser checks readiness through a metadata-only endpoint. It loads
the same accepted full headline, complete type/ownership partition, geographic
matrix and distinct barangay-presence readers as the improved cards. The report
requires all applicable tables to reconcile with the same headline, ownership,
facility count and barangay numerator/denominator. It does not replace failed or
incomplete tables with a partial report. A verified zero remains a real report.

Type names must also belong to the accepted snapshot. Full area rosters, exact
facility and barangay shares, and child totals are checked before producing files.
City/municipality reports contain the overview and all recorded type rows; the
child-area comparison is not applicable there. No individual facility list is
fetched, exported or selectively omitted.

The readiness response contains only location metadata and row counts. Closed
choosers make no extra request; verified readiness is retained for the same scope.
Requests abort when scope changes or the chooser closes, wrong-scope responses
cannot enable links, and failures show an explicit unavailable state with retry.
Download endpoints independently reload and verify the report.

## Files and layout

- PDF is the primary report download, with fixed A4 landscape pages.
- PPTX uses widescreen slides with the same complete pages and source context.
- PNG and SVG downloads are ZIP bundles of separate numbered report pages. They
  retain every row while avoiding an unreadably tall single image. The UI explicitly
  labels the ZIP delivery.
- XLSX has Summary, Facility types, Child areas and About this report sheets.
- CSV uses a common sectioned schema with Table/Record fields, all aggregate rows,
  raw percentages, exact denominators, availability statuses, source, full scope,
  generation timestamp and definitions. Empty tables have explicit records; a
  city/municipality child-area table is marked not applicable. Formula-like text
  is escaped. XLSX likewise retains exact numeric values and definitions.

The overview matches the updated headline measures: registered facilities,
barangays with recorded presence and its complement, ownership, and recorded bed
capacity. Type pages include every recorded category and ownership split. Area
pages include every child area, facility share of the full parent and barangay
share of each area's own denominator. Zero denominators remain unavailable.

Tables have at most six rows per page and shrink the row group further when wrapped
names require more space, preserving readable print text. Every page includes the
location, source/snapshot and full-report page number; the generated timestamp is
labelled separately from the source snapshot. Technical notes are optional appendices
for PDF, slides and image bundles. Data files always include definitions.

The previous summary endpoint delegates to the verified report handler. Existing
PNG summary URLs now return numbered PNG report pages in a ZIP rather than the
older truncated renderer. New UI links use the report endpoint directly. Unknown
query fields (including type, area and search filters), duplicated parameters and
unsupported roots are rejected rather than silently ignored. Both endpoint paths
trace the existing bundled fonts. No package, migration, source-data load or shared
deployment change is required.

## Validation

- Reader/model tests reject incomplete/mismatched tables, changed denominators,
  unknown type names and unsupported/filtered scopes, and preserve verified zeroes.
- Export tests include 25 types and 55 areas, exceeding the previous limits. They
  verify all rows, wrapping, row-aware pagination, optional notes, image ZIP contents,
  fixed A4 dimensions, widescreen slide count, all six real formats, exact CSV/XLSX
  values, metadata, formula escaping and zero/not-applicable states.
- Six browser cases cover 320/390/768/1440px, independence from card type selections,
  scope/notes links, actual downloads and appendix contents, failed verification,
  wrong-scope responses and retry. Four existing catalog/legacy facility-list cases
  verify that list search does not change the report's scope or URL.
- The full local unit/database suite passed: 2,323 tests, 13 skipped. TypeScript,
  lint and migration lint pass; lint retains only the intentional base-path defect
  fixture warning. Prefixed no-data and seeded production builds are checked.
- The live local city flow passed from database through readiness to all formats.
  National-flow verification used temporary matching aggregate margins over the
  committed synthetic facts, confirmed all 18 child areas and three recorded types
  in every format, and restored original aggregates in a finally block.
- Axe WCAG 2 A/AA and 2.1 AA found no violations at four screen widths. Phone/laptop
  chooser layouts, horizontal overflow and rendered report image pages were inspected.

All evidence uses generated development data. No source records, screenshots,
credentials, generated report files or temporary aggregate changes are committed.
