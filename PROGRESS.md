# Progress Tracker — Form-Builder SaaS

**Single source of truth for "where are we right now."** Keep the top three sections
(Current Status · Next Session · the G table) current. Full per-session narrative history
lives in [PROGRESS_ARCHIVE.md](PROGRESS_ARCHIVE.md) and in git — do **not** re-read it to resume.

## Standing Rules

1. **Keep this tracker current** — update "Current Status" + "Next Session" as work completes. Never leave the repo in a state the next session has to reverse-engineer.
2. **One shared design system, no exceptions** — every page (builder, dashboard, inbox, settings, public runtime) uses the same app shell + component library + tokens (plan Main Feature #6 / DSR doc #19). Any exception needs a documented rationale.
3. **Fully styled by default** (confirmed 2026-07-05) — every page ships fully styled from shared components; only pure backend/API increments have no styling work. Don't re-ask "styled vs unstyled?" per increment.
4. **End every session** (confirmed 2026-07-10) with (a) a save to the MD files changed this session — this tracker's top sections + one dated line in `PROGRESS_ARCHIVE.md`, plus any `docs/**`/ADR/plan actually touched — and (b) a copy-paste "what to prompt next" line. Keep it lean: update the top of this file + append one archive line; don't rewrite the whole history.

## Current Status

**Phase 2 (Kobo-style rigor + offline) — underway. Phases 0 + 1 COMPLETE.** `main` clean + CI-green. Repo `avincentpatrick/dev_formbuilder_app`. Hosting = self-hosted Windows Server 2016 (ADR-0005). Local demo tenant `http://acme.localhost:8080` (`demo@meridian.test` / `meridian-demo-2026`); bare `localhost:8080` = central host.

- Phase 2 = **Increment G (G1→G11)**; **G1→G6 MERGED**; **G7 (XLSForm) underway — G7a (export) MERGED, G7b (import) is NEXT**. `main` clean + CI-green.
- **Last merged: G7a** — XLSForm export (survey/choices/settings) (PR #37 `49e2b91`). Before it: **G6 (media capture) MERGED** (PR #36 `2b73c52`) — the repo's first `morphTo`; shared polymorphic `attachments` write path end-to-end, both runtimes; `signature` renderer deferred.
- **G7a (as-built)** — new **`app/Services/Xlsform/`**: `XlsformTypeMap` (bidirectional FieldType⇄XLSForm type table — reused by G7b), `GeoWireConverter` (the single lat/lon-flip site, ADR-0006 §3.1, unit-tested both directions), `XlsformWorkbookWriter` (openspout multi-sheet), `XlsformExporter` (reads the id-free `SchemaSnapshotSerializer` snapshot; workbook built in request scope so **no DB in the stream closure**). Lossy §3 expansions handled (page_break→group boundary, likert_matrix→per-row `select_one`, matrix→per-cell, duration→decimal; a `#meridian` breadcrumb column). Two documented deviations (interop-spec §8): cascading→single `select_one` + `level`/`parent` choice columns; structured constraints rendered only where grammar v2.0 can. Export on **both** surfaces: tenant-web builder toolbar button + API-v1 `GET /forms/{form}/versions/{version}/xlsform`.
- Latest gate counts (local, all green): **Pest 721 + Vitest 358**; Pint / vue-tsc / Redocly / `openapi.json` regen all pass; Larastan clean (ran the Xlsform subset locally with `-d memory_limit=3G` — the full local run OOMs, per Gotchas). CI (6 jobs) green on #36 + #37.

## Next Session — Resume Here

**PRIMARY NEXT PROMPT →** `Read PROGRESS.md and build Increment G7b` — the XLSForm **import** half. Targets an existing form's current **DRAFT** (never creates a form), reusing the `RestoreService` destructive-replace pattern (lock form → delete validations/fields/sections → repopulate with **EXPLICIT keys** via raw `FormField::create` + key→id FK remap, the `SchemaTreeCloner` shape). **Parse+validate FULLY UPFRONT** (missing `survey` sheet / unmapped `type` → throw *before* the destructive txn; error code `xlsform_unsupported_field_type`, `details:{row,type}`). **Reuse the G7a foundation**: `XlsformTypeMap::toFieldType`/`isImportable` + `GeoWireConverter::wireToGeoJson` (both already built + unit-tested). New in G7b: `XlsformWorkbookReader` (openspout reader — pattern proven in `tests/Feature/Submissions/SubmissionExportTest.php`), `ImportPlan`/spec DTOs, `XlsformImportParser` (pure, DB-free), `XlsformImporter` (orchestrator), `XlsformImportException`, `XlsformImportRequest` (`file` `mimes:xlsx` `max:5120`), tenant-web `POST /forms/{form}/draft/xlsform-import` + API-v1 `POST /api/v1/forms/{form}/draft/xlsform-import`, and the builder **Import** modal (confirm-destructive-replace + upload + warnings banner). **Keystone test:** export→import→publish round-trip asserting by key (assert on parsed values, NOT the checksum — float caveat). Full spec: the plan file's "PR 2 — G7b" section.

Plan (G7 approach + full G7b spec): `C:\Users\DOH\.claude\plans\read-progress-md-and-build-quizzical-lamport.md`. Interop spec: `docs/xlsform-interop-spec.md` (**§8 = G7a as-built notes**). G-map: `C:\Users\DOH\.claude\plans\read-progress-md-and-start-shimmying-yeti.md`.

**G7b gotchas (from the plan):** sanitize XLSForm `name`→key (`^[a-z][a-z0-9_]*$`, de-dupe per version, warn on rewrite); `constraint` always stored as an **expression** row (never decomposed); import never yields `is_required=conditional` and never auto-infers `yes_no`; `matrix`/`likert_matrix` NOT importable (downgrade + warning); expression-valued `repeat_count`→unbounded (`max_instances=NULL`) + warning; unsupported XLSForm functions surface at **publish** (existing `ExpressionValidationGate`), not at import.

**Env notes:** local Postgres runs on `postgis/postgis:17-3.5` (first-time pull needs `docker compose down -v && docker compose up -d` for the PostGIS init SQL). **`gh` CLI is NOT pre-authed in the shell** — read its token from the git credential helper: `export GH_TOKEN="$(printf 'protocol=https\nhost=github.com\n\n' | git credential fill 2>/dev/null | sed -n 's/^password=//p')"` before any `gh` command.

**Alternative tracks:** later G steps (G8 PWA/offline · G9 templates · G10 `morphTo` scoping · G11 personalization); Track B — stand up the Windows Server host (`docs/deployment-infrastructure.md` §8).

## Roadmap Phases (from the approved plan)

| Phase | Contents | Status |
|---|---|---|
| **Phase 0 — Foundations** | Repo/infra, Docker Compose, multi-tenancy+RLS, tenant-scoped auth/RBAC, CI, OpenAPI scaffold, `form_versions` draft/publish, shared design system, build-vs-buy spike → ADR | ✅ **COMPLETE** (PRs #1–#17) |
| **Phase 1 — MVP** ("Kobo-lite + Fillout-lite") | Core builder, sections, validation, skip-logic, publish/versioning, manual encoding, guest responses, submission inbox+export, dashboard, WCAG AA runtime | ✅ **COMPLETE** — Increment F (F1→F7), PRs #18–#26 |
| **Phase 2 — Kobo-style rigor + offline** | Repeat groups, matrix/grid, Likert, cascading select, geo (PostGIS), media capture, full expression engine, XLSForm import/export, PWA/offline, field library/templates | 🚧 **In progress** — Increment G (G1→G11); G1→G6 MERGED, G7 (XLSForm) underway — G7a (export) MERGED, G7b (import) next |
| **Phase 3 — Fillout-style polish + OCR** | Visual workflow builder, piping, save-and-resume, payments, integrations, custom domains, analytics, OCR | Not started |
| **Phase 4 — Scale & enterprise** | SSO/SAML, dedicated-DB tenancy, data residency, GDPR tooling, CRDT sync | Not started |

## Phase 2 — Increment G decomposition (G1→G11)

Plan (full G-map + entry-point rationale): `C:\Users\DOH\.claude\plans\read-progress-md-and-start-shimmying-yeti.md`. Each Gn = one mergeable PR with the standard CI gates. Grammar is FROZEN at **v2.0** (bumped in G3). Detail for any merged Gn is in `PROGRESS_ARCHIVE.md`.

- **G1** — Repeat groups: pipeline + engine core (backend) ✅ MERGED (PR #27 `4656283`)
- **G2** — Repeat groups: runtime + manual-encode UI ✅ MERGED (PR #28 `21e3326`)
- **G3** — Full expression engine + calculated fields (grammar v2.0) ✅ MERGED (PR #29 `846071f`)
- **G4** — matrix / Likert (scale+matrix) / cascading select ✅ COMPLETE (G4a PR #30 `409898a` + G4b PR #31 `59ca345`)
- **G5** — Geo (geopoint / geotrace / geoshape) → decomposed G5a/G5b1/G5b2 ✅ **COMPLETE**:
  - **G5a** — geo-storage spike → ADR-0006 (hybrid GeoJSON-in-JSONB + persist-time GiST PostGIS `geometry(4326)` + Leaflet/OSM capture; Risk R11 resolved) ✅ MERGED (PR #32 `d2a3ff7`, docs-only)
  - **G5b1** — backend + PostGIS + engine (PostGIS image swap, `submission_geo_index` GiST projection in the persist txn, doubly-mirrored `coerceGeo`/`processGeo`, geo `supported=false`, no grammar bump) ✅ MERGED (PR #33 `3c4ae07`)
  - **G5b2a** — geo capture renderer + Leaflet + scoped CSP + wiring (lazy code-split `GeoInput.vue`, img-src-only OSM CSP, lockstep wiring → geo `supported=true`) ✅ MERGED (PR #34 `53cdffe`)
  - **G5b2b** — builder geo config editor + "Map" tab (`FieldType::configEditor()` geo arm + `GeoEditor.vue` + `ConfigPanel` Map tab + lenient `UpdateFieldRequest::configRules()` isGeo arm + `Geo Builder Demo` draft + builder-axe Map-tab walk; no engine/grammar/migration change, no OpenAPI drift) ✅ MERGED (PR #35 `787f3c7`)
- **G6** — Media capture: image / audio / video / file — the shared polymorphic `attachments` write path (upload + storage + RLS + retrieval), pipeline linking (`coerceMedia`/`processMedia` PHP↔TS + `AttachmentReferenceValidator` + owner-re-point + `attachment_refs`), `MediaInput.vue` renderer (both runtimes) + `MediaEditor.vue` builder tab. Whole increment in one PR (user-chosen). ✅ **MERGED** (PR #36 `2b73c52`). `signature` renderer deferred (backend `kind=signature_capture` ready).
- **G7** — Bidirectional XLSForm import/export (depends on repeat/cascading/expression) → split **G7a / G7b** (user-chosen), both surfaces web + API-v1:
  - **G7a** — export (survey/choices/settings): the shared `app/Services/Xlsform/` foundation (`XlsformTypeMap` bidirectional, `GeoWireConverter` lat/lon flip, `XlsformWorkbookWriter`, `XlsformExporter`) + web/API export routes + builder toolbar button; interop-spec §8 as-built (cascading single-question + structured-constraint scope). ✅ **MERGED** (PR #37 `49e2b91`)
  - **G7b** — import (draft-replace, upfront-validating): `XlsformWorkbookReader` + `XlsformImportParser` (pure) + `XlsformImporter` (orchestrator) + `XlsformImportException` + upload request/routes + builder Import modal; reuses G7a's `toFieldType`/`wireToGeoJson`. Keystone = export→import→publish round-trip. **← NEXT**
- **G8** — Installable PWA + offline sync (service worker + IndexedDB/Dexie + Background Sync + 409 UX; Risk R4; biggest lift)
- **G9** — Question library + form templates (schema-complete; UI + instantiate/clone + RLS carve-out)
- **G10** — Generalized `morphTo` resource-scoping (largely independent)
- **G11** — Richer personalization — accent / text-size / dyslexia (columns already exist)

## What's done (compact ledger)

- **Phase 0** — walking skeleton + A (tenancy+RLS) + B1/B2a/B2b/B2c (auth/RBAC/membership/super-admin) + C1/C2/C3 (design system → app shell → data/admin/settings + Playwright gate) + Icony refresh (PR #9) + D1→D4b (`form_versions` + full interactive builder) + E (OpenAPI 3.1 + `/api/v1` Sanctum-token surface) + ADR-0004 (build custom engine). PRs #1–#17.
- **Phase 1** — Increment F: F1 submissions model+RLS · F2 PHP expression evaluator · F3 semantic-validation core · F4a/F4b submission pipeline + manual-encode UI · F5 guest runtime backend · F6a/F6b TS engine mirror + guest SPA · F7 submission inbox + review + streamed CSV/XLSX export. PRs #18–#26.

## Verification loop (per increment)

`docker compose up -d` → `docker compose exec app php artisan migrate:fresh --force` → `... php artisan test` (real Postgres) → `composer run quality` + `npm run type-check` on the host → push branch → `gh pr create` → CI green (6 jobs) → `gh pr merge --squash`. `gh` CLI authed via `GH_TOKEN`. Local Docker runs ~3× slower than CI (WSL2 volume I/O), not a code issue.

## Gotchas / standing lessons

- **phpstan local OOM** — `composer run quality`'s phpstan OOMs its parallel workers at 128M locally → phantom "undefined property"/relation-nullability errors. CI's Larastan (`disableMigrationScan: false`) scans `database/migrations`, resolves every column → 0 errors → green is the source of truth. For possibly-null relation chains prefer `data_get($m, 'rel.attr')` over `?->`.
- **PHP 8.3** removed `${var}` string interpolation → single-quote grammar snippets in tests (don't let `${...}` interpolate).
- **Windows npm** — a nested design-system esbuild-bin file-lock breaks `npm install <pkg>` → use `--no-bin-links` locally (CI's Linux `npm ci` relinks fine).
- **Known-flaky** `GuestRuntimeTest › it 401s a tampered token` — flipping the LAST base64 char can alias to the same signature bytes → flip the FIRST char (or assert over several tampers).
