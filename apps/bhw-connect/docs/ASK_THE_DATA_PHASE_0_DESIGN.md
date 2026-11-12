# Ask the Data Phase 0 technical design

**Date:** 2026-11-12. **Status:** Phase 0 design complete; runtime compatibility and deployment remain unverified. No application functionality changed.

This is the implementation handoff for [the phased build plan](ASK_THE_DATA_BUILD_PLAN.md). Phase 1 is next. The design preserves every confirmed product decision, including removal of anonymous Ask the Data before the private workspace is released.

## Inspected foundation

Inspection baseline: local commit `b41d1766` (`Distinguish unavailable FHSIS data from missing area reports (#418)`). Findings describe this checkout, not the live Windows server.

| Concern | Existing component | Required change |
| --- | --- | --- |
| Identity | `lib/db/meridian-identity.ts` forwards the Meridian session cookie to whoami; caches identity for 30 seconds | Reuse identity; approval remains a separate BHW decision |
| Admin authorization | `lib/db/require-admin.ts` accepts both `admin` and `editor` roles | Require actual `admin` for approval/sharing administration; do not confuse editor with administrator |
| Public assistant | `app/api/ai/chat/route.ts`, `components/chat/chat-launcher.tsx` | Remove anonymous answering, including cache hits, before any source/provider work |
| Internal assistant | `app/api/ai/assistant/route.ts`, `components/admin/assistant-chat.tsx` | Extract shared orchestration and UI; preserve admin-only curation |
| Routing and tool loop | `lib/ai/route-request.ts`, `agent-loop.ts`, `dataset-tools.ts` | Preserve context; replace fixed four-round wrap-up for complex jobs with checkpointed execution |
| Dataset discovery | `lib/db/dataset-registry.ts` | Keep approved dataset/column descriptions and one common analysis catalogue |
| Analysis tools | `lib/ai/analysis-tools.ts`: peer context, distribution, insight cards, area profile | Reuse dashboard summaries; add a general calculation runtime |
| Row retrieval | `lib/ai/query-dataset.ts`: maximum 100 returned rows | Keep bounded previews; use complete, versioned, paginated extracts for statistics |
| Graph | `lib/ai/traverse-graph.ts`: geographic and lineage traversal | Preserve traceable edges and approved graph review; add project-scoped evidence |
| Message limits | `lib/ai/rate-limit.ts`: 20 public/60 internal messages per ten minutes | Remove these product quotas for approved analysis users |
| Provider limits | `lib/ai/quota.ts`: database-backed minute/day caps and provider pause handling | Separate actual provider allowances from legacy application caps; reconcile with purchased plan before changing values |
| Audits | `lib/ai/audit.ts`, `citations.ts` | Validate typed computed evidence and citations, including rounded/derived numbers |
| Files | `lib/storage/screenshots.ts` uses disk outside releases | Reuse root/path-validation approach; create a separate project-file store |
| Exports | `lib/exports/`, `app/api/export/`; ExcelJS, resvg, PptxGenJS already installed | Reuse renderers; add analysis-specific protected routes; existing public dashboard exports are not private project exports |
| Maps | MapLibre, `components/maps/choropleth-map.tsx`, `point-map.tsx` | Reuse display; build deterministic export rendering from validated geographic results |
| Background work | Existing cron tasks; no general analysis queue found in `lib` or `app/api` inventory | Add durable jobs outside request lifetimes |

### Public removal inventory

Replace embedded chat with access-aware navigation at these page families:

- `app/bhw/page.tsx` and `app/explore/page.tsx`.
- `app/facilities/page.tsx` and `app/facilities/[geoLevel]/[geoCode]/page.tsx` through `AskFacilities`.
- `app/uuc-phc/page.tsx` and `app/uuc-phc/[geoLevel]/[geoCode]/page.tsx` through `AskTheList`.
- UUC PHC `bhw-coverage`, `criteria`, and `indicators`, each with national and geographic pages, plus `data-quality/page.tsx`.

Wrappers: `components/facilities/ask-facilities.tsx`, `components/uuc-phc/ask-the-list.tsx`, and `components/chat/chat-launcher.tsx`. Re-run a reference search during Phase 1 to catch additions since this inventory.

The legacy `/api/ai/chat` handler may remain as an approved-user compatibility adapter, but must never return an anonymous answer. Authorize before exact/near cache lookup, logs containing answers, data queries, or provider invocation. Protect `/api/ai/assistant` consistently; keep regression submission/source-reading routes admin-only until their intended analyst behavior is explicitly implemented. Inspect methodology/help text and links for obsolete descriptions of public chat.

