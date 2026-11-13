# PDF extraction and worker (Phase 2)

This is application code and a locally verified worker, not an installed Windows
service. The upload library is Phase 3; there is no upload endpoint yet. The shared
assistant and document records remain strictly admin-only.

## Components and deployment prerequisites

- `ingestion/document_extract.py`: generic PyMuPDF extraction and local Tesseract
  OCR. No deck-specific font/heading filtering. It never opens embedded attachments,
  runs PDF JavaScript, follows links, or calls an AI provider. Text sorting is a
  best effort, not guaranteed reading order.
- `ingestion/document_worker.py`: PostgreSQL queue, leases, retries and atomic
  publication into existing `doc_source`/`doc_chunk` plus immutable extraction records.
- `ingestion/document_process.py`: bounded subprocess tree and credential filtering.
- `ingestion/document_embed.py`: optional separate provider-consuming stage using
  the existing Gemini embedding helper and stored model registry.

Python 3.12 x64 with `pip install -r ingestion/requirements-documents.txt` is the
local tested dependency set. Install local Tesseract separately, including the
selected language data. Pass a trusted full executable path with `--tesseract`
when it is not on PATH, and `--languages eng` or `--languages eng+fil` only if
those exact language packs are installed. This environment has English and OSD;
Filipino/Tagalog (`fil`/`tgl`, depending on the actual installed distribution) is
not validated. No fallback to English when configured language data is missing.

PyMuPDF 1.26.6 is offered under AGPL-3.0 or a commercial license; its existing use
in this repository does not waive distribution/service obligations. Tesseract
uses Apache-2.0, and psycopg2-binary uses LGPL with its documented OpenSSL exception.
The operator must include dependency notices and meet applicable source/license
obligations or use the appropriate commercial PyMuPDF license before deployment.
This change does not embed licensed binaries or claim legal clearance. Check the
actual Windows wheel and Tesseract build on Server 2016; Linux tests do not prove
Windows compatibility.

The operator provisions `BHW_WORKER_DATABASE_URL` for this worker only. It must
connect to the BHW database with a trusted role that can use the private library,
existing corpus/model tables and their sequences (the BHW schema owner is one
option). Never use Meridian's database. Keep the credential in the worker's
protected environment, not command-line arguments. No shared deploy/ops/workflow
files were changed. This new setting is documented in `.env.example` and requires
operator configuration; it is not automatically written from GitHub secrets.

`BHW_STORAGE_DIR` must be an absolute persistent path outside releases. The
worker opens only generated UUID PDF keys beneath its `documents` subfolder,
checks size and SHA-256 against the catalog, then parses a private snapshot.
Protect this folder and the service account's temp directory with filesystem ACLs.

## Process model

Use one Windows Task Scheduler task for extraction, repeated every minute with
"Do not start a new instance" and the service account's protected environment:

```
C:\meridian\bhw\worker-venv\Scripts\python.exe ingestion\document_worker.py --once --languages eng --tesseract C:\Tesseract-OCR\tesseract.exe
```

Set the task's working directory to a separately provisioned worker release tree
containing all four new worker modules and `ingest_documents.py`. Next standalone
output does not automatically include this Python code. Retain the preceding
worker tree for rollback. The operator installs/updates this tree and venv before
scheduling it; do not run the task from an expiring web release directory.

`--once` claims at most one job and returns; no job depends on a browser or request.
Alternatively omit `--once` for a supervised continuous process polling every two
seconds. A separate, opt-in task uses `--once --embed` and receives
`GEMINI_API_KEY`, `GEMINI_EMBEDDING_MODEL` and optionally `GEMINI_EMBEDDING_DIM`.
Only this embedding task can consume provider tokens. Leave it unscheduled to
keep lexical-only operation; its jobs remain pending and text remains searchable.
The extraction task does not need any AI keys. OCR languages/executable are trusted
operator CLI configuration, never upload-supplied values.

Submission is through the service-only `enqueue_document_job(version, actor)` RPC.
It checks the actor's current strict admin role and deduplicates active extraction
jobs under a version lock. The future upload/retry route must independently
resolve `getStrictAdminUser()` and pass that identity, never a caller-chosen actor.
There is no public queue-submission or worker-execution route. The worker rechecks
the requesting admin before claiming, and fails jobs from revoked/editor accounts.

## Recovery and resource controls

