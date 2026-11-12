# Unified Ask the Data phased build plan

**Status:** Phase 0 merged in PR #509. Phase 1 is implemented and locally verified on 2026-11-12; PR #510 is under CI review and is not deployed. See [Phase 0 technical design](ASK_THE_DATA_PHASE_0_DESIGN.md) for the component inventory, architecture, method matrix, WPSAR checklist, fixtures, and deployment gates.
**Owner decisions recorded:** 2026-11-07.
**Application:** BHW Connect, under `apps/bhw-connect/`.

Build one private analysis workspace combining the conversational Ask the Data experience with the admin assistant's dataset, document, and knowledge-graph capabilities. It should support broad conversational AI work with dashboard context, automatic online research, file attachments, reproducible calculations, and scientifically presented results.

## How to start or resume this plan

Send this instruction when ready:

> Read apps/bhw-connect/AGENTS.md and apps/bhw-connect/docs/ASK_THE_DATA_BUILD_PLAN.md. Start the first incomplete phase of this plan. Implement and validate that phase, update its progress and evidence, and prepare it for review under the repository's working agreement. Do not start a later phase unless I request it. Ask only necessary questions, one at a time.

To target a phase, say: **“Build Phase N of the Ask the Data plan.”** Check its dependencies first and report any missing prerequisite. Do not assume that a checked box proves the deployed feature works: read the recorded evidence and inspect current code.

This file authorizes no implementation by itself. Once invoked, settled product decisions below should not be asked again. Work in small reviewable increments within the requested phase. Follow current repository instructions for branches, checks, review, deployment, and shared-file ownership.

## Confirmed product decisions

| Area | Decision |
| --- | --- |
| Access | Only approved dashboard users. Existing Meridian accounts provide identity; dashboard administrators approve and revoke analysis access through an admin screen. Analysis access does not grant administrative privileges. |
| Remove public Ask the Data | The existing public, no-sign-in-required Ask the Data will be removed in Phase 1. There will be no anonymous chat, limited public version, demo answering mode, or anonymous cached-answer access. All Ask the Data use requires both sign-in and dashboard approval. |
| Dashboard datasets | Every approved user has the same dataset access. The owner states these datasets will never contain personally identifiable data. Do not introduce per-user dataset permissions. |
| Private work | Attachments and projects are private by default, independent of the shared dashboard dataset catalogue. |
| Sharing | Owners can share projects with other approved users as viewers or editors. No public sharing links. |
| Persistence | Automatically save conversations, calculations, attachments, and outputs as projects. Keep them until their owner deletes them. |
| Scope | Broad conversational AI capabilities with structure and dashboard context; remove narrow application topic and output restrictions. Do not promise capability beyond the selected providers and available tools. |
| Limits | Remove application-level user/message quotas. Retain authentication, permissions, source verification, statistical validation, and operational resource controls. External provider limits still apply. |
| Online research | Search automatically when useful; cite sources and distinguish dashboard, uploaded, and online evidence. |
| Conflicting evidence | Flag conflicting figures and explain source selection using relevance, authority, and reporting period/date. |
| Autonomy | Proceed automatically and ask only when missing information or ambiguity could materially affect findings. Ask necessary questions one at a time. |
| Analysis priorities | Statistical analysis, maps, and geographic analysis first; reports and presentations later. |
| Statistics | Descriptive and inferential methods, including tests and regression where supported by the data. Explain assumptions, uncertainty, and limitations. |
| Narrative | Results include descriptions, interpretation, and limitations alongside figures, tables, graphs, and maps. A standalone visual is not the default completed analysis. |
| Scientific style | English only. Use current WHO Western Pacific Surveillance and Response (WPSAR) guidance as the scientific writing and presentation reference. |
| Attachments | CSV, Excel, PDF, Word, PowerPoint, GeoJSON, and zipped shapefiles. |
| Preparation | Inspect data quality and prepare files automatically; preserve originals and record transformations. Clarify ambiguous choices that could change results. |
| Initial exports | Excel/CSV tables and high-resolution PNG/SVG graphs and maps. |
| Later exports | PDF reports and editable PowerPoint presentations. |
| Paid services | Paid AI and online-search services are allowed. Monthly budget remains undecided; track usage and costs without inventing a hard budget or per-user quota. |

## Current foundation and implementation constraints

These are planning observations from the checkout inspected on 2026-11-07, not verification of the live deployment. Recheck before implementation.