Public pages and their ordinary dataset exports remain governed by their existing access rules. Removing anonymous Ask the Data does not remove the dashboard itself.

## Approval and project authorization design

Add `analysis_users` keyed by Meridian UUID, with approval/revocation timestamps and acting administrator. Do not copy passwords or use a new identity provider. Admins explicitly approve their own analysis access if needed; admin status alone does not bypass the approved-user check.

Use a single server helper returning signed-out, unapproved/revoked, or approved status. API behavior: signed-out returns 401; authenticated but not approved returns 403. HTML pages provide sign-in or access-pending navigation without redirect loops. Fail closed on identity/database failures. Check BHW approval on every request without a stale approval cache; Meridian session invalidation remains subject to its existing 30-second identity cache and must be tested/documented.

Administrators manage approval records only after a server check of `role === "admin"`. Ordinary approval is independent of `admin_users`. An editor cannot approve users. A person may request access under their verified identity; administrators need not enumerate Meridian's users or access Meridian's database.

Project access requires both current approval and owner/member permission. Owners manage sharing and deletion; editors change project content; viewers read and download. A private project is not visible to administrators by default. Approval administration does not confer access to private files or conversations.

Project editors can invoke paid analysis, but only in projects they can edit. Jobs inherit an actor and project, recheck authorization before execution and artifact publication, and stop after approval revocation. Viewer downloads also recheck approval and membership. Do not expose private records through anonymous PostgREST roles; server service-role access must always follow authorization and scope checks.

Suggested routes: `/ask-the-data`, `/ask-the-data/projects/[projectId]`, and `/admin/analysis-users`. Use new `/api/analysis/*` endpoints for projects, messages, jobs, sources, and artifacts. All names are BHW-relative and must use existing base-path conventions.

## Data model and execution contract

Use additive migrations with deny-by-default RLS and server-mediated access. Reuse existing registry, graph, provider, and usage structures where their semantics fit.

| Proposed record | Purpose and key relationships |
| --- | --- |
| `analysis_users` | Meridian user approval and revocation audit |
| `analysis_projects` | Owner, title, revision, deletion state |
| `analysis_members` | Project/user uniqueness and viewer/editor role |
| `analysis_messages` | Project, author, turn sequence, content, run reference; idempotency key |
| `analysis_sources` | Project or curated shared source, kind, version/hash, location, extraction metadata |
| `analysis_files` | Project, original display name, opaque disk key, MIME, bytes, hash; original/derived relationship |
| `analysis_preparations` | Ordered transformation, input/output hashes, rationale, affected-row counts |
| `analysis_runs` | Question, source snapshot, method specification, result, provenance, validation status |
| `analysis_jobs` | Project/actor/run, status, lease expiry, attempt, checkpoint, cancellation, idempotency |
| `analysis_artifacts` | Project/run/result reference, format, file key, rendering metadata |

Store bounded structured run results in JSONB and large extracted datasets/artifacts on private disk. Do not retain huge spreadsheets or full geometry blobs in conversation messages. Migrations introduce the schema; deletion uses authorized runtime operations and cleanup jobs, respecting existing migration rules.

Every result carries: result ID, method/version, input hashes, population/grain, variables/units, filters, join cardinality, n/denominator, missingness, estimates/intervals/tests, diagnostics, warnings, source references, and display precision. A narrative numeric claim points to a result field rather than merely matching any number present in a payload. Record formulas, software versions, random seeds, and model/provider IDs.

A complete-data extraction manifest must include expected and retrieved row counts, stable ordering, source version, and completion status. Statistics must never treat a 100-row preview or a truncated graph traversal as the full population. Prefer a database-side read-only extract/aggregate function or snapshot transaction; repeated PostgREST pages alone cannot guarantee consistency while ingestion changes data.

Concurrent project edits use revisions; stale writes are rejected or merged explicitly. Shared projects expose only their attached sources, not an uploader's other projects. Cloning a project copies permitted evidence into a new project or retains an explicit authorized reference; it cannot silently broaden file access.

## Selected technical direction

These are design selections, not installed or production-tested dependencies. Pin supported versions and licenses when their phase begins.

