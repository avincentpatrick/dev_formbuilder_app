# Observability & Incident Response Doc

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v1.0 — instruments the SLOs `docs/non-functional-requirements.md` already fixed, elaborates the incident-procedure skeleton `docs/deployment-infrastructure.md` §5.4 pointed here, and formalizes the specific dashboards the architecture plan already named by name (webhook delivery success rate, queue depth, ingest latency).

---

## 1. What's Already Decided (not repeated in full)

- Per-endpoint webhook delivery log (status, latency, response code/body excerpt, attempt count) and platform-level webhook metrics (delivery success rate, queue depth, oldest pending delivery) — `docs/architecture/technical-architecture.md` §7.4, `docs/webhook-integration-design.md` §5.
- NFR performance/availability targets this document's dashboards and alerts measure against (`docs/non-functional-requirements.md` §1–§2).
- The Deployment doc's incident-procedure skeleton (declare → assess → restore → verify → review) — this document supplies the full runbook that skeleton pointed to.
- Expression-evaluator client/server drift telemetry — already named as a mitigation for Risk R3 (`docs/architecture/technical-architecture.md` §9): "client/server evaluation mismatches are logged as telemetry to catch drift in production before it accumulates."

---

## 2. Logging Strategy

- **Structured (JSON) logs**, not free-text — every log line is machine-parseable, consistent with treating observability as a first-class engineering concern rather than an afterthought grep target.
- **Correlation IDs thread through the entire async chain**: a single `request_id` (or, for a channel with no originating HTTP request — e.g., a scheduled retention job — a generated `job_chain_id`) is attached to every log line from the originating action through every downstream queued job it triggers (submission persist → domain event → webhook dispatch → delivery attempt) — this is what makes it possible to answer "what happened to submission X" as one correlated trace rather than reconstructing it from unrelated log lines across several queue workers.
- **What is never logged**: raw PII-bearing field values, `users.password`/`remember_token`, webhook secrets, Sanctum tokens — the same redaction discipline `docs/audit-compliance-logging-spec.md` §2 already established for the audit trail applies equally to application logs, which are a second place sensitive data could otherwise leak if this weren't stated explicitly as a shared principle rather than two independently-invented redaction lists.
- **Log levels**: `error` (a request/job failed and needs investigation), `warning` (a degraded-but-recovered condition — e.g., a webhook retry succeeded on attempt 3), `info` (normal lifecycle events — a form published, a submission persisted), `debug` (verbose, disabled in production by default, enabled per-request via a support-tooling flag for live troubleshooting).

---

## 3. Metrics

