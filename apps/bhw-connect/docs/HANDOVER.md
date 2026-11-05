# BHW Connect — your handover

**For:** jongsky25. **From:** avincentpatrick, 2026-11-05.

BHW Connect now runs wholly on the testing server, at **https://staging.pitahc.gov.ph/bhw-connect**. Its code is
here, in `apps/bhw-connect/`. Its database, admin sign-in and settings are on the server too. Supabase and Vercel no
longer serve it. From today it is yours.

This page says what is yours, what a merge does, where everything lives, and what to do on your first day. The rules
we both follow are in `docs/working-agreement.md`. It is short, so read it once.

## What is yours

- **Everything under `apps/bhw-connect/`.** You merge your own pull requests once every check is green. Nobody else
  needs to approve them.
- **Shared with avincentpatrick:** `apps/bhw-connect/ops/`, `.github/`, `deploy.ps1` and `docs/working-agreement.md`.
  A change to any of these can take the other app down, so it needs the other person's `boundary-approved` label.
  - This now works both ways. When one of Meridian's pull requests touches a shared path, it waits for **your**
    label. Please answer within a working day (working agreement §10).
- **Your Claude sessions.** Open them in `apps/bhw-connect/`. They follow `apps/bhw-connect/CLAUDE.md`, and the
  repository's root `CLAUDE.md` is Meridian's, so it does not apply to you.

## What a merge does

1. You merge a pull request that changes only `apps/bhw-connect/`.
2. The testing server deploys it within minutes.
   - It rebuilds BHW beside the running one and runs your new migrations.
   - It switches over, checks the site answers, and switches back by itself if it does not.
   - The build and switch take about a minute. Meridian is not touched.
3. Look at it on staging. **Staging is your preview.**

Before you merge, look at the last **Deploy (production, self-hosted)** run in GitHub's Actions tab. If it is red, tell
avincentpatrick first.

Something broken after a merge? Open a revert pull request, or ask avincentpatrick to roll BHW back on the server.
A rollback takes seconds.

**Migrations add, never remove.** No dropped table or column, no TRUNCATE, no DELETE FROM. CI refuses them. A
migration that truly must remove data starts with `-- bhw:destructive` and needs avincentpatrick's approval.

## Where everything lives

| What | Where | Who changes it |
|---|---|---|
| The code | This repository, `apps/bhw-connect/` | You |
| The live database | `bhw_connect`, on the server's PostgreSQL, served by PostgREST | You, through migrations. Data loads go through avincentpatrick. |
| A database to develop against | The committed development copy: the live schema with made-up figures. `node db/load-dev.mjs` builds it locally, and CI builds the same one. | You. Refresh it with the **BHW Connect dev copy** workflow after a schema change. |
| Your settings: the AI keys, `CRON_SECRET`, `USAGE_EVENTS_IP_SALT` | GitHub repository secrets named `BHW_<NAME>` (Settings → Secrets and variables → Actions) | You. Every deploy writes them to the server. For a key change with no code change, run the **BHW Connect settings** workflow. |
| The server's settings: the database address, PostgREST's keys, every `NEXT_PUBLIC_` value | A file on the server | avincentpatrick |
| Deploy logs | GitHub → Actions → **Deploy (production, self-hosted)**. The second step is BHW's. | Read them any time |
| The app's own logs, PostgREST's and the scheduled jobs' | On the server | Ask avincentpatrick |
| Backups | A dump of `bhw_connect` every night at 01:45 (14 kept), and one before every deploy that migrates | Automatic |
| Scheduled jobs: precompute and the regression replay, daily | `ops/cron-schedule.json` | You, through a pull request (it is a shared path) |
| Feedback screenshots | On the server. Admins see them under Admin → Feedback. | Automatic |
| Who is a BHW admin | The `admin_users` table | avincentpatrick adds a row |

**Real data never goes into git.** The repository is public and BHW's dataset holds personal records. You never need
the real data to develop: the made-up copy is enough. A new real data load is prepared in your Claude session and sent
to avincentpatrick privately (a shared Drive file, for example), who loads it on the server.

## What still needs avincentpatrick

- Anything on the server: a data load, a server setting, a rollback, or a look at the server's logs.
- Their label, when you change a shared path or write a destructive migration.
- Adding a BHW admin, your own row included (first-day step 3).

## Your first day

1. **Read `docs/working-agreement.md`.**
2. **Accept your Meridian invite.**
   - It comes by email from `staging.pitahc.gov.ph`. Set a password, and set up two-factor if it asks.
   - BHW's admin pages sign you in through this account: BHW has no password of its own.
   - Your Meridian role is Admin (`D209`). Meridian's testers' forms and answers are theirs: look, don't change.
3. **Become a BHW admin.**
   - Signed in, open https://staging.pitahc.gov.ph/api/v1/auth/whoami and copy the `id` it shows. It is an id, not a
     secret.
   - Send it to avincentpatrick, who adds your row on the server.
   - After that, BHW Connect → Admin opens for you.
4. **Check that you can change your own settings.**
   - Set one `BHW_` secret, for example one of your AI keys.
   - Run the **BHW Connect settings** workflow from the Actions tab. Its log names the settings that changed, never
     their values.
   - If GitHub will not let you set a secret, tell avincentpatrick. GitHub's documentation says a collaborator can.
5. **Close the old homes.** Nothing reads them any more. The server's copy was taken on 2026-11-03, and on
   2026-11-05 Supabase held nothing newer. Do them in this order, and tell avincentpatrick as each is done:
   1. **Vercel:** delete the projects `bhw-connect` and `bhw-connect-phase-2`.
   2. **Supabase:** delete the project `ejcuwrnxngdwvecxwrhy`, then downgrade your organisation to Free. It is on Pro,
      which is why it could not just be paused (`D206`).
   3. **GitHub:** archive `jongsky25/BHW-Connect-Dashboard` (its Settings → Archive this repository). It stays
      readable (`D207`).
6. **Start.** Open a Claude session in `apps/bhw-connect/` and pick up your own work.

## If something goes wrong

- **A page is broken after your merge:** revert it, or ask for a rollback.
- **The trunk is red because of your side:** fix it or revert it within the day (working agreement §9).
- **Anything else:** ask avincentpatrick.
