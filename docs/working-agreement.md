# Working agreement — two applications, one repository, one server

**For:** both developers — avincentpatrick (Meridian) and jongsky25 (BHW Connect). Plain rules, no
Meridian jargon. Why it is set up this way is `docs/adr/0024-second-application-under-one-host.md`; the
decisions behind it are `D172`–`D185` in `docs/claims/decisions.md`. Changing this page needs both of you
(it is shared in `.github/CODEOWNERS`).

## Until the handover (D198, 2026-11-02)

jongsky25 has not started yet. BHW Connect is being moved onto the testing server first: its code, its
database and its admin sign-in. It is handed over once it runs there on its own. Until then:

- **Every file in this repository is avincentpatrick's**, `apps/bhw-connect/` included, and no pull
  request needs your label. `.github/CODEOWNERS` says so; your lines are kept in it, set aside.
- **At the handover:**
  - your lines come back exactly as they were, and the sections below apply as written;
  - section 6 is rewritten so that you change BHW's settings yourself, through GitHub (D199);
  - a handover page says what is yours and where everything lives;
  - avincentpatrick tells you when to start.

## 1. Who owns what

- **Everything under `apps/bhw-connect/` is BHW Connect's — jongsky25's.**
- **Everything else is Meridian's — avincentpatrick's.**
- **Shared** (a change here can take the other application down): `.github/`, `deploy.ps1`,
  `apps/bhw-connect/ops/`, and this page.

Ownership lives in `.github/CODEOWNERS`. You never need the other person for a change on your own side. A
pull request that touches the other person's files, or a shared one, needs **their** approval: they add
the label `boundary-approved` to it. CI's `boundary` step checks that, and that the label was added by the
right person. If you push new commits after they approved, ask them to remove the label and add it again.

## 2. One trunk

Branch from `origin/main`. Open a pull request into `main`. Merge only when every required check is green.
Never push to `main` directly, and never force-push a branch someone else works on.

## 3. What a merge does

Every merge to `main` deploys to the testing server, `https://staging.pitahc.gov.ph`.

- **A BHW-only merge** — only files under `apps/bhw-connect/` — rebuilds and restarts **only BHW**, in
  seconds. Meridian is not touched and its testers notice nothing. jongsky25 may merge one **whenever its
  checks are green**.
- ⚠️ **Except after a failed deploy.** If the last Deploy run on GitHub is red, the next merge of any kind
  takes Meridian's full path, with a short maintenance window. Look at the last Deploy run first; if it is
  red, tell avincentpatrick before merging.
- **A Meridian merge** follows Meridian's own merge points (the testers are told), and never touches BHW.
- **A merge that touches both sides** does both, so it follows Meridian's merge points. Ask first.

Until the testing server runs BHW (phase P1 of the move), BHW merges are checked by CI but not visible
anywhere yet.

## 4. How BHW is tested and shipped — from a phone

1. Claude runs the checks in its own session: `npm ci`, `npm run lint`, `npm run typecheck`,
   `npx vitest run`, and the build the way CI builds it (the command is in `apps/bhw-connect/CLAUDE.md`).
2. Open the pull request. CI's **BHW Connect** check repeats all of it. Meridian's checks run too, so it
   takes about 30–40 minutes.
3. Green → merge it.
4. Look at it on `https://staging.pitahc.gov.ph/bhw-connect` a few minutes later. **Staging is BHW's
   preview.**
5. Broken? Either open a revert pull request (one more CI run), or ask avincentpatrick to roll back on
   the server — seconds, no rebuild.

## 5. The database

- `bhw_connect` is BHW's; `meridian` is Meridian's. Neither application ever connects to the other's.
- Schema changes are SQL files in `apps/bhw-connect/supabase/migrations/`, applied automatically at
  deploy. **Additive only on the testing server**: no dropping a table or column, no TRUNCATE, no
  DELETE FROM, no type change, no rename. CI refuses them. If one truly has to remove data, its first line
  is `-- bhw:destructive`, and avincentpatrick must approve the pull request.
- **Data never goes into git** — the repository is public and the BHW dataset is personal data. A new
  data load is prepared in Claude's session, sent to avincentpatrick privately (for example a shared
  Drive file), and loaded on the server by them.

## 6. Secrets

Never in git, never in chat. BHW's live settings are in a file on the server, `C:\meridian\bhw\.env`.
Two kinds of setting live there.

**BHW's own settings: jongsky25's, set in GitHub (`D199`).** These are the AI providers' keys
(`GEMINI_API_KEY`, `GROQ_API_KEY`, `OPENROUTER_API_KEY`, `MISTRAL_API_KEY`), `CRON_SECRET` and
`USAGE_EVENTS_IP_SALT`.
- **Where they live:** each is a repository secret named `BHW_<NAME>`, set under the repository's Settings →
  Secrets and variables → Actions.
- **When they reach the server:** every deploy writes them into the file before BHW's deploy runs. For a
  changed key with no code change, run the **BHW Connect settings** workflow from the Actions tab
  (`gh workflow run bhw-settings.yml`). It writes them and restarts BHW, and it deploys nothing.
- **An unset secret keeps the server's value**, so a key can't be blanked by accident. The log shows which
  names changed, never a value.

**The server's own settings: avincentpatrick's, set on the box.** These are the database address, PostgREST's
keys, and every `NEXT_PUBLIC_` value, which the build compiles in.

**A new BHW setting** is a line in `apps/bhw-connect/.env.example` and a note in the pull request.
- To make it one of the GitHub-managed ones, add it to `ops/write-settings.ps1`'s list and to both workflows.
  `tests/Feature/Deploy/DeployWorkflowTest.php` holds the three equal.
- Otherwise avincentpatrick adds it on the server before the merge.

**Trust.** Anyone who can set repository secrets can set Meridian's too, and a workflow change can read any
secret. That is why `.github/` is shared and needs both owners after the handover (§1).

## 7. Each application keeps its own things

- Its own `package.json` and lockfile; no shared installs. BHW never imports from Meridian's folders, and
  Meridian never imports from `apps/`. The only contract between the two is Meridian's "who is signed in"
  endpoint (from phase P3) and the links in each other's menus.
- Its own documents and decisions. BHW's stay in `apps/bhw-connect/docs/`. Meridian's tracker, claims,
  queue, decisions, version number and release notes are Meridian's own bookkeeping; BHW never edits them
  and is never asked to.
- Its own look. BHW keeps its own design.

## 8. URLs inside BHW

BHW is served under `/bhw-connect`. Links made with `next/link`, `router.push` and `redirect()` get the
prefix by themselves. A raw `<a href="/…">`, a `fetch("/…")` or a `window.location` change does not:
wrap it in `withBasePath()` from `apps/bhw-connect/lib/base-path.ts`. A test fails on the literal ones.

## 9. When the trunk breaks

If `main` is red because of your side, fix it forward or revert within the day. The other person may
revert your merge if it blocks theirs — and tells you.

## 10. Reachability

Be reachable within a working day for a label, a load, a secret or a rollback.
