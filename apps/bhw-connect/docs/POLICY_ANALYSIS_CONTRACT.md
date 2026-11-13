# Policy and dataset analysis — Phase 4

Windows worker configuration remains deferred by the owner. This phase works with
already-processed document revisions; it does not install Python/OCR or a worker
on the live server. The local acceptance setup uses synthetic documents/data.

## Shared assistant and source controls

Admin → Document library → **Analyze policies with dataset evidence** opens the
existing `/ask-the-data?policies=active` assistant. Document/collection Ask links
open the same engine and component. Existing project/attachment analysis remains
separate from this shared strict-admin policy library; no second provider loop,
public cache or public log is introduced.

Policy questions require both the existing dashboard approval and BHW role
`admin`. Signed-out users, members and editors cannot resolve policy sources.
Every question resolves a new, bounded selection on the server. The model never
chooses source permissions, dataset restrictions, history access or online mode.
The endpoint does not accept a caller-authored trusted `PolicyContext`. Durable
project jobs without a trusted policy context cannot activate this mode.

- Active scope includes current extractions of active, successfully processed
  versions, optionally filtered to a collection. It excludes archives and old
  extraction revisions. The cap is 100 revisions per question; larger selections
  fail rather than silently truncate. Narrow the collection or select revisions.
- Selected scope accepts explicit version/revision IDs. Archived versions and
  older extractions additionally require the historical checkbox. A missing,
  processing, empty, changed or inaccessible selection fails before a provider.
- Source choices apply to the next question. Earlier answers retain their own
  immutable citation identities. This is a per-question snapshot, not the durable
  complete-collection run/coverage ledger planned for Phase 5.
- Dataset mode is automatic across registered tables, or restricted to explicitly
  validated registered table names. Selecting none means policy-only analysis.
- Online research is off and unavailable in policy mode until Phase 6. No web
  search/fetch tools are supplied, and a forged `online: true` request fails closed.
  This does not change existing project attachment/web features on other paths.

Policy options show at most 501 revisions; the library's document/version links
can select a specific version directly. Listing inventory does not examine text.
Failed/processing/empty material is excluded, and the UI says so. Full collection
accounting is Phase 5; ordinary search must never be advertised as exhaustive.

## Evidence tools and source identity

The additive service-only `policy_evidence_api` RPC rechecks strict admin on each
operation. Browser database roles have no execute privilege. Validated source
keys are captured by the tool closures, not read from model-authored arguments.

`listPolicies` reports selected identities, issuer, issuance/collection metadata,
publication/effective dates, explicit supersession links and bounded quality notes.
`searchDocuments` searches those keys only, with at most 25 hits and 1,200 Unicode
code points per excerpt. `readPolicyPassage` retrieves a stored chunk completely
(up to the worker's 2,400-code-point chunk size), with up to two neighbors on each
side, bounded to five chunks. Returned offsets count Unicode code points.

Every policy hit carries the exact stored version, extraction, chunk and physical
PDF page identity in an `evidenceToken`. The model must copy that token for a
policy requirement/documentary figure. The server builds visible source labels
from the retrieved identities after verification. Both source viewers show the
immutable version/extraction and link to that exact extraction's text/warnings.
Printed page labels are never substituted for physical PDF page numbers.

A full passage read replaces an earlier truncated excerpt for the same chunk in
the citation payload. Old source/chunk IDs remain readable to strict admins after
archive or reprocessing. Current default retrieval excludes those old records;
explicit historical selection can retrieve them. Archive/role changes during a
question can make a later read unavailable; the tool explains the gap instead of
inventing a finding of absence.

## Calculations and verification

Policy mode supplies geographic lookup plus the existing registered dictionary
and constrained query tools. Other handwritten numeric/graph tools are omitted to
avoid bypassing a table restriction. No arbitrary SQL is accepted. The registry
query tool supports exact counts and bounded row reads; use registered aggregate
relations for other measures rather than pretending a preview is a population.
Document text/embeddings are not computed dataset totals.

The prompt separates policy requirements, documentary figures, dataset
observations and analytical conclusions. It calls for comparison of dates,
geographic vintage, grain, units, denominators and populations; conflicts and
unavailable evidence must be reported. Newer publication alone is not legal
supersession. Missing fields alone cannot establish noncompliance. Uploaded
passages, OCR and metadata remain untrusted evidence and cannot grant permissions.

The original numeric/citation audits remain on other assistant paths. Policy mode
adds exact token identity and page checks; known cross-document title swaps,
unmatched direct quotations, fabricated dates/numbers and common unsupported
supersession/noncompliance claims are withheld. Documentary values come from the
matched passage text; dataset values come from query payloads, not page counts,
character offsets or a policy's printed figures. Legitimate source dates are
checked against the matched identity's metadata. Partial removals produce a notice.

These checks are deliberately conservative and are not semantic entailment or
legal verification. They cannot prove every paraphrase, applicability judgement
or comparison correct. OCR/layout limits remain visible, and interpretation still
requires reading the sources. No policy review or inferred KB approval is created.

## Local checks

`db/policy-evidence.test.ts` creates its own disposable DB from the made-up dev
copy. It tests active/historical selection, same-page identity separation, full
passages beyond excerpts, scope/role denial and archive changes. Parallel DB
suites serialize cluster-wide role bootstrap with an advisory lock.

`lib/policy-analysis/*.test.ts` covers selection validation, restrictive tool
composition, no-online behavior, poisoned argument rejection, identity/quotation/
number/date audits, conflicts and combined answers through the shared engine.

The mobile browser harness uses real local Postgres/PostgREST/OCR and a synthetic
provider transport (no paid calls):

```
BHW_POLICY_E2E_COMBINED=1 BHW_TEST_DATABASE_URL=postgres://postgres:bhw-local@127.0.0.1:55442/bhw_phase3 BHW_TEST_REST_URL=http://127.0.0.1:53002 node scripts/verify-document-library.mjs
```

It registers a synthetic dictionary only if the made-up fixture has none, checks
a real `fact_bhw_raw` count against SQL, and removes its own records afterward.
Use only a disposable loopback database, with migrations applied and the prefixed
production build already prepared. Windows setup and live-provider acceptance
remain deferred deployment work. Next application phase: explicit full-collection
review with durable runs and coverage accounting.

Final verification: 1,853 app tests passed, 13 skipped, including 20 focused policy
unit/engine cases and four PostgreSQL policy evidence cases. Thirteen mobile
browser acceptance groups passed with the synthetic provider transport and real
local query/OCR paths. Lint (one existing deliberate-fixture warning), types,
migration lint, prefixed production build and whitespace checks passed.

Policy numeric checks also require explicit values from the appropriate evidence;
the generic assistant's percentage/year allowances do not justify an unsupported
policy-mode dataset count. Exact reporting dates from actual query fields remain
usable, separately from the matched policy's publication/effective metadata.