| Subsystem | Key metrics |
|---|---|
| Submission ingest | Latency (p50/p95) and throughput, broken down **per channel** (manual/guest/OCR-single/OCR-linelist/offline-sync/API-import) — a single blended ingest-latency number would hide a regression specific to one channel, exactly the kind of per-channel drift `docs/testing-strategy.md` §2's parametrized pipeline suite already guards against at test time; this is its production-observability counterpart. |
| Webhooks | Delivery success rate, queue depth, oldest pending delivery (already named in the architecture plan), circuit-breaker open/close events per endpoint. |
| Queues | Per-queue depth and processing rate for each priority tier `docs/deployment-infrastructure.md` §6 defines (`submissions`, `webhooks`, `exports`, `ocr-processing`, `scheduled-maintenance`). ⚠️ **These metrics are Horizon-mediated and therefore UNBUILT (2026-07-21).** ADR-0007 §D1 runs the queue on the `database` driver with a plain `queue:work` and **defers Horizon**, so nothing emits per-queue depth or throughput today. **The minimum contract that does exist** is ADR-0007 §D12: every `TenantAwareJob` implements `failed(Throwable)` logging structured `{job, tenant_id, queue, attempts, exception_class}`, and a `MaintenanceJob` prunes `failed_jobs` on `scheduled-maintenance`. Depth is queryable directly from the `jobs` table in the interim. **Any tenant-facing failure surface built on this is a cross-tenant read of an RLS-free table** (`failed_jobs` carries no `tenant_id` policy), sanctioned only via `pgsql_superadmin` + `superadmin_bypass` — never a tenant-scoped query. Adopting Horizon is ADR-0007's named revisit trigger for when this observability becomes load-bearing. |
| Expression evaluator | Client/server evaluation mismatch rate (Risk R3's telemetry) — a non-zero rate here is always a defect, never expected background noise, and should page (§5) rather than sit in a dashboard unnoticed. |
| OCR pipeline | Extraction latency (against the `docs/non-functional-requirements.md` §1 target), confidence-score distribution (a shifting distribution over time may indicate a degrading OCR provider or a tenant's scan quality issue worth proactive outreach about). |
| Exports | Job duration, failure rate, by format (CSV/XLSX/PDF) and by sync-vs-async mode (`docs/api-specification.md` §2's mode split). |
| Database | Slow-query log (queries exceeding a threshold, e.g. 500ms), connection-pool saturation, RLS-policy-check overhead specifically (the accepted-but-unmeasured cost `docs/adr/0002-multi-tenancy-shared-db-rls.md` §D2 and `docs/multi-tenancy-rbac-design.md` §6 both flagged as "should be benchmarked, not treated as free" — this is where that benchmark's ongoing production reality gets tracked, closing the loop those two docs left open). |
| API | Rate-limit hit rate per caller type (`docs/api-specification.md` §2.5) — a high hit rate against a legitimate integration may indicate the tier's limit needs revisiting, not just that the limiter is "working." |

---

## 4. Tracing

Distributed tracing (e.g., via OpenTelemetry, vendor-agnostic per this project's general avoidance of unnecessary lock-in) follows the same correlation-ID chain as §2's logging — a single submission's journey through `SubmissionPipeline` stages, into a post-commit domain event, into a queued webhook dispatch, into an actual delivery attempt, is one trace, not four disconnected log streams a human has to manually stitch together during an incident.

---

## 5. Dashboards & Alerting

**Dashboards** (the three the architecture plan names by name, plus the additions this document adds): webhook delivery success rate, queue depth (per-priority-tier), submission ingest latency (per-channel) — **plus** an expression-evaluator-drift dashboard, a per-tenant usage dashboard (super-admin-facing, built on `usage_counters`), and an error-rate dashboard (5xx rate by endpoint).

**Alert thresholds**, tied directly to the NFR doc's targets rather than arbitrary numbers:

| Alert | Condition | Severity |
|---|---|---|
| Ingest latency SLO breach | p95 ingest latency exceeds `docs/non-functional-requirements.md` §1's target for 3 consecutive 5-minute windows | High |
| Queue depth runaway | Any queue's depth exceeds a sustained-growth threshold (queue is filling faster than it's draining) for 10 minutes. *Until the metrics above exist, this is a manual `jobs`-table check, not a live alert (ADR-0007 §D1).* | High |
| Webhook circuit breaker opened | Any tenant endpoint hits the 20-consecutive-failure breaker (`docs/webhook-integration-design.md`) | Medium (tenant-scoped, not platform-wide) |
| Expression-evaluator drift detected | Any client/server mismatch (§3) | High — always a defect, never noise |
| Backup/restore drill failure | The quarterly restore drill (`docs/deployment-infrastructure.md` §5) fails to complete or fails integrity verification | Critical |
| Availability SLO breach | Uptime tracking shows the monthly 99.5% target (`docs/non-functional-requirements.md` §2) is at risk given month-to-date downtime | Medium (trend alert, not a single-incident page) |

---

## 6. On-Call & Escalation

**Concrete, since no prior doc addresses staffing**: at MVP/Phase 1 scale, a **single-rotation on-call** (the founding engineering team) is sufficient — a full follow-the-sun or multi-tier escalation ladder is not justified until team size and tenant-criticality grow past what one rotation can reasonably cover. Every "High"/"Critical" alert (§5) pages the on-call rotation directly; "Medium" alerts route to a team notification channel for next-business-day triage, not an off-hours page. This is a deliberately lightweight starting posture, explicitly flagged as something to revisit (more rotations, formal escalation tiers) once team size or contractual SLA commitments to enterprise customers (Phase 4) require it — not built out prematurely for a team that doesn't yet exist at that scale.

---

## 7. Incident Response Runbook

Elaborates `docs/deployment-infrastructure.md` §5.4's skeleton into the full procedure:

1. **Detect** — an alert fires (§5), or a support/feedback report (`docs/data-dictionary.md` §21) surfaces a pattern suggesting an incident.
2. **Declare & classify severity**: **Sev1** (platform-wide outage or a confirmed cross-tenant data exposure — the highest-blast-radius failure mode ADR-0002 names explicitly), **Sev2** (a single tenant or a single subsystem/channel degraded, not platform-wide), **Sev3** (a non-urgent defect, no active user impact).
3. **Assess scope**: single-tenant vs. platform-wide (directly informs whether affected-tenant notification is needed at all, and how many tenants).
4. **Mitigate/restore**: per `docs/deployment-infrastructure.md` §5's backup/DR runbook if data recovery is needed; otherwise a targeted fix/rollback (§3 of that same doc).
5. **Verify**: cross-check the audit trail (`docs/audit-compliance-logging-spec.md`) for the affected window to confirm the actual blast radius matches the assessed scope before declaring the incident resolved.
6. **Breach-notification decision** (only if the incident involved a confirmed or suspected data exposure): `docs/data-privacy-gdpr-compliance.md` §8's 72-hour GDPR notification clock starts at the point the platform "becomes aware" of the breach (step 1/2 above) — this runbook's speed through steps 1–5 is precisely what determines whether that window is realistically achievable in practice, which is the concrete link between this document and that one.
7. **Post-incident review**: a blameless postmortem for every Sev1/Sev2 incident — timeline, root cause, what alerting/runbook gap (if any) delayed detection or resolution, and concrete follow-up actions with owners. Sev3 incidents get a lighter-weight note, not a full postmortem, to keep the process proportionate.

---

## 8. Out of Scope / Deferred

- Specific APM/logging/tracing vendor selection (Datadog, New Relic, Sentry, self-hosted Grafana/Loki/Tempo, etc.) — an implementation detail left to whichever team sets up the observability stack, not an architectural decision this document needs to pin.
- Formal multi-tier on-call/escalation policy — deferred until team size or enterprise SLA commitments (Phase 4) justify it (§6).
- Per-plan-tier SLA commitments (does a higher tier get a contractual uptime guarantee) → Doc #24.
