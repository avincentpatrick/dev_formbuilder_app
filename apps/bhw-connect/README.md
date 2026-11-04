# BHW Connect Dashboard

> **This app now lives in Meridian's repository**, at `apps/bhw-connect/`, and is served at
> `https://staging.pitahc.gov.ph/bhw-connect` from Meridian's testing server. Read
> [`docs/working-agreement.md`](../../docs/working-agreement.md) (at the repository root) and this
> app's [`CLAUDE.md`](CLAUDE.md) first. Its history before the move is in the archived repository
> <https://github.com/jongsky25/BHW-Connect-Dashboard>, imported here at commit `e4237373`. Its source
> data (`ingestion/data/`) is no longer in git: see [`ingestion/data/README.md`](ingestion/data/README.md).

A public, open-access dashboard for the Philippine Barangay Health Worker (BHW) dataset — built for both lay and technical audiences, with WHO WPSAR-style figures, cascading geographic filters (national → region → province → city/municipality → barangay), interactive maps, purpose-built downloads (CSV/XLSX/PNG/PPTX), and (Phase 2) strictly data-grounded AI insights and chat.

**Status:** implementation in progress — Phase 0 (foundation).

## Repository contents

| Path                             | What it is                                                                                                                                                                                 |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `ingestion/data/dataset.parquet` | Source data: 270,917 BHW records × 130 columns (2025 snapshot)                                                                                                                             |
| `docs/BUILD_PLAN.md`             | **The build reference** — approved architecture, phased/incremental roadmap, engineering standards, pitfall register, and launch checklist. All implementation work follows this document. |
| `docs/DECISIONS.md`              | Dated log of deviations from the build plan and implementation judgment calls.                                                                                                             |
| `app/`, `lib/`, `components/`    | Next.js App Router application (TypeScript strict, Tailwind).                                                                                                                              |
| `ingestion/`                     | Python ingestion pipeline (parquet → Supabase Postgres → aggregates); run locally/scripted, not deployed.                                                                                  |
| `supabase/migrations/`           | SQL schema migrations.                                                                                                                                                                     |

## Development

```bash
npm install
npm run dev        # http://localhost:3000
npm run lint
npm run typecheck
npm test
```

With a database, in the testing box's shape (PostgreSQL 15 with pgvector, and PostgREST), built from the
committed development copy: the live schema in `db/baseline/`, and a made-up seed in `supabase/seed.sql`
(real geography, every count and every person generated; real data stays on the testing server):

```bash
docker compose up -d
BHW_DEV_DATABASE_URL=postgres://postgres:bhw-local@127.0.0.1:5442/bhw_connect node db/load-dev.mjs
```

Then set `.env.local` from `.env.example`'s local section, with keys from `node db/mint-jwt.mjs`. The copy
is refreshed from the testing server by the **BHW Connect dev copy** workflow. The live site is
https://staging.pitahc.gov.ph/bhw-connect.

## Key commitments

- **Free-tier only** hosting (Vercel Hobby + Supabase Free) and AI (multi-provider free tiers with auto-pause and graceful degradation — the dashboard never depends on AI availability).
- **Privacy by design:** small-cell suppression (n < 5) for individual-level breakdowns at barangay level; anonymized usage logging; RA 10173-aware privacy notice.
- **Open data:** published aggregates and downloads licensed under CC BY 4.0.
