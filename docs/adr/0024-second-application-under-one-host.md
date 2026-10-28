# ADR-0024 — A second application under one host: BHW Connect in this repository, at `/bhw-connect`

- **Status:** Accepted
- **Date:** 2026-10-28
- **Increment:** M192 (the BHW Connect merge, phase P0 — the import, CI and governance; nothing on the box)
- **Decisions:** `D172` (the prefix, and one origin accepted) · `D173` (the database home) · `D174` (one repository, one server) · `D175` (today's code only) · `D176` (BHW-only merges on green) · `D177` (review across the boundary only) · `D178` (Meridian's session for BHW admins) · `D179` (the navigation link) · `D180` (pgvector built off the box) · `D181` (data loads) · `D182` (the local stack; staging is BHW's preview) · `D183` (Supabase after the move) · `D184` (Vercel after the move) · `D185` (BHW's dependency advisories warn until cleared)
- **Supersedes / amends:** amends `docs/deployment-infrastructure.md` §8 step 2 ("a dedicated instance … shared with no other site") for the testing site, and adds §8.4
- **Related:** `0005-hosting-self-hosted-windows-server.md` (the box this runs on) · `0022-single-lane-development.md` (Meridian's single lane — BHW is outside it, by path) · `0023-platform-support-api.md` (the platform API; the `whoami` endpoint of §D5 is not part of it) · `docs/working-agreement.md` · `.github/CODEOWNERS` · `apps/bhw-connect/`

---

## Context

The user asked for a second, already-built application — **BHW Connect**, a public dashboard over the
Philippine Barangay Health Worker dataset, written by a second developer (`jongsky25`) in Next.js 16 with
Supabase and hosted on Vercel — to live inside this repository and be served from the testing site's own
address, with its database moved off Supabase onto the testing box. The second developer keeps building
it, and works only from Claude on a phone; the user keeps building Meridian and drives the box by hand.
Both ship to one Windows Server 2016 box with no Docker. Neither may break the other — in code, in CI,
in the database, or in a deploy — and the testers on Meridian must never notice a BHW release.

The plan, measured against both applications and the live Supabase database before any decision, is
`~/.claude/plans/there-is-another-app-whimsical-cascade.md`. This record keeps what was decided and why.

## Decision

### §D1 — One repository, BHW nested at `apps/bhw-connect/`, no npm workspaces (`D174`, `D175`)

BHW lives only here, at `apps/bhw-connect/`. Meridian stays at the root — moving it would touch
`deploy.ps1`, every script, CI and every migration for nothing. There are two independent dependency trees
and two lockfiles: Meridian is on Vite 8, TypeScript 6 and Vitest 4, BHW on Next 16, TypeScript 5 and
Vitest 3, and hoisting would let one side's upgrade break the other. The import is **today's code only**
— one commit naming `jongsky25/BHW-Connect-Dashboard@e4237373` — so nothing in BHW's history (an inlined
key, an unredacted workbook) enters this public repository; its history stays readable in the archived
repository. Left out: `ingestion/data/` (56 MB of source data, now kept off git), BHW's own CI workflow and
`vercel.json`. The second developer is a collaborator here and points Claude at this repository; their
old repository is archived. Rejected: a two-way sync between two repositories (every conflict would live
in it) and two repositories with a second runner on the box (only BHW's owner could register it, and the
code here would be a copy nobody may edit).

### §D2 — One address, one origin, and what is done about it (`D172`)

BHW is served at `https://staging.pitahc.gov.ph/bhw-connect`, through Next's `basePath` driven by
`NEXT_PUBLIC_BASE_PATH` (empty everywhere but the box and CI, so a laptop and Vercel behave as before)
and one Apache `ProxyPass`. Every URL Next does not prefix goes through `withBasePath()`; a source census
(`apps/bhw-connect/lib/base-path-census.test.ts`) holds the literal ones at zero. **One address is one
origin:** Meridian's session cookie reaches BHW's pages and its `XSRF-TOKEN` is readable by script, so a
script-injection bug in BHW could act as whoever is signed in to Meridian. The user accepted that
knowingly, on the condition that the source runs on the same server (it does — §D3). The mitigations: a
strict per-request-nonce Content-Security-Policy on every BHW page (`apps/bhw-connect/lib/csp.ts`), and
Meridian's API-key mint behind a password re-check (`bhw-p3-identity`). Measured cost of the nonce: every
BHW page renders on request rather than from a prerendered copy (28 static pages became 6, the six being
images, robots and the sitemap). Rejected: a subdomain, which isolates the origin at the price of DICT DNS
work and a second certificate.

### §D3 — A Node service serving a standalone build from a releases directory

Next's standalone output (`output: 'standalone'`, its tracing and Turbopack roots pinned to the app,
because the repository root carries Meridian's lockfile) is assembled into `C:\meridian\bhw\releases\<sha>`
and served by an NSSM service through a `current` junction — never from the git checkout, which every
Meridian deploy resets and where Windows locks a loaded native module. The junction swap is the deploy and
the rollback. Cron jobs move from Vercel to Task Scheduler, from a versioned schedule
(`apps/bhw-connect/ops/cron-schedule.json`).

