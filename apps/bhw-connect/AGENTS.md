# BHW Connect — instructions for Codex

You are the AI coding agent for **jongsky25**, who owns BHW Connect and works from a phone. This page is your
handoff: what this repository is, how it is set up, what you may change, and what has already been measured. Read it
all before changing anything. It was written on 2026-11-05 by the other developer's agent, so check a fact against
the code when it matters.

**Starting a task.** Codex reads `AGENTS.md` from the repository's root down to its working folder, and the root has
none. A task started at the root will not load this page by itself, so begin each task with:
`Read apps/bhw-connect/AGENTS.md and follow it.`

## What this repository is

Two separate applications, two developers, one repository, one server. The repository is **public**.

| | BHW Connect | Meridian |
|---|---|---|
| Where | `apps/bhw-connect/` | everything else |
| Owner | jongsky25 | avincentpatrick |
| Built with | ChatGPT Codex, from a phone | Claude Code, on a PC |
| Stack | Next.js 16, React 19, TypeScript 5, Vitest 3 | Laravel and Vue |
| Live at | https://staging.pitahc.gov.ph/bhw-connect | https://staging.pitahc.gov.ph |

Testers use Meridian on the same server every day. Neither application may break the other.

**Files that are not for you.** The root `CLAUDE.md`, `PROGRESS.md`, `VERSION`, `database/data/releases.json` and
`docs/claims/`, `docs/pipeline.md` and `docs/feature-backlog.md` are Meridian's bookkeeping. They are not your
instructions, and you never edit them. Numbers like `M214` or `D177` in other files refer to that bookkeeping.

## Read next, in this order

1. `apps/bhw-connect/CLAUDE.md`: BHW's rules and exact commands. It is named for another tool, but it is written for
   whichever agent works on BHW, and every rule in it applies to you.
2. `docs/working-agreement.md`: the rules between the two developers. It wins over this page on any rule.
3. `apps/bhw-connect/docs/HANDOVER.md`: what is jongsky25's, where everything lives, and his remaining steps.

## What you may change

| Paths | Whose | To change them |
|---|---|---|
| `apps/bhw-connect/`, except `ops/` | jongsky25 | Nobody else needed. He merges on green. |
| `apps/bhw-connect/ops/` | Shared: BHW's deploy, which a Meridian test also reads | avincentpatrick's `boundary-approved` label |
| `.github/`, `deploy.ps1`, `docs/working-agreement.md` | avincentpatrick. A change here can take either application down | avincentpatrick's `boundary-approved` label |
| Everything else | avincentpatrick | Only if jongsky25 says it is agreed, and it needs that label too |

`.github/CODEOWNERS` is the map, and CI reads it from `main`. The rule is about who may change what, so:

- **Never** edit `.github/` or the check to get a red check to pass, merge over a red check, or push directly to `main`.
- **A label can't come from you.** It counts only if the right person applied it, and they do that on GitHub themselves.
- If new commits land after a label, ask for it to be removed and added again.

## How BHW actually runs

⛔ **Nothing runs on Vercel or Supabase any more.** The code still says "supabase" everywhere (`@supabase/supabase-js`,
`supabase/migrations/`, `NEXT_PUBLIC_SUPABASE_URL`), and those are only names. Do not reason from Supabase's or Vercel's
documentation about how this deploys, signs in or stores files.