| Component | Selected direction | Reason and compatibility gate |
| --- | --- | --- |
| Web and orchestration | Existing Next.js/Node tool engine | Avoid a second chat system; preserve CSP and provider adapters |
| Durable queue | PostgreSQL lease queue, claimed atomically through restricted RPCs; separate BHW Node worker service | No Redis/Docker prerequisite; use lease expiry, heartbeat, retry/backoff, checkpoints, and idempotent artifact writes |
| Statistical execution | Python worker environment with pandas, NumPy, SciPy, statsmodels; fixed typed methods initially | Established numerical methods; invoke an absolute executable without a shell and exchange validated JSON/file manifests |
| Excel/CSV | Existing ExcelJS plus Python pandas/openpyxl for calculation datasets | Preserve formulas as formulas/cached values metadata; do not execute macros or silently evaluate missing formula results |
| PDF | PyMuPDF text extraction | Already listed in ingestion requirements; scanned PDFs need an optional OCR capability, reported explicitly |
| Word/PowerPoint | python-docx and python-pptx for `.docx`/`.pptx` | Legacy `.doc`/`.ppt` require a separately deployed converter such as LibreOffice; retain these as required upload support, with conversion a release dependency |
| Geographic files | Python pyogrio/GDAL, pyproj, Shapely for validation/transforms | Windows wheel/runtime compatibility is a required deployment proof; accept GeoJSON and zipped `.shp` with `.shx`/`.dbf`, require known CRS or clarify it |
| Display/export | Existing MapLibre and chart components; server SVG plus existing resvg for PNG | Use the same saved values and geometry for display and export; SVG metadata includes units/source/CRS as appropriate |
| Private storage | Separate `analysis-projects` tree under `BHW_STORAGE_DIR`, outside release junctions | Opaque IDs, atomic writes, DB ownership metadata, authorized streaming downloads; never place uploads in `public/` |
| Search | Provider-neutral search/retrieval adapter; no vendor subscription selected yet | Current providers do not establish a general web-search capability; evaluate a paid vendor against citation quality, terms, costs, and credentials in Phase 3 |
| Later report/deck | PptxGenJS reuse; PDF renderer selected during Phase 6 | Separate rendering from validated analysis; prefer vector outputs and bundled fonts |

Only allowlisted calculation/preparation functions run locally. General model-authored code execution requires a separate isolation design and is not enabled by starting a Python subprocess. Read online evidence through a server fetcher with explicit timeouts and redirect/address checks; do not forward Meridian cookies to external hosts. Strip or reject executable upload content and archive traversal paths; preserve originals in private storage.

### Deployment prerequisites

The application instructions describe Windows Server 2016, Node 24, PostgreSQL 15, and no Docker. No claim is made that a modern Python/GDAL/LibreOffice combination works on that host. Before Phase 3 implementation is declared deployable, the operator must prove supported Python/runtime wheels and converter operation on the actual host, or provide a separate supported worker host. Do not silently install an unsupported runtime or assume ingestion requirements prove production availability.

Prepare service installation/health-check/rollback instructions for a separate worker; configure private storage ACLs, storage capacity, backup and restore of project files alongside the database, worker executable paths, network access, and provider/search credentials. The current backup documentation covers screenshots and database backups, not the new project store. Record backup expiration behavior for deleted projects.

Worker/deploy scripts under `ops/` and `.github/` need the ownership review specified by the working agreement. The operator must configure server-owned settings. These dependencies affect later phases; Phase 1 can implement approved access without a worker. No subscriptions, deployment settings, or server changes were made in Phase 0.

## First release method matrix

| Family | Initial supported methods | Required checks |
| --- | --- | --- |
| Description | Counts, proportions, rates, mean/SD, median/IQR, quantiles, grouped summaries | Grain, denominator, missingness, units, sample versus census; no unrequested automatic imputation |
| Comparisons | Welch independent t-test, paired t-test for explicit pairing, Mann–Whitney, chi-square/Fisher for suitable tables | Independence/pairing, cell counts, ties, distribution assumptions; effect size and interval where defined |
| Association | Pearson and Spearman correlation | Numeric/ordinal type, missing pairs, nonlinearity/outliers; association wording |
| Regression | OLS, binary logistic, Poisson count model with optional exposure offset | Design matrix rank, outcome type, convergence, residual/dispersion/separation diagnostics; reject unsupported design claims |
| Time description | Period summaries, observed change, trend plots | Consistent interval/definition and gaps; forecasting is not an initial method |
| Geography | Validated joins, area summaries, rates, comparisons and choropleths | CRS, boundary vintage, join cardinality, coverage, denominator and classification |

Survey-weighted inference, multilevel/spatial models, survival analysis, forecasting, and causal estimation remain extensions. Retain metadata and explain method unavailability; do not substitute an inappropriate test. Describing a census does not automatically justify sample-based p-values. If counts of individuals, areas, or repeated years create dependence, require a supported design rather than treating all rows as independent.

