# Exact facility-type figures

The selected NHFR catalog card now keeps exact type figures inside the facility-mix
card under a closed “View exact figures” disclosure. It removes the duplicate
standalone catalog table. Legacy facility dashboards retain their existing table.

## Shared scope and lookup

- Both views use the same complete, reconciled type/ownership partition. Duplicate
  types, invalid counts, mismatched government/private totals or an incomplete area
  total cannot produce a chart, exact table or download.
- Multiple type selections and count/name ordering apply to both chart and table.
  Fewest-first ordering is now available alongside most-first and alphabetical.
- Exact figures contain type, facility count, share of all facilities in the location,
  government count and private count. Every share keeps the full location denominator.
- Type-name search is case-insensitive and narrows the table only. It does not change
  selected checkboxes or chart rows. A matching count and displayed subtotal make the
  search scope explicit. Clearing search restores selected types; collapsing the
  disclosure returns Export and Present to the chart. The search value is retained.
- Desktop uses a semantic five-column table. Narrow containers use labelled compact
  rows, preserving numeric count/share pairs. Both have full names and labelled
  ownership counts. The full-location denominator and displayed subtotal stay visible.

## Viewing and files

Maximize retains disclosure state, selections, search and order. While exact figures
are open, the existing Export and Present controls use matching table rows. The
visible disclosure explains this behavior. Present includes the selected-type and
search context and paginates eight matching types at a time; its subtotal and
percentage precision use the complete matching set on every slide. There is no
technical disclosure in presentation mode.

All six formats support table output: PNG, SVG, PDF, CSV, XLSX and PPTX. CSV/XLSX keep
raw percentages, the full area denominator, selected types, search, sorting and
matching totals. Existing ownership-percentage columns remain available in these
data files. Table images/documents show aligned exact-value columns. PDF/PPTX
paginate all matching types, and PDF can include technical notes on its last page.
Empty searches explain that no type matched and disable Export/Present; they do not
create a zero-facility finding. Direct unmatched or unverified exports fail safely.

The existing export route independently reloads and verifies the full partition
before applying selections or search. Search is accepted only for table view and
bounded to 200 characters. No new dependency, migration or deployment setting is
required. All raw internal links retain the configured base path.

## Validation

- Model tests cover lowest-first sorting, case-insensitive lookup, fixed denominators,
  unknown selections, duplicate types and invalid ownership counts.
- Export tests generate all six real formats and verify raw shares, search/selection
  metadata, malformed requests and unverified partitions. Table pagination preserves
  every matching type and the full subtotal across pages.
- Browser tests cover 320/390/768/1440px, shared selection/order state, independent
  search, empty/recovery states, collapse behavior, Maximize, table presentation and
  real downloads. Existing chart and catalog/legacy facility-list flows are checked.
- Axe WCAG 2 A/AA and 2.1 AA found no violations at all four widths in inline,
  maximized and presentation modes. Horizontal overflow and phone/laptop screenshots
  were checked against the committed synthetic development data.
- Full unit/database suite: 2,302 passed, 13 skipped. Lint retains only the existing
  intentional base-path fixture warning. TypeScript, migration lint and both prefixed
  no-data and seeded production builds passed, including standalone extraction checks.
- All six new browser cases passed, including an 18-type presentation fixture; the
  12 existing chart/analysis/facility-list cases also passed.

Screenshots and temporary evidence stay outside git. No production records are used.
