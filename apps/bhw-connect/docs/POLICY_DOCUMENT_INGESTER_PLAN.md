# Policy and Document Ingester — Phased Implementation Handoff

**Status:** Phase 1 foundation implemented; acceptance verification remains incomplete.  
**Owner:** jongsky25  
**Created:** 2026-11-07  
**Application:** BHW Connect, `apps/bhw-connect/`

Build an admin-only document library that catalogs uploads, preserves their full extracted text,
and supplies evidence to the existing admin Ask the Data assistant. It must support combined
analysis of policies, applicable datasets, and optional online resources, including an explicit
analysis mode that accounts for the entire selected policy collection.

## Start here in a new chat

Suggested instruction:

> Read apps/bhw-connect/AGENTS.md and follow it. Read
> apps/bhw-connect/docs/POLICY_DOCUMENT_INGESTER_PLAN.md and the current
> apps/bhw-connect/docs/AI_ASSISTANT_PLAN.md. Implement the next incomplete phase of the
> policy/document ingester, following the confirmed owner decisions. Update this handoff with
> what shipped, verification evidence, and remaining work.

Read `CLAUDE.md`, `../../docs/working-agreement.md` (from the application root), and
`docs/HANDOVER.md` as required by AGENTS.md. Recheck current code and this checklist before work;
the implementation may have advanced since this plan was written. This file authorizes no
implementation by itself: follow the new chat's requested scope.

## Confirmed owner decisions

| Topic | Decision |
| --- | --- |
| Access | Strictly BHW users whose role is `admin`; exclude `editor`. |
| Formats | PDF first, including scanned and mixed text/image PDFs. No PowerPoint. Other formats are outside the initial scope. |
| Availability | Successfully processed uploads become usable immediately, without a human approval queue. |
| Extraction | Include local PDF text extraction and local OCR in this build; neither requires AI-provider tokens. |
| AI extraction | Optional future fallback only. Never silently send a difficult scan to a paid AI extraction service. |
| Online research | Off by default; enable per question, prioritizing official sources. |
| Assistant | Use the same admin Ask the Data path covered by `AI_ASSISTANT_PLAN.md`; do not build a second chat. |
| Public migration | The owner will correct that existing handoff to remove public Ask the Data and migrate it to admin use without public-facing limitations. This module consumes that path; it does not own a competing migration. |
| Storage | Catalog uploads and preserve original files plus complete extracted text. Chunks, summaries, and embeddings are derived artifacts. |

The immediate-use decision overrides the older assistant plan's review requirement for newly
uploaded source documents. It does not automatically approve AI-inferred graph facts or change
the existing KB review workflow. Reading an uploaded passage and asserting a new inferred
relationship are separate operations.

“Without public-facing limitations” means the admin assistant can use the internal corpus and
applicable registered datasets. Maintain authentication, source citations, safe dataset query
execution, and resource controls. Do not interpret it as a requirement to remove grounding or
execute arbitrary model-authored SQL.

## Existing foundations and gaps

These are observations from the checked-out code, not a claim that every feature is live:

| Existing location | Reuse or required change |
| --- | --- |
| `lib/db/require-admin.ts` | Resolves Meridian identity against BHW `admin_users`. Currently accepts `admin` and `editor`; this module needs an explicit strict-role check. |
| `app/admin/(dashboard)/assistant/` and `app/api/ai/assistant/route.ts` | Existing internal assistant UI, tool loop, citation/numeric checks, and no public answer-cache writes. |
| `lib/ai/dataset-tools.ts` | Existing internal tool composition; extend here rather than create another assistant. |
| `lib/ai/search-documents.ts` | Hybrid document search with keyword fallback; default six hits, maximum 25, excerpts capped at 1,200 characters. These limits cannot establish complete collection coverage. |
| `app/admin/(dashboard)/assistant/source/[chunkId]/page.tsx` | Existing source viewer; extend for general PDF citations and stable version links. |
| `doc_source`, `doc_chunk`, `doc_chunk_embedding`, `doc_embedding_model` | Existing document metadata, chunks, offsets, and embeddings. Extend additively. |
| `ingestion/ingest_documents.py` | PyMuPDF pipeline tailored to one budget deck. Its font filtering and one-slide chunking must not become generic PDF defaults. |
| `app/admin/(dashboard)/ingestion/page.tsx` | Batch history only; not an upload library. |
| `app/admin/(dashboard)/kb-review/` | Existing inferred-fact review; do not make this an upload activation gate. |
| `lib/storage/screenshots.ts` | Private on-disk storage pattern using `BHW_STORAGE_DIR`; adapt its containment/runtime-path approach for documents. |

