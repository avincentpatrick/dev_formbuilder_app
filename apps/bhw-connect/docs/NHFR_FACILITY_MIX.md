# Facility mix comparison

The section after the health facilities headline is “What kinds of facilities are
recorded?” Its ranked horizontal bars already suited category comparison. The
improvement retains that structure and replaces the text-only ownership split
with government/private stacked bars and two explicitly named comparison views.

- **Facility mix:** bar segments use all registered facilities in the selected
  location as the denominator. Selecting types does not renormalize that total.
- **Ownership within types:** each type's full bar represents that type's own
  total, split into government/private shares. The type's count and share of the
  entire area's inventory remain labelled separately.
- A plain-language reading describes the selected data, including tied leading
  types without claiming a unique leader. Technical definitions and limitations
  are in a closed “About this figure” disclosure.
- Labelled native checkboxes support multiple recorded types. The comparison's
  filter is independent of the headline and other figures; reset returns to all
  recorded types. Counts and name ordering are available. Existing exact tables,
  geography charts and facility search retain their current readers and scope.
- The card reuses verified server-loaded aggregate rows, so filtering requires no
  extra client requests. Invalid/incomplete partitions and zero denominators retain
  explicit unavailable/zero states instead of fabricated shares.
- Maximize and Present share the same selection, view and ordering. Presentation
  divides long comparisons into eight-type pages. Labels, source, snapshot and
  the full-comparison reading remain visible on every page.
- PNG/SVG retain the complete selected chart. PDF/PPTX paginate all selected rows,
  preserving denominator and precision; PDF optionally includes technical notes on
  the last page. CSV/XLSX retain unrounded shares, source, location/code/level,
  selection, view, ordering and area denominator. XLSX has a methodology sheet.
- The export endpoint validates the full type/ownership partition against the
  area's accepted NHFR total and refuses unsupported scopes or unknown types.
  Existing headline export behavior is unchanged. No dependency or migration is
  added, and the new export route explicitly traces the existing bundled fonts.

## Validation

- Lint passed with the existing deliberate base-path fixture warning; types passed.
- Full unit/database suite: 2,271 passed, 13 skipped with the local synthetic database.
- Fourteen targeted browser cases passed, including headline regressions, both
  comparison views, multiple types, sorting, disclosures, viewing modes, six real
  downloads and invalid/unsupported export parameters.
- New interaction/layout checks cover 320, 390, 768 and 1440px. Axe WCAG 2 A/AA and
  2.1 AA found no violations in inline, maximized or presented cards at 320/390/1440px.
  Phone/laptop screenshots were visually inspected.
- A temporary standalone fixture of the actual component presented all 45 synthetic
  types once across six pages on a phone, without horizontal overflow. A unit test
  likewise verifies all rows, consistent full-selection readings and six PDF pages.
- Both prefixed production builds and standalone extractor checks passed. All six
  new browser cases passed again against the packaged standalone production server;
  export tracing includes both bundled font files.
- React review: direct imports, existing serializable aggregate data, native controls
  and dialogs, pure derived comparisons, no new fetch waterfall or UI dependency,
  and heavy download libraries imported only on the server when requested.

No source records, screenshots, synthetic fixtures or shared deployment changes are
committed. Local figures are synthetic and are not production evidence.
