# Barangay presence comparison

The presence card follows the facility mix and geographic facility-count cards at
national, regional and provincial PSGC levels. It compares the share of each child
area's barangays with at least one recorded facility. It does not infer access,
adequacy or care quality.

## Reading and interaction

- The question title, short weighted reading, selected area/type labels, exact
  numerator and denominator, and source/snapshot stay visible. Technical definitions
  are in a closed “About this figure” disclosure. A brief visible sentence explains
  that residents may also use facilities in nearby barangays.
- Teal and a pale neutral remainder show recorded presence and no facility recorded
  on a fixed 0–100% scale. Text labels and counts carry the meaning independently of
  color. A filtered selection qualifies the remainder as “No facility of selected
  types recorded.” It never calls an area underserved or treats attribution as proof
  that no facility exists.
- Lowest percentage first is the default. Highest percentage and alphabetical order
  are available; unavailable percentages sort last in percentage orders. Every area's
  denominator remains all its barangays when types or displayed areas change.
- Multiple areas and multiple facility types can be selected. Empty selections mean
  all, with explicit reset buttons. Filters affect this card only. Types use OR;
  overlapping selected types count a barangay once.
- The short overall reading uses the combined numerator divided by the combined
  denominator for the displayed areas, rather than averaging area percentages.
  Very small or near-complete shares use “Fewer than 1” / “More than 9” in every 10,
  so nonzero presence is never described as zero and a gap is never described as all.
- Eight rows show initially, with an option to show all. Maximize retains selections
  and sort order. Present shows eight rows per page with full selection context and
  no technical disclosure. Geographic links retain the NHFR/map return journey.
- Native controls have touch-sized targets and keyboard labels. Rows wrap on narrow
  screens while numeric count/percentage pairs remain together. Unavailable status
  can wrap separately from its count pair.

## Data contract

`nhfr_presence_summary` is an additive, stable, security-invoker SQL function. It
uses the caller's RLS and returns public aggregate fields only. It traverses each
child area's descendants, counts facilities recorded at city/municipality level,
and counts distinct barangay codes only when the barangay belongs to that facility's
city/municipality. Null, incorrect-level and mismatched-parent assignments cannot
increase presence. Facts from other snapshots cannot contribute. Type options come
from the accepted snapshot, including types with a verified zero in the parent.

Before showing a ranking, the server checks the geographic root, full expected
child roster, child levels, parent and child facility totals, barangay denominators
and unfiltered presence. Each filtered read returns baseline fields, so it must
reconcile again before the selected union is accepted. The complete selection API
and export API independently run these checks. No raw facility rows reach the client.

The initial all-type summary is server-rendered. Type requests are debounced and
aborted when superseded, schema-checked and matched to the requested selection.
Pending and failed type selections hide figures and exports; failures allow retry
without losing selections. Returning to all types restores the verified initial
summary. A verified numerator of zero with a positive denominator is 0%; a zero
barangay denominator has no percentage. Failed/incomplete reads never become zeros.

## Files and deployment

PNG, SVG, PDF, CSV, XLSX and PPTX use the current area/type selection and ordering.
CSV/XLSX preserve raw shares, exact numerator/denominator, remainder, status,
geographic codes and selection metadata. CSV text is formula-escaped. XLSX includes
technical notes separately. Images/documents show the source, date and selected
scope. PDF/PPTX include every selected area across eight-row pages, retain shared
series precision, and PDF can include notes on its final page. The new route traces
the existing bundled fonts; no package or shared deployment change is needed.

The additive migration is applied automatically by the existing deployment runner.
There is no source data load or destructive schema operation.

## Verification

- SQL tests run as anon against an enum-backed synthetic schema. They cover national,
  regional and provincial roots, overlapping and repeated types, another snapshot,
  null and mismatched barangay assignment, empty areas and zero denominators.
- Reader tests reject incomplete/duplicate rosters, mismatched baseline totals,
  impossible selected presence, unknown types and failed reads.
- Export tests generate all six real file formats, check raw per-area precision,
  formula escaping, metadata and unavailable-versus-zero output, and verify 18 areas
  across three PDF pages.
- Browser tests exercise 320/390/768/1440px, multi-selection, sort/view state, export
  queries, preserved links, pagination, retry and superseded response handling.
- The real local database → catalog page → filtered presence → six exports flow was
  verified using the committed synthetic facts and temporary matching parent
  aggregates. Original development aggregates were restored in a finally block.
  Axe WCAG 2 A/AA and 2.1 AA found no violations at 320/390/1440px in inline,
  maximized and presentation modes; screenshots and horizontal overflow were checked.

All evidence uses made-up development data, not a production data-quality assessment.
No real records, screenshots, tokens or temporary data are committed.

Local checks: 2,297 unit/database tests passed (13 skipped); all eight new browser
cases and the 20 preceding headline/mix/distribution/analysis cases passed.
Lint, TypeScript, migration lint and prefixed no-data and seeded production builds
passed, including the standalone extractor verification. Lint retains only the
existing intentional base-path defect fixture warning.
