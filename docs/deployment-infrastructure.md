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

### 4.1 Third-party OAuth clients that must be registered by hand

Two Google clients exist and they are **not** interchangeable. Registering one set of credentials in both
places is the mistake this section exists to prevent — they have different consent screens, different
scopes and very different blast radii.

| Purpose | `.env` keys | Redirect URI to register | If unset |
|---|---|---|---|
| **First-party sign-in** (J3c2 / ADR-0017) | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | `https://<CENTRAL_DOMAIN>/auth/google/callback` | "Continue with Google" does not render and the flow cannot be started — a **supported** state, see the note below |
| **Google Sheets connector** (H16a / ADR-0009) | `GOOGLE_CONNECTOR_CLIENT_ID`, `GOOGLE_CONNECTOR_CLIENT_SECRET` | `https://<CENTRAL_DOMAIN>/oauth/google_sheets/callback` | the connector cannot be connected; the consent screen refuses |

⚠️ **"Unconfigured" closes the DOOR, not all three routes, and an earlier draft of this table said
otherwise.** Only `GET /auth/google/redirect` consults `GoogleSignInGate` and 404s. The callback and the
completion hop stay **registered and reachable**, and answer the same closed `?google=failed` bounce every
other refusal produces — the callback because its `state` cannot verify, the completion hop because its
handoff matches no row. That is harmless (neither can mint a session without a row this deployment never
wrote) but it is not absence, and an operator auditing the surface deserves the true statement rather than
the reassuring one. The distinction was found by an adversarial review after CI was green.

**Sign-in client — the console settings this app cannot detect the absence of.**

- Publish exactly `openid`, `email` and `profile`. Nothing else is requested: a sign-in asking for Drive
  scopes would *be* a connector and would fall under ADR-0009's token-custody rules in full.
- Set the user type to **External** unless every expected user is inside your own Workspace organisation.
- ⚠️ **ONE redirect URI serves every workspace.** Google rejects wildcard redirect URIs for an identity
  client exactly as it does for an API one, which is why the callback lands on the central host and
  carries the workspace inside a signed `state` rather than in a session. Do not attempt to register
  per-tenant subdomains.
- ⚠️ **The URI is DERIVED from `APP_URL`, not configured separately.** Google matches it byte for byte
  between the authorize step and the token exchange, so a third environment variable would be a third
  chance to get it wrong — and wrong only in production, where the host differs from a developer's. If
  `APP_URL` is not the central host with the correct scheme and port, sign-in fails with
  `redirect_uri_mismatch` and nothing else.

**Key rotation, and the one difference from the connector lane.** The sign-in `state` is signed with a key
derived from `APP_KEY` with a domain separator, so an `APP_KEY` rotation invalidates every consent screen
currently open — a **~10-minute** window, and the person simply presses the button again. That is a far
milder consequence than the connector lane's, where §4's `APP_PREVIOUS_KEYS` gap means a rotation would
make stored connector tokens undecryptable (still open, recorded in the threat model §9.8). Nothing about
Google sign-in is stored encrypted, because nothing about it is retained: the flow reads an identity once
and discards the token. `GOOGLE_SIGNIN_STATE_KEY` exists only to rotate that one family independently.

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

- **Queue names — now binding on code, not just prose.** The six names are `submissions` and `webhooks` highest (user-facing / near-real-time); `mail` (transactional email — a person is waiting on an invite/reset, H3); `exports` and `ocr-processing` medium (a pending state is shown); `scheduled-maintenance` (usage rollups, retention purges) lowest. Per **ADR-0007 §D6** every job MUST declare `$queue` from this set, `default` is only the un-annotated fallback, and priority is expressed as the `--queue=` ordering string in the `queue:work` invocation until a supervisor with real per-queue weighting exists. *(Before ADR-0007 this catalog was documented as binding while `onQueue(` had zero hits repo-wide.)*
- **Process supervision**: `php artisan queue:work --queue=submissions,webhooks,mail,exports,ocr-processing,scheduled-maintenance` as a **Windows service via NSSM**, auto-restarted on crash. Scaling is **manual** on a single box (more worker processes / a bigger box) — there is no managed autoscaling; capacity is a deliberate operational choice, and a scale-out need is one of ADR-0005's revisit triggers *and* ADR-0007 §D1's trigger for adopting Redis + Horizon.
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
8. **TLS** — **win-acme** for a Let's Encrypt certificate (auto-renew via its scheduled task); terminate TLS at nginx. Covers the central host and the tenant-subdomain wildcard. **Tenant custom domains are NOT covered by this step and are not automated — see §8.1.**
9. **GitHub Actions self-hosted runner** — register against the repo and install as a Windows service (`config.cmd` → run as service). It executes the deploy workflow (§3). Then set the repo **Variables** (Settings → Secrets and variables → Actions → Variables): **`MERIDIAN_APP_PATH`** (e.g. `C:\meridian\app`) and **`DEPLOY_ENABLED=true`**. Until `DEPLOY_ENABLED` is `true`, `.github/workflows/deploy.yml` stays dormant (skipped) — so it can be committed safely before the runner exists.
10. **App directory** — a git clone at a fixed path (e.g. `C:\meridian\app`) whose `public/` is nginx's root; create the server `.env` (§4, DB host `127.0.0.1`, real secrets); run `deploy.ps1` once to prime it.

