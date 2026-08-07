# Meridian

A multi-tenant SaaS form-builder / data-collection platform (a hybrid of Kobo-style research
rigor and Fillout-style polish). This repository currently holds the **Phase 0 walking skeleton** —
the CI-green foundation every later feature sits inside. The full architecture and design set lives
in [`docs/`](docs/); current status and handoff notes live in [`PROGRESS.md`](PROGRESS.md).

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
cp .env.example .env
docker compose up -d --build            # app, web (nginx), postgres, redis, mailpit, node (vite)
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate:fresh --seed
```

- App: <http://localhost:8080>  ·  Mailpit UI: <http://localhost:8025>  ·  Vite/HMR: <http://localhost:5173>

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

# Frontend
npm run dev            # Vite dev server (or use the "node" compose service)
npm run build          # production assets
npm run type-check     # vue-tsc

# Design system
npm run ds:tokens              # regenerate design tokens (dist/tokens.css + tokens.ts)
npm run ds:storybook:build     # build the component library docs
npm run ds:test                # axe every story (WCAG 2.2 AA)
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

`.github/workflows/ci.yml` runs, per push/PR: static analysis + style + security (Pint, Larastan L8,
thin-controller gate, `composer audit` / `npm audit` / gitleaks) → Pest on real PostgreSQL → frontend
build & type-check → design-system axe (merge-blocking) → contract/e2e/deploy stages (present as stubs,
filled in by later Phase-0 increments).

## What's next (Phase 0 increments)

Tenancy + Row-Level Security → Auth + RBAC (Spatie teams, 5 roles) → design-system app shell →
`form_versions` draft/publish → OpenAPI scaffold. Then the deferred form-engine spike (ADR-0004).
See [`PROGRESS.md`](PROGRESS.md).
