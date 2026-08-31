# ADR-0003: Laravel Cloud as Initial Production Hosting for MVP Launch

## Status

**Superseded by [ADR-0005](0005-hosting-self-hosted-windows-server.md) — 2026-07-04.** The project is now **self-hosted on the owner's Windows Server 2016** (nginx + self-managed PostgreSQL/Redis, git-driven deploys via a self-hosted GitHub Actions runner) rather than on Laravel Cloud. The analysis below is retained as the historical record of *why* Laravel Cloud was originally selected, and remains the reference if the project ever revisits a managed host — but it no longer describes the current hosting. See ADR-0005 for the decision in force.

_Originally: **Accepted** — 2026-07-03._

> Note on metadata: the source plan does not assign explicit ADR numbers or decision dates. This document is filed as ADR-0003 consistent with the plan's own hosting-choice item in its ADR checklist (§4, item 7: "ADRs ... hosting choice"), on the assumption that ADR-0001 and ADR-0002 cover the two decisions the plan calls out as needing to land first ("scaffold the Phase 0 repo/infra and write ADR #1 (Postgres vs. MySQL), ADR #2 (tenancy isolation model), and ADR #3 (hosting platform) first since they're foundational to everything else" — plan, "Next Steps"). The decision date is set to the date this ADR was drafted; re-date if formal sign-off happens later.

## Context

### The problem this ADR resolves

The new form-builder SaaS platform needs a production hosting target decided *before* Phase 0 infrastructure scaffolding begins, because the choice of platform shapes how CI/CD, queue workers, scheduled tasks, database provisioning, and secrets management are all built (plan, §4 item 22: "Deployment & Infrastructure Doc ... environments, CI/CD stages, secrets management, Postgres backup/DR runbook, queue/worker scaling, and the chosen hosting platform's specifics").

### Why the legacy deployment model cannot be reused

The legacy system was never deployed as a real production SaaS service — it ran on a **Laragon-style local/shared-hosting development setup**, with no committed Docker configuration (despite Laravel Sail being present as a Composer dependency, it was never actually used to produce a runnable, committed container definition) and a CI pipeline that ran PHPUnit only, with no build/deploy stage at all (plan, tech-stack table: "Legacy shipped no committed Docker (despite Sail as a dependency) and CI that ran PHPUnit only — both named, explicit fixes"; plan §5: "CI enforces static analysis + code style + a real deploy pipeline + committed Docker parity"). Concretely, this means the legacy deployment model has **no**:

- reproducible environment definition (no Docker Compose committed to the repo);
- automated build/deploy pipeline (CI ran tests only; deploys, if they happened, were manual/ad hoc);
- managed queue-worker process supervision (plan: "Legacy had 'no Redis in active use' — a real gap given webhooks/exports/sync all need reliable async processing with observability");
- multi-tenant isolation model at the infrastructure layer (legacy was single-organization, single-database, with no tenant-scoping concept at any layer);
- zero-downtime deploy mechanism, autoscaling, or managed backups.

That model was adequate for a single-organization internal tool serving one government department. It is **not viable** as the foundation for a multi-tenant SaaS product that needs queue-driven webhook delivery, scheduled jobs (subscription billing, usage-counter resets, export cleanup), horizontal scaling under variable multi-tenant load, and a real zero-downtime deploy story from the very first paying customer. Standing up an equivalent from scratch on bare VPS infrastructure is itself non-trivial engineering work that competes directly with Phase 0/1 feature work (plan §3, Phase 0: "repo/infra scaffolding, Docker Compose, multi-tenancy + RLS, tenant-scoped auth/RBAC, CI ... before many field types exist").

### Constraints that shape the decision

1. **Stack lock-in is already decided** (see the stack-recommendation ADR / plan §1): Laravel 11/12 + PHP 8.3+, Postgres, Redis/Horizon, Laravel Reverb, S3-compatible object storage, Cashier/Stripe billing. Any hosting choice is evaluated against how cleanly it supports *this specific* stack, not hosting in the abstract.
2. **Small team, no dedicated DevOps role** at MVP stage — the plan's stated philosophy is "start simple, add complexity only when justified" (plan, hosting recommendation section), which argues against taking on infrastructure ownership the team doesn't yet have the bandwidth to run well.
3. **Budget is not yet revenue-backed at the pre-launch stage** — the plan explicitly separates a *pre-revenue validation* posture (self-hosted, free) from a *committed production launch* posture (managed platform, paid), and this ADR needs to state which posture applies to which stage rather than picking one hosting answer for all stages.
4. **Every option's pricing is subject to change** — the plan itself flags that "exact Fillout.com pricing/limits and quantitative EAV-vs-JSONB benchmark figures should be treated as indicative, not pinned ... marketing pages change frequently, so re-check current figures before quoting them in customer-facing material." The same caveat applies to hosting-platform pricing cited below.