### 8.1 Custom-domain certificates — the manual runbook (H22a / ADR-0012)

A tenant on the Business tier can point its own hostname (`forms.acme.com`) at this box. **Per-domain
certificate issuance is not automated, and until it is, nothing a tenant does can put its hostname into
service.** That is enforced in the application, not by convention: `activated_at` on `domains` can only
be set by the artisan command below, and until it is set the host resolves to no tenant and appears in no
link a respondent receives.

The ordering below is the whole point of choosing a TXT record as the proof of control rather than a
CNAME: the tenant proves ownership **out of band**, we install the certificate, and the tenant repoints
live traffic **last** — so there is no window in which their traffic arrives here and we cannot serve it.

1. **The tenant claims the domain** — on the **`/domains` page** (H22b), or `POST /api/v1/domains` — and
   publishes the TXT record it is shown: `_meridian-challenge.forms.acme.com` →
   `meridian-domain-verification=<token>`. The page shows that record on every state, so a tenant who lost
   it at the registrar can copy it again without a support conversation.
2. **Verification happens on its own** — on demand via the page's **Check DNS** button or
   `POST /api/v1/domains/{domain}/verify`, or within fifteen minutes from `VerifyCustomDomainsJob`. The
   domain reaches `verified`. **It still serves nothing.** An unverified or verified-but-not-activated
   domain is invisible to tenant resolution. The page labels this state **"Awaiting setup"** rather than
   "Verified", and says in words that the next step is ours — so a tenant does not repoint live traffic
   here on the strength of a green tick.
3. **Add the hostname to the nginx server block** for the app's `public/` root. It must be a `server_name`
   on the same block that serves tenant subdomains — the application distinguishes hosts, nginx does not
   need to.
4. **Issue the certificate by hand**, for that hostname only:
   `wacs.exe --target manual --host forms.acme.com --installation iis` (or the nginx/script installer this
   box uses). win-acme will register its own renewal task for it. **The tenant's DNS must already point
   here for the HTTP-01 challenge to succeed** — so in practice steps 3-4 are done together with the
   tenant, in a scheduled window.
5. **Reload nginx** and confirm the certificate serves: `curl -sSI https://forms.acme.com/` .
6. **Only now, activate:** `php artisan domains:activate forms.acme.com`. The command refuses a domain
   that is not verified, and prints the TXT record still needed if so. `--deactivate` takes a host back
   out of service without losing its verification, so re-activating later needs no new DNS record.
   **If this is the tenant's first live custom domain the command also makes it primary**, so respondent
   links move to it without the tenant having to choose between one host and none; a second activation
   leaves the existing primary alone, because repointing outstanding links is the tenant's call to make on
   `/domains` (H22b).
7. **Removing a domain**: `php artisan domains:activate <host> --deactivate` first (routing stops
   immediately, and the primary flag is cleared — `domains_primary_requires_live_chk` requires that, and
   respondent links fall back to the next-oldest live domain or to the tenant's subdomain), then let the
   tenant release it from `/domains` or through the API. Retire the win-acme renewal and the nginx
   `server_name` afterwards, in that order.

**Fail-closed properties worth knowing before you deviate.** A domain whose DNS later lapses keeps
serving — the sweep records the failure but does not withdraw routing, because a transient resolver
outage must not take a paying tenant's forms offline, and the globally-unique `domains.domain` prevents a
new owner of the lapsed name from claiming the row. A domain that has never been activated is unreachable
no matter what nginx is configured to do, because tenant resolution will not match it.

**When Track B automates issuance**, this section is what gets deleted, and ADR-0012's *When to Revisit*
records what else changes with it (the operator gate can become an API action, and an
N-consecutive-failures demotion becomes worth building).

---

## 9. Out of Scope / Deferred
- Metrics, dashboards, alerting, on-call → Doc #23 (this doc's §5.5 incident skeleton points there).
- Automated zero-downtime release-swap on Windows → future enhancement (§3.1).
- A second node / managed failover for higher availability → revisit before paid-customer SLAs (ADR-0005).
- Plan-tier-specific infrastructure quotas → Doc #24.
