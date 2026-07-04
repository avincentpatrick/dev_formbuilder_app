# ADR-0005: Self-Hosted Deployment on Windows Server 2016 (supersedes ADR-0003)

## Status

**Accepted** — 2026-07-04. **Supersedes [ADR-0003](0003-hosting-laravel-cloud.md)** (Laravel Cloud), which is now marked *Superseded*.

## Context

ADR-0003 chose **Laravel Cloud** as the managed production host, reasoning that a small team with no dedicated DevOps role should offload infrastructure ops to a managed platform. That decision is now reversed: the project owner has decided to **self-host the platform on hardware they already operate — a Windows Server 2016 machine** — and to make deployment a **git-driven** flow so that updating the running server is a `git push`, not a manual chore.

This changes the posture from "managed platform, pay to avoid ops" to "self-managed on owned Windows hardware, own the ops." The application code is unaffected — it is standard, platform-agnostic Laravel. What changes is *where* it runs and *how* it is deployed, supervised, and backed up.

### What's driving this
- The owner already runs the Windows Server 2016 box and prefers to use existing infrastructure over a new recurring cloud bill.
- A git-based deploy (push → server updates itself) is explicitly wanted.
- Development is Windows/Laragon-based, so a Windows production target is familiar ground.

## Decision

**Host production on the owner's Windows Server 2016**, configured as follows. The operational runbook for each row lives in `docs/deployment-infrastructure.md` (Doc #22).