| Part | What it is |
|---|---|
| Server | One Windows Server 2016 box, with no Docker. You cannot reach it. |
| Web | Apache sends `/bhw-connect` to `127.0.0.1:3100`, which is the `bhw-connect` Windows service running Node 24 |
| Build | Next's standalone output, in `C:\meridian\bhw\releases\<sha>`. A `current` junction points at the live one, so swapping it is both the deploy and the rollback |
| Database | `bhw_connect` on the box's PostgreSQL 15, with pgvector. Meridian's database is separate, and neither application can connect to the other's |
| Data access | PostgREST v16.4 on `[::1]:3001`, not `127.0.0.1`. `lib/db/data-plane.ts` rewrites supabase-js's `/rest/v1/` calls to it. Row-level security still applies, and `anon` has a 3-second statement timeout |
| Sign-in | BHW has no passwords. Its server forwards the `meridian-session` cookie to Meridian's `GET /api/v1/auth/whoami`, and BHW's `admin_users` table decides who is an admin |
| Screenshots | Files on disk under `BHW_STORAGE_DIR` (`lib/storage/screenshots.ts`), shown to admins only |
| Scheduled jobs | Windows Task Scheduler, from `ops/cron-schedule.json`: precompute at 20:00 UTC and regression replay at 22:00 UTC |
| Backups | A dump of `bhw_connect` nightly at 01:45, 14 kept, and one before any deploy that migrates |
| Settings | His AI keys, `CRON_SECRET` and `USAGE_EVENTS_IP_SALT` are GitHub secrets named `BHW_<NAME>`, written to the box by every deploy. The rest is a file on the box that only avincentpatrick edits |

⛔ **One address means one origin.** BHW is served under Meridian's own address, so Meridian's session cookie reaches
BHW's pages and a script injected into BHW could act as whoever is signed in to Meridian. That is why every page has a
strict per-request nonce Content-Security-Policy (`lib/csp.ts`), and why every page renders on request. Do not weaken
it. A new external script or font origin has to be added to the policy deliberately.

## From a change to live

1. Branch from `origin/main`, change only what the task needs, and run the checks in `apps/bhw-connect/CLAUDE.md`.
2. Open a pull request into `main`. CI's checks run.
3. When every check is green, jongsky25 merges it. Before merging, look at the last **Deploy (production,
   self-hosted)** run: if it is red, the next merge of any kind takes Meridian down briefly, so tell avincentpatrick first.
4. `main` runs every job, then the deploy runs. Measured: **about 30–60 minutes** from merge to live.
5. The deploy builds BHW beside the running one, runs new migrations, swaps it in, checks that it answers, and swaps
   back by itself if not. Meridian is not touched.
6. Look at it on staging, which is BHW's preview. There is no per-pull-request preview.

If it is broken, open a revert pull request, or ask avincentpatrick to roll back on the box, which takes seconds.

**A merge touching both applications** follows Meridian's planned merge points. Ask first.

### The checks

Eight are required: Boundary (from main) · Static analysis, style & security · Tests (Pest on PostgreSQL) · Frontend
build & type-check · Design system a11y (axe) · Contract tests (OpenAPI) · E2E (Playwright + axe) · BHW Connect (lint,
types, tests, build).

- **Boundary (from main)** is the ownership rule. It runs from `main`'s copy, so a pull request cannot change the rule
  that judges it, and it runs again by itself when a label is added or removed.
- **A BHW-only pull request takes minutes.** Meridian's jobs skip and show as passed, and the secret scan still runs.
  Skipped jobs report no steps, and that is correct.
- **A change under `ops/` runs every job,** about 30–40 minutes.
- **BHW Connect** runs lint, types, unit tests, the migration lint, both builds, five pages and the browser specs in `e2e/`.

## Your environment

- **Network:** by default Codex cloud has network during its setup script and not while the agent works. So the setup
  script has to install dependencies: `cd apps/bhw-connect && npm ci`. Node 24 matches CI and the box.
- **Lockfile:** change `package-lock.json` with npm 10 (`npx npm@10 install …`). npm 11 rewrites entries you did not touch.
- **What you probably can't run:** Vitest's database cases need PostgreSQL (`BHW_TEST_DATABASE_URL`) and skip without
  it, and the browser specs need a browser and a running server. CI runs both. **Say plainly in your report what you
  did not run.** A session here reported 1,281 tests passing on 2026-11-05; with the database, the count was 1,286 and 11 skipped.
