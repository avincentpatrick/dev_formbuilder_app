# Optional online policy research (Phase 6)

Policy mode in the existing Ask the Data assistant supports opt-in official-source
research for one quick question or one newly created full review. It defaults off.
Sending a quick question or creating a run resets the checkbox; a running review
keeps its frozen choice. Resume does not change that choice or repeat a completed
web search. Ordinary project attachment/web behavior remains separate.

## Provider and public query boundary

Reuse the existing Brave Web Search adapter and server-only
`BHW_WEB_SEARCH_API_KEY`; there are no new settings or shared ops/workflow changes.
Without the key, or when search fails, the assistant continues with its internal
sources and adds an explicit research gap. Live configuration remains deferred
by the owner. Usage follows existing Brave per-request accounting and provider
price conventions; an unknown price is not a zero-cost claim.

The server builds queries from a fixed public-topic vocabulary, one selected
publisher (`doh.gov.ph`, `pitahc.gov.ph`, or `officialgazette.gov.ph`) and an optional
numeric/hyphen issuance reference. No user question, conversation, policy title,
private passage, dataset row, model argument or retrieved text is appended. The
reference field is explicitly for a **public issuance number**, never a personal
identifier. New topics/sites require a deliberate allowlist change. This narrower
interface is intentional: it avoids asking a model to decide whether a free-form
query contains private evidence.

Search snippets only locate URLs; they are not grounds for factual citations.
Only HTTPS results on the selected publisher or its subdomains are eligible.
Fetch at most two distinct result URLs; no links, scripts or instructions in
retrieved content can add destinations. No search/fetch tools are exposed to the
answer model. It receives the captured evidence as untrusted JSON alongside the
existing scoped policy and registered-data tools.

## Retrieval, extraction and evidence

The shared fetcher rejects credentials, non-HTTPS schemes, nonstandard ports,
private/local/special IPs and mixed public/private DNS answers. It pins a validated
public address, preserves the original TLS/Host identity, and repeats validation
on every redirect. Policy retrieval additionally keeps redirects within the
selected publisher; credential-bearing search redirects cannot leave the search
API origin. Ambient session cookies and application credentials are not sent.
DNS resolution is bounded to five seconds and each HTTP request to twenty;
a research attempt has a sixty-second deadline. At most four redirect hops are
allowed and policy bodies are limited to two MiB after decompression.

HTML is parsed as inert text, excluding executable/navigation markup. Plain text
is supported. PDFs use the existing bundled extraction sidecar in a private temp
directory, with a 512 MiB heap, twenty-second child timeout and eight MiB output
limit. The temp original is removed afterward. No parser/PDF script or embedded
URL can authorize a network request. Online OCR is not added: scanned/unreadable
PDFs and incomplete extraction remain explicit limitations.

The bounded excerpt selector considers the first six PDF pages and at most twelve
2,400-character blocks per page, retaining at most two blocks per source ranked by
the public search vocabulary. It never claims complete online coverage. Capture
URL, publisher, title where available, retrieval time, original-byte hash, exact
excerpt/hash and publisher-reported dates. HTML publication metadata and explicitly
labeled ISO effective dates are retained; missing dates remain unstated. A PDF's
creation date or retrieval time is not inferred to be its publication/effective
date. Excerpts and hashes are retained, not a permanent library original.

Each accepted online citation has a server-issued `[[web:...]]` identity, distinct
from uploaded-policy tokens and dataset observations. Quote/date/numeric checks
use its own captured passage. Online text cannot silently establish supersession
of an uploaded policy, replace its canonical extraction or become a library entry.
The source viewer displays the saved excerpt and checks its SHA-256; clicking the
original public URL is an explicit user action. Dates and authority remain
publisher-reported, not independently legally verified.

## Private storage, durability and failure behavior

The additive `policy_research_evidence` table and service-only
`policy_research_api` store owner-scoped settings, lease state and a bounded
snapshot. Strict BHW admin role **and** current analysis approval are required
for preparation, save and read. Another admin's shared-library access does not
confer access to this private evidence. Generic registry tools exclude these
private table names even if someone registers them. No text enters public caches,
logs, inferred-KB approval states or the document library.

Quick questions prepare and retain evidence before the answer loop. If retention
fails, no unretained web claims are used. Run creation uses an atomic wrapper
around the existing review facade: freeze the opt-in/configuration together with
the original section inventory. Idempotent request retries cannot enable research
on an existing offline run or replace its original public settings.

The browser only creates/polls a run. Its existing secret-authenticated review
worker prepares the run's one web snapshot and reuses it for subsequent sections.
A ninety-second research lease and three-attempt interruption limit prevent
unbounded repeat searches. Stale workers cannot save and cancellation/revocation
prevents publication. If another worker owns research, the section uses its
existing bounded retry path. Provider/readability failures freeze an explicit gap
instead of invented findings. Resume retains that snapshot; start a new run for a
fresh search. A completed internal section-coverage result does not imply complete
or successful web research; online limitations remain separately visible.

Include the new table in BHW database backups. Restoring a run preserves its web
snapshot; expired preparation leases can be reclaimed. Disabling web credentials
prevents new searches while saved evidence stays readable by its authorized owner.
An app rollback can leave the additive evidence table intact. Windows scheduling,
backup validation and live-provider acceptance remain deferred deployment work.

## Local verification

Unit tests cover public-query construction, deceptive hosts, result limits,
snippet/injection isolation, extraction/date distinctions, provider failures,
snapshot reuse, cancellation, citation audits and HTTPS pinning/redirect/size/time
limits. The disposable PostgreSQL review suite also checks atomic opt-in,
idempotency, owner/role boundaries, stale leases and interrupted research recovery.

After the prefixed production build, use only a disposable loopback BHW database
and PostgREST:

```sh
BHW_POLICY_E2E_COMBINED=1 BHW_POLICY_E2E_REVIEW=1 BHW_POLICY_E2E_ONLINE=1 \
BHW_TEST_DATABASE_URL=<loopback synthetic database> \
BHW_TEST_REST_URL=<loopback PostgREST> node scripts/verify-document-library.mjs
```

Identity, AI and official-site/search transports are synthetic; local SQL,
PostgREST, Chromium, OCR and bounded PDF extraction are real. The harness checks
that online-off causes zero web requests, a private question never enters the
public query, injected links cannot trigger requests, the opt-in resets, saved
sources retain dates/hashes and ownership, unavailable search preserves internal
answers, and a closed-tab full review reuses one research snapshot. This does not
validate live Brave coverage, official-site availability, semantic/legal quality,
or Windows compatibility.
