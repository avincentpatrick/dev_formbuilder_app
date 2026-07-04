# Deployment & Infrastructure Doc

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v1.0 — `docs/adr/0003-hosting-laravel-cloud.md` explicitly reserves "environments, CI/CD stage configuration for Laravel Cloud, secrets management, Postgres backup/DR runbook, and queue/worker scaling configuration" for this document, and states it should "take this ADR's decision as given and fill in the platform-specific operational detail." This document does exactly that, plus operationalizes `docs/non-functional-requirements.md` §2/§8's availability and durability targets into a concrete runbook.

---

## 1. What's Already Decided (not repeated in full)

Laravel Cloud for committed production launch (Oracle Cloud Always-Free Ampere VM for pre-revenue validation first); Docker Compose remains the source of truth for local dev/CI parity regardless of where production runs; the CI pipeline stages 1–6 (`docs/testing-strategy.md` §6); the platform-provided primitives (managed Postgres, Valkey/Redis, object storage, autoscaling, zero-downtime deploys, Reverb).

**Standing due-diligence item, carried forward from ADR-0003, not yet resolved anywhere**: confirm Laravel Cloud's managed Postgres actually supports Row-Level Security policies and the PostGIS extension **during the Phase 0 spike**, before ADR-0002's RLS design or Phase 2's geo field types are built against an assumed capability. This document does not fabricate an answer to that open question — it states the requirement and the point in the timeline (Phase 0 spike) at which it must be confirmed.

---

## 2. Environments