### §D4 — The data: a second database on the same instance, PostgREST, and a one-file bridge (`D173`, `D180`–`D183`)

`bhw_connect` sits on the box's existing PostgreSQL 15 instance with its own roles, which collide with
none of Meridian's; CONNECT is revoked from PUBLIC on both databases, so neither application can even open
a connection to the other's. Nothing in BHW's schema needs version 17, and one instance is one thing to
back up and patch. PostgREST — official Windows binaries — serves it, so BHW's 189 supabase-js call sites
change by nothing: `apps/bhw-connect/lib/db/data-plane.ts` rewrites `…/rest/v1/` to PostgREST's root when
`SUPABASE_REST_URL` is set, and is otherwise absent. Supabase's role contract is rebuilt by
`apps/bhw-connect/db/bootstrap-roles.sql` (measured against a local PostgreSQL 15 + PostgREST v16.4:
1,000 rows per request with the exact count, RLS for `anon`, `service_role` bypassing it). pgvector is
built off the box. Data loads arrive as files sent privately and loaded by the operator; personal records
never pass through git. Supabase is paused for 30 days as the rollback, then deleted; Vercel is paused,
then deleted. Rejected: a second PostgreSQL 17 instance, staying on Supabase (paid, foreign), and
rewriting BHW to query Postgres directly (189 call sites of the other developer's code, and RLS is its
access layer).

### §D5 — Identity: Meridian signs people in, BHW decides who is its admin (`D178`, `D179`)

Supabase Auth goes. A small `GET /api/v1/auth/whoami` on Meridian answers who is signed in, and BHW's own
`admin_users` table keeps deciding who administers BHW; nothing about BHW enters Meridian's permission
tables. Meridian's top menu (signed-in users) and footer link to BHW, and BHW's header links back. Built
in `bhw-p3-identity`.

### §D6 — Two deploys that never share a window (`D176`)

`deploy.ps1` treats `apps/` as site-safe, so a BHW-only push fast-forwards Meridian's checkout and opens
no maintenance window — provided the previous deploy finished cleanly (otherwise any push takes the full
path). `apps/bhw-connect/ops/deploy-bhw.ps1` then deploys BHW alone, against its own recorded sha, with
its own migrations, census and rollback. `DeploySkipAllowlistTest` holds both halves: `apps/` is allowed,
and nothing Meridian loads lives under it. A BHW-only pull request merges whenever it is green; every
other pull request keeps `D137`'s merge points.

### §D7 — CI and governance: ownership by path (`D177`, `D185`)

A seventh job, `bhw-connect`, runs BHW's lint, types, tests (with the database cases against a
PostgreSQL 15 service), the migration lint and a build under `/bhw-connect` **with no database at all**
(`BHW_DATA_SOURCE=none`: measured, supabase-js retries a failed read for seven seconds, which pushed a page
past Next's prerender limit). On a pull request that leaves `apps/bhw-connect` alone it skips everything
and still reports success. Ownership is `.github/CODEOWNERS` — Meridian everything, BHW its folder, and
`.github/`, `deploy.ps1`, BHW's `ops/` and the working agreement shared — and the job's boundary step
(`scripts/boundary-check.php`) refuses a pull request that changes another owner's paths without that
owner's `boundary-approved` label, reading the map from the base commit so a pull request cannot rewrite
the map that judges it. "Require review from code owners" stays off: with one owner per side an author
could never approve their own pull request. BHW's dependency audit runs through Meridian's judge and,
since `M194`, **blocks** on a high or critical production advisory; it warned only while the two critical
and eight high BHW carried at the import were open (`D185`), and `bhw-dependency-advisories` cleared them
(`D187`) before `bhw-p1-box` serves BHW from this origin.

### §D8 — Meridian's records stay Meridian's

Nothing under `apps/` enters Meridian's claims, pipeline, decisions or lint gates; BHW's documents and
decisions stay in `apps/bhw-connect/docs/`. Two Meridian gates read the whole tree and were taught to
leave `apps/` alone, each with controls proved red by a committed mutation: `scripts/backlog-triage.php`
(measured: the import drifted `docs/pipeline.md` and turned `pipeline-lint` red) and
`scripts/citation-liveness-lint.php` (latent: a BHW file sharing a basename would have blinded a Meridian
citation). Meridian's `VERSION` and release notes are not bumped by BHW-only merges. Meridian's root
`CLAUDE.md` opens with a line telling a session working only under `apps/bhw-connect/` that it is not for
them, and BHW has its own `CLAUDE.md`.

### §D9 — The design-system exception

Meridian's standing rule is one shared design system on every page, with any exception written down. BHW
is that exception: a separately owned React application reached by a link, keeping its own Tailwind
design — not a page inside Meridian's shell.

## Consequences

**Accepted:**
- One origin for two applications, with the CSP and the password re-check as its mitigations (§D2).
- Every BHW page rendered per request (the nonce), and a box-load check owed in `bhw-p1-box`.
- Meridian's six jobs still run on a BHW-only pull request (about 30 minutes) until `bhw-ci-fast-path`.
- No per-pull-request preview for BHW: staging is its preview (`D182`), until `bhw-preview-slot`.
- BHW's Playwright specs do not run in CI (the deploy's health check catches a dead server, not a dead
  page) until `bhw-e2e-in-ci`.
