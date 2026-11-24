# Health facilities headline

The headline now follows the approved responsive preview: a primary facility total,
barangay presence and its complement, ownership, and secondary recorded bed capacity.
Each item has a short plain-language reading. Definitions, attribution rules,
denominators and limitations sit in closed-by-default “About this figure” sections.

## Multiple facility types

- Native labelled checkboxes support one or more registry types. Empty selection
  means all types; the reset action explicitly returns to all facility types.
- The picker lists types from the accepted registry, including types with no
  facilities in the selected area; their verified count is zero.
- Types use OR. A facility contributes once, and a barangay with several selected
  types contributes once to presence. The denominator remains all barangays in the
  reporting geography, and the selected share uses all facilities in that geography.
- This is a headline-only filter. Existing maps, detail tables, facility search and
  linked-source context retain their existing scope and readers.
- A public, security-invoker SQL function summarizes the accepted snapshot on the
  database. No facility records or contact details are sent to the client.
- Geographic roots come from verified PSGC geography or verified, non-conflicting
  district membership. Split-city contributions include attributed facilities only;
  incomplete district mappings are not promoted to complete totals.
- The summary and export endpoints independently check accepted geographic
  observations, so direct download URLs cannot bypass partial or unavailable states.
- Complete accepted catalog figures render immediately. Type filtering is enabled
  after the fact summary reconciles with those figures. Partial/unavailable scopes
  retain the existing qualified overview. Requests are debounced and aborted when
  superseded; pending/failed selections cannot display a previous selection's values
  or download its exports.
- Bed capacity is omitted for a barangay-health-station-only selection. Other types,
  including RHUs, can carry recorded bed capacity. Missing capacity is not evidence
  of zero beds, and the existing source-cleaning completeness limitation remains.

## Viewing and downloads

Maximize reuses the native dialog, retains the filter and shares the selection with
the inline panel. Present uses a viewport-sized dialog with larger labels, source,
snapshot and selected types; technical disclosures and filter controls are hidden.
Both support keyboard dismissal and keyboard scrolling, including small phones.

Exports summarize the selected headline in PNG, SVG, PDF, CSV, XLSX and PPTX.
The export endpoint recomputes the requested public summary; images/documents name
all selected types, location, source and snapshot, without interface controls.
CSV/XLSX preserve unrounded percentages, explicit denominators and unavailable
values. XLSX includes methodology in a separate sheet. PDF can include technical
notes; PDF and PPTX embed a rendered image of the headline. Rasterization uses the
existing bundled fonts, with the new export route explicitly included in tracing.

## Validation

- Full local unit/database suite: 2,265 passed, 13 skipped with the local development
  database. The new transaction-isolated SQL case covers overlapping types,
  unassigned/misassigned barangays, duplicate roots, split roots and dataset isolation.
- Fifteen browser cases passed: multi-selection at 320/390/768/1440px, native notes,
  maximization/presentation, all six real download formats, failed-request recovery,
  existing search, card entry, geography navigation and exact-data disclosure.
- Axe WCAG 2 A/AA and 2.1 AA: no violations in the headline, maximized or presented
  panel at 320/1440px. Phone/laptop headline screenshots were visually inspected.
- Lint passed with the repository's existing deliberate base-path fixture warning;
  type checking and migration lint passed. Prefixed production builds with no data
  and the seeded data source passed, including standalone extractor checks.
- React review: direct imports, heavy export libraries loaded only on the server
  for their requested format, native controls/dialogs, stable primitive request
  dependencies, abortable requests and no additional third-party UI dependency.

Only the committed synthetic development database was used for local verification.
No source records, screenshots, secrets or shared deployment changes are committed.
