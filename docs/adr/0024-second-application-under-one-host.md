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

<!-- pipeline: id=bhw-p0-import title="BHW Connect merge P0 — the import under apps/bhw-connect, prefix-aware with the data plane and the CSP, the box scripts written, CI's bhw-connect job with the boundary, CODEOWNERS and the working agreement" phase=n/a state=done size=XL tier=during-testing done="M192 — merged at merge point 8 (PR #385, 7cc8506b, 2026-10-28); recorded in docs/claims/lane-a.md's M192 claim" -->
<!-- pipeline: id=bhw-dependency-advisories title="Clear BHW's production dependency advisories (next — a proxy bypass under Turbopack; maplibre-gl — an XSS fixed only in a major; pptxgenjs and image-size; four in-range) with the map and export screens checked in a browser, then put the bhw-connect job's audit back under Meridian's blocking rule (D185)" phase=n/a state=done size=M tier=during-testing done="M194 — merged at merge point 9 (PR #387, df66f1f8, 2026-10-31) with the BHW owner's label; recorded in docs/claims/lane-a.md's M194 claim" -->
<!-- pipeline: id=bhw-p1-box title="BHW Connect merge P1 — the app on the box against Supabase. Live since 2026-11-01 by hand (the release, the NSSM service, Apache's ProxyPass; recorded in M194's release); what remains comes after bhw-p3-identity (D194, BHW standalone first): the scheduled tasks with CRON_SECRET, the runner's grant to control the bhw-connect service, deploy.yml running ops/deploy-bhw.ps1, proved by a BHW-only push and a rollback, runbook 8.4 rewritten from what the box measured, and the box-load check now that every BHW page renders per request and its database shares the box" phase=n/a state=done size=L tier=during-testing done="M209 — merged at its own merge points (PR #402 0c223812 the wiring, PR #403 dff21734 the BHW-only push, 2026-11-04): the runner's service entry on bhw-connect, CRON_SECRET and three tasks at the box, deploy.yml's second step, the runner's first BHW build in 51 s, the BHW-only push with no Meridian window, the rollback in 2.5 s each way, the load check, runbook 8.4 from what was measured; recorded in docs/claims/lane-a.md's M209 claim" -->
<!-- pipeline: id=bhw-deploy-db-tools title="BHW's ops scripts call a bare pg_dump (ops/nightly-dump.ps1, and deploy-bhw.ps1's Invoke-Migrations before a migration), and the box's PATH has no PostgreSQL bin (measured at B0, 2026-11-03): today the hand deploy and the bhw-connect-db-backup task put PostgreSQL 15's bin folder on their own PATH (M206). Resolve pg_dump as Meridian's DeployBackupCommand does (a configured path, else the newest PostgreSQL install's, else PATH), and give the runner (NETWORK SERVICE) write on the BHW backup folder under C:/meridian/backups, before bhw-p1-box wires deploy.yml to ops/deploy-bhw.ps1, where a pending migration would otherwise stop at the backup" phase=n/a state=done size=S tier=during-testing done="M208 — merged at its own merge point (PR #401, b18bd1c2, 2026-11-04); ops/pg-tools.ps1 resolves pg_dump and masks the deploy echo; NETWORK SERVICE has Modify on the BHW backup folder, proved by a nightly dump with no PostgreSQL on PATH; recorded in docs/claims/lane-a.md's M208 claim" -->
<!-- pipeline: id=bhw-p2-db title="BHW Connect merge P2 — the database moves in: bhw_connect on the box's PostgreSQL 15 with CONNECT revoked from PUBLIC on both databases, pgvector built off the box, PostgREST verifying Supabase's own legacy JWT secret so today's keys keep serving data, sign-in and screenshots until bhw-p3-identity (D195), the copy of the live public schema and data from Supabase's PostgreSQL 17 into the box's 15 (jongsky25 sends the Session-pooler connection string and the legacy JWT secret privately; the live database had 61 tables and 170 recorded migrations on 2026-11-02, so it, not the repository's migrations, is the source) with an exact census of every table, a baseline recorded through db/migrate.mjs, the nightly dump and a restore drill, rehearsed on the dev machine first and then run by hand at the box with the migrations through ops/deploy-bhw.ps1; Vercel paused, while Supabase stays up for BHW's admin sign-in until bhw-p3-identity (D194)" phase=n/a state=done size=XL tier=during-testing done="M206 — live at the box on 2026-11-03 13:44:33 UTC after a 13.6-minute freeze, no pull request of its own (its close-out is one, D201): census 61/61 tables and 2,114,275 rows equal to Supabase at the freeze, 52 sequences current, 111 migration files baselined with 0 pending, PostgREST 16.4 on [::1]:3001 (Server 2016 refuses the IPv4 socket option its network library sets), the write proof in bhw_connect and not in Supabase, ops/deploy-bhw.ps1 through the migrations check, the nightly bhw-connect-db-backup restored to the same counts; Vercel's bhw-connect-phase-2 paused; recorded in docs/claims/lane-a.md's M206 claim" -->
<!-- pipeline: id=bhw-p3-identity title="BHW Connect merge P3 — Meridian's session for BHW admins (GET /api/v1/auth/whoami), screenshots on local disk, the API-key mint behind a password re-check, the links between the two applications, and the box's own JWT secret with freshly minted anon and service keys replacing Supabase's (a rebuild, since the anon key is in the client bundle; D195); then Supabase paused for 30 days as the rollback and deleted, since nothing of BHW reads it any more (D183, D194)" phase=n/a state=done size=L tier=during-testing done="M207 — merged at its own merge point (PR #400, d7ca70a0, 2026-11-03, D203) and live at the box 2026-11-04: whoami, the mint password, the account-menu and footer links, admins by Meridian account (D204), 34 screenshots on disk, the box's own secret and keys with Supabase's old key refused; Supabase cannot be paused on Pro (D206); recorded in docs/claims/lane-a.md's M207 claim" -->
<!-- pipeline: id=bhw-p4-loop title="BHW Connect merge P4 — the other developer's loop: the seed from staging (synthetic personal rows), CI's database cases against a seeded PostgREST, the old repository archived" phase=n/a state=ready size=M tier=during-testing -->
<!-- pipeline: id=bhw-ci-fast-path title="Meridian's six CI jobs skip a pull request that changes only apps/bhw-connect, the secret scan kept — BHW's checks drop from about 30 minutes" phase=n/a state=ready size=S tier=during-testing -->
<!-- pipeline: id=bhw-preview-slot title="A preview of a BHW pull request on the box before it merges (a second BHW service at /bhw-connect-preview), if staging-as-preview proves too coarse (D182)" phase=n/a state=ready size=M tier=before-launch -->
<!-- pipeline: id=bhw-data-upload-page title="An admin-only BHW page that takes a data load file and loads it, so a load needs no operator (D181)" phase=n/a state=ready size=L tier=before-launch -->
<!-- pipeline: id=bhw-boundary-hardening title="Run the boundary check from main's own copy of its workflow (a pull_request_target workflow reading the head's commits without running them, or a ruleset-required workflow), so a pull request cannot delete the check that judges it; measure first whether such a check can satisfy a required status" phase=n/a state=ready size=S tier=during-testing -->
<!-- pipeline: id=bhw-e2e-in-ci title="BHW's Playwright specs in CI against the prefixed build, so a broken page is caught before it ships rather than on staging" phase=n/a state=blocked size=S blocker="bhw-p4-loop" tier=before-launch -->
<!-- pipeline: id=bhw-geo-drilldown-isr title="BHW's geo drill-down pages (place, facilities, profiling-status, uuc-phc and its sub-pages, by level and code) answer 500 with DYNAMIC_SERVER_USAGE from a build with no data source, on the trunk and on M194's branch alike (measured 2026-10-30): they stay ISR, generateStaticParams with revalidate, while the root layout reads the per-request CSP nonce through headers() since M192; render them per request, then measure a build with the data source, which pre-renders hundreds of them against Supabase and hit Next's 60-second page limit" phase=n/a state=done size=M tier=during-testing done="M194 — folded into PR #387, force-dynamic on the seven drill-downs, merged at merge point 9 (df66f1f8, 2026-10-31); recorded in docs/claims/lane-a.md's M194 claim" -->
<!-- pipeline: id=bhw-drilldown-cold-render title="The first view of a BHW region or province drill-down takes 20 to 40 seconds now that the seven render per request (M194; a repeat takes 0.3 to 1.4 s, the data layer caches; on the box, 2026-11-01, 19 to 32 s first and under 1 s warm): those figures were taken against Supabase across the internet, and measured again after bhw-p2-db put the database on the box (M206, 2026-11-03, from outside): /place/region/01 17.2 s and /uuc-phc/region/01 28.5 s on first view, 0.1 s warm, so the database's distance was not the cause; and at M209's load check (2026-11-04) twelve cold views at once took 18 to 30 s while the box's CPU peaked at 2.5%, so a first view waits rather than computes: find what it waits on, then request every region and province page once at the end of ops/deploy-bhw.ps1" phase=n/a state=ready size=S tier=during-testing -->
<!-- pipeline: id=bhw-deploy-release-leak title="ops/deploy-bhw.ps1's New-Release returns the npm ci and npm run build output along with the release path (Invoke-Native passes a native command's output down the pipeline), so on a fresh build the caller's release becomes the whole log and the current junction points at nothing: the box's first deploy on 2026-11-01 left the service unable to find server.js, recovered by removing the junction and running again (an assembled release returns cleanly); send the two npm calls to Out-Host, keeping Invoke-Native's output for Get-Census, before bhw-p2-db's first migration runs through the script by hand (D194)" phase=n/a state=done size=S tier=during-testing done="M202 — merged at its own merge point (PR #395, 19f87694, 2026-11-03, D196) with the BHW owner's label; proved by the user's hand deploy of 9370e532, a fresh build with no recovery; recorded in docs/claims/lane-a.md's M202 claim" -->
<!-- pipeline: id=bhw-migrate-duplicate-versions title="Two pairs of BHW migration files share a version (20260721080000 and 20260827180000), and db/migrate.mjs keys its history on the version alone: --baseline inserts each covered version unguarded, so it stops on the primary key partway through, and a normal run applies the first file of a pair and skips the second for good; record a version already present without failing, and refuse a new duplicate above the lint floor, measured on a scratch PostgreSQL 15 before the fix is written, before bhw-p2-db records its baseline (found 2026-11-02 while planning P2)" phase=n/a state=done size=S tier=during-testing done="M203 — merged at its own merge point (PR #396, 9370e532, 2026-11-03, D197) with the BHW owner's label; recorded in docs/claims/lane-a.md's M203 claim" -->
<!-- pipeline: id=bhw-admin-signin-csp title="BHW's admin sign-in on staging is blocked by its own Content-Security-Policy: connect-src is self only (lib/csp.ts, served live on /bhw-connect/admin/login, read 2026-11-02), while the login page calls Supabase's sign-in from the browser, and img-src blocks the admin's signed screenshot links too; so today an admin can sign in only on Vercel, which bhw-p2-db pauses. Open connect-src and img-src to the NEXT_PUBLIC_SUPABASE_URL origin only while it differs from the page's own, which ends at bhw-p3-identity (D195), proved by a real admin sign-in on staging" phase=n/a state=done size=S tier=during-testing done="M203 — merged at its own merge point (PR #396, 9370e532, 2026-11-03, D197); proved on live staging by a headless wrong-password sign-in that reached Supabase (400) with no CSP violation, jongsky25 not having started (D198); recorded in docs/claims/lane-a.md's M203 claim" -->
<!-- pipeline: id=bhw-ownership-until-handover title="jongsky25 has not started, and the move is finished before the handover (D198): jongsky25's lines in .github/CODEOWNERS set aside until the handover, so * gives every path to avincentpatrick and the move's pull requests need nobody's label; the working agreement says so at its top; boundary-check-controls' B0 holds the shipped map to D198. Needs his label once, rides merge point 10" phase=n/a state=done size=S tier=during-testing done="M204 — merged on its own ahead of merge point 10 (PR #397, 2d94f09c, 2026-11-03, D202) with the BHW owner's label; recorded in docs/claims/lane-a.md's M204 claim" -->
<!-- pipeline: id=bhw-settings-from-github title="BHW's own settings (its third-party API keys, CRON_SECRET, the usage-log salt) as GitHub repository secrets that deploy.yml writes into the box's BHW .env file before ops/deploy-bhw.ps1 runs, never echoed, so jongsky25 changes a key himself after the handover (D199); the working agreement's section 6 rewritten to match" phase=n/a state=ready size=M tier=during-testing -->
<!-- pipeline: id=bhw-handover title="Hand BHW Connect to jongsky25 once it runs wholly on the box (D198, D199): his lines back in .github/CODEOWNERS and the working agreement, his settings set as repository secrets, his Meridian account on staging and his admin_users row (D204), his Supabase project deleted and organisation downgraded with the two Vercel projects deleted (D206), a handover page in plain words (what is his, what a merge does, where data, settings and logs live, what still needs avincentpatrick), and the user tells him to start" phase=n/a state=blocked size=S blocker="bhw-p4-loop, bhw-settings-from-github" tier=during-testing -->
<!-- pipeline: id=bhw-supabase-vercel-delete title="Delete jongsky25's Supabase project (ejcuwrnxngdwvecxwrhy) and downgrade his organisation to Free, and delete both Vercel projects (bhw-connect, bhw-connect-phase-2): nothing of BHW reads them since bhw-p3-identity, and a Pro project cannot be paused, so D183's 30-day pause is impossible (D206); the deletions are the owner's clicks, asked for in the handover page" phase=n/a state=blocked size=S blocker="bhw-handover" tier=during-testing -->
<!-- pipeline: id=api-key-mint-sso-password title="An account that signs in only with Google or SSO holds a random password, so the password every API-key mint requires from bhw-p3-identity on (D205) refuses it until the account sets one through Forgot password: give such an account a way to mint (an SSO step-up for the mint, or the key page leading it to set a password first), decided before the first API-key page ships" phase=n/a state=ready size=S tier=before-launch -->
<!-- pipeline: id=bhw-screenshot-backup title="BHW Connect's feedback screenshots are files on the box from bhw-p3-identity on (C:/meridian/bhw/storage, 34 files and 1.9 MB at the move), and bhw_connect's nightly dump (ops/nightly-dump.ps1) carries only the database, so no backup holds them: copy the storage folder beside each night's dump in the BHW backup folder, kept as long as the dumps, and restore one in the drill" phase=n/a state=ready size=S tier=during-testing -->
<!-- pipeline: id=bhw-screenshot-trace title="BHW's build warns that lib/storage/screenshots.ts traces the whole project into the release (Turbopack: dynamic filesystem access in storageRoot's path.resolve and readScreenshot's readFile, seen on the box's M207 build of d7ca70a0): scope the paths so Next traces only the storage folder, or mark the calls turbopackIgnore, and measure the release size before and after" phase=n/a state=done size=S tier=during-testing done="M209 — PR #403 (dff21734, 2026-11-04), M209's BHW-only push: turbopackIgnore on the store's file calls; standalone output 3,404 files and 60.0 MB to 2,770 and 50.2 MB, no warning on the box's build; recorded in docs/claims/lane-a.md's M209 claim" -->
