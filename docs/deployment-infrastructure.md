# Deployment & Infrastructure Doc

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v2.0 — operationalizes **`docs/adr/0005-hosting-self-hosted-windows-server.md`** (self-hosted on Windows Server 2016), which **supersedes ADR-0003** (Laravel Cloud). This document fills in the platform-specific operational detail: environments, the git-driven deploy pipeline, secrets, the self-managed Postgres backup/DR runbook, queue/worker supervision, and the Windows service topology. It also operationalizes `docs/non-functional-requirements.md` §2/§8's availability & durability targets — now self-managed.

---

## 1. What's Already Decided (not repeated in full)

Per **ADR-0005**, production runs on the owner's **Windows Server 2016**: a **web server** — ADR-0005 decided **nginx**, and the testing site behind `staging.pitahc.gov.ph` runs **Apache 2.4 with `mod_proxy_fcgi`** under `D46` (§8.3) — → PHP 8.4 FastCGI, **self-managed PostgreSQL for Windows** (a dedicated instance per site; the testing site runs PostgreSQL 15, §8 step 2), **no Redis** (ADR-0007 moved the queue to the `database` driver and cache and sessions use it too, so ADR-0005's Memurai is not installed — it returns only with Redis + Horizon), exactly one `queue:work` worker as a Windows service, the scheduler via Windows Task Scheduler, TLS by **ACME** (win-acme on an nginx host, Apache's own `mod_md` on the testing site — §8 step 8), and **git-driven deploys through a self-hosted GitHub Actions runner**. ⚠️ **This sentence listed "Horizon/Reverb as Windows services" until Increment M46 (2026-08-29): neither is built.** Horizon was declined by ADR-0007 §D1 in favour of a plain worker; Reverb is Track B and ships no artisan command in this tree, so a service pointing at it cannot start. ⚠️ **And it said "TLS via win-acme" without qualification until `M100` (2026-09-19), which is the web-server half of the same defect.** Docker Compose remains the source of truth for local-dev/CI parity regardless of the production host; the CI pipeline stages are in `docs/testing-strategy.md` §6.

**No open hosting due-diligence item.** Because Postgres is now self-managed, Row-Level Security and PostGIS are **guaranteed** available — but the PostGIS binaries must be installed **before the first migrate**, because a migration already runs `CREATE EXTENSION postgis` (§8 step 2) — and the ADR-0003 "confirm the managed platform supports RLS/PostGIS" question is eliminated, not deferred.

**Honest posture note (ADR-0005).** A single self-hosted box is a single point of failure, and the team owns all ops (patching, backups, TLS, service supervision). The 99.5% Phase-1 uptime target is self-managed; revisit redundancy before committing SLAs to paying customers, and plan a Server-OS upgrade before Windows Server 2016 end-of-support (~Jan 2027).

---

## 2. Environments

| Environment | Purpose | Infrastructure |
|---|---|---|
| **Local development** | Individual dev machines | Docker Compose (Laravel, Postgres, Redis, Mailpit) — the committed parity source. Native PHP + Postgres on Windows/Laragon is an accepted alternative for developers who don't run Docker. |
| **CI** | Automated tests (`docs/testing-strategy.md`) | GitHub-hosted **Linux** runners with PostgreSQL 17 + PostGIS 3.5 and Redis service containers. (Note the Linux-CI vs Windows-prod parity gap, ADR-0005, and a version gap too: the testing site runs PostgreSQL 15, §8 step 2.) |
| **Staging** — **built, and it is the testing site** | Pre-production validation and tester access | **`staging.pitahc.gov.ph` on the owner's Windows Server 2016, built 2026-09-17 under `D46`** — one workspace at the root, served by **Apache** (not a second nginx server block), its own **PostgreSQL 15** instance, worker service `meridian-test-worker` and scheduler task, app at `C:\meridian\test-app`. Isolated synthetic/seeded data, never real respondent PII (`docs/data-privacy-gdpr-compliance.md`). §8.3 is its runbook; §8 step 2 says why a separate database on a shared instance is not enough. |
| **Production** | Live tenant traffic | **Self-hosted Windows Server 2016**, per ADR-0005. |

**Promotion path**: `main` is the deployable branch. A green CI run on `main` triggers the deploy workflow (self-hosted runner) → production. If a staging site is configured, validate there first and promote to production as a deliberate act, given the blast radius against live tenant data.

---

## 3. CI/CD — the git-driven Deploy Pipeline

CI (test/build) is unchanged (`docs/testing-strategy.md` §6, Linux runners). **Deployment** is a separate workflow (`.github/workflows/deploy.yml`) that runs on the **self-hosted GitHub Actions runner installed on the Windows Server**:

1. **Trigger**: `on: workflow_run` after the **CI** workflow completes **successfully** on `main` (nothing deploys unless every gate is green). `runs-on: self-hosted` — the Windows Server's runner is the only self-hosted one, so the workflow deploys exactly **one** site.
2. **Action**: the workflow invokes **`deploy.ps1`** (committed at the repo root) against the live application directory. Every native step **fails fast**: a non-zero exit stops the script and turns the run red. It first refuses a host where the worker service named by `MERIDIAN_WORKER_SERVICE` (default `meridian-worker`) is not installed, or where `<app path>\.deploy-stage` exists but is not its own git worktree, then:
   - `git fetch`, then `-Ref`, which defaults to `origin/<branch>` (`main`), resolved to one commit — and **nothing more** when that commit is already live, the site is up and `storage\framework\deployed-sha` names it, so a CI run that changed nothing opens no window,
   - **and no window either when every path changed since the deployed commit is one the site does not load** — `docs/`, `tests/`, `scripts/`, `.github/` and the four top-level markdown files — in which case the checkout is fast-forwarded, `storage\framework\deployed-sha` is rewritten, and the site is never taken down. This is what stops an increment's close-out push, which regenerates `docs/pipeline.md`, from interrupting testers to ship a markdown file. It denies by default: one unrecognised path, one unreadable diff, a live build that is missing or a checkout that is not where `deployed-sha` says, and the full deploy runs. `tests/Feature/Deploy/DeploySkipAllowlistTest.php` pins the list's shape from both sides,
   - **with the site still up**, `git worktree prune` and that commit checked out into `<app path>\.deploy-stage` (created on the first run, inside the tree), the live `.env` copied in because the build reads `VITE_APP_NAME` and `ASSET_URL`, and there `composer install --no-dev --optimize-autoloader`,
   - `npm ci` + design-system deps + `npm run ds:tokens` + `npm run build` (compiled assets), still in the stage, so live traffic keeps the old `vendor\` and `public\build`; then a half-done swap left by an earlier run is moved back and stale `.prev` copies are deleted,
   - `php artisan down --render=deploy-window` (the maintenance window — see §3.1; the page is prerendered, so a browser gets it without PHP loading `vendor\`, and since M99 `public/maintenance-guard.php` answers the requests the framework's stub hands onwards — anything expecting JSON, and Inertia visits — above the autoloader, because those used to fall through and boot the framework mid-swap), then `git reset --hard` to the commit and the renames: live `vendor\` and `public\build` aside to `.prev` and the staged ones in, each retried for about ten seconds while a process holds a file open inside,
   - the old `bootstrap\cache` config, services, packages, routes and events caches deleted and `php artisan package:discover`, then `php artisan migrate --force` (backward-compatible / additive-first migrations, so old code tolerates the new schema),
   - `php artisan config:cache route:cache view:cache event:cache`,
   - `php artisan queue:restart`, **last inside the window** — the graceful signal for a plain `queue:work` worker, which finishes its current job and exits so NSSM relaunches it on the new code. There is no `Restart-Service`: Windows PHP has no `pcntl`, so a service stop kills the job in flight. ⛔ **A failure anywhere inside the window leaves the site DOWN on purpose** — the script warns, skips `up` and exits red, because new code over an unmigrated schema is worse than a maintenance page; fix the cause and re-run (the re-run moves a half-done swap back first), or roll back (item 3). ⚠️ **A change to `deploy.ps1` itself takes effect one deploy late**: the runner starts the copy already on disk, and PowerShell reads a script whole before running it, so the script's own `git reset` cannot swap the body that is running. *(Until M95 this bullet described a `horizon:terminate` call and a Horizon/Reverb restart loop; neither ever ran — ADR-0007 §D1, and Increment M46 for Reverb.)*
   - `php artisan up`, only when every step inside the window succeeded, and the commit recorded in `storage\framework\deployed-sha` — then, if the worker service is **Stopped**, it is started (also on a run with nothing to deploy). That is a Stopped-only guard: NSSM reports a crash-looping worker as Paused, which it cannot see. Last, warnings only: the old `vendor\` moves into the stage so the next `composer install` is incremental, and `public\build.prev` and the stage's `.env` copy are deleted.
3. **Rollback**: `deploy.ps1 -AppPath <app path> -Ref <previous-good-sha>` (stages, swaps and re-caches that commit like any other deploy). A bare `git reset --hard <sha>` followed by a re-run does **not** roll back, because the script's own reset returns to `origin/main`; and the next green CI run on `main` redeploys `main`, so revert the bad commit there as well. Because migrations are additive-first, a code rollback does not require a down-migration in the incident hot path; a schema reversal, if ever needed, is a separate deliberate step.

### 3.1 On zero-downtime (an honest limitation)
Single-box Windows self-hosting has **no managed zero-downtime deploy**. `deploy.ps1` builds each release in `.deploy-stage` while the site stays up, then uses a short `artisan down`/`up` window around the code reset, two directory renames, `package:discover`, migrate and the caches (**measured at 7.9 seconds** on the Windows testing server on 2026-09-17, in that site's first automatic deploy: `artisan down` at 22:50:09.57Z, `artisan up` returning at 22:50:17.48Z, with nothing to migrate and no refused directory move. The whole run took 4 min 43 s, so the build and the npm install sat outside the window where they belong. A run with migrations to apply, or one whose renames are refused while a handle is open, is longer). A junction-swapped release directory does not rescue this on either web server: nginx for Windows does not resolve `$realpath_root`, Apache needs the `ProxyFCGISetEnvIf` form in §8.3 for the same class of reason, and a long-lived php-cgi keeps a junction's old target in its realpath cache. True zero-downtime (atomic release-swap, opcache priming) is a future enhancement — or a reason to move to Forge+VPS / Laravel Cloud (ADR-0005 revisit triggers) — not something a single Windows box provides out of the box.

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
| **First-party sign-in** (J3c2 / ADR-0019) | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | `https://<CENTRAL_DOMAIN>/auth/google/callback` | "Continue with Google" does not render and the flow cannot be started — a **supported** state, see the note below |
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
- **Process supervision**: `php artisan queue:work --queue=submissions,webhooks,mail,exports,ocr-processing,scheduled-maintenance` as **one** Windows service via NSSM, relaunched whenever it exits (§8 step 6). Scaling is **manual**, and on Windows it means **a bigger box, not more worker processes**: PHP there has no `pcntl`, so `--timeout` cannot stop a hung job, and a second process would run that job again once `retry_after` passes. There is no managed autoscaling; capacity is a deliberate operational choice, and a scale-out need is one of ADR-0005's revisit triggers *and* ADR-0007 §D1's trigger for adopting Redis + Horizon.
- **Per-tenant fairness** (closes `docs/architecture/technical-architecture.md` Risk R6, **now RESOLVED**): jobs are tagged with `tenant_id` (required on every job payload, ADR-0002 §D3, asserted at runtime by ADR-0007 §D2); the per-tenant job-rate ceiling is **`RateLimited` job middleware** keyed `tenant:{id}:queue:{name}` (**ADR-0007 §D9**), counting job executions started per tenant per queue per minute, so one tenant's bulk OCR/export burst is *deferred* rather than rejected and cannot starve other tenants' queued work. Ceilings live in `config/queue-fairness.php` and are **unvalidated planning assumptions** until real traffic exists.
- **Not provisioned by the script.** `deploy.ps1` creates no worker service — §8 step 6 installs it by hand. The script refuses a host where the service named by `MERIDIAN_WORKER_SERVICE` (default `meridian-worker`) is missing, signals `queue:restart` last inside its maintenance window, and starts the service after `up` if it is Stopped (§3).

---

## 7. Object Storage
- **Backing store**: **local disk** on the Windows Server initially (a dedicated data volume), addressed through Laravel's filesystem/`attachments` abstraction so an S3-compatible target (MinIO on the box, or external S3) is a config-only swap later — no code change.
- **Layout**: `tenants/{tenant_id}/{category}/...` where `{category}` mirrors `docs/data-dictionary.md` §10's `AttachmentKind` values — a predictable, auditable layout that makes per-tenant storage accounting (`usage_counters.storage_bytes`) a prefix query.
- **Lifecycle**: `export_artifact` objects auto-deleted **7 days** after generation (a convenience download, not a durable record); every other kind persists per the ordinary retention rules. On local disk this is a scheduled cleanup task; on S3 it's a bucket lifecycle rule.

---

## 8. Windows Server Setup Runbook (one-time topology)

The concrete pieces to stand one site up (companion to `deploy.ps1`). Each step says what a piece is and how to configure it; **§8.2 says in what order to bring them up the first time — follow that order, not this list's.** The examples name the testing site: app path `C:\meridian\test-app`, worker service `meridian-test-worker`.

1. **PHP 8.4** x64, the Non Thread Safe build (it ships `php-cgi.exe`) — install the VC++ 2015–2022 runtime first. In `php.ini` enable `pdo_pgsql`, `zip`, `mbstring`, `openssl`, `fileinfo`, `curl` and `intl`, plus `zend_extension=opcache`, then confirm with `php -m` that `dom`, `xmlreader`, `libxml`, `iconv`, `ctype`, `filter` and `tokenizer` are listed as well. **Not `redis`** — cache, queue and sessions all use the `database` driver and no code calls Redis — and **not `gd`**, because QR codes render as SVG. Production values: `display_errors=Off`, `opcache.enable=1`, `upload_max_filesize=25M` and `post_max_size=30M`; the stock 2M/8M rejects every attachment over 2 MB, while `config/attachments.php` allows 25 MB. Leave `memory_limit` at its 128M default unless you also change step 6's `--memory`. ⚠️ Windows PHP has no `pcntl`; step 6 says why that matters.
2. **PostgreSQL 15 for Windows (EDB), as a dedicated instance for this site**, plus **PostGIS 3.5** from Stack Builder (Spatial Extensions), or the newest 3.x Stack Builder offers for 15. Dev and CI run PostgreSQL 17 with PostGIS 3.5; the testing site runs 15 because EDB tests its 17 installer only on Windows Server 2019 and 2022, and its 15 installer on 2016 (the user's choice, recorded in `docs/claims/decisions.md`).
   - **Dedicated** means its own Windows service, port and data directory, shared with no other site. The role names `meridian_auth` and `meridian_superadmin` are cluster-wide and hard-coded in their migrations, so on a shared instance the first site to migrate would fix their passwords for every database — and §8.2's privileged login is the instance's superuser, which reaches every database on it. A later production site gets its own instance.
   - Bind it to this box only: `listen_addresses = 'localhost'` in `postgresql.conf`, only `127.0.0.1/32` and `::1/128` in `pg_hba.conf`, and no firewall rule for its port.
   - A migration runs `CREATE EXTENSION IF NOT EXISTS postgis` over the privileged connection, and it fails without the PostGIS binaries.
   - As the installer superuser: `CREATE ROLE meridian_app LOGIN PASSWORD '<new password>' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;` then `CREATE DATABASE meridian OWNER meridian_app;`, then `ALTER DATABASE meridian SET timezone TO 'UTC';`. ⛔ **`meridian_app` must own the database.** From PostgreSQL 15 a non-owner has no `CREATE` on schema `public`, so the first `CREATE TABLE` would fail, and FORCE row-level security relies on the app role owning every table (ADR-0013's trigger function needs the same privilege).
   - **Do not create `meridian_auth` or `meridian_superadmin` yourself, and never rename them.** The migrations create them with the passwords `.env` holds at the first migrate, then grant to the usernames `.env` names.
   - ⚠️ **The `ALTER DATABASE … SET timezone` above is belt-and-braces, not the guard.** The guard is `'timezone' => 'UTC'` on all four connections in `config/database.php`, which makes each session set its own zone. The `ALTER` is worth running anyway, for `psql` and for `pg_dump`/restore work done by hand — but it survives a restore only with `--create`, and a fresh `initdb` takes this box's zone (Asia/Manila here), which is how every PHP-bound timestamp landed eight hours early on the testing server until `M98`. If you are rebuilding a database that already holds data written under a non-UTC session, the skew is per COLUMN, not per row: values written by SQL `now()` are correct and values bound from PHP are not, and both kinds appear in one row — so never repair it with a blanket interval shift.
   - RLS does **not** apply to superusers, so the app must NOT connect as one — this is a hard requirement for the tenancy/RLS work (Increment A). Configure `archive_mode`/`archive_command` (§5).
3. **Redis / Memurai — not required.** *(ADR-0007 moved the queue to the `database` driver, and cache and sessions use it too, so nothing on this box needs a Redis server or the `redis` extension. Memurai returns only if ADR-0007 §D1's trigger brings Redis + Horizon.)*
4. **The web server** — **two arms, and the testing site uses the second.**
   - **nginx for Windows** (ADR-0005's decision, and what a wildcard-subdomain production host should use) —
     server block: `root` → the app's `public/`; `try_files $uri $uri/ /index.php?$query_string`; a PHP
     `location` with `fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;` and
     `include fastcgi_params;` that passes to step 5's `upstream` (the shape of `docker/nginx/default.conf`);
     run nginx as a Windows service via **NSSM**. *(A WebSocket `location` proxying to Reverb was listed here
     until Increment M46 (2026-08-29) measured that no Reverb service exists to proxy to — it returns with
     the stack, Track B.)*
   - **Apache 2.4 with `mod_proxy_fcgi`** — what the testing site actually runs, already on the box when
     `D46` chose it. ⛔ **The vhost MUST carry
     `ProxyFCGISetEnvIf "true" SCRIPT_FILENAME "%{DOCUMENT_ROOT}%{reqenv:SCRIPT_NAME}"`.** On Windows a
     balancer handler hands `php-cgi` a path whose drive prefix has been stripped to a bare leading slash, and
     every PHP request then answers *"No input file specified"* — a 404 for the whole site. Switching to a
     direct `fcgi://` handler does not avoid it. Full vhost shape, the setup lock and the certificate
     arrangement are in **§8.3**.
   Step 8 adds TLS, the host name and the body-size limit.
5. **php-cgi FastCGI backend** — two or more `php-cgi.exe` instances as NSSM services, **each on its own port** (`php-cgi.exe -b 127.0.0.1:9000`, `-b 127.0.0.1:9001`, …), listed as `server` lines in an nginx `upstream` block that `fastcgi_pass` names. One instance serves one request at a time, and two cannot share a port. In each service's environment set `PHP_FCGI_MAX_REQUESTS=0` (`nssm set <service> AppEnvironmentExtra PHP_FCGI_MAX_REQUESTS=0`): php-cgi otherwise exits after 500 requests, which testers see as intermittent 502s while NSSM restarts it. *If php-cgi supervision proves fragile under load, switch to **IIS + the PHP FastCGI module** — the documented fallback (ADR-0005).*
6. **Queue worker** — exactly **one** `php artisan queue:work` process (with the §6 `--queue=` ordering; **not** Horizon, per ADR-0007 §D1) as an NSSM Windows service, **installed but left Stopped** until §8.2's priming deploy starts it:
   `nssm install meridian-test-worker C:\php\php.exe artisan queue:work --queue=submissions,webhooks,mail,exports,ocr-processing,scheduled-maintenance --sleep=3 --max-time=3600 --memory=112`, then `nssm set meridian-test-worker AppDirectory C:\meridian\test-app`. `nssm install` does not start the service, but its start type is automatic, so do not reboot before the priming deploy. Leave `AppExit` at its `Restart` default: `--max-time` and `queue:restart` both end the process cleanly, and NSSM relaunching it is how new code loads. `--memory=112` sits below the 128M `memory_limit`, as the compose worker's does, so the graceful recycle fires before PHP's hard limit. Do **not** start it by hand: the worker reads the database cache at boot, and that table does not exist before the first migrate.
   ⚠️ **One process, never more.** Windows PHP has no `pcntl`, so `--timeout` can never stop a hung job; a second process would take the same job again once `DB_QUEUE_RETRY_AFTER` (120 s) passes and run it twice.
   The service name is per site. Set the machine environment variable `MERIDIAN_WORKER_SERVICE` to it (`setx /M MERIDIAN_WORKER_SERVICE meridian-test-worker`), because `deploy.ps1` reads the name from there and otherwise looks for `meridian-worker`. **`deploy.ps1` still does not create this worker service**: it refuses to deploy onto a host where the named service is not installed, runs `php artisan queue:restart` last inside its maintenance window, and starts the service after `up` if it is Stopped (§3). Mail, the upload scan that makes an attached file openable, and every scheduled sweep run only while this worker does.
   ⛔ **Correction (Increment M46, 2026-08-29) — this step previously also prescribed `php artisan reverb:start` as a second NSSM service, and that command does not exist.** Measured on this tree: `laravel/reverb` is absent from `composer.json`, `reverb` appears in no `artisan list` output, and `php artisan reverb:start --help` exits **1**. An operator following this runbook would have created a Windows service whose executable fails on every start, and NSSM's auto-restart would have retried it forever. **Reverb is Track B by explicit user decision** — see ADR-0002 §D3's Realtime row, corrected in the same increment. The realtime service returns to this step when the stack does, and not before.
7. **Scheduler** — a Windows Task Scheduler task running `php artisan schedule:run` every minute (Windows has no cron), one per site, registered **after** §8.2's first deploy has migrated: `schtasks /Create /TN meridian-test-scheduler /SC MINUTE /MO 1 /RU SYSTEM /TR "C:\php\php.exe C:\meridian\test-app\artisan schedule:run"`, which runs whether or not anyone is signed in. `routes/console.php` declares **seven** jobs: the failed-job prune (daily 03:10), the usage roll-up (02:40), the draft reaper (03:40), the scheduled-form and webhook-retry sweeps (every 5 minutes), the connector-token refresh (hourly) and custom-domain verification (every 15 minutes). Every entry is a queued job, so nothing happens unless step 6's worker is Running, and none is due while the site is in maintenance mode. `deploy.ps1` provisions no Task Scheduler task; until this one exists, nothing periodic runs. *Never create `app/Console/Kernel.php`*: `Kernel::shouldDiscoverCommands()` is `get_class($this) === __CLASS__`, so any console-kernel subclass silently stops `routes/console.php` loading and every schedule disappears with no error.
8. **DNS and TLS** — **two arms, because the challenge type follows from which ports are open.**
   - **A wildcard host (ADR-0005's production shape).** DNS `A` records for `<CENTRAL_DOMAIN>` and
     `*.<CENTRAL_DOMAIN>` (every workspace is a subdomain), **published last**, per §8.2. Let's Encrypt issues a
     wildcard only through **DNS-01** validation, which needs only a TXT record. Use **win-acme** with the DNS
     plugin for your provider, the **PEM-files store** (nginx cannot read the Windows certificate store) and a
     script installation that reloads nginx — for example `wacs.exe --source manual --host
     <CENTRAL_DOMAIN>,*.<CENTRAL_DOMAIN> --validationmode dns-01 --validation <plugin> --store pemfiles
     --pemfilespath C:\nginx\certs --installation script --script <nginx reload script>`; check the argument
     names against `wacs.exe --help` for the version you install. win-acme registers its own renewal task.
     Terminate TLS at nginx: `listen 443 ssl;`, `ssl_certificate` and `ssl_certificate_key` pointing at the files
     it writes, `server_name <CENTRAL_DOMAIN> *.<CENTRAL_DOMAIN>;` and `client_max_body_size 30m;`
     (step 1's `post_max_size`).
   - **A single named host on Apache (the testing site, `D46`/`D49`).** One `A` record, no wildcard, and the
     certificate is issued and renewed by Apache's own **`mod_md`** over **`tls-alpn-01`** on port 443. There is
     no DNS plugin for this zone — DICT runs the name servers for `pitahc.gov.ph` and offers no API — and
     **inbound 80 is closed**, so `http-01` is unavailable and DNS-01 cannot be automated. `D49` answered this:
     inbound **443 is open**, measured from outside the agency network, and `tls-alpn-01` is the challenge.
     ⛔ **Do not count a win-acme installation on this box as a working renewal** — its `http-01` could
     never validate through a closed port 80. The directives, the three traps and the **activation task Windows
     requires** are in **§8.3**; read it before touching the certificate.
   **HTTPS is required even for testing**: the form runtime's service worker, which carries offline drafts and
   background sync, registers only in a secure context. **Tenant custom domains are NOT covered by this step and
   are not automated — see §8.1.**
9. **GitHub Actions self-hosted runner** — register against the repo and install as a Windows service (`config.cmd` → run as service), under an account that has Modify on the app path and is allowed to start step 6's service. Confirm both: a refused start turns a good deploy red after `up`. Restart the runner service after setting `MERIDIAN_WORKER_SERVICE`, so its jobs inherit the variable. It executes the deploy workflow (§3). Set the repo **Variables** (Settings → Secrets and variables → Actions → Variables): **`MERIDIAN_APP_PATH`** (e.g. `C:\meridian\test-app`) when you register it, and **`DEPLOY_ENABLED=true` only as the last step of §8.2**. Until `DEPLOY_ENABLED` is `true`, `.github/workflows/deploy.yml` stays dormant (skipped); once it is, every green CI run on `main` runs `deploy.ps1` — a scheduled run included, although a run whose commit is already live opens no maintenance window — and a first migrate against an unfinished `.env` fixes the role passwords permanently. ⛔ **Never approve an unknown contributor's run.** The repository is public through testing (`D48`), a fork's workflows run from its own head and can name `runs-on: self-hosted`, and an approval puts that run's code on this box where the runner's account holds Modify on the app tree. The approval policy is set to `all_external_contributors`, so every user who is neither a member nor an owner waits for the owner — and the owner is the whole control. This sentence was assigned disjunctively by a struck ledger row and carried by neither of its named rows until `M100` wrote it here (`R-ceb66cb8`).
10. **App directory** — install **Git for Windows** (2.31 or later, for `git rev-parse --path-format`), **Composer 2** and **Node.js 24 LTS** (CI's version; Vite needs 20.19 or later), and put them and `php` on the PATH of both your admin account and the runner's service account, because `deploy.ps1` calls `git`, `composer`, `npm` and `php` by bare name. Clone the repository at a fixed path (e.g. `C:\meridian\test-app`) whose `public/` is nginx's root. Grant the php-cgi, worker and runner service accounts Modify on `storage\` and `bootstrap\cache\`, and the runner Modify on the whole tree, which is where `deploy.ps1` builds each release (`.deploy-stage`) and renames `vendor\` and `public\build` in from, so it needs no other directory. Then follow **§8.2**, which creates the server `.env` (§4) and runs `deploy.ps1` once, by hand, to prime the site.

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
3. **Add the hostname to the web server**, for the app's `public/` root.
   - **nginx:** a `server_name` on the same block that serves tenant subdomains — the application
     distinguishes hosts, nginx does not need to.
   - **Apache (this box):** a `ServerAlias` on the existing vhost, which already carries the
     `ProxyFCGISetEnvIf` line §8 step 4 requires. Do not add a second vhost.
4. **Issue the certificate by hand**, for that hostname only.
   - **nginx / win-acme:** `wacs.exe --target manual --host forms.acme.com --installation iis` (or the
     nginx/script installer this box uses). win-acme registers its own renewal task for it. **The tenant's
     DNS must already point here for the HTTP-01 challenge to succeed** — so in practice steps 3-4 are
     done together with the tenant, in a scheduled window.
   - ⛔ **Apache (this box): `http-01` CANNOT WORK HERE, because inbound 80 is closed.** Add the host to
     `MDomain` as a further member and let `mod_md` take it over `tls-alpn-01` on 443, or obtain it out of
     band and install it as a static pair. Either way **the tenant's DNS must already point here** before the
     challenge can validate, and ⚠️ **a renewal still needs §8.3's activation task to be served**, so
     do not treat issuance as the end of the job.
5. **Reload the web server** and confirm the certificate serves: `curl -sSI https://forms.acme.com/` .
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

### 8.2 First boot — before anyone signs in

Steps 1–10 say what each piece is. This is the **order** for bringing a new site up the first time, and the order
matters: until sign-up is closed (item 7) anyone who reaches the site can register, and a deploy that runs before
`.env` is finished fixes its mistakes into the database. Run the commands in an elevated Windows PowerShell, in the
app path, over an interactive (RDP) console: the two account commands ask for a password with hidden input, and
refuse to create an account without a console.

1. **Before you install anything.**
   - Choose `<CENTRAL_DOMAIN>` (for example `test.example.com`) at a DNS provider that win-acme has a DNS-01
     plugin for, and get SMTP credentials that work from this server.
   - Allow **outbound** access from the server to:
     - `api.pwnedpasswords.com` over HTTPS. Every password that is set or reset is checked against it; without
       it, each one stalls for about 30 seconds and the breach check is skipped silently.
     - your SMTP host and port;
     - your DNS provider's API and `acme-v02.api.letsencrypt.org` (step 8);
     - `github.com`, `api.github.com`, `codeload.github.com`, `repo.packagist.org` and `registry.npmjs.org`,
       which `deploy.ps1` fetches from on every run, plus the hosts GitHub lists for self-hosted runners;
     - a connector provider's API (Slack, Google, Airtable) only if you configure that connector (§4.1).
   - The app trusts no proxy. If a proxy or tunnel ever fronts nginx, every guest shares the proxy's address in
     the per-address limits of item 3.

2. **Install steps 1–5 and 8**: PHP, the dedicated PostgreSQL 15 instance with PostGIS and the `meridian_app`
   role and database, nginx, php-cgi and the certificate. DNS-01 needs only the TXT record, so **publish no `A`
   record yet, and keep inbound 80 and 443 closed until step 10.** ⚠️ **On an Apache host renewing through
   `mod_md`/`tls-alpn-01` this ordering is different and matters:** that challenge needs inbound 443 reachable from
   the internet, so the certificate cannot be issued during this step at all. Install the web server here, publish
   the `A` record and open 443 at step 10, and let `mod_md` obtain the certificate on the restart after that —
   §8.3 gives the order. The testing site's first eleven renewal attempts all failed for exactly this reason,
   and the cause was the firewall rather than the configuration.

3. **Clone the app and write `.env`** (step 10). Copy `.env.example` to `.env`, then set:
   - `APP_ENV=production` and `APP_DEBUG=false`. ⛔ **`production` is mandatory on a testing site too.**
     `DatabaseSeeder` calls `DemoSeeder` last, and `DemoSeeder` returns early only in `production`. In any other
     environment a seed creates `admin@meridian.test` as platform super-admin — with the demo password this
     repository's guides publish, and no second factor — plus the demo accounts. The first stranger to sign in
     as it enrols their own authenticator and owns the platform console. The only visible cost of `production`
     is that Settings → About says "production", which is expected.
   - `APP_URL=https://<CENTRAL_DOMAIN>`: the central origin, never a workspace host. `TenantUrl` takes the host
     and scheme of every emailed link from it, and Google sign-in derives its redirect URI from it (§4.1).
   - `CENTRAL_DOMAIN=<host, no port>`. A wrong value 404s `/admin` and every workspace.
   - `SESSION_DOMAIN=null`. ⛔ Keep it. Host-only cookies keep one workspace's session off every other
     workspace's host; widening the domain to fix a sign-in problem exposes every tenant's session to every
     other tenant.
   - `SESSION_SECURE_COOKIE=true`, since HTTPS serves from step 8.
   - `DB_HOST=127.0.0.1`, `DB_PORT=<this instance's port>`, `DB_DATABASE=meridian`, `DB_USERNAME=meridian_app`
     and `DB_PASSWORD=<step 2's password>`.
   - `DB_PRIVILEGED_USERNAME` and `DB_PRIVILEGED_PASSWORD`: this instance's installer superuser (EDB names it
     `postgres`). **It is a permanent runtime secret**, not a migrate-only one: `PlatformRowCounter` uses that
     connection during requests, and so does every seeder.
   - `DB_AUTH_PASSWORD` and `DB_SUPERADMIN_PASSWORD`: new, strong values. Leave `DB_AUTH_USERNAME` and
     `DB_SUPERADMIN_USERNAME` as they are (step 2).
   - ⛔ **Replace every `secret` in the file before the first migrate.** The role migrations create
     `meridian_auth` and `meridian_superadmin` only if they do not exist yet, with whatever password `.env`
     holds at that moment. The published `secret` would become permanent, because a later migrate never
     corrects it; the only repair is `ALTER ROLE meridian_auth PASSWORD '…'` (and the same for
     `meridian_superadmin`), run by hand as the superuser.
   - `MAIL_MAILER=smtp` with a **real** `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
     `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`. `.env.example` points at `mailpit`, which does not exist here:
     every verification, password-reset and invitation mail would look sent while its job failed into
     `failed_jobs`. With sign-up closed (item 7), an emailed invitation is the **only** way a tester gets an
     account.
   - Leave `QUEUE_CONNECTION`, `CACHE_STORE` and `SESSION_DRIVER` at `database`, and `FILESYSTEM_DISK` at `local`.
   - **On a testing site only** (decision D32): raise `GUEST_MINT_PER_IP` (default 30), `GUEST_SUBMIT_PER_IP`
     (60) and `GUEST_CHALLENGE_PER_IP` (90). They are requests per minute per client address, and a room of
     testers on one network shares one address. `.env.example` ends with them commented out. A production site
     keeps the defaults.
   - Optional: the Google sign-in and connector client ids (§4.1). Left empty, those features are unreachable,
     which is a supported state.

   Then run `composer install --no-dev --optimize-autoloader --no-interaction` and `php artisan key:generate`,
   before anything caches the config.

4. **Install the worker service and leave it Stopped** (step 6), and set `MERIDIAN_WORKER_SERVICE` to its name.

5. **Prime the site by hand**, in the elevated console: `& C:\meridian\test-app\deploy.ps1 -AppPath C:\meridian\test-app`.
   It refuses to start if the worker service is missing. It fetches, installs the Composer and npm dependencies and
   builds the assets in `.deploy-stage`, then opens the maintenance window: it renames the built `vendor\` and
   `public\build` in, runs `migrate --force` (which creates the PostGIS extension and both roles from `.env`), the
   config, route, view and event caches, and `queue:restart`. Only if all of that succeeds does it run `up` and
   start the Stopped worker. Confirm with `nssm status meridian-test-worker` that the service reads `SERVICE_RUNNING`.
   If the run fails inside its window, the site stays down on purpose (§3); either way, fix the cause and run it again.

6. **Seed the catalogs by class, never with a bare `db:seed`.** Run
   `php artisan db:seed --class=RolePermissionSeeder --force`, then the same for `PlatformTemplateSeeder`,
   `PlatformFieldLibrarySeeder` and `PlanSeeder`. They create the roles and permissions, the template gallery,
   the question library and the plan catalog, and naming them means a mistyped `APP_ENV` can never reach
   `DemoSeeder`.

   ⛔ **Never run the tenancy package's `tenants:migrate`, `tenants:migrate-fresh`, `tenants:rollback`,
   `tenants:seed` or `tenants:run`.** `artisan list` shows them because stancl/tenancy registers them, but they
   assume a database per tenant and this app has one shared database, so none of them does here what its name
   says. The operator's commands are `migrate --force`, inside `deploy.ps1`, and the four seeders above;
   `tenants:create` and `tenants:extract` are this app's own.

   Then register the scheduler task (step 7).

7. **Create the first platform operator, while there is still no `A` record.**
   - In the interactive console: `php artisan platform:super-admin <ops-email> --name="<your name>"`, then type
     the password at the two hidden prompts.
   - On the server itself, add a hosts-file entry pointing `<CENTRAL_DOMAIN>` at `127.0.0.1`. Sign in at
     `https://<CENTRAL_DOMAIN>/login`, then go straight to `https://<CENTRAL_DOMAIN>/admin/tenants`: a direct
     sign-in lands on `/dashboard`, which exists only on workspace hosts. The console sends you to
     `/admin/two-factor`; enrol an authenticator app there.
   - ⛔ **Then, immediately, open `/admin/settings` and turn off Open signup** (decision D31: invite-only). It is
     on by default and only an enrolled super-admin can reach the switch, so until it is off anyone who reaches
     the site can register. Invitations do not depend on the switch.

8. **Create the first workspace.** Run `php artisan tenants:create <slug> "<Workspace name>" <owner-email> --plan=<tier>`,
   adding `--owner-name="<name>"` for a new owner, who types a password at the hidden prompts.
   - The owner must be a different address from the operator's: the command refuses a platform super-admin as
     an owner. A plus-address is fine.
   - Choose a tier whose seats cover every tester plus the owner: `starter` (10 seats) or above, **never
     `free`**, which has 2 seats and no offline sync or save-and-resume.
   - The command prints the workspace's sign-in address, `https://<slug>.<CENTRAL_DOMAIN>/login`. Testers always
     use that address, never the central one.

9. **After any later `.env` edit** (the guest limits included), run `php artisan config:cache` and then
   `php artisan queue:restart`, or simply re-run `deploy.ps1`. A deploy caches the config and a cached config
   ignores `.env`, so an edit without this changes nothing.

10. **Expose the site.** Publish the `A` record for the site's host name — and `*.<CENTRAL_DOMAIN>` too **only**
    if the site serves workspaces as subdomains. **Open inbound 443.**
    - ✅ **On the testing site this is DONE, and it is what `D49` answered.** Inbound 443 to `121.58.210.237`
      is open, measured from **outside the agency network** on 2026-09-18 and again on 2026-09-19: TLS 1.3
      completes, `/up` and `/login` answer 200. **It must stay open**, because `mod_md` renews over it
      (§8 step 8, §8.3). **Inbound 80 stays closed.**
    - ⛔ **DO NOT remove the on-box `hosts` entries.** `M100` measured it: a TCP connect from the server to
      its own public address `121.58.210.237:443` gets **no connection within 8 seconds**, so **this network
      does not hairpin**. The two entries — `127.0.0.1 pitahc.gov.ph` and `127.0.0.1 staging.pitahc.gov.ph`
      — are the only route by which the certificate check, the `/up` check, `Require local` and the
      operator's own browser reach the site from the box. Removing them breaks every check they protect.
      *(This was a conditional instruction resting on an unmeasured fact until `R-98682188` was closed on the
      measurement above; the Testing Server Checklist's "remove the hosts entry" step is wrong and is
      corrected there too.)*
    - Testers' devices must resolve the name themselves: on a private office network, check the DNS those
      devices use, because a hosts file cannot hold a wildcard.

11. **Test mail before inviting anyone.** Sign in as the owner at the workspace address, invite a second address
    you control from Members, and confirm that the email arrives and that `php artisan queue:failed` lists
    nothing. Accepting an invitation verifies the address, so testers need no separate verification email, but
    the invitation link exists only in that email. A form's share link answers 404 until the form is published
    **and** its owner turns on guest submissions in Share, which is off by default.

12. **Turn on automatic deploys, last.** Register the runner (step 9), set `MERIDIAN_APP_PATH`, and only now set
    `DEPLOY_ENABLED=true`. From then on:
    - **Every merge to `main` redeploys this site** once CI is green. The release builds while the site is still
      up, then a short maintenance window swaps it in, and testers mid-session will notice it.
    - **A deploy that fails inside its maintenance window leaves the site down on purpose**, because new code
      over an unmigrated schema is worse than a maintenance page. It shows as a red run of the Deploy workflow,
      and nothing alerts anyone (§9). Fix the cause and re-run `deploy.ps1`, or roll back with
      `deploy.ps1 -AppPath C:\meridian\test-app -Ref <good-sha>` and revert the bad commit on `main` (§3).
    - A change to `deploy.ps1` itself takes effect one deploy late (§3).

### 8.3 The testing site as built — Apache, `mod_md`, and the activation task Windows requires

**This is the runbook for `staging.pitahc.gov.ph` (`D46`, `D49`), and until `M100` none of it was written
down anywhere in this repository.** It lived as configuration on the box and as chat history, which is why
`§8` described a server that does not exist for four increments. Everything below was read off the box or
measured against it, and the measurement is named wherever one was taken.

**The layout.** One workspace at the root of `staging.pitahc.gov.ph` (121.58.210.237), served by
**Apache 2.4.66 (Win64)**, service name **`Apache2.4`**, root `C:\Apache24`, site config
`conf\extra\meridian.conf`. `CENTRAL_DOMAIN=pitahc.gov.ph`, and `pitahc.gov.ph` itself is the agency
website on a different machine — so **no wildcard, no subdomain workspaces, and no path-prefixed deploy**.
App at `C:\meridian\test-app`, PHP 8.4.25 x64 NTS at `C:\php` behind `php-cgi-9000`/`9001`, PostgreSQL 15
+ PostGIS, worker `meridian-test-worker`, scheduler `meridian-test-scheduler` (every minute).
Main `ErrorLog` is `logs\error_log` — **no extension** — and the vhost writes
`logs\meridian-{access,error}.log`. `openssl.exe` ships at `C:\Apache24\bin\`.

**The certificate directives, as they stand and as they must stay.** In `conf\extra\meridian.conf`:

```apache
MDContactEmail        <operator address>
MDCertificateAgreement accepted
MDCAChallenges        tls-alpn-01
MDPrivateKeys         secp256r1
<MDomain staging.pitahc.gov.ph>
    MDMembers  manual
    MDRenewMode always
</MDomain>
...
SSLCertificateFile    "C:/Apache24/conf/certs/staging.pitahc.gov.ph-chain.pem"
SSLCertificateKeyFile "C:/Apache24/conf/certs/staging.pitahc.gov.ph-key.pem"
```

⛔ **Four traps govern this arrangement. Three of them take the site down or silently stop renewing, and
each was paid for once already.**

1. ⛔ **`MDPrivateKeys secp256r1` is load-bearing, and its absence cost a day.** The served leaf is
   **EC P-256**; `mod_md`'s default challenge certificate is **RSA**. Apache holds **one certificate slot
   per key type**, and `mod_md`'s `tls-alpn-01` swap replaces **only the RSA slot** — so Let's Encrypt,
   which resolves to ECDSA, is handed the untouched ordinary certificate and answers *"Received
   certificate which is not self-signed."* `mod_md`'s own source comment predicts it: *"we cannot override
   all fallback certificates present, just a single one … Bit of a mess."* The `.secp256r1` filename suffix
   `mod_md` then writes is harmless; it finds its own files.
2. ⛔ **Never pin `MDCertificateFile`/`MDCertificateKeyFile` inside `<MDomain>`.** In httpd 2.4.66
   `get_certificates()` takes the static branch *unconditionally* whenever they are set, and
   `md_reg_renew_at` computes renewal from that static pair — so a certificate `mod_md` renews is **never
   served**, and renewal re-triggers on every check into Let's Encrypt's duplicate-certificate limit.
   Nothing in the module ever clears `md->cert_files`.
3. ⛔ **Keep the vhost `SSLCertificateFile` pair permanently, and never remove it to "let `mod_md` take
   over".** With no certificate in the vhost, `mod_ssl`'s list goes empty, the fallback hook installs a
   self-signed *"Apache Managed Domain Fallback"* certificate and `mod_ssl` sets `service_unavailable`
   (`AH10085`) — **503 on every request, behind a browser trust error, until a human notices.**
   `md_add_cert_files` *appends* `mod_md`'s files after yours (`AH10084` warns and proceeds), so the
   managed certificate still wins and `pks->cert_files` can never be empty at a future restart.
4. ⛔ **ON WINDOWS `mod_md` CAN NEVER ACTIVATE A RENEWED CERTIFICATE BY ITSELF.**
   `md_server_graceful()` is `APR_ENOTIMPL` on WIN32 and **has no caller anywhere in the module**.
   Staged-to-live promotion happens only in `md_reg_load_stagings()`, called from exactly one place,
   `md_post_config_before_ssl()` — **an Apache restart**. A renewed certificate therefore sits in
   `md\staging\<domain>\` for ever while the served one expires.

**The activation task, which is the answer to trap 4.**
`scripts/activate-staged-cert.ps1` in this repository is the source of truth; it is installed on the box at
`C:\meridian\activate-staged-cert.ps1` and registered as the scheduled task **`meridian-certificate-activate`**,
daily at **03:20**, as **SYSTEM** at `runlevel=Highest`, `StartWhenAvailable`, ten-minute limit.

```powershell
# install/refresh the operational copy from the repository, then re-register if the task is absent
Copy-Item C:\meridian\test-app\scripts\activate-staged-cert.ps1 C:\meridian\activate-staged-cert.ps1 -Force
```

⚠️ **The operational copy deliberately sits OUTSIDE the app directory.** `deploy.ps1` hard-resets the app
checkout, and a rollback with `-Ref <older-sha>` would take the script with it — certificate activation must
not depend on the app tree's state. The repository copy is the source; this is the one place that says so.

It restarts Apache **only** when a certificate is actually staged, **refuses** when `httpd -t` does not
exit 0, and **proves** the activation by re-reading the served certificate rather than trusting the
restart's exit code. Its log is `C:\meridian\certificate-activation.log`.

⛔ **The glob `pubcert*.pem` in that script is load-bearing — do not narrow it to an exact filename.** Its
predecessor, `check-certificate.ps1`, tested `Test-Path "$stage\pubcert.pem"`; with `MDPrivateKeys
secp256r1` in force `mod_md` writes **`pubcert.secp256r1.pem`**, so that arm became unreachable the moment
trap 1's fix landed — **while the daily task still exited 0 and logged success every day.** The fix for one
defect silently disarmed the mitigation for another and every signal stayed green. It is now unregistered,
its definition backed up to `C:\meridian\meridian-certificate-check.task.xml`, and the script parked as
`check-certificate.ps1.superseded`.

**Read the task's `LastTaskResult`, which is the monitoring surface:** `0` nothing staged or activation
proved · `1` unexpected error · `2` a certificate was staged but `httpd -t` failed, so the restart was
refused · `3` Apache restarted but the activation could not be proved — **check the site immediately.**

**What a renewal looks like when it works.** `mod_md` renews at roughly 30 days before expiry. The staged
certificate lands in `md\staging\<domain>\`, the error log records
`AH10059 … activated on next (graceful) server restart`, and the **next 03:20 task run** performs that
restart and logs the new serial and expiry. The served leaf as of 2026-09-19 is serial `05F10E…53AE`, valid
**2026-09-18 → 2026-12-17**, so the next renewal falls due around **2026-11-17**.

⚠️ **`httpd -t` on this box prints `AH00558: Could not reliably determine the server's fully qualified
domain name, using fe80::…` before `Syntax OK`, because there is no *global* `ServerName`.** It is benign —
the vhost carries its own — but it appears in every `CONFIG` line of the activation log for ever. **Do not
chase it during an incident.**

**Recovering a failed renewal by hand.** Delete `md\staging\<domain>\job.json`, `order.json` and
`md\challenges\<domain>\*` so the retry backoff is skipped and the challenge certificate is regenerated
with the right key type, then restart Apache **twice**: the first restart obtains and stages, the second
activates. ⚠️ **Rate limit to respect: five failed validations per account per hostname per hour**, resetting
on the hour. Failed orders issue nothing, so the five-per-week duplicate-certificate limit stays untouched.

✅ **The free diagnostic for a key-type mismatch — reuse it, it needs no CA and burns no rate limit.**
While challenge files exist in `md\challenges\<domain>\`, probe from anywhere:

```
openssl s_client -connect <ip>:443 -servername <domain> -alpn acme-tls/1
openssl s_client -connect <ip>:443 -servername <domain> -alpn acme-tls/1 -sigalgs rsa_pss_rsae_sha256:rsa_pkcs1_sha256
```

**Interleave the two over at least five rounds** — a single pass cannot distinguish a sigalg effect from the
two-second challenge window. If the default run returns the ordinary certificate while the RSA-restricted
run returns `CN=tls-alpn-01-challenge`, the mismatch is proven.

⚠️ **One check the Testing Server Checklist tells an operator to read does not work:** `md-status` is
swallowed by `public/.htaccess`'s front-controller rewrite (`RewriteCond %{REQUEST_FILENAME} !-f` sends it
to `index.php`, and `mod_rewrite` has no handler exemption), so it returns a Laravel page rather than
`mod_md`'s status. **No step in this runbook may lean on it.** The activation log above is the substitute
until that is fixed.

⚠️ **Also on the box, unfiled against any step here:** a leftover `*:80` vhost for
`staging.pitahc.gov.ph` in `conf\extra\httpd-vhosts.conf`, harmless while 80 is blocked from outside, and
an `X-Robots-Tag: noindex, nofollow, noarchive` header set in the vhost — which is the **only** thing
keeping this site out of search results, since `public/robots.txt` ships from the repository with an empty
`Disallow:` and must stay that way so production is not blocked.

---

## 8b. Per-Tenant Extract Runbook (P2b — ADR-0018)

`php artisan tenants:extract <id|slug|hostname> [--path=…]` writes one workspace's record as one NDJSON
file per table plus a `manifest.json`, into `storage/app/tenant-extracts/<slug>-<timestamp>/` by default.
Used for offboarding, an isolation-clause customer, or answering "what exactly do you hold for us".

**Before you run it**

1. **Run as the application role.** The command refuses a SUPERUSER/BYPASSRLS connection and writes nothing
   — that refusal is the guard working, *not* a misconfiguration to route around by pointing `DB_USERNAME`
   at `meridian`. On that role every RLS policy is ignored and the artefact would be **every** tenant's rows
   in a directory bearing one tenant's name.
2. **Pick an empty destination.** It refuses a directory that already has files in it; merging two
   point-in-time artefacts produces a manifest that describes neither.
3. **Expect it to hold a read transaction** for the duration (REPEATABLE READ, for a consistent snapshot
   across all 43 tables). On a large tenant, run it in the maintenance window you would use for a backup.

**After it finishes — read the manifest, not just the row count**

- `snapshot.isolation_level` and `snapshot.role` are **read back from the session**, so they say what
  actually happened. `repeatable read` + the app role is the expected pair.
- `unresolved_user_references` lists ids that extracted rows point at and the extract does not contain.
  **This is normal**, and the command warns about it on the console: the `users` policy admits only the
  workspace's ACTIVE members, so an outstanding invitation, a removed or suspended member whose forms
  remain, and the platform operator on an `impersonation_tokens` row all land here. Read it before handing
  the artefact over — it is the list you will be asked about.
- `not_extracted` states, in the file itself, what was deliberately left out and why.

**⚠️ Choose the destination for its ACL, because the command's own permissions do nothing on this host.**
The writer asks for `0700`, which is a POSIX control: **on this Windows Server box PHP ignores `mkdir()`'s
mode and `chmod()` only toggles the read-only attribute**, so the extract directory simply inherits the ACL
of whatever it is created under. Pass `--path` pointing inside a directory you have already restricted —
the same location §5 puts database dumps in — rather than accepting the `storage/app/tenant-extracts/`
default, which inherits the web application's own tree. Confirm with `icacls <path>` before you run it, not
after.

**⚠️ What the artefact is, for retention purposes.** One workspace's entire record in plaintext — every
submission answer, every respondent email the forms collected. It carries **no credentials** (ADR-0018 §D3
withholds them), but nothing in the command encrypts, transfers or expires it. Treat it as you would a
database dump under §5: move it to the same protected location, and delete the working copy when the
transfer is confirmed. **It is NOT a GDPR subject-access response** — see
`docs/data-privacy-gdpr-compliance.md` §3, which explains why using it as one would over- and
under-disclose at the same time.

---

## 9. Out of Scope / Deferred
- Metrics, dashboards, alerting, on-call → Doc #23 (this doc's §5.5 incident skeleton points there).
- Automated zero-downtime release-swap on Windows → future enhancement (§3.1).
- A second node / managed failover for higher availability → revisit before paid-customer SLAs (ADR-0005).
- Plan-tier-specific infrastructure quotas → Doc #24.

<!-- The pipeline markers below are DELIBERATELY at end-of-file. A marker inserted mid-document
     shifts every line beneath it, and this repository cites documents as `path:N` — 25 such
     citations point into the files that carry markers. End-of-file shifts nothing. -->
<!-- pipeline: id=track-b-deployment title="Track B — stand up the ADR-0005 self-hosted production host" phase=4 state=held size=XL blocker="user: deferred until app development is done, and needs the host itself" tier=before-launch -->