The live architecture is Windows Server, Node, PostgreSQL/PostgREST and private disk storage.
Historical Supabase/Vercel names and old plan text do not establish current hosting. No cloud
storage migration or new hosted platform is required by this plan.

## Target flow and data contracts

`Admin upload → private original + catalog record → durable extraction/OCR job → complete text
and page map → searchable chunks → immediately available to admin Ask the Data`

Embedding runs as a separate stage. A provider outage must not prevent an otherwise successful
document from being available through keyword search.

Proposed records below are logical responsibilities; settle exact additive tables/columns in
Phase 1 after reviewing existing constraints:

- **Upload attempt:** uploader, time, original filename, detected type, bytes, content hash,
  outcome, duplicate target, and safe failure reason. Record rejected attempts after admin
  authorization where the application receives them; infrastructure-rejected requests may need
  separate request logs. Do not retain invalid file contents merely to catalog a rejection.
- **Document identity:** title, issuer, document type, policy/issuance number, tags, and optional
  collection membership. Allow missing metadata rather than inventing it.
- **Immutable version:** original storage key, hash, publication/effective dates, supersedes link,
  uploaded time, active/archived state, and pointer to its extraction. Upload time is not an
  effective date. Admins can correct metadata and archive a source without rewriting old evidence.
- **Extraction revision:** complete canonical text, per-page text/offset map, parser/OCR versions,
  language settings, page count, extraction method per page, and quality warnings. Preserve old
  revisions when reprocessing changes text so existing citations remain resolvable.
- **Processing job:** stage, progress, attempts, lease/heartbeat, retry time, error, and timestamps.
  Keep processing, availability, and embedding status distinct.
- **Analysis run:** requesting admin, question, source settings, frozen document versions and
  extraction revisions, dataset versions/as-of information where available, coverage ledger,
  cited evidence, outcome, and timestamps.

Suggested processing states: `queued`, `extracting`, `ocr`, `indexing`, `ready`,
`ready_with_warnings`, `failed`. Warnings do not create an approval queue. Unreadable pages must
be identified; partial text may be searchable with a clear warning, but cannot count as a fully
reviewed document. Failed/empty extraction must never appear as successful ingestion.

Retain a catalog event for an exact duplicate but reuse immutable file content where practical;
do not create duplicate evidence in search. Different bytes for the same policy are a new
version, not an overwrite. Default analysis scope is active, successfully processed versions;
historical versions can be explicitly selected.

## Phase 1 — Contracts, access, and persistent storage

- [x] Reconcile with the owner's updated `AI_ASSISTANT_PLAN.md`; identify the shared assistant entry point.
- [x] Design additive schema for catalog, versions, extraction revisions, jobs, and audit events.
- [x] Reuse `doc_source`/`doc_chunk` without breaking existing corpus keys or citations. Prefer a
  source row per immutable extraction with logical-document/version linkage rather than replacing
  chunks in place. Document the final mapping.
- [x] Keep automatic upload activation distinct from existing review status. If retrieval requires
  `approved`, explicitly map successful upload activation to that compatibility value and record
  the activation origin; do not globally treat every old `auto` source as approved.
- [ ] Enforce strict `admin` role on pages, server actions, APIs, original/text downloads, retrieval,
  and background-job submission. Audit the shared assistant route and source viewer too: accepting
  editors there would bypass the library restriction.
- [x] Deny direct public database access to new document records and text. Service credentials stay server-side.
- [x] Store files under a private document folder outside release directories, with generated keys,
  containment checks, atomic writes, and no user-supplied filesystem paths.
