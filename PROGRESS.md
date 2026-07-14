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

- Phase 2 = **Increment G (G1→G11)**; **G1→G5 MERGED**; **G6 (media capture) BUILT + locally verified**, pending PR.
- **Last merged: G5b2b** — builder geo config editor + "Map" tab (PR #35 `787f3c7`); **G5 (geo) COMPLETE**.
- **In-flight: G6 (media capture)** on branch **`feat/g6-media-capture`** — image/audio/video/file end-to-end: new `attachments` table + `Attachment` model (the repo's first `morphTo`) + `AttachmentKind`/`ScanStatus` enums; upload/retrieval endpoints (auth + guest) with content-MIME-sniff + size/type gate + server-generated `tenants/{id}/{kind}/…` keys; `ScanAttachmentJob` (first `app/Jobs/`); pipeline owner-re-point + `attachment_refs`; Stage-1 `coerceMedia` + Stage-3 `processMedia` (PHP↔TS lockstep) + Stage-3.5 `AttachmentReferenceValidator`; `MediaInput.vue` renderer (both runtimes) + `MediaEditor.vue` builder "Media" tab; `media-src`/Permissions-Policy CSP. **Not merged yet** — push → PR → CI (6 jobs) → merge pending.
- Latest gate counts (local, all green): **Pest 700 + Vitest 358**; migration-lint / controller-gate / Pint / vue-tsc / vite build all pass; `openapi.json` regenerated. (Larastan + DS-axe + Playwright run in CI — the local Larastan OOMs, per Gotchas.)
- **Signature renderer deferred** (canvas control; backend `kind=signature_capture` ready) — the only media type left `unsupported`.

## Next Session — Resume Here

**FIRST → land G6:** push `feat/g6-media-capture` → `gh pr create` → CI green (6 jobs) → `gh pr merge --squash`. G6 is built + locally verified (Pest 700 / Vitest 358 green); the working tree also has `PROGRESS.md`/`PROGRESS_ARCHIVE.md`/`docs/data-dictionary.md` doc updates + a regenerated `openapi.json`.

**THEN, PRIMARY NEXT PROMPT →** `Read PROGRESS.md and build Increment G7` — bidirectional XLSForm import/export (depends on repeat/cascading/expression, all shipped): map `form_versions.schema_snapshot` ⇄ the XLSForm `survey`/`choices`/`settings` sheets against the written interop spec.

Plan for G6 (as-built): `C:\Users\DOH\.claude\plans\read-progress-md-and-build-noble-teacup.md`. G-map: `C:\Users\DOH\.claude\plans\read-progress-md-and-start-shimmying-yeti.md`.

**G6 follow-ups (fast, non-breaking):** the `signature` canvas renderer; audio/video `duration_seconds` extraction (ffprobe/getID3); real ClamAV (Phase-1 = `skipped`); orphan-cleanup job for staged-but-unsubmitted attachments; a desktop in-page getUserMedia/MediaRecorder recorder (mobile capture already works via the native `<input capture>`).

**Env note:** local Postgres runs on `postgis/postgis:17-3.5`; a first-time pull needs `docker compose down -v && docker compose up -d` to load the PostGIS init SQL.

**Alternative tracks:** Track B — stand up the Windows Server host (`docs/deployment-infrastructure.md` §8); or a later G step (G7 XLSForm, G8 PWA/offline, G9 templates, …).

## Roadmap Phases (from the approved plan)

| Phase | Contents | Status |
|---|---|---|
| **Phase 0 — Foundations** | Repo/infra, Docker Compose, multi-tenancy+RLS, tenant-scoped auth/RBAC, CI, OpenAPI scaffold, `form_versions` draft/publish, shared design system, build-vs-buy spike → ADR | ✅ **COMPLETE** (PRs #1–#17) |
| **Phase 1 — MVP** ("Kobo-lite + Fillout-lite") | Core builder, sections, validation, skip-logic, publish/versioning, manual encoding, guest responses, submission inbox+export, dashboard, WCAG AA runtime | ✅ **COMPLETE** — Increment F (F1→F7), PRs #18–#26 |
| **Phase 2 — Kobo-style rigor + offline** | Repeat groups, matrix/grid, Likert, cascading select, geo (PostGIS), media capture, full expression engine, XLSForm import/export, PWA/offline, field library/templates | 🚧 **In progress** — Increment G (G1→G11); through G5 (geo) COMPLETE, G6 (media) built + verified (pending PR), next = G7 |
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
- **G6** — Media capture: image / audio / video / file — the shared polymorphic `attachments` write path (upload + storage + RLS + retrieval), pipeline linking (`coerceMedia`/`processMedia` PHP↔TS + `AttachmentReferenceValidator` + owner-re-point + `attachment_refs`), `MediaInput.vue` renderer (both runtimes) + `MediaEditor.vue` builder tab. Whole increment in one PR (user-chosen). ✅ **BUILT + locally verified** (Pest 700 / Vitest 358 green) on `feat/g6-media-capture` — **pending PR/merge**. `signature` renderer deferred.
- **G7** — Bidirectional XLSForm import/export (depends on repeat/cascading/expression)
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
