# Deployment & Infrastructure Doc

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v2.0 — operationalizes **`docs/adr/0005-hosting-self-hosted-windows-server.md`** (self-hosted on Windows Server 2016), which **supersedes ADR-0003** (Laravel Cloud). This document fills in the platform-specific operational detail: environments, the git-driven deploy pipeline, secrets, the self-managed Postgres backup/DR runbook, queue/worker supervision, and the Windows service topology. It also operationalizes `docs/non-functional-requirements.md` §2/§8's availability & durability targets — now self-managed.

---

## 1. What's Already Decided (not repeated in full)

Per **ADR-0005**, production runs on the owner's **Windows Server 2016**: **nginx** (web server) → PHP 8.4 FastCGI, **self-managed PostgreSQL for Windows**, **Redis via Memurai**, Horizon/Reverb as Windows services, the scheduler via Windows Task Scheduler, TLS via win-acme, and **git-driven deploys through a self-hosted GitHub Actions runner**. Docker Compose remains the source of truth for local-dev/CI parity regardless of the production host; the CI pipeline stages are in `docs/testing-strategy.md` §6.

**No open hosting due-diligence item.** Because Postgres is now self-managed, Row-Level Security and PostGIS are **guaranteed** available (install the `postgis` extension when Phase 2 geo fields land) — the ADR-0003 "confirm the managed platform supports RLS/PostGIS" question is eliminated, not deferred.

**Honest posture note (ADR-0005).** A single self-hosted box is a single point of failure, and the team owns all ops (patching, backups, TLS, service supervision). The 99.5% Phase-1 uptime target is self-managed; revisit redundancy before committing SLAs to paying customers, and plan a Server-OS upgrade before Windows Server 2016 end-of-support (~Jan 2027).

---

## 2. Environments

| Environment | Purpose | Infrastructure |
|---|---|---|
| **Local development** | Individual dev machines | Docker Compose (Laravel, Postgres, Redis, Mailpit) — the committed parity source. Native PHP + Postgres on Windows/Laragon is an accepted alternative for developers who don't run Docker. |
| **CI** | Automated tests (`docs/testing-strategy.md`) | GitHub-hosted **Linux** runners with Postgres/Redis service containers pinned to the same majors as production. (Note the Linux-CI vs Windows-prod parity gap, ADR-0005.) |
| **Staging** *(recommended)* | Pre-production validation | A second site on the same Windows Server (separate nginx server block + separate database, e.g. `meridian_staging`), or a separate box — isolated synthetic/seeded data, never real respondent PII (`docs/data-privacy-gdpr-compliance.md`). |
| **Production** | Live tenant traffic | **Self-hosted Windows Server 2016**, per ADR-0005. |

**Promotion path**: `main` is the deployable branch. A green CI run on `main` triggers the deploy workflow (self-hosted runner) → production. If a staging site is configured, validate there first and promote to production as a deliberate act, given the blast radius against live tenant data.

---

## 3. CI/CD — the git-driven Deploy Pipeline

CI (test/build) is unchanged (`docs/testing-strategy.md` §6, Linux runners). **Deployment** is a separate workflow (`.github/workflows/deploy.yml`) that runs on the **self-hosted GitHub Actions runner installed on the Windows Server**:

1. **Trigger**: `on: workflow_run` after the **CI** workflow completes **successfully** on `main` (nothing deploys unless every gate is green). `runs-on: [self-hosted, windows]`.
2. **Action**: the workflow invokes **`deploy.ps1`** (committed at the repo root) against the live application directory. `deploy.ps1`:
   - `git fetch --all --prune` and `git reset --hard origin/main` on the live checkout (deterministic — the server matches the remote exactly),
   - `composer install --no-dev --optimize-autoloader`,
   - `npm ci` + design-system deps + `npm run ds:tokens` + `npm run build` (compiled assets),
   - `php artisan down` (brief maintenance window — see §3.1),
   - `php artisan migrate --force` (backward-compatible / additive-first migrations, so old code tolerates the new schema),
   - `php artisan config:cache route:cache view:cache event:cache`,
   - restart the worker and Reverb Windows services (so workers/realtime run the new code). **Correction (2026-07-21):** this step previously claimed a `horizon:terminate` call for a graceful worker cycle. `deploy.ps1` does **not** run it — it only iterates `meridian-horizon`/`meridian-reverb` behind a `Get-Service … -ErrorAction SilentlyContinue` guard (`:49-54`), which is a silent no-op on a box that has neither service. Per **ADR-0007 §D1** there is no Horizon; the graceful equivalent for a plain `queue:work` worker is `php artisan queue:restart`, which must be added to `deploy.ps1` when the worker service is actually provisioned (§8),
   - `php artisan up`.
3. **Rollback**: `git reset --hard <previous-good-sha>` then re-run `deploy.ps1` (rebuilds + re-caches). Because migrations are additive-first, a code rollback does not require a down-migration in the incident hot path; a schema reversal, if ever needed, is a separate deliberate step.