- [ ] Define concrete upload byte/page/time limits after measuring representative synthetic files;
  document configuration and user-visible rejection behavior.

**Acceptance:** Signed-out users, ordinary members, and editors cannot upload or retrieve document
metadata, originals, text, or citations through direct requests. Admin access works. Existing
corpus citations still resolve. File/DB failures leave a recoverable state, not a false success.

## Phase 2 — PDF extraction and local OCR

- [x] Refactor or reuse PyMuPDF components after checking dependency/licensing compatibility;
  remove deck-specific assumptions from the general parser.
- [x] Extract existing PDF text first; detect pages that need OCR, including mixed PDFs. Do not
  discard image-only sections just because another part of a page contains text.
- [ ] Add local Tesseract OCR, with required PDF rendering support and English/Filipino language
  support as appropriate. Verify actual Windows compatibility and installed language packs.
- [x] Preserve all page text and stable PDF page numbers. Capture headings/reading order where
  reliable; flag uncertain tables and layouts rather than inventing structure or values.
- [x] Save canonical text and page maps before chunking. Verify chunk offsets round-trip against
  the saved text, including non-ASCII characters across Python, JavaScript, and PostgreSQL.
- [x] Run extraction in a bounded subprocess, with safe argument passing, timeouts, memory/page
  budgets, no execution of PDF attachments/scripts, and cleanup of temporary files.
- [x] Use a durable database-backed job queue. Workers claim jobs with leases, recover abandoned
  jobs, retry bounded failures, and write idempotently. No work depends on an open browser tab or
  an unawaited request-side background promise.
- [x] Keep embeddings separate, configurable, and retryable; use existing model registry behavior.

**Acceptance:** Synthetic selectable-text, scanned, mixed, rotated, blank, corrupted, encrypted,
and table-heavy PDFs produce correct text or an explicit limited/failure outcome. Closing the
browser and restarting the worker do not lose jobs or duplicate chunks. Local extraction/OCR
works with AI-provider calls disabled and consumes no AI-provider tokens. Embedding failure
leaves keyword search usable. OCR is not presented as guaranteed accurate.

## Phase 3 — Admin document library

- [ ] Add `/admin/documents` and an admin navigation entry, respecting the application base path.
- [ ] Provide mobile-usable upload, progress, retry, and catalog views, with search and filters by
  title, issuer, type, tags, dates, and processing state.
- [ ] Display document/version detail: metadata, original download, complete extracted text,
  per-page warnings, processing history, and search availability.
- [ ] Support version replacement, supersession metadata, archive/unarchive, and duplicate feedback.
  Archive excludes a document from default future retrieval while retaining historical citations.
- [ ] Activate usable source text automatically after persistence and lexical indexing; no approval
  button or mandatory reviewer step. Keep OCR warnings visible after activation.
- [ ] Provide “Ask about this document/collection” through the shared admin assistant with source
  selection carried server-side or validated by the server.

**Acceptance:** An admin uploads a PDF, sees it cataloged, waits for processing, reads its text,
and asks a cited question without operator intervention or review approval. Failed and duplicate
uploads remain visible. Another authorized admin can see the shared library; editors cannot.

## Phase 4 — Combined policy and dataset analysis

- [ ] Extend existing retrieval with validated document/version/collection filters and tools to
  list eligible documents and read complete passages or neighboring sections beyond truncated hits.
- [ ] Update citations from slide-only wording to document + immutable version + PDF page/section.
  Do not confuse printed page labels with physical PDF page numbers.
- [ ] Add source controls to the shared assistant: active library or selected documents,
  automatic/restricted dataset selection, and online research off by default.
- [ ] Use the existing dataset registry/query tools for calculations. Do not embed numeric rows
  and treat similarity matches as computed totals.
- [ ] Distinguish policy requirements, dataset observations, documentary figures, and analytical
  conclusions. Compare dates, geography, units, and population definitions before joining evidence.
- [ ] Report unavailable evidence and conflicting policies; distinguish newer publication from
  confirmed legal supersession. Never infer noncompliance solely from missing dataset fields.
