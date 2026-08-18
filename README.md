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
docker compose exec node npm run build          # production assets
docker compose exec node npm run type-check     # vue-tsc

# Design system
docker compose exec node npm run ds:tokens              # regenerate tokens (dist/tokens.css + tokens.ts)
docker compose exec node npm run ds:storybook:build     # build the component library docs
docker compose exec node npm run ds:test                # axe every story (WCAG 2.2 AA)
```

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
- **Playwright / e2e** run in Linux (containers / CI), not against Windows-installed browsers, so
  local and CI results match.

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
