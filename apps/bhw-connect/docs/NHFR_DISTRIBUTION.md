# Geographic facility distribution

Implements the approved assessment of the card following facility mix. It retains
ranked horizontal bars, with government/private segments, count and share of the
full parent location, area drill-down links and a data-based reading. The title
names regions, provinces or cities/municipalities according to the parent level.
The geographic comparison now has its own full-width card; barangay presence stays
separate with its existing data and behavior.

## Selection and denominators

- Labelled native checkboxes support multiple areas and facility types. Empty
  selection means all; reset actions are explicit. Other cards keep their own scope.
- Facility types use OR. Selecting areas never renormalizes the denominator: each
  share uses the matching types across the entire parent location.
- Bar segments use a common count scale based on the largest area count in the full
  parent roster for the selected types. Selecting only smaller areas does not make
  their bars look as large as the parent-wide maximum.
- Counts, shares, government/private counts, selection labels and the reading update
  together. Most/fewest facilities and alphabetical ordering are supported.
- Verified zero areas stay visible. With a zero parent denominator, counts remain
  zero and shares are explicitly unavailable. Missing/incomplete data never becomes
  a zero or an invented ranking. Readings avoid arbitrary unique-leader claims at ties.

## Data boundary

The existing accepted NHFR counts, full child roster and parent type totals are
reused. The additional public aggregate projection reads child-by-type facility and
ownership counts in bounded batches with ordered pagination, including continuation
reads after exactly 1,000 rows. No source records or contact fields are read.

Both area and type ownership margins must reconcile: every area's cross-tab must
match its accepted counts, and every type must match the parent's accepted type
count. Duplicate/unknown cells, missing areas, wrong levels and failed reads reject
the comparison. Only after reconciliation can a sparse missing type cell mean zero.
Filtering is then local over the compact verified matrix, with no client fetches.
The loader shares the existing request caches and starts alongside other detail reads.

The download endpoint independently loads and verifies the full matrix. It rejects
unsupported levels, unknown selections and incomplete data, preserving source scope.

## Reading, viewing and exports

Short visible readings describe the inventory, without inferring need, adequacy or
travel access. Definitions, denominator rules and limitations sit behind “About this
figure”. Source and snapshot remain visible. Area links retain NHFR and validated
map-return context.

Maximize/Present retain the area/type selections and ordering. Present shows eight
areas per page. PNG/SVG include the full selected chart; PDF/PPTX paginate every
selected area, retaining the full comparison's denominator, scale, precision and
reading. PDF optionally includes technical notes on the last page. CSV/XLSX retain
unrounded shares, explicit unavailable-share states, ownership counts, source,
snapshot, parent/child codes and levels, selections, ordering and denominator.
The existing binary renderer and bundled fonts are reused; no dependency, schema
migration or shared deployment change is needed.

## Validation

- Full local unit/database suite: 2,283 passed, 13 skipped with the synthetic dev DB.
  Model/reader cases cover both reconciliation margins, unknown/duplicate/missing
  cells, zero areas, fixed filtered denominators/scales and exact-page continuation.
- Export cases exercise all six real formats, raw CSV precision and metadata,
  unavailable shares at zero denominators, rejected scopes and paginated PDF output.
- Twenty browser cases passed: six isolated tests of the actual geographic component
  and fourteen existing headline/facility-mix regressions. The committed dev copy
  lacks a complete parent NHFR cross-tab, so the six component cases use generated
  aggregate fixtures and test the actual component with only Next Link stubbed.
- A separate temporary local aggregate fixture verified the real database → page →
  filtering → all six download formats flow. Original development aggregates were
  restored afterward; no temporary fixture is committed.
- Interaction/layout checks cover 320/390/768/1440px. Axe WCAG 2 A/AA and 2.1 AA found
  no violations in inline/maximized/presented cards at 320/390/1440px. Actual-page
  phone/laptop screenshots were inspected using temporary synthetic aggregates.
- Lint passed with the existing deliberate fixture warning; types, formatting and
  migration lint passed. Both prefixed production builds and standalone extractor
  checks passed. Export tracing explicitly includes both bundled font files.
- React review: native controls/dialogs, unique control IDs in inline/modal views,
  direct imports, pure derived comparisons, compact public aggregate props and no
  additional client fetch waterfall. Heavy file libraries remain server-only.

No real source records, screenshots, temporary SQL fixtures or shared paths are
committed. Synthetic figures are validation evidence, not production counts.