- [ ] Keep source content untrusted: documents cannot change tool permissions or system instructions.
- [ ] Retain existing numeric/citation checks and add version-aware evidence checks. Keep internal
  answers and source text out of public caches/logs and public tools.

**Acceptance:** Questions using a policy alone, multiple conflicting policies, and policy plus
dataset yield traceable answers. Unsupported figures and invented citations fail verification.
Archived versions are excluded by default, historical selection works, and a passage from one
document cannot be cited as the same-numbered page of another.

## Phase 5 — Explicit full-collection analysis

- [ ] Add an explicit “Review all policies” mode in the same assistant. Ordinary retrieval remains
  a quick question-answer path; top search results must not be labeled an exhaustive review.
- [ ] Snapshot eligible versions at run start, with an inventory of excluded, failed, processing,
  and unreadable material. Later uploads must not silently change an ongoing run's scope.
- [ ] Process every selected document in bounded sections; persist evidence-backed findings and
  consolidate across documents without replacing source text with summaries.
- [ ] Track coverage per document and section, with retries, resumable progress, cancellation, and
  resource budgets. An interrupted or budget-limited run returns a partial result explicitly.
- [ ] Present synthesis, conflicts, relevant dataset findings, gaps, citations, and a coverage
  report. Distinguish “examined with no relevant finding” from “not examined.”

**Acceptance:** A synthetic policy collection includes a decisive clause in a low-similarity
document that ordinary top-hit retrieval misses. Full review accounts for it and every other
selected document. Interruptions and unreadable pages prevent a false completeness claim.

## Phase 6 — Optional online research

- [ ] Integrate a supported search/fetch provider into the existing assistant, enabled only for
  the current question/run. Provider choice and deployment configuration are implementation tasks.
- [ ] Prefer official issuing agencies and original issuances; capture source URL, title,
  retrieval time, relevant quoted text, and publication/effective date when available.
- [ ] Label web evidence separately from uploaded policy and dataset evidence. A web result must
  not silently supersede an uploaded policy or become a permanent library entry.
- [ ] Build queries without private document passages or personal dataset records. Constrain URL
  fetching against private/local addresses, unsafe redirects, oversized responses, and timeouts.
- [ ] Treat fetched pages as untrusted text and retain evidence needed to explain cited claims.
- [ ] If search is unavailable, finish with available internal evidence and explain the gap.

**Acceptance:** With online research off, no search/fetch tool runs. With it on, official evidence
is cited and dated. Web prompt injection cannot alter access, expose internal text, or trigger
unauthorized requests. Provider failure does not invent online findings.

## Phase 7 — Deployment, recovery, and end-to-end verification

- [ ] Coordinate installation of Python/parser/OCR dependencies and a durable worker service or
  scheduled worker with the Windows server operator. Decide and document the actual process model
  before deployment; do not assume development tooling exists on the server.
- [ ] Extend backup/restore coverage to originals, extraction text, metadata, and run evidence.
  Existing screenshot backups do not prove document files are included.
- [ ] Verify service-account filesystem permissions, disk capacity, worker concurrency, restart
  recovery, failed-job visibility, and cleanup of abandoned temporary uploads.
- [ ] Keep originals and canonical text out of git. Use only synthetic fixtures and approved
  non-sensitive examples in development and tests.
- [ ] Run required lint, types, tests, migration checks, and prefixed builds from `CLAUDE.md`.
  Add focused database and browser checks for access, upload-to-answer, OCR failure, version
  citations, and full-review coverage. Report any checks not run.
- [ ] Verify on the Windows deployment: admin upload → OCR/text → searchable library → combined
  answer → source link; then restore a backup and verify original/text/citation consistency.
- [ ] Record operator actions and rollback procedure. An additive rollback can disable the feature
  and worker without deleting uploaded originals or completed evidence.

Changes to `ops/`, `.github/`, or shared deployment files require the existing ownership process
and the other developer's approval. Prepare concrete changes before requesting that approval.
No new hosting service is implied. Do not delay application-only work merely because deployment
coordination will eventually be needed.

## Cost and completeness expectations

