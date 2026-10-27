# CLAUDE.md — BHW Connect

You are working on **BHW Connect**, a Next.js app that lives inside **Meridian's** repository at
`apps/bhw-connect/`. Meridian (the Laravel app at the repository root) belongs to another developer.
The rules in the root `CLAUDE.md` are Meridian's and **do not apply to you**; these do. The page both
developers agreed to is `docs/working-agreement.md` at the repository root — read it once.

## Your tree, and only your tree

- Edit only files under `apps/bhw-connect/`. A pull request that touches anything else — Meridian's
  code, `deploy.ps1`, `.github/` — fails CI's **boundary** step unless Meridian's owner approves it
  with the `boundary-approved` label. Ownership is `.github/CODEOWNERS`.
- Never edit Meridian's records: `PROGRESS.md`, `docs/`, `VERSION`, `database/data/releases.json`.
  BHW's own documents stay in `apps/bhw-connect/docs/`.
- Branch from `origin/main`, open a pull request into `main`. Never push to `main`.

## Testing and shipping (all of it works from a Claude session)

```
npm ci
npm run lint && npm run typecheck && npx vitest run
NEXT_PUBLIC_BASE_PATH=/bhw-connect BHW_DATA_SOURCE=none NEXT_PUBLIC_SUPABASE_URL=http://no-data-source.invalid \
  NEXT_PUBLIC_SUPABASE_ANON_KEY=x SUPABASE_SERVICE_ROLE_KEY=x npm run build
```

That last line is the build CI runs: under the prefix, with no database (`lib/db/data-plane.ts`).
Open the pull request; CI's `bhw-connect` job repeats all of it. When every check is green, merge
it yourself — a pull request that changes only `apps/bhw-connect/` merges whenever it is green.
The testing server then redeploys BHW alone, within minutes: check it at
`https://staging.pitahc.gov.ph/bhw-connect`. Staging is the preview. If a release is broken, open a
revert pull request, or ask Meridian's owner to run `ops/deploy-bhw.ps1 -Rollback` on the box
(seconds).

## The URL prefix

The app is served under `/bhw-connect`. `next/link`, `router.push`, `redirect()` and Next's own assets
get the prefix automatically. A raw `<a href="/…">`, a `fetch("/…")`, a `window.location` change, a
`new URL("/…", base)` redirect, or the URL of a file under `public/` does **not** — wrap it in
`withBasePath()` from `lib/base-path.ts`. `lib/base-path-census.test.ts` fails on any literal one you
miss; a root-relative string kept in a variable first is beyond it, so wrap where it is built.

## Database

- Migrations go in `supabase/migrations/<version>_<name>.sql` and are applied by `db/migrate.mjs`
  at deploy. **Additive only**: `db/lint-migrations.mjs` refuses DROP, TRUNCATE, DELETE FROM, a type
  change or a RENAME. If one must remove data, its first line is `-- bhw:destructive` and Meridian's
  owner must approve the pull request (the boundary step asks for it).
- Never commit data. `ingestion/data/` is out of git (see its README); a load file reaches the box
  privately and Meridian's owner loads it.
- A local database in the box's shape: `docker compose up -d` (see `docker-compose.yml`).

## Small things that bite

- Change `package-lock.json` with npm 10 (`npx npm@10 install …`): npm 11 rewrites entries the
  change did not touch.
- Secrets never go in git or in chat. A new one is a line in `.env.example` and a note in the pull
  request; Meridian's owner adds it on the box before the merge.
- Every page carries a strict Content-Security-Policy (`lib/csp.ts`): an inline `<script>` runs only
  with the request's nonce, and an external script or font origin must be added to the policy.
