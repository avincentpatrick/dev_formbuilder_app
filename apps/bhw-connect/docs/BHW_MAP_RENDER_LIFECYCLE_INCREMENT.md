# BHW analytics — retain the map during exploration edits

Phase 1 response follow-up: filtering and classifying an already loaded map must
not reconstruct its renderer or reset its viewport. The parent now keeps the
canvas key stable for those edits; DatasetCanvas updates MapLibre paint/filter
expressions on the existing sources instead of recreating geometry and workers.
Source, rows, symbol sizing, color boundaries, published totals and exports still
use the existing implementations. No new data or disclosure policy is introduced.

All usable symbol points and supplied labels remain in their existing sources;
layer filters control inclusion. Excluded outlines remain explicit, missing
values keep their distinct fill, and zero-count symbols remain separate. Source
values are not recalculated. Style expressions use exact area codes and the same
colorForValue helper as before.

True renderer replacements track readiness/error against their own inputs. They
cannot inherit an earlier canvas's status, and an error cannot be overwritten by
a later idle event. Capture still waits for a loaded render and the existing PNG
export-version guard rejects stale settings.

Acceptance: count/rate/mean filter and classification behavior, legend/table/map
consistency, viewport continuity, zero/missing distinctions, and loading/failure
recovery. Unit evaluation uses MapLibre's expression engine for exact cut points,
excluded values, missing values, symbols and labels. Browser checks require the
same canvas after filter/class edits and exercise a held boundary read, failure
and successful replacement.

Validation results and bounded timing samples are consolidated in
[BHW_PHASE_ONE_ACCEPTANCE.md](BHW_PHASE_ONE_ACCEPTANCE.md). This change does not by itself establish cold map readiness,
physical-device usability or representative production performance.

Checks completed locally: npm ci, lint, types, 2,000 full-suite unit passes with
55 database skips, 201 focused unit passes after the parent-key correction,
migration lint, prefixed build and standalone extractor verification. Of 55
selected browser cases, 53 passed initially; a load-timeout case and a corrected
share-navigation race passed on targeted rerun. Ten final axe/page-error/overflow
samples were clean. CI remains the database-inclusive gate; Boundary is externally
blocked until the announced GitHub window ends. No phase signoff is claimed.