Claims use `FOR UPDATE SKIP LOCKED`, a random lease token and 45-second lease.
A heartbeat runs every 10 seconds. Expired active jobs can be claimed again;
expired third attempts become terminal failures. Other failures requeue after a
minute, with at most three attempts. Job states/progress/safe errors are persisted.
A replacement worker cannot be overwritten by a stale lease holder.
SIGTERM/console interruption stops the parser tree and leaves an expiring lease;
a hard crash recovers after lease expiry. If the DB fails, restart the worker;
no completion can be published without a successful transaction.

Limits: 25 MiB input, 300 physical pages, 600 seconds per attempt, 60 seconds per
OCR page, at most 12 million raster pixels per page, 10 million canonical Unicode
characters. Linux applies a 1.5 GiB address-space cap inherited by OCR. Windows
uses a 1.5 GiB Job Object memory cap, four-process limit and kill-on-close. The
parser waits for a parent handshake before opening the PDF, so Windows limits
are attached first; failure to attach them fails closed. Actual Windows Job Object
behavior under Task Scheduler remains an operator acceptance check.

Temporary PDF snapshots, OCR PNGs and extraction JSON live only in private temp
folders. Normal completion, failure, timeout and handled shutdown clean them up.
A hard OS crash may leave folders; add age-based cleanup after confirming no
active worker owns them during Phase 7 deployment setup. The trusted disk roots
must contain no attacker-controlled junctions/symlinks. This is bounded parsing,
not an OS security sandbox.

## Evidence, availability and embeddings

Every page is represented by a physical PDF page number and Unicode code-point
start/end offsets into the saved canonical text. Pages are joined with one form
feed. Native text is preserved; pages with images (including inline images),
short/no native text, or rotation receive full-page local OCR. Mixed pages retain
both native and OCR text with a transcription marker: overlaps may be duplicated
and are explicitly warned. Blank/unreadable pages and OCR failures are recorded.
An empty whole extraction fails, never activates. Missing OCR software/language
configuration fails explicitly. Complex tables/vector layouts are warned; no
structured numeric interpretation is invented. OCR accuracy is never guaranteed.

Before publication, offsets/page order are checked. One transaction inserts the
complete canonical text/page map, bounded 2,400-code-point chunks, an approved
internal source and upload activation provenance, current-extraction pointer,
separate embedding job, audit event and successful extraction-job state. The GIN
trigram index makes lexical search available immediately on commit. Warnings
produce `available_with_warnings`, and retrieval reports quality limitations.

Each extraction gets a new source key `upload:<extraction UUID>` and is unique per
job. Reprocessing creates a revision and changes the previous revision's source
status to `rejected` solely to retire it from default search; it does not delete
text/chunks or alter their IDs. This status means "not currently searchable" for
superseded upload revisions, not human rejection. It does not change old `auto`
corpus sources or the KB review queue. Archived versions are never activated in
search by the worker. Archive/unarchive UI and all-version filters are Phase 3/4.

Embedding jobs are independently leased/retried. Missing keys/provider failures
only affect embedding status; lexical availability never changes. Model IDs stay
in configuration; dimensions are measured by the existing helper, stored in the
registry and checked against any existing row. A dimension change is rejected,
not written over existing vectors. All vectors for a job commit together.

## Verification and remaining operator work

Run synthetic parser/worker tests without any provider key:

```
BHW_TEST_DATABASE_URL=postgres://postgres:bhw-local@127.0.0.1:55442/bhw_connect python -m unittest discover -s ingestion -p 'test_document*.py' -v
```

The real-DB fixtures require a loopback-only disposable development database with
both library migrations applied. Sixteen tests cover native Unicode, scanned,
mixed, rotated, blank, encrypted, corrupt and table-heavy PDFs; limits; queue
submission/role checks; concurrent locks; expiry/retry exhaustion; SIGTERM recovery;
timeout cleanup; lexical search; retained citations; provider-disabled embedding
failure and synthetic-vector model registration. No live provider call was made.

Before deployment, operator checks remain: install/test Python/parser/Tesseract
and chosen languages on Windows, verify Job Object/NTFS/ACL behavior, provision
the worker credential/tasks/tree, test restart recovery on the box, include
originals/DB extraction text in backup/restore, and measure OCR throughput there.
Disabling the task and restoring the prior worker/web tree is an additive rollback;
keep the database migration and all originals/extractions. Never remove uploaded
evidence to roll back code.