## WPSAR presentation checklist

Source: [current WPSAR author guidelines](https://ojs.wpro.who.int/ojs/index.php/wpsar/about/submissions), accessed 2026-11-12; use this live guidance rather than historical PDF instructions.

Verified guidance to implement:

- English with UK spelling; article formatting specifies Arial 12, double spacing and one-inch margins.
- Sequential superscript references; WHO/Vancouver-like reference list. Bibliographic references are not footnotes.
- Self-contained figures with titles above, abbreviations explained and lettered notes below; discuss each in text.
- Tables need titles, headed columns, units, explicit missing-value symbols and explanatory notes; avoid shading.
- Maps showing international boundaries must follow WHO map procedures.
- AI use in analysis or figures requires methods disclosure. The journal restricts AI-drafted original prose; automated narrative is not a submission-ready manuscript.

Application defaults: narrative accompanies each substantive analysis; tables/figures are numbered, use consistent rounding and accessible colours, and retain traceable sources. Journal article limits apply only to a selected manuscript profile. Keep human authorship/review and submission declarations outside automatic claims of compliance.

## Synthetic acceptance fixtures

The adjacent [fixture manifest](fixtures/ask-the-data-phase-0.json) contains made-up numerical and geographic inputs with reference expectations. This is acceptance data, not a new calculation implementation. Independently calculated algebraic expectations avoid blessing the implementation's own output.

| Scenario | Required observable behavior |
| --- | --- |
| Access matrix | Anonymous, unapproved, revoked: denied on page/API/cache/download/job paths; approved: allowed; editor: cannot approve |
| Public retirement | Probe every page family above and both chat APIs, including exact/near cache hits; no anonymous answer or provider call |
| Sharing | Owner can share/revoke/delete; editor modifies; viewer cannot submit jobs; unshared project IDs and file paths reveal no evidence |
| Revocation during job | Worker checks approval before source access/publication; private downloads remain denied |
| Numerical reference | Manifest mean, sample SD, comparison statistic, slope and rates match at stated tolerance |
| Unsupported inference | Census summary does not gain an automatic p-value; aggregate-level observations do not support individual causal claims |
| Complete extraction | 250 synthetic rows with final 150 values different from first 100: analysis must use all 250 or report incomplete input |
| File preparation | Mixed date strings, blank cells, duplicates and formulas produce an explicit preparation log; originals remain unchanged |
| Source disagreement | Dashboard 2025 value and online 2024 value retain distinct periods; narrative explains rather than silently overwrites |
| Geographic joins | Two synthetic polygons/codes plus one unmatched observation: match counts and unmapped counts are explicit; missing CRS is not guessed |
| Injection and isolation | File/web text requesting another project's files or tool access cannot change authorization |
| Scientific output | Narrative/table/chart/map/export use the same result IDs and rounded values; titles, units, notes and citations remain readable |
| Recovery/deletion | Restart reclaims expired job leases without duplicate publication; delete removes active files/indexes and records backup expiry |

Future automated tests must turn this matrix into meaningful assertions. Phase 0 does not assert these behaviors already work.

## Phase 1 implementation sequence

1. Add approval records and authorization helper; test the identity/approval/admin-role matrix.
2. Add admin approval UI and approved-user entry page using existing identity; preserve geography/dataset context.
3. Extract shared chat orchestration and authorize both old/new routes before all answer paths.
4. Replace every anonymous launcher, remove message quotas for approved users, and update public help/methodology wording.
5. Run repository checks plus signed-out/unapproved/revoked/approved browser/API cases. Record independent CI/deployment verification.

## Evidence and remaining gates

Read-only inspection covered the files listed above, `package.json`, ingestion requirements, schema baseline, CSP/proxy, existing export and storage code, repository instructions and working agreement. The schema's `fact_bhw_raw` has individual-level demographic fields but no name column in the inspected definition. Treat the owner's no-PII requirement as the analysis catalogue contract; do not expose every internal table merely because the old admin assistant could reach it. Validate dataset grain/dictionaries without copying real records.

Phase 0 produces design and synthetic fixtures only. No application tests, browser flows, production database calls, provider calls, package installations, or deployment were performed. Those are not evidence of implemented functionality. Document links, fixture algebra, and whitespace are checked locally as recorded in the build-plan progress entry.

The checkout has no `origin/main` ref available during this session. Branching from the required base and preparing a PR must wait until that ref is available; no push, merge, or deployment is claimed. The only unresolved product decision remains the monthly operating budget. Worker-host compatibility and search-vendor selection are explicit later technical gates, not reasons to reopen settled product choices.