- Public chat: `app/api/ai/chat/route.ts` and `components/chat/chat-launcher.tsx`; dataset scoping, answer-bank caching, numeric auditing, and message limits.
- Admin assistant: `app/api/ai/assistant/route.ts`, `app/admin/(dashboard)/assistant/page.tsx`, and `components/admin/assistant-chat.tsx`; admin-only access, broader registry tools, citations, routing, follow-ups, and figures.
- Shared execution: `lib/ai/agent-loop.ts`, `lib/ai/dataset-tools.ts`, provider adapters, and existing analysis tools. Reuse and extend these before creating a parallel engine.
- Knowledge graph: `lib/ai/traverse-graph.ts`; geographic containment and source/relationship lineage, with existing admin review workflows.
- Presentation: `lib/ai/figure-from-payload.ts`, `components/admin/assistant-figure.tsx`, and `components/present/` provide foundations, not proof of the requested export capabilities.
- Identity: `lib/db/meridian-identity.ts` and `lib/db/require-admin.ts`. Add an approved-analysis-user boundary rather than granting every Meridian account access or treating every analyst as an admin.
- BHW currently runs on Windows Server, Node, PostgreSQL, and PostgREST. Historical `supabase` names in the code do not imply hosted Supabase. Do not design around Vercel or assume Docker exists on the production host.
- Preserve the base-path handling, nonce Content Security Policy, application separation, and additive migration rules documented in `AGENTS.md` and the working agreement.
- Historical `AI_ASSISTANT_PLAN.md` and `ASK_CACHE_PLAN.md` explain existing behavior. This plan supersedes their product choices only where the decisions above explicitly differ. Current runtime instructions take precedence over their old deployment descriptions.

## Intended system design

Use one conversation and analysis engine behind the approved-user boundary. Route requests through source discovery, contextual retrieval, calculation, validation, narrative composition, and artifact rendering. Simple questions may skip unnecessary steps.

Separate four concerns:

1. **Identity and project access:** approval, revocation, ownership, and sharing checked on the server for every read/write/download/job request.
2. **Evidence and context:** shared dashboard catalogue and curated graph, plus project-scoped attachments and retrieved online evidence.
3. **Execution:** typed tools and a reproducible calculation runtime. The model chooses and explains methods; executable calculations supply numerical results.
4. **Output:** one structured result feeds narrative, tables, charts, maps, and later reports/slides, preventing divergent numbers across formats.

Proposed additive records include analysis approvals, projects, memberships, conversation messages, source versions, attachments, preparation steps, runs/jobs, calculation results, artifacts, and usage events. Final names and schema belong to Phase 1 design; do not duplicate equivalent existing structures.

Each analysis run should record input versions/hashes, filters, joins, exclusions, method and parameters, executable calculation/tool trace, runtime/library versions, random seed when applicable, results, diagnostics, citations, and generated artifacts. Repeating a calculation should reproduce results within documented numeric tolerances; do not promise byte-identical AI prose or live web pages.

Do not feed private project content into the public answer cache, public ask log, globally searchable graph, or another project's retrieval context. Any reusable cache must respect identity/project access, source versions, and analysis configuration.

## Phase 0 — Verify foundations and settle the execution design

**Depends on:** nothing. **Deliverable:** a code-grounded implementation design and acceptance fixtures.

- Inventory all public Ask the Data launchers, APIs, cached-answer paths, admin tools, limits, source retrieval, and exports.
- Map current identity and administrator/editor roles. Specify approval, revocation, ownership, viewer, and editor behavior without modifying Meridian's identity system.
- Inspect data dictionaries and dataset grain. The owner's no-PII requirement is the product contract; legacy raw-table references must not automatically determine the new analysis catalogue.
- Review current official WPSAR author guidance; record its URL and access date and translate applicable requirements into a presentation checklist. Distinguish verified journal requirements from application defaults. Do not claim journal compliance from styling alone.
- Choose the statistical runtime, document parsers, map renderer, private file storage, and background-job mechanism based on the actual Windows deployment. Explain new server prerequisites and shared-path changes.
- Define a representative supported-method matrix for the initial statistical release and exact file extensions for the agreed upload families. Treat forecasting and advanced spatial models as extensions unless explicitly included in that matrix.
- Create synthetic reference scenarios for comparison, inference, geographic joins, uploads, conflicting sources, and project isolation. Real data and secrets never enter the public repository.

**Acceptance:** documented component map, dependencies, WPSAR checklist, method matrix, test scenarios, and deployment prerequisites. Budget may remain unresolved; do not block ordinary design work on it. Ask only when a concrete infrastructure or service choice requires an owner decision.

## Phase 1 — Approved-user access and one Ask the Data workspace