### 3.1 On zero-downtime (an honest limitation)
Single-box Windows self-hosting has **no managed zero-downtime deploy**. `deploy.ps1` uses a brief `artisan down`/`up` window around migrate + cache (typically seconds). True zero-downtime (atomic release-swap, opcache priming) is a future enhancement — or a reason to move to Forge+VPS / Laravel Cloud (ADR-0005 revisit triggers) — not something a single Windows box provides out of the box.

---

## 4. Secrets Management
- **Environment secrets** (DB credentials, Stripe keys, the OCR-provider key, mail credentials, `APP_KEY`) live in the server's **`.env` file on the Windows Server**, git-ignored — created and maintained on the server by hand, never committed. `deploy.ps1` never overwrites `.env`.
- **Committed-secret prevention**: `gitleaks` runs in CI (stage 1) as a backstop against a credential ever reaching the repository.
- **Per-tenant secrets** (webhook signing secrets) are application data — encrypted in the database (Laravel encrypted cast, `docs/data-dictionary.md` §14), a distinct concern from the server `.env`.
- **Rotation**: server secrets rotated annually at minimum and immediately on suspected compromise (a manual runbook step; no automated rotation in Phase 1).

---

## 5. PostgreSQL Backup & Disaster-Recovery Runbook (self-managed)

Operationalizes `docs/non-functional-requirements.md` §2 (RTO 4h / RPO 15min Phase 1) and §8 (continuous WAL + daily snapshot, 30-day retention) — now on the self-managed Windows Postgres:

1. **Daily full backup**: `pg_dump` (custom format) of each database via a **Windows Task Scheduler** job, written to a backup volume and **copied off-box** (a second disk / NAS / offsite target — a backup on the same box is not disaster recovery).
2. **Continuous WAL archiving for PITR**: `archive_mode = on` with an `archive_command` that copies WAL segments to the off-box target — this gives point-in-time recovery within the retention window and is what makes the 15-min RPO achievable (fully under our control now).
3. **Retention**: 30 days rolling (prune old dumps/WAL in the same scheduled job).
4. **Restore drills**: a full restore to a fresh instance **quarterly** — a backup never test-restored is not a verified backup.
5. **Incident procedure** (skeleton; full runbook is Doc #23): declare → assess scope → restore from the latest clean dump + replay WAL to the target PITR point → verify integrity against the audit trail (`docs/audit-compliance-logging-spec.md`) → post-incident review.
6. **RTO/RPO ownership**: if a drill shows the achievable RTO/RPO misses the NFR targets, that's a finding to feed back into the NFR doc or this runbook — not a silent gap.

---

## 6. Queue & Worker Supervision (Windows services)

> **Revised by [ADR-0007](adr/0007-async-execution-substrate.md) (2026-07-21).** The queue runs on the **`database` driver over PostgreSQL**, not Redis/Memurai, and is drained by a plain **`queue:work`** process, not Horizon (ADR-0005's queue rows are superseded in part). Nothing in this section is provisioned on the box today.

- **Queue names — now binding on code, not just prose.** The five names are `submissions` and `webhooks` highest (user-facing / near-real-time); `exports` and `ocr-processing` medium (a pending state is shown); `scheduled-maintenance` (usage rollups, retention purges) lowest. Per **ADR-0007 §D6** every job MUST declare `$queue` from this set, `default` is only the un-annotated fallback, and priority is expressed as the `--queue=` ordering string in the `queue:work` invocation until a supervisor with real per-queue weighting exists. *(Before ADR-0007 this catalog was documented as binding while `onQueue(` had zero hits repo-wide.)*
- **Process supervision**: `php artisan queue:work --queue=submissions,webhooks,exports,ocr-processing,scheduled-maintenance` as a **Windows service via NSSM**, auto-restarted on crash. Scaling is **manual** on a single box (more worker processes / a bigger box) — there is no managed autoscaling; capacity is a deliberate operational choice, and a scale-out need is one of ADR-0005's revisit triggers *and* ADR-0007 §D1's trigger for adopting Redis + Horizon.
- **Per-tenant fairness** (closes `docs/architecture/technical-architecture.md` Risk R6, **now RESOLVED**): jobs are tagged with `tenant_id` (required on every job payload, ADR-0002 §D3, asserted at runtime by ADR-0007 §D2); the per-tenant job-rate ceiling is **`RateLimited` job middleware** keyed `tenant:{id}:queue:{name}` (**ADR-0007 §D9**), counting job executions started per tenant per queue per minute, so one tenant's bulk OCR/export burst is *deferred* rather than rejected and cannot starve other tenants' queued work. Ceilings live in `config/queue-fairness.php` and are **unvalidated planning assumptions** until real traffic exists.
- **Not provisioned.** `deploy.ps1` creates no worker service and restarts only the non-existent `meridian-horizon`/`meridian-reverb`; see §8.

---

## 7. Object Storage
- **Backing store**: **local disk** on the Windows Server initially (a dedicated data volume), addressed through Laravel's filesystem/`attachments` abstraction so an S3-compatible target (MinIO on the box, or external S3) is a config-only swap later — no code change.
- **Layout**: `tenants/{tenant_id}/{category}/...` where `{category}` mirrors `docs/data-dictionary.md` §10's `AttachmentKind` values — a predictable, auditable layout that makes per-tenant storage accounting (`usage_counters.storage_bytes`) a prefix query.
- **Lifecycle**: `export_artifact` objects auto-deleted **7 days** after generation (a convenience download, not a durable record); every other kind persists per the ordinary retention rules. On local disk this is a scheduled cleanup task; on S3 it's a bucket lifecycle rule.

---

## 8. Windows Server Setup Runbook (one-time topology)

The concrete pieces to stand the box up (companion to `deploy.ps1`):

1. **PHP 8.4** — install the VC++ 2015–2022 runtime; enable `pdo_pgsql`, `redis`, `intl`, `zip`, `bcmath`, `openssl`, `mbstring`, `fileinfo` in `php.ini`; production `php.ini` (opcache on, `display_errors=Off`).
2. **PostgreSQL for Windows** (EDB) — install as a Windows service; create the `meridian` database and a **non-superuser** `meridian_app` login role. RLS does **not** apply to superusers, so the app must NOT connect as one — this is a hard requirement for the tenancy/RLS work (Increment A). Configure `archive_mode`/`archive_command` (§5).
3. **Memurai** (Redis-compatible) — install as a Windows service; bind to localhost.
4. **nginx for Windows** — server block: `root` → the app's `public/`; `try_files $uri $uri/ /index.php?$query_string`; `fastcgi_pass` to the php-cgi backend; a WebSocket `location` proxying to Reverb; run nginx as a Windows service via **NSSM**.
5. **php-cgi FastCGI backend** — one or more `php-cgi.exe -b 127.0.0.1:9000` instances as Windows services via NSSM (auto-restart). *If php-cgi supervision proves fragile under load, switch to **IIS + the PHP FastCGI module** — the documented fallback (ADR-0005).*
6. **Queue worker & Reverb** — `php artisan queue:work` (with the §6 `--queue=` ordering; **not** Horizon, per ADR-0007 §D1) and `php artisan reverb:start`, each a Windows service via NSSM. **Gap: `deploy.ps1` does not create this worker service** — it only restarts `meridian-horizon`/`meridian-reverb` if they happen to exist, so provisioning it is a manual step of this runbook, and `php artisan queue:restart` should be added to the deploy script at the same time (§3).
7. **Scheduler** — a Windows Task Scheduler task running `php artisan schedule:run` every minute (Windows has no cron). **The application-side half now EXISTS** (H2, 2026-07-22): `routes/console.php` declares `Schedule::job(PruneFailedJobsJob::class)->dailyAt('03:10')` per ADR-0007, and locally a `scheduler` compose service runs `schedule:work`. **Gap, narrowed to the host side: `deploy.ps1` still provisions no Task Scheduler task**, so provisioning it remains a manual step of this runbook. Until it exists, nothing periodic runs in production — the declarations are there, but nothing ticks them. *Never create `app/Console/Kernel.php`*: `Kernel::shouldDiscoverCommands()` is `get_class($this) === __CLASS__`, so any console-kernel subclass silently stops `routes/console.php` loading and every schedule disappears with no error.
8. **TLS** — **win-acme** for a Let's Encrypt certificate (auto-renew via its scheduled task); terminate TLS at nginx.
9. **GitHub Actions self-hosted runner** — register against the repo and install as a Windows service (`config.cmd` → run as service). It executes the deploy workflow (§3). Then set the repo **Variables** (Settings → Secrets and variables → Actions → Variables): **`MERIDIAN_APP_PATH`** (e.g. `C:\meridian\app`) and **`DEPLOY_ENABLED=true`**. Until `DEPLOY_ENABLED` is `true`, `.github/workflows/deploy.yml` stays dormant (skipped) — so it can be committed safely before the runner exists.
10. **App directory** — a git clone at a fixed path (e.g. `C:\meridian\app`) whose `public/` is nginx's root; create the server `.env` (§4, DB host `127.0.0.1`, real secrets); run `deploy.ps1` once to prime it.

---

## 9. Out of Scope / Deferred
- Metrics, dashboards, alerting, on-call → Doc #23 (this doc's §5.5 incident skeleton points there).
- Automated zero-downtime release-swap on Windows → future enhancement (§3.1).
- A second node / managed failover for higher availability → revisit before paid-customer SLAs (ADR-0005).
- Plan-tier-specific infrastructure quotas → Doc #24.