| Environment | Purpose | Infrastructure |
|---|---|---|
| **Local development** | Individual developer machines | Docker Compose (Laravel, Postgres, Redis/Valkey-compatible, Mailpit for email capture) — the committed source of truth for environment parity (ADR-0003). |
| **CI** | Automated test execution (`docs/testing-strategy.md`) | The same Docker Compose service definitions, run as GitHub Actions service containers — guarantees CI tests against the identical Postgres/Redis versions a developer runs locally, not a CI-only substitute. |
| **Staging** | Pre-production validation, integration testing against real (non-production) Laravel Cloud infrastructure | A separate Laravel Cloud environment/app, isolated data (synthetic/seeded, never a production data copy containing real respondent PII — consistent with `docs/data-privacy-gdpr-compliance.md`'s posture). |
| **Production** | Live tenant traffic | Laravel Cloud, per ADR-0003. |

**Promotion path**: every merge to `main` deploys to staging automatically; production deploys are a deliberate, manual-trigger promotion from a staging build that has passed its own smoke-test pass — never a direct merge-to-production auto-deploy, given the blast radius of a bad deploy against live tenant data.

---

## 3. CI/CD — the Deploy Stage

Builds on `docs/testing-strategy.md` §6's stages 1–6 (static analysis → unit → integration → contract → build → E2E). **Stage 7, specified here**:

1. A successful E2E pass against the built artifact triggers a **staging** deploy automatically (Laravel Cloud's Git-integrated deploy mechanism — no custom deploy scripting needed for the common path).
2. Database migrations run as part of the deploy, **before** the new application code receives traffic (Laravel Cloud's zero-downtime deploy sequences this correctly by default) — a migration that would break currently-running old code is caught by the standard practice of writing backward-compatible migrations (additive-first, cleanup-after — a discipline stated here since it isn't specific to Laravel Cloud but matters more once zero-downtime deploys are real).
3. Production promotion is a **manual gate** (§2) — a human explicitly promotes a specific staging build, never an automatic cascade from staging success alone.
4. **Rollback**: Laravel Cloud's deploy history supports reverting to a prior successful build; a rollback that would require reversing an already-applied migration follows the same "additive-first" discipline (roll back application code first, address any migration reversal as its own deliberate follow-up, never an automatic down-migration in the hot path of an incident).

---

## 4. Secrets Management

- **Environment-specific secrets** (database credentials, Stripe API keys, the OCR provider's API key, the transactional email provider's credentials, the app's own encryption key) live in Laravel Cloud's built-in environment/secrets configuration — never committed to the repository, never in a `.env` file tracked by git (standard Laravel practice, restated here as a hard requirement, not an assumption).
- **Committed-secret prevention**: a secret-scanning check (`gitleaks` / GitHub secret scanning) runs in CI (`docs/testing-strategy.md` §6, stage 1) as a backstop against a credential ever reaching the repository — complementing, not replacing, the never-commit-`.env` rule above.
- **Per-tenant secrets** (webhook endpoint signing secrets, `docs/webhook-integration-design.md` §5's dual-secret rotation) are **application data**, not infrastructure secrets — they live encrypted in the database (Laravel's encrypted cast, per `docs/data-dictionary.md` §14's Design Notes), a distinct concern from platform-level secrets even though both are "secrets" in the colloquial sense.
- **Rotation cadence**: platform-level secrets (database credentials, API keys) are rotated on a recommended annual cadence at minimum, and immediately on any suspected compromise — no automated rotation mechanism is committed to in Phase 1 (a manual, documented runbook step), with automated rotation flagged as a plausible Phase 2+ hardening once the operational maturity to support it exists.

---

## 5. Postgres Backup & Disaster-Recovery Runbook

Operationalizes `docs/non-functional-requirements.md` §2 (RTO 4h/RPO 15min Phase 1) and §8 (continuous WAL + daily snapshot, 30-day retention):

1. **Backup mechanism**: Laravel Cloud's managed Postgres backup capability (continuous WAL archiving + daily snapshots) — confirmed against the platform's actual current capability during the Phase 0 spike (the same due-diligence item as §1), not assumed to match the NFR target automatically.
2. **Retention**: 30 days rolling, per the NFR doc.
3. **Restore drills**: a full restore-to-a-fresh-environment drill is performed **quarterly** — not just trusted to work because backups exist; this is a new, concrete operational practice this document introduces, since neither prior doc specified a drill cadence. A backup that has never been test-restored is not a verified backup.
4. **Incident procedure** (a skeleton; full detail is Doc #23's incident-response runbook, not duplicated here): declare incident → assess scope (single-tenant data issue vs. platform-wide) → restore from the most recent clean backup/PITR point → verify data integrity against the audit trail (`docs/audit-compliance-logging-spec.md`) for the affected window → post-incident review.
5. **RPO/RTO ownership**: this runbook's job is to make the NFR doc's targets achievable in practice; if a drill (step 3) reveals the actual achievable RTO/RPO doesn't meet the NFR targets, that is a finding to feed back into either the NFR doc (revise the target) or this runbook (improve the mechanism) — not a silent gap between a stated target and actual capability.

---

## 6. Queue & Worker Scaling

- **Queue priorities** (Horizon), reflecting the six submission channels and other async work already named across prior docs: `submissions` (all six channels' post-persist stages, `docs/architecture/technical-architecture.md` §4.1) and `webhooks` (delivery, `docs/webhook-integration-design.md`) get the highest priority — user-facing or near-real-time; `exports` and `ocr-processing` get medium priority — the user is shown a progress/pending state, not blocked; `scheduled-maintenance` (usage-counter rollups, retention purges, `docs/data-privacy-gdpr-compliance.md` §4) gets the lowest priority — no user is waiting on these.
- **Autoscaling**: Laravel Cloud's managed autoscaling handles worker-count growth under load — no manual capacity planning committed to at MVP scale (ADR-0003).
- **Per-tenant fairness** (a genuinely new addition — no prior doc specifies this mechanism, though `docs/architecture/technical-architecture.md` Risk R6 names the *problem*: "one high-volume tenant degrading queue latency for every other tenant"): queue jobs are tagged with `tenant_id` (already required for every job payload per ADR-0002 §D3), and a per-tenant job-rate ceiling (distinct from the API rate limits in `docs/api-specification.md` §2.5, which govern synchronous requests, not background job volume) prevents one tenant's bulk OCR-linelist batch or export burst from starving other tenants' queued work — a concrete mechanism closing a risk every prior doc only named, not solved.

---

## 7. Object Storage

- **Bucket layout**: `tenants/{tenant_id}/{category}/...` where `{category}` mirrors `docs/data-dictionary.md` §10's `AttachmentKind` values (e.g., `tenants/{tenant_id}/submission_file/...`, `tenants/{tenant_id}/export_artifact/...`) — a predictable, auditable layout that makes per-tenant storage-usage accounting (feeding `usage_counters.storage_bytes`, `docs/data-dictionary.md` §18) a straightforward prefix query rather than a cross-referencing exercise.
- **Lifecycle policy**: `export_artifact`-kind objects (generated CSV/XLSX/PDF exports) are automatically deleted **7 days** after generation — they are a convenience download, not a durable record (the underlying submission data remains in Postgres regardless); every other attachment kind persists per the ordinary soft-delete/retention rules already established (`docs/data-dictionary.md`, `docs/data-privacy-gdpr-compliance.md` §4).

---

## 8. Out of Scope / Deferred

- Metrics, dashboards, alerting, on-call rotation → Doc #23 (Observability & Incident Response Doc) — this document's incident-procedure skeleton (§5.4) is a pointer to that doc's full runbook, not a substitute for it.
- The actual, current Laravel Cloud pricing figures — ADR-0003 already flags these as requiring re-verification before any budget commitment; not re-verified here.
- Plan-tier-specific infrastructure quotas (e.g., does a higher plan tier get dedicated queue capacity) → Doc #24.
