# Non-Functional Requirements (NFR) Doc

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v1.0
**Purpose:** Sets measurable performance, availability, security-baseline, accessibility, and i18n **targets** — the numbers other docs build against. `docs/adr/0005-hosting-self-hosted-windows-server.md` (self-hosted Windows Server; supersedes ADR-0003) defers the Postgres backup/retention specifics to the Deployment & Infrastructure Doc, which in turn needs targets to implement against — this document is where those targets are set. Doc #11 (Security & Threat Model) measures threats against this doc's security baseline; Doc #22 (Deployment & Infrastructure) implements this doc's availability/durability targets operationally; Doc #23 (Observability) instruments against this doc's SLOs.

---

## 1. Performance Targets

Response-time targets are stated as **p50/p95**, measured server-side (excluding client network/render time), for the Admin/Builder app and public runtime under normal load (not during a traffic spike or a bulk export).

| Operation class | p50 | p95 | Notes |
|---|---|---|---|
| Authenticated page navigation (Inertia) | < 200ms | < 600ms | Dashboard, submissions inbox, settings. |
| Public form runtime — initial load | < 300ms | < 800ms | Excludes respondent's own network/device; assumes the form schema is already cached client-side per `docs/form-versioning-schema-migration.md`'s `checksum`-based cache-busting. |
| API read (`GET /api/v1/...`) | < 150ms | < 500ms | Non-export endpoints. |
| Submission ingest (manual, guest, API import) — single submission | < 250ms | < 750ms | Full `SubmissionPipeline` (validate → integrity → semantic validation → persist), one submission. |
| Expression evaluation (a single field's `relevant`/`constraint`) | < 20ms | < 80ms | Server-side sandboxed evaluator; a full form's worth of expressions (many fields) is expected to scale roughly linearly, not evaluated as one aggregate budget here. |
| Dashboard KPI query (org-wide or per-form) | < 400ms | < 1.5s | May use precomputed/cached aggregates rather than a live query if this target is not met by direct querying at scale. **Pinned by ADR-0011 §D7 (2026-08-03):** this clause is *permission, not instruction*. The Phase-3 posture is bounded live SQL under RLS — every analytics query time-bounded by a mandatory date range with a stated maximum span, form-bounded by an explicit set or scope subtree, and fixed-cardinality in its result — matching the doctrine `DashboardMetricsService` already carries. The precomputed-aggregate permission is exercised on a **measured** p95 breach of the 1.5s maximum on real tenant data, materialized as a `MaintenanceJob` fan-out (ADR-0007 §D3), never chosen up front; and any such rollup must never be able to disagree with the submission inbox (ADR-0011 §D2's countable-submission predicate). |
| Streamed/chunked export | No hard time limit | — | Correctness (chunked, memory-safe, per `docs/data-dictionary.md`'s stated pattern) and a visible progress indicator matter more than a fixed completion time for large exports; a target *throughput* (rows/second) is deferred until real export sizes are observed in Phase 1. |
| OCR extraction round-trip (single form) | — | < 15s p95 | Dominated by the third-party OCR provider's own latency (`docs/architecture/technical-architecture.md`'s C4 Context diagram already models this as an external dependency) — this is a target for the *product's* orchestration overhead around that call, not a commitment on the provider's own SLA. |

**Assumption flagged for the Phase 0 spike**: these targets assume the JSONB-hybrid submission model and the RLS-per-row overhead (both flagged as "not yet measured" in `docs/adr/0002-multi-tenancy-shared-db-rls.md` §D2 and `docs/multi-tenancy-rbac-design.md` §6) land within a small fraction of the above budgets. If the Phase 0 spike's benchmarking shows otherwise, these targets — not the underlying architecture — should be revisited first.

---

**Queued PDF generation (H17) holds a database transaction open for the whole render.** `TenantAwareJob` wraps `handleForTenant()` in `DB::transaction` — that is how RLS context is established for a worker, and the base class exposes no seam to render outside it — so a dompdf render is an *idle-in-transaction* hold for its duration. Bounded rather than eliminated: `GeneratePdfJob` raises `$timeout` from the base class's 60s to **110s**, which is the largest value permitted by the base class's own invariant that `$timeout` stay strictly below `queue.connections.database.retry_after` (120s), beyond which the queue can hand the same job to a second worker. At Phase-3 volumes (a per-tenant ceiling of 12 exports/minute, `config/queue-fairness.php`) this is acceptable; it is recorded here because a long idle-in-transaction hold blocks vacuum, so it is a real cost if PDF volume ever grows or if a future increment renders something much larger.

## 2. Availability & Reliability Targets

| Metric | Phase 1 (MVP) target | Later-phase aspiration |
|---|---|---|
| Uptime (monthly) | 99.5% (≈ 3.6 hours/month allowed downtime) | 99.9% once on dedicated infrastructure investment can be justified by revenue (Phase 3/4) |
| RTO (Recovery Time Objective) | 4 hours | 1 hour |
| RPO (Recovery Point Objective) | 15 minutes | 5 minutes (continuous WAL streaming configured on our **self-managed PostgreSQL** per ADR-0005, so point-in-time-recovery granularity is under our own control rather than a managed platform's — Doc #22 defines the WAL-archiving/PITR mechanism) |
| Planned-maintenance windows | Allowed, announced ≥ 24h in advance via the Settings-doc `maintenance.enabled`/`maintenance.message` mechanism (`docs/data-dictionary.md` §20) | Brief per-deploy windows (seconds — `artisan down`/`up`) are the norm on the single self-hosted Windows box; true zero-downtime is a future enhancement (atomic release-swap) or a host change — see ADR-0005 / Doc #22 §3.1 |

**Availability posture**: this 99.5% Phase 1 target is now **self-managed** — the platform runs on a single self-hosted Windows Server 2016 box (ADR-0005, which supersedes ADR-0003), which is a **single point of failure**. Uptime depends on the owner's own patching, power, and network rather than a managed platform's built-in redundancy; introducing redundancy (a warm standby / second node) should be revisited before any paid-customer SLA is offered.

**Degradation posture**: a partial outage (e.g., the OCR provider or the queue/worker tier is down) should degrade specific *channels* (OCR submission temporarily unavailable) rather than the whole platform — consistent with the "six channels, one pipeline" architecture already established (`docs/architecture/technical-architecture.md`), each channel's own health should be independently observable (Doc #23) and independently degradable.

---

## 3. Scalability Assumptions

Grounded in `docs/adr/0002-multi-tenancy-shared-db-rls.md`'s already-stated tenant-profile assumption ("a modest number of tenants — low tens to low hundreds — growing over time... cost-sensitive SMB/NGO/research customers, with a smaller number of larger...enterprise tenants anticipated later") and the founding architecture plan's own indicative hosting sizing note (~100k requests/day, one Horizon worker, ~20GB Postgres, Reverb realtime — explicitly flagged there as "a rough planning number, verify current pricing/capacity before committing," a caveat this document inherits rather than re-litigates):

- **Phase 1 target**: tens of tenants, low hundreds of concurrent authenticated users platform-wide, low thousands of submissions/day platform-wide.
- **Phase 2–3 target**: low hundreds of tenants, low thousands of concurrent users, tens of thousands of submissions/day — the point at which read replicas or connection-pool quotas (named as ADR-0002's own mitigation options for "noisy neighbor" contention) become worth implementing, not before. **Per-tenant rate limiting is no longer deferred**: ADR-0007 §D9 specifies a per-tenant job-rate ceiling as `RateLimited` job middleware, built in Phase 3 (H2), because Phase 3 is when async work becomes load-bearing and Risk R6 needed a real mechanism rather than a dangling cross-reference. Note it governs **queued work**, not HTTP requests — the HTTP limiters key on token-hash/IP by design (`AppServiceProvider:105-127`), since `throttle:api` runs before auth resolves the user.
- **Per-tenant ceilings are soft, not hard-coded**, until Doc #24 (Pricing & Feature-Gating Matrix) defines actual plan-tier quotas backed by `usage_counters` (`docs/data-dictionary.md` §18).

---

## 4. Security Baseline

**Target: OWASP ASVS Level 1, with several Level 2 controls adopted early** where they're already structurally implied by decisions this project has already made — not a from-scratch gap analysis, since much of Level 2 is already architecturally "free" here:

- Tenant data isolation as a defense-in-depth, database-enforced control (ADR-0002) already exceeds typical Level 1 access-control expectations.
- Audit logging of security-relevant events (`Auditable` trait, `AuditEvent` enum including `permission_changed`) already satisfies several ASVS V7 (logging) requirements at Level 1 and touches into Level 2.
- Role-based access control with an explicit permission matrix (`docs/multi-tenancy-rbac-design.md` §5) satisfies ASVS V4 (access control) requirements more thoroughly than a minimal Level 1 baseline demands.

**Full threat-scenario-by-threat-scenario mapping is Doc #11's job** (Security & Threat Model), not repeated here — this section exists only to fix the *target level* Doc #11 measures against, so that document doesn't have to also justify *why* ASVS (as opposed to some other baseline) was chosen.

---

## 5. Accessibility

**Hard requirement, CI-enforced, from Phase 1**: the **public form runtime** (guest/respondent-facing) meets **WCAG 2.2 Level AA**, verified by automated `axe-core` scans in CI on every change touching the public runtime's components (already named as a "Best Practice" in the architecture plan §5: "WCAG AA as a build-time constraint... checked via automated axe-core scans in CI"). `docs/ux/design-system-reference.md` already specifies the token-level contrast/component work this depends on — this document only fixes the *target level* and *enforcement mechanism*, not re-deriving the design system's own accessibility spec.

**Admin/Builder app**: targeted at the same WCAG 2.2 AA level as a goal, but **not CI-gating in Phase 1** — the respondent-facing runtime is the hard requirement because it's the surface guests with no account and no support relationship encounter; the authenticated builder/admin app gets the same design-system components (so most AA properties come "for free") but a missed edge case there is recoverable via support, unlike a guest silently unable to complete a form. Full CI gating on the admin app is a Phase 2 target.

**Manual audit cadence**: an annual third-party accessibility audit is recommended once the product has paying customers (Phase 2+), supplementing (not replacing) the continuous automated scans.

---

## 6. Internationalization (i18n) Requirements

Two genuinely distinct concerns, kept separate per `docs/domain-glossary.md`'s general discipline of not conflating adjacent concepts:

- **Form-content translation** (respondent-facing): already structurally supported from Phase 1 via the `{column}_translations` sibling-column pattern (`form_sections.label_translations`, `form_fields.label_translations`/`hint_translations`, etc. — `docs/data-dictionary.md`) and `tenants.supported_locales`/`forms.supported_locales`. **Target**: a tenant may author a form in as many locales as they've enabled; a respondent sees the form in their selected/browser-detected locale, falling back to the form's `default_locale`. **Interaction with answer piping (Phase 3, Doc #26 §4):** once a label may contain a `${key}` hole, every locale variant is independently a template and independently validated at publish. Two rules follow, both load-bearing. **(1) Order:** resolve the locale *first*, then render the template — filling holes before the fallback runs would interpolate into a string the respondent will never see. **(2) Parity is not required:** different grammars legitimately need different references, so variants need not carry the same hole set; the enforceable rule is only that every hole in every variant resolves. Locale codes remain free-form `varchar(10)` with no closed enum, and no `{column}_translations` column has any validation rule today — a gap piping is what finally forces closed.
- **UI chrome translation** (builder/admin/dashboard interface text): **English-only at Phase 1 launch.** Nothing in the PRD's Phase 1 acceptance criteria requires a localized admin UI, and building full UI-chrome i18n (extraction, translation-management workflow, locale-switching in the app shell) before there's a paying customer requiring it would be scope not currently justified by any stated requirement. Flagged here as a genuine **Phase 2+ candidate**, not a silent gap — revisit if a specific tenant's procurement requirement demands it sooner.
- **RTL (right-to-left) layout support**: **not built in Phase 1 or 2.** No current tenant profile or persona (`docs/PRD.md` §2) names an RTL-language requirement; the design system (`docs/ux/design-system-reference.md`) is not currently verified for logical-property/RTL-safe CSS. This is an explicit non-goal until a specific customer need materializes, at which point it becomes a design-system-level change (not a per-page patch) — flagged here so it isn't silently assumed to already work.
- **Locale-aware formatting**: dates, numbers, and timezones respect `tenants.timezone`/`default_locale` (already-modeled columns) for both form rendering and dashboard display — a Phase 1 requirement, since it's cheap to get right from day one and expensive to retrofit.

---

## 7. Browser & Device Support

| Surface | Support target |
|---|---|
| Admin/Builder app (Inertia) | Latest 2 versions of Chrome, Firefox, Safari, Edge (desktop); no IE11 or legacy Edge support. |
| Public form runtime (PWA) | Same browser matrix, plus installable-PWA behavior on the latest 2 major versions of iOS Safari and Android Chrome (Phase 2, when offline/PWA installability ships — `docs/PRD.md` Feature #5). |
| Minimum viewport | 320px width (small mobile) for the public runtime, per the design system's mobile-first responsive rules; the builder/admin app targets a 768px+ practical minimum (form-building on a phone screen is not a target use case, though the app shell itself remains responsive down to mobile per Main Feature #6/#5). |

---

## 8. Data Durability

| Target | Value |
|---|---|
| Postgres backup frequency | Continuous WAL archiving + daily full snapshot, **self-managed on our own Windows PostgreSQL** per ADR-0005 (mechanism defined in Doc #22) — no managed-platform dependency. |
| Backup retention | 30 days rolling, minimum. |
| Attachment (S3-compatible storage) durability | Whatever the object-storage provider's own durability SLA is (typically 99.999999999%-class for major providers) — not a number this product controls directly, stated here as an assumption to verify against the actual chosen provider in Doc #22. |
| Soft-delete grace period (forms, submissions, attachments, etc.) | 30 days before any hard-deletion/purge job runs, consistent with the soft-delete convention already stated in `docs/data-dictionary.md`. |

---

## 9. Observability Targets (cross-reference)

Doc #23 (Observability & Incident Response) instruments against these SLOs — not re-derived here. This document's job is limited to fixing the *targets themselves* (§1–§2, §8 above); *how* they're measured, alerted on, and operationally maintained belongs there.

---

## 10. Out of Scope / Deferred

- The full OWASP ASVS control-by-control checklist and threat-scenario analysis → Doc #11.
- Backup/DR runbook mechanics for the self-hosted Windows PostgreSQL → Doc #22 (ADR-0005).
- Metrics, dashboards, alerting, on-call → Doc #23.
- Plan-tier-specific quotas (submissions/month, seats, storage) → Doc #24.
- UI-chrome translation-management workflow and RTL layout support, if/when either becomes justified by a real customer requirement (§6).