- The boundary label is trust-based: a label applied before later commits still counts, and the working
  agreement asks the approver to re-apply it.
- The boundary runs inside a `pull_request` job, which GitHub takes from the pull request's own copy of
  `ci.yml` — so a collaborator could delete the step in the same pull request it would have refused. The
  `.github/` change still shows in the diff, but no check flags it. GitHub cannot limit a collaborator to one
  folder of a personal repository, so this guard is the whole fence; hardening it is `bhw-boundary-hardening`.

**Rejected:** a subdomain; two repositories; a second PostgreSQL instance; staying on Supabase; direct
Postgres queries; npm workspaces; moving Meridian to `apps/meridian/`; "require review from code owners";
rewriting BHW into Laravel and Vue.

## The work this files

Each phase is one increment, claimed when taken. The markers below are the queue's entries for all of it.

<!-- pipeline: id=bhw-p0-import title="BHW Connect merge P0 — the import under apps/bhw-connect, prefix-aware with the data plane and the CSP, the box scripts written, CI's bhw-connect job with the boundary, CODEOWNERS and the working agreement" phase=n/a state=done size=XL tier=during-testing done="M192 — built and held green for the merge point the user names; recorded in docs/claims/lane-a.md's M192 claim" -->
<!-- pipeline: id=bhw-dependency-advisories title="Clear BHW's production dependency advisories (next — a proxy bypass under Turbopack; maplibre-gl — an XSS fixed only in a major; pptxgenjs and image-size; four in-range) with the map and export screens checked in a browser, then put the bhw-connect job's audit back under Meridian's blocking rule (D185)" phase=n/a state=blocked size=M blocker="M192 merged: the job and the code live on the trunk; the edit is under apps/bhw-connect, so a Meridian session needs the BHW owner's label" tier=during-testing -->
<!-- pipeline: id=bhw-p1-box title="BHW Connect merge P1 — the app on the box against Supabase: the release, the NSSM service, Apache's ProxyPass, the scheduled tasks, deploy.yml running ops/deploy-bhw.ps1, proved by a BHW-only push and a rollback; plus the box-load check now that every BHW page renders per request" phase=n/a state=blocked size=L blocker="bhw-dependency-advisories (D185); then the user at the box, one guarded block at a time" tier=during-testing -->
<!-- pipeline: id=bhw-p2-db title="BHW Connect merge P2 — the database moves in: bhw_connect on the box's PostgreSQL 15 with CONNECT revoked from PUBLIC on both databases, pgvector built off the box, PostgREST, the minted keys, the restore with an exact census, the nightly dump and a restore drill; Supabase and Vercel paused" phase=n/a state=blocked size=XL blocker="bhw-p1-box" tier=during-testing -->
<!-- pipeline: id=bhw-p3-identity title="BHW Connect merge P3 — Meridian's session for BHW admins (GET /api/v1/auth/whoami), screenshots on local disk, the API-key mint behind a password re-check, and the links between the two applications" phase=n/a state=blocked size=L blocker="bhw-p2-db" tier=during-testing -->
<!-- pipeline: id=bhw-p4-loop title="BHW Connect merge P4 — the other developer's loop: the seed from staging (synthetic personal rows), CI's database cases against a seeded PostgREST, the old repository archived" phase=n/a state=blocked size=M blocker="bhw-p3-identity" tier=during-testing -->
<!-- pipeline: id=bhw-ci-fast-path title="Meridian's six CI jobs skip a pull request that changes only apps/bhw-connect, the secret scan kept — BHW's checks drop from about 30 minutes" phase=n/a state=blocked size=S blocker="M192 merged: the bhw-connect job must be on the trunk first" tier=during-testing -->
<!-- pipeline: id=bhw-preview-slot title="A preview of a BHW pull request on the box before it merges (a second BHW service at /bhw-connect-preview), if staging-as-preview proves too coarse (D182)" phase=n/a state=blocked size=M blocker="bhw-p1-box" tier=before-launch -->
<!-- pipeline: id=bhw-data-upload-page title="An admin-only BHW page that takes a data load file and loads it, so a load needs no operator (D181)" phase=n/a state=blocked size=L blocker="bhw-p2-db" tier=before-launch -->
<!-- pipeline: id=bhw-boundary-hardening title="Run the boundary check from main's own copy of its workflow (a pull_request_target workflow reading the head's commits without running them, or a ruleset-required workflow), so a pull request cannot delete the check that judges it; measure first whether such a check can satisfy a required status" phase=n/a state=blocked size=S blocker="M192 merged: the boundary and CODEOWNERS must be on the trunk" tier=during-testing -->
<!-- pipeline: id=bhw-e2e-in-ci title="BHW's Playwright specs in CI against the prefixed build, so a broken page is caught before it ships rather than on staging" phase=n/a state=blocked size=S blocker="bhw-p4-loop" tier=before-launch -->