- **GitHub access:** Codex pushes through the **ChatGPT Codex Connector** app, installed on the repository owner's
  account on 2026-11-05, and pull requests carry his name. A **403 on pushing or creating a branch** means that access
  has changed. Only avincentpatrick can fix it, and it is not a reason to change anything in the repository.

## What needs avincentpatrick

Anything on the box: a data load, a server setting, a rollback, the app's or PostgREST's logs, or adding a BHW admin. Also
his label on shared paths and on a destructive migration. Prepare what you can, then say exactly what he needs to do, in
one line. Don't ask him to do things that are on jongsky25's own list.

## Rules that are never bent

- **Real data never enters git.** `fact_bhw_raw` holds personal records, and this repository is public. The committed
  development copy is the live schema with made-up figures, and it is enough to develop against. A real data load is
  prepared in a session and sent to avincentpatrick privately.
- **Secrets never go in git or chat.**
- **Migrations only add.** No dropped table or column, no `TRUNCATE`, no `DELETE FROM`, no type change, no rename. CI
  refuses them. One that truly must remove data starts with `-- bhw:destructive`, and needs avincentpatrick's approval.
- **Every internal URL has the prefix.** `next/link`, `router.push` and `redirect()` get `/bhw-connect` by themselves. A
  raw `<a href="/…">`, `fetch("/…")`, `window.location` change or `public/` file URL goes through `withBasePath()`
  (`lib/base-path.ts`). A test fails on the literal ones.

## Already measured, so you don't have to rediscover it

- **A place page is not slow.** A cold page reached the visitor within 3 seconds, on staging with gzip too. Only the AI
  insight card arrives later, 20–35 seconds when its text has to be generated, because it sits in `Suspense` and calls a
  provider live. Total transfer time from `curl` is not what a person waits for. Warming pages at deploy would not help,
  and it would spend quota on his AI keys.
- **The made-up seed gives figures to one region only** (Region I). Other regions show "—", and `compare`'s head-to-head
  strip, which needs two places with values, correctly hides itself there.
- **Browser specs navigate relative to the base address** (`"explore"`, not `"/explore"`), or they lose the prefix.
- **A standalone build has no assets beside it.** `.next/static` and `public` must be copied in before it starts, as
  the deploy does. Without them pages still answer 200, with no script that runs.
- **supabase-js retries a failed GET on 503 or 520,** after 1, 2 and 4 seconds. That is why CI's no-database build
  answers every call at once with a 501 (`BHW_DATA_SOURCE=none`).

## Where things stand (2026-11-05)

- **Live and healthy.** BHW runs wholly on the box.
- **His own change** hiding the 2026 Profiling Status card from the landing page is pull request #411. Check whether it
  has merged.
- **Still his, on his own accounts:** see *Your first day* in `docs/HANDOVER.md`. As measured today, the Supabase project
  and Vercel's `bhw-connect` are gone. Vercel's `bhw-connect-phase-2` remains, the old repository is not archived, and
  only `BHW_USAGE_EVENTS_IP_SALT` is set among the `BHW_` secrets.
- **Left for him to decide:** the section of that name in `docs/HANDOVER.md`. BHW's work queue is his now, and none of it
  lives in Meridian's.

**How to report to him.** He reads on a phone: lead with the result, keep it short, use plain words, and end with what
he needs to do next, if anything.

<!-- BEGIN:nextjs-agent-rules -->

## This is NOT the Next.js you know

This version has breaking changes — APIs, conventions, and file structure may all differ from your training data. Read the relevant guide in `node_modules/next/dist/docs/` (resolved from this file's directory; in monorepos the `next` package may not be visible from the repo root) before writing any code. Heed deprecation notices.

This block is written and re-added by `next dev` — verify at `node_modules/next/dist/server/lib/generate-agent-files.js`. Removing it from a diff only re-creates the uncommitted change; committing it with your work keeps the tree clean.

<!-- END:nextjs-agent-rules -->
