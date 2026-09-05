# Meridian

A multi-tenant SaaS form-builder / data-collection platform (a hybrid of Kobo-style research
rigor and Fillout-style polish). Phases 0–3 have shipped: multi-tenancy on PostgreSQL row-level security,
RBAC, the custom form engine (builder, publish/versioning, conditional logic and branching), the public
offline-capable respondent runtime, manual encoding and review, webhooks and native connectors, analytics,
custom domains, tenant branding, SAML SSO and first-party Google sign-in, and a points/badges/streaks
engine. The full architecture and design set lives in [`docs/`](docs/); current status and handoff notes
live in [`PROGRESS.md`](PROGRESS.md), and [`docs/TESTING-GUIDE.md`](docs/TESTING-GUIDE.md) walks the whole
product by hand.

## Stack

Laravel 13 (PHP 8.4) · Inertia v2 + Vue 3 + TypeScript (admin) · PostgreSQL · Redis · Vite ·
`@meridian/design-system` (Style Dictionary tokens + Storybook + axe). A separate Vue 3 PWA for the
public form runtime arrives in Phase 1–2.

## Prerequisites

- **Docker Desktop with the WSL2 backend** — development happens *inside* the containers, which is the
  committed dev/CI/prod parity source. Laragon is only the docroot; do **not** use its bundled
  MySQL/PHP for this app (it defaults to MySQL; this project is PostgreSQL + RLS).
- Git. (PHP/Node/Composer on the host are optional — handy for editor tooling, not required to run.)

## Quick start

```bash
cp .env.example .env                    # ships CENTRAL_DOMAIN=localhost — see below, do not drop it
docker compose up -d --build            # app, web (nginx), postgres, redis, mailpit, node (vite)
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate:fresh --seed
```

- App: <http://localhost:8080>  ·  Mailpit UI: <http://localhost:8025>  ·  Vite/HMR: <http://localhost:5173>

`CENTRAL_DOMAIN` must match `APP_URL`'s **host** (no port). It is what makes `localhost:8080` the *central*
host — the super-admin console at `/admin/*`, the sign-in that is not a workspace, and the OAuth callbacks —
while workspaces are its subdomains. Point it somewhere else and every `/admin/*` URL 404s *after* a
successful sign-in, which looks like a broken build and is not.

`migrate:fresh --seed` builds the **demo fixture** — two workspaces at <http://demo.localhost:8080> and
<http://northwind.localhost:8080>, every role, six forms and ~90 days of submissions. Sign in as
`owner@demo.test` / `meridian-demo-2026`.

**To exercise the whole application by hand, follow [`docs/TESTING-GUIDE.md`](docs/TESTING-GUIDE.md)** — a
chapter per feature, with the URL, the account and the expected result for every step, plus a list of what is
deliberately not built yet.

### Enable the pre-push guard (one command, once per clone)

```bash
git config core.hooksPath .githooks     # or: composer run hooks:install
```

It refuses two pushes this project has already made by accident: work pushed before its claim is on
the trunk, and a `HEAD:main` carrying more than one commit — `git push origin HEAD:main` pushes the
**whole branch**, which is how a tracker surgery once reached the trunk with no squash merge.

⚠️ **It cannot enable itself.** `core.hooksPath` is local git configuration and a repository may not
turn on its own hooks, by design. `php scripts/preflight.php --lane=a` reports when it is missing, so
an unguarded clone says so at session open. `--no-verify` bypasses it deliberately: this guards
mistakes, not intent — the server-side control is the branch ruleset on `main`.

## Everyday commands