Local parsing and OCR spend server CPU, memory, and storage, not AI-provider tokens. Embedding,
AI answers, full-collection synthesis, and any selected online provider may have usage costs.
Do not advertise the entire module as token-free. OCR errors, tables, missing annexes, and
unreadable pages remain evidence limitations and must appear in the catalog and analysis output.

The build is complete when an admin can upload scanned policies without manual server-side
loading, use them immediately after processing, ask combined questions through the shared
assistant, and request a verifiable full-collection review with optional online evidence.

## Progress and next action

| Phase | Status | Evidence / handoff |
| --- | --- | --- |
| 1 — Contracts and access | In progress | Additive schema, strict assistant/API/source guards, private atomic storage, and contracts in POLICY_DOCUMENT_STORAGE.md. Upload endpoints remain later phases. Local migration/RLS checks passed (80 related tests); 16 local browser/API checks passed; scanned/mixed synthetic probes completed. Windows acceptance and worker enforcement remain. |
| 2 — Extraction and OCR | Implemented; Windows acceptance pending | Generic parser, local OCR, leased queue, atomic lexical activation, separate embeddings; 16 synthetic Python/DB tests pass. See POLICY_DOCUMENT_WORKER.md. Operator must validate Windows runtime/languages/process limits before deployment. |
| 3 — Admin library | Not started | No general upload UI in inspected checkout. |
| 4 — Combined analysis | Not started | Reuse existing assistant and document/dataset tools. |
| 5 — Full-collection review | Not started | Requires durable runs and explicit coverage accounting. |
| 6 — Online research | Not started | Per-question opt-in; provider not selected. |
| 7 — Deployment and verification | Not started | Coordinate worker, OCR dependencies, and backups. |

Update this table after each phase with changed paths, checks/results, unresolved dependencies,
and the next concrete step. Do not mark a phase complete on code changes alone if its acceptance
criteria remain unverified. No implementation, dependency installation, database change, or
deployment was performed while authoring this plan.

### Phase 1 implementation evidence (2026-11-12)

See `POLICY_DOCUMENT_STORAGE.md` for schema mapping, activation transaction contract,
storage recovery and resource budgets. No deployment or live DB change was performed.
The assistant plan still has historical hosting/review text: current app instructions
and confirmed owner decisions above govern. No second assistant was introduced.
The access and limit checklist items remain open until direct page/API and database
acceptance checks plus representative OCR measurements are complete. No upload,
worker, or download endpoint is claimed as shipped in this increment.

Verification: `npm ci`, lint, typecheck, full Vitest run (1,734 passed,
16 skipped), migration lint and the prefixed no-data production build passed.
Final focused suite including editor denial and storage traversal/size checks:
48 passed. Database integration, browser and Windows checks were not run.
Next: validate migration/RLS and direct page access on a synthetic development DB;
measure scanned/mixed PDFs and finish resource limits before closing Phase 1.

### Local database verification follow-up

Created an isolated Docker PostgreSQL 15 database with pgvector 0.8.2, bound only
to loopback port 55442. Loaded the committed development schema and made-up seed
using `db/bootstrap-roles.sql` and `db/load-dev.mjs`; 112 baseline migrations were
recorded and both later migrations, including the library migration, applied.
No live server/database was contacted.

Added `db/document-library.test.ts` (six integration tests). Verified migration
reruns have no pending changes; synthetic legacy source/chunk IDs resolve;
all seven tables enable RLS with no browser policies; anon/authenticated SELECT,
INSERT, UPDATE and DELETE are denied; RLS still hides synthetic records if SELECT
is temporarily granted; service-role inserts, extraction revisions, duplicate
attempts and audit events work; invalid page counts fail and new versions remain
unavailable with embeddings pending. Fixtures and temporary grants roll back.

Command: `BHW_TEST_DATABASE_URL=postgres://postgres:bhw-local@127.0.0.1:55442/bhw_connect
npx vitest run db/document-library.test.ts db/db-tools.test.ts
lib/db/require-admin.test.ts app/api/ai/assistant/route.test.ts
app/api/ai/assistant/regression/route.test.ts` — 80 passed, none skipped.
Lint passed with one existing warning in the deliberate base-path defect fixture;
typecheck and diff whitespace checks passed. Application role tests cover editor
denial separately from the database's generic authenticated role.