| Concern | Choice on Windows Server 2016 |
|---|---|
| Web server | **nginx for Windows**, reverse-proxying to a PHP 8.4 FastCGI backend (`php-cgi`), both run as Windows services via **NSSM**. |
| PHP | PHP 8.4 (VC++ 2015–2022 runtime) with `pdo_pgsql` + `redis` extensions enabled. |
| Database | **PostgreSQL for Windows** (EDB build), self-managed. Because we own the instance, **Row-Level Security and PostGIS are guaranteed available** — the standing ADR-0003 due-diligence item disappears. |
| Cache / queue backend | **Redis via Memurai** (Redis-API-compatible, native Windows service). |
| Queue workers | `php artisan horizon` run as a Windows service (NSSM). |
| Realtime | `php artisan reverb:start` run as a Windows service (NSSM), reverse-proxied by nginx with WebSocket upgrade. |
| Scheduler | `php artisan schedule:run` every minute via **Windows Task Scheduler** (Windows has no cron). |
| TLS | Let's Encrypt via **win-acme** (or a purchased certificate), terminated at nginx. |
| Object storage | Local disk under the tenant-prefixed layout (Doc #22 §7) initially; an S3-compatible target (MinIO on the box, or external S3) is a drop-in later via the same `attachments` abstraction — no code change. |
| Deploy | A **self-hosted GitHub Actions runner** installed as a Windows service on the box. When CI passes on `main`, a deploy workflow runs on that runner and executes `deploy.ps1` against the live checkout: fetch/reset to `origin/main` → `composer install --no-dev` → build assets → `migrate --force` → cache config/routes/views → restart Horizon/Reverb. |

Docker Compose **remains** the local-development and CI parity source (ADR-0003 already kept that independent of the host). Production is now Windows-native — a deliberately-accepted parity gap (see Consequences).

## Consequences

### Positive
- **No monthly managed-hosting bill**; uses hardware already owned.
- **Full Postgres control** — RLS + PostGIS guaranteed; the ADR-0003 "confirm the managed platform supports RLS/PostGIS" risk is *eliminated*, not merely deferred.
- **Familiar environment** — Windows, matching the team's Laragon-based development.
- **Git-driven deploys** — a push to `main` updates the server automatically via the self-hosted runner, which is exactly the requested workflow.

### Negative / accepted trade-offs
- **Windows Server 2016 reaches end of extended support ~January 2027** — security updates stop then. A move to Windows Server 2019/2022/2025 (or a Linux host) should be planned before that date. Recorded as a standing revisit trigger below.
- **A single self-hosted box is a single point of failure.** The NFR doc's **99.5% Phase-1 uptime target becomes self-managed** — bounded by the owner's patching, power, network, and hardware. Adequate for MVP/pilot; redundancy or managed failover should be evaluated before committing SLAs to paying customers.
- **Windows-production vs Linux-CI parity gap.** CI runs on Linux; production is Windows. Watch for OS-specific behaviour (path separators, case-sensitivity, file locking, `proc_open`/signals). Mitigation: keep code OS-agnostic; the RLS/tenant tests also run against the *real* Windows Postgres in production.
- **No managed zero-downtime deploys.** Single-box Windows deploys use a brief `artisan down`/`up` maintenance window rather than atomic zero-downtime swaps (Doc #22).
- **The team owns all ops** previously handled by the platform: Postgres backup/DR, Redis, TLS renewal, worker/service supervision, OS patching, and monitoring.
- **PHP on nginx/Windows has no FPM** — PHP runs as `php-cgi` FastCGI processes supervised by NSSM. If that proves fragile under load, **IIS + the PHP FastCGI module** is the more battle-tested Windows PHP host and is the documented fallback (Doc #22).

### Risks & Mitigations
| Risk | Mitigation |
|---|---|
| WS2016 EOL (Jan 2027) leaves the host unpatched | Plan the Server-version upgrade well before EOL; tracked as a revisit trigger |
| Single-box outage takes the whole platform down | Self-managed backups + a documented restore drill (Doc #22 §5); evaluate redundancy before paid-customer SLAs |
| Windows/Linux behavioural drift | OS-agnostic code; RLS/tenant tests run on Linux CI *and* against the real Windows Postgres; add a Windows CI job if drift appears |
| `php-cgi` FastCGI pool instability under load | NSSM auto-restart; IIS+FastCGI fallback; load-test before launch |
| Public network exposure (if not datacenter-hosted) | Static IP or dynamic DNS + firewall + TLS; treat public exposure as a security-review item (Doc #11) |

## Alternatives Considered
- **Laravel Cloud (ADR-0003, now superseded)** — fully managed, near-zero ops, but a recurring bill, less control, and an open RLS/PostGIS question. Remains the natural fallback if self-managed ops become a burden.
- **Laravel Forge + Linux VPS** — managed provisioning of an owned Linux VPS; closes the Windows/Linux parity gap and sidesteps WS2016 EOL, at the cost of a VPS bill and not using the owned hardware. The most likely future target if Windows self-hosting proves painful.
- **IIS instead of nginx on the same box** — more native Windows PHP hosting (mature FastCGI process manager); not chosen as primary per the owner's nginx preference, kept as the documented fallback for the PHP-process-management concern.

## When to Revisit
- **Before ~January 2027** (WS2016 EOL) — mandatory: upgrade the Server OS or migrate hosts.
- When availability needs exceed what a single self-hosted box can promise (paying-customer SLAs) — evaluate redundancy, Forge+VPS, or Laravel Cloud.
- If Windows-specific operational friction (php-cgi supervision, deploy downtime, parity bugs) becomes a recurring cost — evaluate IIS, a Linux VPS (Forge), or Laravel Cloud.

## Related Decisions
- **Supersedes ADR-0003** (Laravel Cloud).
- **ADR-0001** (Postgres) — unaffected; Postgres runs natively on Windows Server with full RLS/PostGIS.
- **ADR-0002** (multi-tenancy RLS) — unaffected and *de-risked*: we now control the Postgres instance, so RLS is guaranteed rather than assumed.
- **Doc #22 — Deployment & Infrastructure** — rewritten to operationalize this decision (nginx, Windows services, self-hosted-runner deploy, self-managed backup/DR).

## References
- Supersedes `docs/adr/0003-hosting-laravel-cloud.md`.
- `docs/deployment-infrastructure.md` — the operational runbook for this decision.
- `docs/non-functional-requirements.md` — availability/RTO/RPO targets, now self-managed.
- Owner decision (2026-07-04): self-host on the existing Windows Server 2016 with git-driven deploys.