```bash
# Tests (Pest, against real PostgreSQL — never sqlite)
docker compose exec app php artisan test

# Quality gates (what CI enforces)
docker compose exec app composer run quality      # Pint + Larastan L8 + thin-controller gate
docker compose exec app composer run lint:fix     # auto-fix code style

# Frontend — inside the container, like everything else. The Windows host has no `rolldown` win32
# binding, so these fail on the host with a missing-native-module error rather than a useful one.
# (The "node" service already runs `npm run dev` for you; the rest are on demand.)
#
# ⛔ ON A FRESH CLONE OR WORKTREE, RUN THE TWO DESIGN-SYSTEM STEPS FIRST — `npm run build` CANNOT
#    BOOTSTRAP ONE, AND THE ORDER BELOW IS THE ORDER `ci.yml` USES. Both `resources/css/app.css` and
#    `resources/public-runtime/public-runtime.css` open with
#    `@import '@meridian/design-system/tokens.css'`, and that file is a BUILD ARTIFACT: `.gitignore`
#    excludes `/packages/*/dist`, so a tree you have just cloned does not contain it.
docker compose exec node npm run ds:install     # design-system deps — ONCE per clone (~4 min)
docker compose exec node npm run ds:tokens      # writes packages/design-system/dist/tokens.{css,ts}
docker compose exec node npm run build          # production assets
docker compose exec node npm run type-check     # vue-tsc

# Design system — ds:install/ds:tokens above are the bootstrap; re-run ds:tokens after editing tokens/*.json.
docker compose exec node npm run ds:storybook            # local preview -> http://localhost:6006
docker compose exec node npm run ds:storybook:build     # -> packages/design-system/storybook-static

# axe every story (WCAG 2.2 AA) — the merge-blocking gate, run the way ci.yml runs it.
#
# ⛔ NOT THE `ds:test` SCRIPT, AND NOT IN THE `node` SERVICE. Two independent reasons, both measured:
#    (1) `node` is node:24-alpine — musl — while Playwright ships a glibc Chromium, so the launch
#        fails `spawn ... ENOENT` WITH THE BINARY PRESENT AND EXECUTABLE, which reads as a missing
#        browser and is not one. Installing it there does NOT help: `playwright install` warns it is
#        downloading the ubuntu fallback build, and the scan still reports 0 of 42 suites.
#    (2) `ds:test` is a bare `test-storybook` with no `--url`, so it needs a server already
#        listening on :6006 and nothing here starts one.
#    So the scan is two images and not one line. Build above in `node`; scan below in `e2e`.
#
# ⚠️ `--maxWorkers` is local-only and not optional: jest defaults to one worker per core and the
#    container runs out of memory, which surfaces as `ENOMEM` in 34 of 42 suites — a load failure,
#    so the tests that DO run all pass and the tail looks survivable. CI needs no cap.
# ⚠️ The `e2e` service shares the app container's network namespace, so `app` must be running.
docker compose run --rm --entrypoint sh e2e -c 'cd packages/design-system && npx concurrently -k -s first "npx --yes http-server storybook-static -p 6006 --silent" "npx --yes wait-on tcp:127.0.0.1:6006 && npx test-storybook --url http://127.0.0.1:6006 --maxWorkers=2"'
```

> **If you skip either step the failure does not look like a missing step.** Skipping `ds:install`
> gives `Cannot find package 'style-dictionary'` from `build-tokens.mjs`. Skipping `ds:tokens` gives
> `Unable to resolve @import "@meridian/design-system/tokens.css"` — twice, once per stylesheet — and
> then **the service-worker build runs next and succeeds**, so the last line on your screen is
> `✓ built in 329ms` and a file size. **Trust the exit code (`1`), not the tail of the output.**
> (`@meridian/design-system` also exports `./tokens` → `dist/tokens.ts` from the same generated
> directory. Nothing imports it today, but it is the same artifact behind a second export path.)

> ⛔ **`ds:storybook:build` USED TO LIE ABOUT ITS EXIT CODE, AND WHAT FIXED IT IS ONE FLAG.** Against
> an incomplete tree it dies with `Cannot find module '@storybook/vue3-vite/preset'`; before
> `--disable-telemetry` it then **exited `0`** — and nothing "swallowed" the status, which is the part
> the old note got wrong. The crash-report prompt binds only a `keypress` listener, so on a non-TTY
> stdin it never settles, the rethrow is never reached, and Node exits on a drained event loop.

## Windows / Laragon gotchas

- **`DB_HOST=postgres`** (the Compose *service name*), never `127.0.0.1`, when running inside Docker.
- **Port conflicts:** Laragon's own MySQL/Apache may hold `5432`/`6379`/`1025`/`8025`. Stop Laragon's
  services or remap the host ports in `docker-compose.yml`.
- **Line endings:** `.gitattributes` forces LF so container entrypoints and Pint behave identically
  on Windows checkouts.
- **File-watch performance:** bind-mounting `C:\laragon\www\...` into Linux containers is slow for
  Vite/PHP watchers. Moving the repo into the WSL2 filesystem materially speeds this up.
- **Blank page / Vite HMR under Docker:** the dev server binds `0.0.0.0` so the published `5173`
  port works, but the browser can't connect to `0.0.0.0` (`net::ERR_ADDRESS_INVALID`). `vite.config.ts`
  sets `server.hmr.host=localhost` (browser-reachable) + `server.cors=true` (the app is served from a
  different origin, e.g. `acme.localhost:8080`) so the `public/hot` URL resolves. Don't revert those.
- **Lockfile drift (`name: "html"`):** `package.json` has no `name` field, so `npm install` **inside the
  container** (`/var/www/html`) rewrites `package-lock.json`'s `name` to `html` plus peer/optional-dep
  churn. That diff is environmental noise — `git restore package-lock.json` it; don't commit it.
- **Playwright / e2e** must run in Linux, not against the Windows-installed browsers — and until M19
  this line asserted that as a fact rather than telling you how, which is worse than saying nothing.
  It was **false**: nothing in the repo could run Chromium on Linux, `npm run e2e` used the Windows
  browsers, and the two do not agree. Four real defects were quarantined in
  `tests/e2e/support/axe.ts` as *"does not reproduce on a Windows host"* when what could not
  reproduce was the **font stack**. There is now a runner, behind a compose profile:

  ```bash
  # 1. Serve BUILT, same-origin assets — the suite measures the wrong fonts otherwise (see below).
  #    On a fresh clone, `npm run ds:install` comes first — see "Everyday commands" above for why.
  docker compose exec node npm run ds:tokens && docker compose exec node npm run build
  rm -f public/hot                      # `npm run dev` recreates it when you want Vite back

  # 2. Run any spec, any project. Everything after `test` is passed straight to Playwright.
  docker compose run --rm e2e test tests/e2e/responsive-axe.spec.ts --project=tablet
  ```

  ⚠️ **Both steps matter and both fail silently.** With `public/hot` present the stylesheet is served
  from `:5173` while the document is on `:8080`, so the `/fonts/*.woff2` request is cross-origin,
  `artisan serve` sends no CORS header, and OpenDyslexic never loads — while `document.fonts.ready`
  resolves perfectly happily, so every personalization scan quietly measures the fallback face.
  And `system-ui` resolves to Segoe UI Variable Display on Windows against DejaVu Sans on a CI
  runner, which is **~27% wider** for the same string: 256px against 324px for one page title.
  `docker/e2e/Dockerfile` installs `fonts-dejavu-core` for exactly that reason — the Playwright base
  image ships the `--with-deps` font set, which does **not** include DejaVu.

## CI

`.github/workflows/ci.yml` runs six merge-blocking jobs per push/PR: static analysis + style + security
(Pint, Larastan L8, thin-controller gate, `composer audit` / `npm audit` / gitleaks) → Pest on real
PostgreSQL → frontend build & type-check → design-system axe → the OpenAPI contract check → Playwright
end-to-end. All six are real; none is a stub.

`deploy.yml` is a self-hosted Windows Server job that stays dormant until the repository variable
`DEPLOY_ENABLED` is set to `true` (ADR-0005). Nothing deploys on merge until somebody turns it on.

## What's next

The remaining work needs inputs this repository cannot supply itself: OCR (sample filled forms plus ground
truth), file uploads and bulk import, payments (a Stripe account), the production Windows Server host, and
the GDPR/legal/pricing decisions. Live third-party credentials — Google OAuth, Sheets, Airtable — are
deployment inputs rather than scope; every path behind them is already built and tested against a fake.
See [`PROGRESS.md`](PROGRESS.md).