Still open: browser/direct page checks, actual Windows filesystem validation,
representative scanned/mixed PDF measurements and worker enforcement. Phase 1
remains in progress; this follow-up completes the local database verification.

### Browser and scan verification follow-up (2026-11-13)

Completed the next local acceptance checks. Chromium ran against the prefixed
production build, real local PostgREST/development DB and fake Meridian identities:
16 checks passed for signed-out/member/editor/admin access to assistant page,
source page and both APIs. Only admin could see the synthetic private passage.
Added a reusable loopback-only harness in `scripts/verify-document-access.mjs`.

Added `ingestion/measure_document_limits.py`; image-only, same-page mixed and
mixed-document synthetic PDFs recovered their scanned clause using local English
OCR in 0.246–0.417 seconds per scanned page. Confirmed native text extraction alone
misses scanned evidence even on pages with a selectable header. Retained the
25 MiB/300-page/600-second/three-attempt initial ceilings; Phase 2 must enforce
page/time limits, and Phase 3 must expose clear rejection messages.
Details and reproducible commands are in `POLICY_DOCUMENT_STORAGE.md`.

No live sign-in, Windows filesystem/CPU, Filipino OCR, upload endpoint or durable
worker was verified. Phase 1 remains open for deployment-dependent acceptance;
application foundation is ready for Phase 2 extraction/OCR/worker implementation.

### Phase 2 implementation (2026-11-13)

Added `20261113110000_document_processing.sql`: per-job extraction uniqueness,
current-revision pointer, separate extraction/embedding job kinds and active-job
indexes, plus service-only strict-admin enqueue RPC. Existing source/chunk IDs
remain valid. `ingestion/document_extract.py` preserves native text and detects
image/mixed pages for local OCR, with physical-page maps, warnings and bounded
resources. `document_worker.py` claims jobs with locks/leases, recovers abandoned
attempts, checks admin identity/hash/path, runs bounded subprocesses, and publishes
complete text/chunks/activation/audit state atomically. No browser tab or request
owns the work. Reprocessing retires old upload sources from default search while
preserving their text/chunks for citations. `document_embed.py` is an optional
separate token-consuming task using the existing model/dimension registry.

Search now returns upload extraction limitations, and upload citations/source
pages use physical PDF-page wording. Canonical/chunk offsets round-trip through
Python and PostgreSQL plus JavaScript code-point validation, including café and
an astral Unicode character. No generic deck font filter was reused.

Verification: the migration applied successfully to the local synthetic database.
16 Python parser/real-DB tests passed, covering text/scan/mixed/rotation/blank,
corrupt/encrypted/table-heavy PDFs, page limits, OCR-language failure, Unicode,
leases/locks, third-attempt failure, timeout cleanup, actual CLI SIGTERM recovery,
lexical availability, retained revisions/citations, and embedding failure with
provider credentials disabled. Synthetic vectors test measured-dimension registry
writes without a real provider call. Full app suite: 1,748 passed, 11 skipped;
lint (one existing deliberate-fixture warning), types, migration lint and prefixed
production build passed. Final verification details follow below if rerun.

The remaining Phase 2 checkbox explicitly includes installation and verification
on Windows and language packs; it stays open. Linux tests do not establish that.
`POLICY_DOCUMENT_WORKER.md` documents the chosen scheduled `--once` process model,
Python/Tesseract dependencies/licenses, worker-only setting, ACLs, recovery and
operator actions. No shared ops/workflow file, live database or service was changed.
Next application phase: Phase 3 admin upload/catalog/progress/retry/detail library.

Final Phase 2 checks: 16 Chromium page/API access checks passed again against the
prefixed production build after the PDF-source viewer update. The final Python
suite now includes 16 tests; JavaScript/PostgreSQL code-point checks explicitly
include an astral character as well as café. Lint/types and the production build
passed after those final changes. Local test containers were stopped afterward.