**Depends on:** Phase 0. **Deliverable:** a private unified conversational entry point.

- Add administrator-managed approval and revocation backed by existing Meridian identity. Restrict approval management to actual administrators, respecting current role distinctions.
- Create the main Ask the Data page and reuse a shared engine for the existing admin and basic chat capabilities. Keep graph curation and other administration separate.
- Remove the public, no-sign-in-required Ask the Data experience from every dashboard surface, including embedded chat panels and launchers. A public navigation link may lead to sign-in, but must not open an anonymous chat or return answers before sign-in and dashboard approval.
- Retire anonymous access to every legacy Ask the Data endpoint, including `app/api/ai/chat/route.ts`. Any retained route must enforce the same approved-user check as the unified workspace before retrieving cached answers, querying data, or invoking a provider. Hiding a button is insufficient; no public fallback or demo answering mode may remain.
- Preserve dataset/geographic context when moving from a dashboard page into Ask the Data.
- Remove user quotas and narrow topic/output refusals from this experience. Separate provider capacity handling and operational bounds from product quotas; audit limits rather than deleting every timeout, row cap, or loop bound indiscriminately.
- Support natural follow-ups and English responses. Explain unavailable tools or evidence without fabricating an answer.
- Ensure this change does not unintentionally privatize unrelated dashboard pages. Inventory other public AI features separately from Ask the Data.

**Acceptance:** anonymous, unapproved, and revoked users cannot invoke new or legacy analysis routes. Approved users get the same dashboard catalogue; analysts cannot administer access. Existing simple questions and geographic follow-ups work through the unified engine. No private result enters the old public cache/log.

**Required public-removal checks:** signed-out browser visits to every former Ask the Data surface show no working chat; direct requests to old and new analysis APIs return no answers, including exact-match and near-match cached answers; signed-in but unapproved users are also denied. Verify an approved user can still use the unified workspace. Phase 1 cannot be marked complete or released with an anonymous Ask the Data path remaining.

## Phase 2 — Saved projects, sharing, and durable jobs

**Depends on:** Phase 1. **Deliverable:** persistent private analysis workspaces.

- Autosave conversations and analysis state as projects, with clear titles and reopen/resume behavior.
- Implement owner, viewer, and editor permissions. Proposed default: owners manage sharing/deletion; editors modify project content; viewers read and download allowed artifacts.
- Share only with approved users. Revocation removes access even where a project membership remains recorded.
- Add durable jobs for lengthy analysis and file processing: progress, cancellation, retry, restart recovery, and duplicate-submission protection.
- Store artifacts privately and authorize downloads server-side. Prevent cross-project access through guessed IDs, storage paths, search results, or cached responses.
- Retain projects until owner deletion. Implement deletion of source files, derived artifacts, and indexes; document backup expiry and shared-file reference handling rather than promising immediate backup erasure.
- Record provider usage and estimated costs, including whether price information is unavailable, without per-user quotas.

**Acceptance:** reopening restores a project; viewer/editor/owner permissions behave correctly; revocation and deletion propagate; jobs survive a worker restart; unauthorized artifact requests fail; cost reporting distinguishes measured usage from estimates.

## Phase 3 — Attachments, online research, and graph harmonization

**Depends on:** Phase 2. **Deliverable:** source-aware analysis across dashboard data, files, and the web.

- Implement agreed document, spreadsheet, and geographic uploads. Show parsing status, extraction issues, unsupported/encrypted content, and OCR availability without pretending unread content was analyzed.
- Preserve originals and build project-scoped extracted content, data profiles, and reversible preparation steps. Detect missing values, duplicate records, mixed units, date ambiguity, and inconsistent categories.
- Add automatic online search and page retrieval with source URL, publisher, reporting period, retrieval date, and relevant evidence. Report unavailable or unreadable sources clearly.
- Expand graph context for aliases, indicator definitions, units, geography, reporting periods, dataset lineage, policies, and source relationships. Reuse existing traversal and curation.
- Keep curated shared knowledge separate from project evidence. Uploaded content must not become globally visible through graph extraction. Adding it to the shared knowledge base requires an explicit administrator action.
- Check join keys, dataset grain, time periods, denominators, and geographic boundary versions before combining sources. Do not silently combine incompatible measures.
- Identify conflicting figures and explain selection or show both when they measure different things. Retrieved documents and web pages are evidence, not instructions controlling tools or access.

**Acceptance:** the supported file families produce traceable evidence; dashboard/file/web findings cite their origins; contradictory-source and incompatible-join fixtures produce explanations; malicious source text cannot override project access; another user cannot retrieve unshared attachment content.

