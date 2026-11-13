# Document storage contract (Phase 1)

The shared entry point is `/admin/assistant`, backed by `/api/ai/assistant`.
Its page, citation viewer and both APIs now require the BHW `admin` role. Other
admin features retain their existing editor permissions. Internal retrieval tools
remain server-only and must only be invoked after this request-level check.
There are no upload/download/job-submission endpoints yet; future endpoints must
call `getStrictAdminUser` before parsing uploads or reading records/files.

## Database mapping

`document_catalog` is logical identity and editable descriptive metadata.
`document_file` is shared original content, deduplicated by SHA-256.
`document_version` identifies immutable original bytes and dates; metadata and
archive state can change. `document_extraction` is a new immutable revision each
time text changes. It stores complete text, ordered page maps and provenance.
Each completed extraction links to its own `doc_source`; use a generated key
`upload:<extraction UUID>`. Existing corpus keys/chunks are untouched. Never update
old canonical text or replace chunks; historical citations must keep resolving.
Page-map offsets must count Unicode code points, matching PostgreSQL length and
Python strings; JavaScript must use code-point conversion when slicing.

Phase 2 must atomically persist canonical text/page map, chunks, source activation
and availability. Successful nonempty lexical indexing sets that source's existing
`status='approved'` with `activation_origin='successful_upload'`. This is automatic
activation of uploaded text, not approval of inferred KB facts. No existing `auto`
source changes status. Embedding status is independent. An empty extraction cannot
be activated. The worker must validate page maps and offsets before publishing.
`document_job` reserves stage, lease, heartbeat, bounded attempts and retry state;
lease-claim and completion transactions belong to Phase 2.

`document_upload_attempt` records authorized attempts, including rejection and
duplicate references. Invalid bytes are not retained. `document_audit_event`
records metadata/archive/retry actions without copying private text into logs.
All seven tables enable RLS, have no browser policies, and revoke browser-role
privileges. Service-role access is explicitly granted. Analysis-run records are
reserved for Phase 5 when snapshot/coverage semantics are implemented.

## Files and recovery

`BHW_STORAGE_DIR/documents` is private, never served as a public directory. On the
server `BHW_STORAGE_DIR` must be the existing absolute persistent storage path
outside releases, with service-account-only write access. The local ignored
`.local-storage` fallback is for development only. No new environment variable.
Keys are generated UUIDs with `.pdf`; no filenames or request paths reach disk.
The helper checks PDF magic (not a full parser validation), writes an exclusive
temporary sibling, then publishes using an atomic no-clobber hard link. NTFS
supports this; verify service permissions and disk behavior on Windows before use.

File publication precedes DB registration. DB failure must return failure and
leave an orphan for reconciliation, never claim successful ingestion. Worker
restart must reconcile receiving attempts and orphan/temp files after a grace
period; cleanup is not implemented in Phase 1. The trusted directory must not
contain attacker-controlled symlinks/junctions. File read failures propagate for
the API to report safely; never mark a failed read as successful processing.

## Initial resource budget

`lib/storage/documents.ts` is the single fixed budget: 25 MiB per PDF, 300 physical
pages, 600 seconds per extraction attempt, three attempts. These are conservative
initial controls, not Windows OCR throughput claims. Storage enforces bytes and
magic now; Phase 2 must enforce page/time budgets in the worker, and Phase 3 must
show explicit rejection messages (PDF only, 25 MiB maximum, 300 pages maximum,
processing timed out). Encrypted/corrupt PDFs must fail explicitly in the parser.

Measured locally on 2026-11-12 with synthetic selectable text PDFs and PyMuPDF
1.26.6: 1 page / 813 bytes / 0.040 s; 300 pages / 124,416 bytes / 0.087 s;
301 pages / 124,830 bytes / 0.036 s for text extraction. These small simple PDFs
validate boundary fixture feasibility, not OCR capacity. Scan/mixed/OCR benchmarks
and tuning of the 600-second budget on Windows remain Phase 2/deployment checks.
Do not interpret this baseline as completed representative OCR measurement.


## Browser and scan verification (2026-11-13)

`scripts/verify-document-access.mjs` runs Chromium against the prefixed production
build, local PostgreSQL/PostgREST, and a local fake Meridian whoami endpoint.
The identity endpoint supplies synthetic admin/editor/member identities; no real
Meridian account or provider is used. All 16 checks passed: only admin sees the
assistant and synthetic stored citation; all other roles lack that content.
Both assistant APIs reject non-admin roles with 401. An admin's deliberately
invalid request reaches schema validation (400), without triggering AI generation.
Synthetic rows are cleaned up afterward. This verifies BHW's access enforcement,
not the live Meridian sign-in service. Requires a prior prefixed production build,
loopback-only disposable DB/REST URLs, and Chromium (`BHW_TEST_CHROMIUM` optional).

`python ingestion/measure_document_limits.py` uses temporary synthetic PDFs and
local Tesseract 5.5.0/PyMuPDF 1.26.6. English OCR found the scanned clause in an
image-only page (33,316 bytes; 0.417 s OCR), a page with both selectable header and
scanned body (33,633 bytes; 0.268 s), and a two-page mixed document (33,803 bytes;
0.246 s for its scan). Native extraction recovered zero scan characters and only
the selectable header/annex from the mixed files. Therefore Phase 2 must consider
image regions even when native text exists. The probe renders at 200 DPI and uses
a 60-second per-page subprocess timeout; it makes no AI-provider calls.

Keep the initial 25 MiB / 300 pages / 600 seconds per attempt / three-attempt
budget. These are hard ceilings, not promised throughput. Complex/noisy scans,
tables, language differences and the Windows CPU can take longer; exceeding the
budget must produce a visible failure/partial warning, never false completeness.
Only `eng` and `osd` are installed here; Filipino OCR is unverified and deployment
must explicitly install/verify any selected language data. Ten repeated synthetic
scan pages occupy 36,440 bytes due to shared PDF image reuse; this does not predict
real upload size. Test real-world complexity using non-sensitive fixtures in Phase
2 and measure Windows throughput before enabling the worker.
