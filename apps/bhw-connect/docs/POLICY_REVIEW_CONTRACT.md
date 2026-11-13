# Full policy review (Phase 5)

Policy mode in the existing Ask the Data assistant offers **Quick question** and
**Review all policies**. Quick questions use retrieval and never claim exhaustive
coverage. A full review is private to its requesting strict BHW admin, who must
also remain approved in `analysis_users`. Library ownership remains shared;
review questions, dataset evidence and findings are owner-only. Pages, APIs,
source reads, claim/heartbeat/publication and resume recheck authorization.

## Snapshot and coverage

`policy_review_api` freezes version/extraction identities, titles, issuer, dates,
supersession pointers, archive state, extraction warnings and a section inventory
at creation. Active scope inventories archived, processing and failed material as
exclusions. Selected history requires explicit historical opt-in. Missing selected
IDs remain gaps. Later uploads, reprocessing, metadata edits and archive changes
do not silently rewrite a run. Canonical text stays in the immutable extraction;
each bounded section stores code-point offsets, physical page and SHA-256.
Both worker reads and the private source viewer check that hash.

Every canonical physical page is traversed without a search/top-hit gate. Sections
contain at most 2,400 code points. The page map must cover the entire canonical
text in order, separated by the extractor's form feeds. Empty/unreadable sections
are recorded without provider calls. An invalid map excludes the document.
Snapshots above 1,000 inventory entries or 5,000 sections are rejected rather than
silently truncated; narrow the collection or selected versions.

A completed coverage result means all frozen sections were examined by the
machine, including sections with no relevant finding, with no exclusions or
extraction-quality warnings. It does **not** prove semantic completeness, source
accuracy, or legal correctness. Missing, unreadable, failed and quality-limited
material prevents that result. Interrupted, cancelled and budget-limited work is
explicitly incomplete. Per-document counts, every section state, exclusions,
findings, limitations and attempt counts remain inspectable.

## Findings, synthesis and datasets

The full bounded section is supplied as untrusted JSON evidence to the existing
provider cascade/tool loop. No model-authored coverage claim is used. Structured
findings must carry the exact server-issued version/extraction/section/page token
and a matching source quote. Existing policy identity, quote/date and numeric
checks apply. Unsupported results fail the attempt, rather than becoming
“examined with no relevant finding.” Providers can still miss a meaning; human
review is required for consequential interpretations.

Every accepted finding is consolidated by normalized subject across the whole
run, with counts and paginated source findings. Different exact comparison quotes
on policy requirements across versions are **comparison candidates**, never a
certified conflict. Synonyms can form separate subjects; scope, definitions,
publication/effect dates and explicit supersession must be assessed by a person.
No summary replaces canonical text. Requirements, documentary figures, dataset
observations and analytical conclusions retain separate categories.

Only approved registered dataset dictionaries and existing checked query tools
are available. Restricted tables and policy-only mode are enforced by the server;
no extra indicator, raw SQL or web tools can widen them. Definitions freeze at
start; a changed or revoked dictionary is not queried. Dataset **rows are not
frozen at start**: actual query results are retained with execution timestamps.
Those timestamps are not a dataset's substantive as-of date. Report evidence
includes the actual results, source pointers, gaps and available provider usage.
Unconfigured provider prices/usage produce unknown cost, not a zero-cost claim.
Private review tables are excluded from the generic registry facade even if
someone registers their names. No private evidence enters public caches/logs.

## Durable execution, resource limits and recovery

Creation is idempotent per owner/request UUID. The browser only submits and polls;
closing it does not execute or cancel work. The existing secret-authenticated
`POST /bhw-connect/api/analysis/worker?kind=policy-review` dispatches one section
attempt. With no `kind`, policy work is the fallback after existing source/project
work. The dedicated dispatch avoids starvation without stealing a project lease.
It reuses `CRON_SECRET`, existing AI keys/quota/provider settings and optional
`BHW_AI_PRICES_JSON`; no new settings or shared ops files are required.

The operator must schedule this HTTP dispatch independently of browsers, allowing
up to 180 seconds for one section. Start with one non-overlapping dispatcher;
there is no browser-triggered worker loop. Scheduling and live provider/Windows
acceptance remain deferred by the owner. Extraction/OCR retains its separate
Python queue/process model in `POLICY_DOCUMENT_WORKER.md`.

A claim consumes one section-attempt budget unit before a provider call. The
initial budget defaults to 200, maximum 1,000. Resume adds 1–1,000 attempts, lifetime
maximum 10,000, and retains completed section outcomes. Provider/tool calls are
bounded to three tool rounds plus the existing forced wrap-up/empty-answer retry;
at most eight tool-call events are allowed per attempt. Each section has a
180-second abort deadline, a 60-second lease renewed every ten seconds, and at
most three automatic attempts per retry cycle. Retries wait a minute. Explicit
resume can reset failed-cycle attempts while retaining lifetime attempts/history.

Parent-run then section locking serializes cancellation, budget use, expiry
recovery and publication. Expired worker leases are recovered on dispatch; stale
leases cannot publish. Cancellation invalidates active leases and preserves
completed findings; heartbeat aborts an outstanding provider call after lease
loss. Resume does not re-examine completed sections. A final role/lease check
prevents publishing after revocation/cancellation, including a response arriving
after the resource deadline. Abandoned/cancelled provider charges may be unknown;
section-attempt consumption remains recorded.

Backups must include all three `policy_review_*` tables plus immutable extraction
text/originals. After restore, dispatch recovers expired leases; review counts
are recomputed from stored section outcomes. Disabling the scheduler pauses new
work; retaining the additive schema permits rollback without removing evidence.
An older app can keep serving ordinary questions. There are no new public grants
or inferred-KB approval states.

## Reproducible local verification

`db/policy-review.test.ts` provisions a disposable database using the made-up
baseline and migrations. It verifies full traversal, comparison candidates,
budgets/concurrent dispatch, stale leases, cancellation, resumability, retries,
blank physical pages/Unicode offsets, exclusions, late uploads, integrity checks,
owner/role restrictions and idempotency. Unit tests verify grounded structured
results, dictionary drift, scope enforcement, cancellation, request boundaries
and shared provider abort propagation.

With a synthetic local database and PostgREST, after the prefixed production build:

```sh
BHW_POLICY_E2E_COMBINED=1 BHW_POLICY_E2E_REVIEW=1 \
BHW_TEST_DATABASE_URL=<loopback disposable BHW database> \
BHW_TEST_REST_URL=<loopback PostgREST> node scripts/verify-document-library.mjs
```

This uses real local PostgreSQL/PostgREST/OCR and Chromium with synthetic identity
and provider transport. It checks a decisive low-similarity annex missed by a
single top search hit, closes the browser during worker execution, pauses/resumes
on budget, opens the exact hash-verified source, and distinguishes clean coverage
from warning-limited partial results. It does not validate a real provider's
semantic quality or Windows compatibility.