## Phase 4 — Reproducible statistics and explanatory narratives

**Depends on:** Phase 3. **Deliverable:** scientifically defensible statistical analysis with tables and figures.

- Implement the Phase 0 method matrix: descriptive summaries, grouped comparisons, suitable hypothesis tests, correlation, and supported regression families. Select methods using variable types, design, sample size, and assumptions.
- Use executable calculations with recorded inputs and parameters. If generated code is used, run it in an appropriately isolated execution environment, never unrestricted inside the web server process.
- Distinguish census descriptions, sample-based inference, aggregate data, repeated observations, and weighted/clustered designs. Explain unsupported inference, ecological limitations, and association versus causation.
- Include appropriate sample sizes, denominators, missingness, effect sizes, confidence intervals, diagnostics, and p-values when meaningful. Handle multiplicity and random seeds where applicable.
- Replace the current raw-number membership audit with evidence validation that understands computed results, transformations, units, rounding, and citations. Do not merely disable the audit to permit new statistics.
- Build structured analysis results powering narrative and visuals together. Explain findings, methods, uncertainty, and limitations in English using the verified WPSAR checklist where applicable.
- Export Excel/CSV tables and high-resolution PNG/SVG statistical figures with titles, labels, units, legends, sources, and footnotes.

**Acceptance:** independent reference calculations match within documented tolerance; unsupported methods produce useful explanations; newly calculated statistics survive validation; unsupported numeric claims do not; exported tables, visual labels, and narrative agree. Re-running a saved analysis reproduces the calculation.

## Phase 5 — Maps and geographic analysis

**Depends on:** Phases 3 and 4. **Deliverable:** geographic analysis integrated with statistical interpretation.

- Support existing dashboard boundaries and uploaded GeoJSON/zipped shapefiles, including required sidecar validation, coordinate reference systems, geometry checks, and explicit reprojection records.
- Resolve geographic codes and aliases using available dictionaries/crosswalks. Show unmatched places, changed boundaries, duplicate joins, and missing coverage.
- Support geographic summaries and comparisons, choropleths, and other suitable map types. Distinguish counts from population-adjusted rates and expose classification choices.
- Render consistent titles, legends, units, geography/date coverage, boundary attribution, source notes, and missing-data styling. Include scale/projection information where meaningful for the map type.
- Explain geographic patterns in accompanying narrative without inferring individual behavior from area-level aggregates or claiming spatial significance without an appropriate test.
- Export high-resolution PNG/SVG maps from the same validated data used by tables and narrative.

**Acceptance:** known geographic fixtures join correctly; mismatches are visible; incompatible boundaries are not silently merged; exported maps retain required labels and attribution; narrative, map values, and tables agree.

**First-release gate:** Phases 0–5 complete. Statistics, maps, narratives, uploads, web research, private projects, sharing, and initial exports work end to end. Reports and presentations remain later work.

## Phase 6 — Reports and editable presentations

**Depends on:** first-release gate. **Deliverable:** complete reports and slide decks derived from saved analyses.

- Generate structured reports with an appropriate question/objective, methods, findings, discussion/interpretation, limitations, and references, adapting structure to the request and WPSAR guidance.
- Generate PDF reports and editable PowerPoint decks. Use editable text and tables, and editable charts where supported; disclose map/figure elements that remain embedded vector or raster artwork.
- Carry source citations, figure/table numbering, footnotes, and methodological notes into documents and slides. Include detailed notes where a slide cannot accommodate them legibly.
- Regenerate reports/decks from saved analysis versions without rerunning statistical calculations unless inputs or methods changed.
- Validate page/slide layout, clipping, legibility, pagination, reference links, and consistency with the source analysis.

**Acceptance:** exported report and presentation open correctly; their numbers match the saved run; text and tables are editable in the deck; citations and scientific notes remain visible/readable; figures have publication-appropriate resolution.

## Verification and release requirements for every phase

- Run current required repository checks and meaningful tests for the changes. Record exact commands, results, skipped checks, and reasons; do not mark a phase complete based on implementation alone.
- Test user-visible workflows in the browser, including the `/bhw-connect` prefix and narrow-screen usability.
- Use synthetic fixtures for scientific correctness, access isolation, file processing, and regression scenarios. Independently verify statistical results rather than testing only that the implementation repeats itself.
- Keep migrations additive and changes inside BHW-owned paths. Identify shared deployment/worker/configuration changes early and follow the working agreement for their approval.
- Preserve working public dashboards. Roll back through the supported release mechanism without destroying saved projects or source evidence.
- Distinguish implemented, locally verified, CI verified, and deployed/verified states. Document operator prerequisites before claiming a feature is ready on the Windows host.
- Update this plan and relevant BHW decision records with completed scope, evidence, remaining issues, and the next increment. Do not rewrite historical plans as though their old implementation never existed.