## Decision

**Launch the MVP's committed production deployment on Laravel Cloud**, Laravel's own first-party managed platform, once the product reaches the point of onboarding real (even if early/pilot) paying or paying-adjacent customers.

This is **sequenced**, not unconditional, and follows the plan's own staged recommendation (hosting recommendation section): validate the product first on a genuinely free environment (Oracle Cloud's Always-Free tier — see Alternatives, below) before committing spend to Laravel Cloud. Laravel Cloud is the target for the *committed production launch*, not necessarily for the earliest pre-revenue prototyping.

### What Laravel Cloud provides out of the box, mapped to this project's needs

| Capability needed by this project | How Laravel Cloud provides it |
|---|---|
| Queue worker process supervision (Horizon jobs: webhook delivery, exports, OCR processing, offline-sync replay) | Managed queue workers — no manual `supervisord`/systemd configuration |
| Scheduled tasks (subscription renewals, usage-counter resets, export cleanup, webhook dead-letter sweeps) | Managed Laravel scheduler execution |
| Autoscaling under variable multi-tenant load | Built-in autoscaling, no manual capacity planning at MVP scale |
| Zero-downtime deploys (a hard requirement once real tenants have data mid-flight) | First-party zero-downtime deploy mechanism |
| Postgres (the plan's chosen database — see the Postgres-vs-MySQL ADR) | First-party **serverless Postgres**, provisioned in-platform |
| Redis (Horizon queues, cache, Reverb pub/sub backing) | First-party **Valkey** (Redis-API-compatible) cache/queue backend |
| Object storage (the plan's unified `attachments` table backing store) | Bundled **S3-compatible object storage** |
| Realtime (Laravel Reverb — live dashboards, builder presence, sync triggers) | Native support, since Laravel Cloud is built by the same team that ships Reverb |

The practical effect: **one vendor covers every infrastructure primitive Phase 0/1 needs** (compute, queues, scheduler, Postgres, cache, object storage, realtime), with no separate accounts, no manual glue between a database provider and a Redis provider and a storage provider, and no custom process-supervision configuration to write and maintain. This directly serves the plan's Phase 0 goal of getting foundations in place with minimal ops overhead so the team's limited early bandwidth goes toward the form/submission domain model (§2.2) and the design system (§4 item 19), not toward hand-rolled infrastructure plumbing.

### Cost — explicitly a reference point, not a commitment

The plan cites an **indicative cost point of roughly €80–150/month** for a mid-size production SaaS workload shaped like this project's early stage: ~100,000 requests/day, one Horizon worker, ~20GB Postgres, Reverb realtime enabled, no free tier. This figure is carried into this ADR as the working planning number for early budget conversations, but it is **explicitly not verified against current published pricing** and must be re-confirmed before it is used to commit budget or quoted to any stakeholder. Concretely:

- **Action item**: re-check current Laravel Cloud pricing (compute tier, Postgres storage/compute tier, Valkey tier, egress/bandwidth, and any per-seat or per-app charges) against the actual published pricing page immediately before Phase 1 budget sign-off, and again before renewing/upgrading any plan tier as usage grows.
- **Action item**: re-verify this figure any time Laravel Cloud's pricing model changes (new platform, pricing structure changes are plausible given it is, per the plan, "the newest of the options").
- This ADR's decision to adopt Laravel Cloud does **not** depend on the €80–150/month figure being exactly right — it depends on Laravel Cloud being the best-fit *managed* option for this stack at MVP scale. The number is a budgeting input, not a decision input.

### What this decision does *not* settle

- It does not commit the project to Laravel Cloud indefinitely — see "When to Revisit This Decision," below.
- It does not remove the need for a committed, versioned Docker Compose setup for **local development and CI parity** (plan, tech-stack table: "Docker Compose + GitHub Actions with Larastan/PHPStan + Pint + Playwright + an actual deploy stage"). Laravel Cloud deploys directly from the Git repository and does not require the team to hand-write Dockerfiles for production, but Docker Compose remains the source of truth for local dev environment parity and is exercised in CI regardless of where production runs. This is a fine-grained detail the plan does not spell out explicitly; it is a reasonable, low-risk default consistent with the plan's explicit call for "committed Docker parity" as a standing CI requirement, independent of the hosting platform chosen.
- It does not decide the object-storage bucket layout, Postgres backup/retention schedule, or secrets-management specifics — those belong in the Deployment & Infrastructure Doc (plan §4, item 22), which should reference this ADR and fill in the platform-specific operational detail.

## Consequences

### Positive

- **Fastest path to a production-grade deployment.** No time is spent standing up and hardening a bespoke VPS/Docker/orchestration stack before the team can ship Phase 1 features. This directly serves the plan's Phase 0 goal of getting infrastructure foundations in place quickly so effort shifts to the form/submission domain.
- **Minimal ongoing ops burden.** Queue worker supervision, scheduled-task execution, autoscaling, and zero-downtime deploys are handled by the platform rather than by the team — meaningful for a small team with no dedicated DevOps role at MVP stage.
- **Single-vendor infrastructure surface.** Postgres, Valkey (Redis-compatible cache/queue backend), object storage, and compute are all provisioned and billed through one platform, reducing the number of external accounts, credentials, and integration seams the team has to manage and secure.
- **First-party alignment with the chosen stack.** Because Laravel Cloud is built and maintained by the Laravel core team, first-party features this project depends on (Horizon, Reverb) are supported natively rather than through third-party glue, reducing the risk of platform-specific compatibility surprises.
- **Consistent with the plan's stated philosophy** of "start simple, add complexity only when justified" — adopting a managed platform now defers the decision to self-manage infrastructure until there's an actual, traffic-driven cost or control justification for doing so.

### Negative

- **Higher per-request cost than a raw VPS at scale.** As request volume, worker count, and database size grow, Laravel Cloud's managed-platform pricing is expected to exceed the cost of provisioning equivalent capacity directly on a VPS (e.g., via Laravel Forge on Hetzner/DigitalOcean/Vultr). This is a known, accepted trade-off at MVP scale and is explicitly flagged as a future revisit trigger (below), not a defect in the current decision.
- **Less infrastructure control.** The team does not get root-server access, cannot fine-tune OS-level configuration, choose non-default Postgres extensions freely, or control patch cadence directly. For a project whose data model plans to lean on Postgres-specific features (JSONB+GIN indexing, Row-Level Security, and — per the plan's roadmap — PostGIS for geo field types in Phase 2), this needs an explicit, early confirmation that Laravel Cloud's managed Postgres offering supports Row-Level Security policies and the PostGIS extension before Phase 2 work depends on it. This is flagged here as a concrete due-diligence action rather than assumed to be fine.
- **No free tier.** Unlike Oracle Cloud's Always-Free option (see Alternatives), there is no zero-cost on-ramp — real spend begins as soon as the platform is adopted for anything beyond a time-limited trial, if one is offered. This is precisely why the plan sequences pre-revenue validation onto a free platform first and reserves Laravel Cloud for the committed-launch stage.
- **Newest of the evaluated Laravel-native options**, per the plan, meaning less operational track record and community troubleshooting knowledge exists for it compared to Laravel Forge (which has years of production history across a large user base). This is a real, if likely diminishing, risk that should be weighed if the platform shows rough edges during the Phase 0 spike or early Phase 1 operation.
- **Vendor dependency.** Migrating away from Laravel Cloud later (e.g., to Forge/VPS at scale, or to Fly.io for multi-region needs) requires a deliberate migration effort — data export/import for Postgres, object-storage migration, and re-establishing queue/scheduler configuration elsewhere. This is a standard managed-platform trade-off and is mitigated by keeping the application code platform-agnostic (no Laravel-Cloud-specific SDK lock-in beyond standard Laravel configuration), which is already the natural default for a Laravel application.

### Risks & Mitigations

| Risk | Mitigation |
|---|---|
| The €80–150/month indicative figure is stale or does not match this project's actual eventual usage profile | Explicit re-verification action items above; treat as a planning input only, confirm before any budget commitment |
| Laravel Cloud's managed Postgres may not support Row-Level Security or PostGIS the way self-hosted Postgres would | Confirm both during the Phase 0 spike, before RLS (plan §2.1) or PostGIS-backed geo fields (plan §3, Phase 2) are built against an assumed-available feature set |
| Platform immaturity surfaces operational issues (incidents, missing features, unclear support SLAs) not yet visible from documentation alone | Treat the Phase 0 spike as a real evaluation window — if Laravel Cloud proves unfit in practice, fall back to Laravel Forge + VPS without having built anything Laravel-Cloud-specific into the application layer |
| Cost grows faster than revenue as the tenant base scales | Track infrastructure spend as a standing input to the "When to Revisit" trigger below, not just at renewal time |

## Alternatives Considered

| Option | Summary | When it becomes the right call |
|---|---|---|
| **Laravel Cloud** *(chosen for committed MVP launch)* | Fully managed: queue workers, scheduler, autoscaling, zero-downtime deploys, serverless Postgres, Valkey cache, bundled object storage. No free tier; ~€80–150/mo indicative (unverified, re-check before committing). | Once there is real (even early/pilot) paying-customer traffic that justifies managed-ops cost, and the team wants to spend its limited bandwidth on product rather than infrastructure. |
| **Laravel Forge + VPS** (Hetzner/DigitalOcean/Vultr) | Forge provisions and manages a VPS you fully own; root-server access; cost-predictable and cheaper than Laravel Cloud at meaningful scale. No free tier (Forge itself ~$12+/mo on top of VPS cost). Trade-off: the team owns patching, scaling configuration, and ops — more team time than Laravel Cloud. | Once request volume/database size/worker count have grown enough that Laravel Cloud's per-request premium is a material line item, **and** the team has (or can hire) the ops bandwidth to own VPS management. This is the natural "graduate off Laravel Cloud for cost efficiency" path, not a Day-1 choice. |
| **Fly.io** (official Laravel Dockerfile + Fly Managed Postgres + Upstash Redis + Tigris storage) | Multi-region deployment story, genuinely useful for latency-sensitive, geographically distributed users. More manual configuration (Docker-based) than Laravel Cloud/Forge; smaller ecosystem of Laravel-specific tooling. Limited free allowance, not enough for a real Postgres+Redis+worker production setup. | Once the customer base is demonstrably multi-region/global — e.g., NGO or research-sector customers spread across continents (echoing Kobo/ODK's actual global user base) — and measured latency from a single-region Laravel Cloud deployment is a real, evidenced customer complaint rather than a hypothetical concern. |
| **Oracle Cloud "Always Free" Ampere VM** *(recommended FIRST, before Laravel Cloud, for pre-revenue validation)* | A genuinely free-forever (not trial) Ampere A1 Flex ARM VM: up to 4 vCPUs / 24GB RAM / 200GB storage — enough to self-host the full stack (Laravel + Postgres + Redis) via Docker Compose for early testing or a small pilot. Requires a credit card for identity verification only; Oracle enforces hard quotas so no surprise charges occur. The team owns 100% of the ops Laravel Cloud would otherwise handle (OS patching, Postgres/Redis setup, backups, TLS, deploys), and the ARM architecture occasionally trips up x86-only packages. | **Before** committing any spend to Laravel Cloud — this is the plan's explicit recommendation for validating the product at zero/near-zero cost during pre-revenue development, pilot testing, or demoing to early stakeholders. Migrate off Oracle to Laravel Cloud (or Forge/Fly.io) once there is paying-customer traffic that justifies managed-ops spend. This ADR's "Accepted" decision for Laravel Cloud governs the *committed production launch* stage; it does not contradict using Oracle's free tier during the stage that precedes it. |
| **Render / Railway / DigitalOcean App Platform** (generic PaaS) | **Render**: plan-based (not credit-based) billing avoids surprise shutdowns, but the free tier spins down after ~1 minute of inactivity — a hard blocker for any real production SaaS traffic; paid tier starts ~$25/mo (Pro) + compute. **Railway**: fast Git-based deploys and clean UX, but its "free tier" is really a one-time $5 trial credit followed by ~$1/mo of ongoing credit — services pause when credit runs out, an unacceptable risk for anything customer-facing. **DigitalOcean App Platform**: free tier is static-sites-only; any real backend/Postgres/worker setup is paid from day one (from ~$5/mo per container). None of these three offers a free tier that is actually viable for production SaaS traffic, and none is as tightly integrated with the Laravel ecosystem (Horizon, Reverb) as Laravel Cloud or Forge. | **Staging and demo environments only** — e.g., a low-traffic environment for internal QA, stakeholder demos, or a marketing-site preview, where occasional cold-starts or credit limits are acceptable. Not appropriate for production tenant traffic at any stage of this project, and not intended to be revisited as a production candidate later. |
| *(Also considered, rejected outright)* **AWS / Google Cloud free tiers** | Useful only if already committed to that cloud for unrelated reasons (existing customer relationship, compliance/region requirement). AWS's old 12-month free tier is gone, replaced by ~$200 in credits valid ~6 months; both clouds require the most manual setup (VPC, IAM, RDS, ElastiCache, etc.) of any option considered — the steepest ops learning curve for a small team with no dedicated DevOps role. | Not applicable to this project at its current stage; would only become relevant if a specific future customer's compliance or data-residency requirement mandated a specific hyperscaler, which is not a known requirement today. |

## When to Revisit This Decision

This ADR should be explicitly revisited (via a superseding ADR, not a silent infrastructure change) if any of the following occur — these are reasonable, concrete trigger conditions consistent with the plan's stated philosophy, proposed here since the plan does not pin exact numeric thresholds:

1. **Cost-driven**: infrastructure spend on Laravel Cloud becomes a material, recurring line item relative to revenue (e.g., consistently trending toward a double-digit percentage of MRR) — the trigger to seriously evaluate migrating high-traffic workloads to Laravel Forge + VPS.
2. **Latency/region-driven**: the tenant base includes customers with a demonstrated, measured latency problem from a single-region deployment (e.g., NGO/research customers on other continents reporting slow form-load or sync times) — the trigger to evaluate Fly.io's multi-region model.
3. **Control-driven**: a specific compliance, data-residency, or infrastructure-customization requirement emerges that Laravel Cloud's managed model cannot accommodate (e.g., a customer requiring a specific Postgres extension, patch schedule, or dedicated-tenancy infrastructure per plan §4 item 4's Phase-4 "dedicated-DB tenancy option, data-residency options").
4. **Platform-fit driven**: the Phase 0 spike, or early Phase 1 production operation, surfaces a concrete gap in Laravel Cloud's support for a required capability (confirmed absence of Row-Level Security support, PostGIS support, or Reverb/Horizon compatibility) that cannot be worked around.

Absent one of these triggers, Laravel Cloud remains the default production target through Phase 1–2 of the roadmap (plan §3).

## Related Decisions

- **ADR-0001** (Postgres vs. MySQL) — this decision assumes Postgres as the database engine; Laravel Cloud's first-party serverless Postgres offering is a direct enabler of that choice, and this ADR's Row-Level-Security/PostGIS due-diligence item depends on ADR-0001's conclusions.
- **ADR-0002** (multi-tenancy isolation model) — the shared-database, `tenant_id`-discriminated, Row-Level-Security-backed model (plan §2.1) depends on the hosting platform's Postgres offering genuinely supporting RLS policies; this is called out as a due-diligence item above rather than assumed.
- Future **Deployment & Infrastructure Doc** (plan §4, item 22) — should take this ADR's decision as given and fill in the platform-specific operational detail: environments, CI/CD stage configuration for Laravel Cloud, secrets management, Postgres backup/DR runbook, and queue/worker scaling configuration.

## References

- Source plan: `hi-lets-create-a-federated-meteor.md` — §1 "Hosting recommendation" (primary source for this decision), tech-stack table (Docker/CI gaps), §2.1 (Row-Level Security requirement), §3 Phase roadmap, §4 item 22 (Deployment & Infrastructure Doc), §5 best practices (CI/deploy-pipeline requirements).
- Legacy schema/deployment context: verified against the legacy system's actual repository state — cited in the plan's tech-stack table and §5 best practices. ⚠️ **The itemised inventory of that system's repository and CI posture was removed by `M51` (`D6`);** the requirement it produced is stated above and is what this decision rests on.