## Open items and decision policy

The monthly paid-service budget is explicitly undecided. Provider selection, runtime packages, concurrency, upload sizes, storage capacity, OCR support, and job infrastructure need code/deployment investigation. These are implementation choices unless they create a material cost, infrastructure dependency, or change to the agreed product scope.

Do not introduce a new hard spending cap or user quota by assumption. Provider limits and finite server capacity must be communicated honestly. Operational controls should queue, paginate, retry, or offer resumable work where possible instead of arbitrarily narrowing analytical capability.

Ask the owner necessary questions one at a time. Do not reopen confirmed choices or treat this plan as permission to purchase subscriptions or change shared infrastructure outside the repository's authorization rules.

## Progress tracker

| Phase | Status | Implementation and verification evidence |
| --- | --- | --- |
| 0 — Foundations and design | Design complete, 2026-11-12 | [Technical design](ASK_THE_DATA_PHASE_0_DESIGN.md) and [synthetic acceptance fixtures](fixtures/ask-the-data-phase-0.json). Code/source inspection complete; runtime and deployment unverified. |
| 1 — Approved access and unified workspace | Locally verified; PR #510 | Separate approval/admin controls, shared protected chat APIs, public-chat removal, source viewer and context-aware navigation. See the Phase 1 entry below. |
| 2 — Projects, sharing, and jobs | Not started | — |
| 3 — Sources, files, and graph | Not started | — |
| 4 — Statistics and narrative | Not started | — |
| 5 — Geographic analysis | Not started | — |
| 6 — Reports and presentations | Not started | — |

For each completed increment, append the date, changed scope, PR/commit if available, checks and outcomes, deployment status, and unresolved items. Leave phases not actually verified incomplete.

### 2026-11-12 — Phase 0 design handoff

Recorded the code inventory and public-chat removal locations, separate approval/project permissions, additive data model, complete-data extraction and result provenance, proposed Windows-compatible worker architecture with explicit compatibility gates, initial statistical methods, current WPSAR presentation guidance, and synthetic acceptance scenarios. Baseline inspected: `b41d1766`. Only documentation and synthetic fixture files changed. Next authorized build phase would be Phase 1; it has not started.

Local verification: JSON parsing and independent standard-library arithmetic checks for the fixture expectations; local Markdown file links checked; whitespace checks passed. No application/browser/CI tests or deployment run for this documentation-only phase. No `origin/main` ref is present in the checkout, so no compliant branch/PR or deployment is claimed. Later gates: prove worker/converter compatibility on the actual host or a supported worker host; select a search provider; monthly budget remains undecided.

### 2026-11-12 — Phase 1 implementation and verification

Built separate administrator-managed analysis approvals, the private `/ask-the-data` workspace, protected document citation viewing, shared execution for the new and both legacy chat endpoints, and navigation replacing anonymous chat. Removed per-user message quotas, retained evidence checks/provider capacity handling, and updated help text and breadcrumbs. No Phase 2 work started.

Verification so far: lint/typecheck passed; the full suite against synthetic PostgreSQL passed with 1,744 tests and 11 skips, followed by the added approval-table RLS/grant test passing independently. Focused authorization, route, source and breadcrumb regression checks passed (93 tests). Additive migration lint passed. Production build and browser results, CI/PR and deployment status will be recorded before marking this phase complete. The first no-database runtime browser attempt correctly failed because that build-only mode cannot run a production server; verification moved to the synthetic data stack without changing the runtime guard.

Local release checks completed: production builds passed, including a build with local connection settings and the build-only no-source mode; a running production server then used synthetic PostgreSQL/PostgREST. Browser checks passed for anonymous launcher/API denial and source protection, approved analyst UI/place context, pending-user denial, editor approval-page denial, administrator revoke/reapprove actions, and immediate API denial after revocation. Identity was supplied by a local synthetic whoami fixture; no live account or AI-provider execution was tested. The browser test initially raced a repeated success message; it was corrected to wait for the actual approval action response.

CI initially caught that the new database-permission test ran before application migrations. The test now executes the actual migration in an isolated transactional schema and rolls it back, including checks against permissive default privileges. The corrected database test passed locally. Required CI must be green before merge; this entry makes no deployment claim. Code review and operator verification remain the release gates.
