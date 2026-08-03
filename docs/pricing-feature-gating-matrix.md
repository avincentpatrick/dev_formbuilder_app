# Pricing & Feature-Gating Matrix

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v1.0 — written before any billing code exists, per the architecture plan's explicit sequencing. This document is the single reconciliation point for **every** plan-tier forward-reference other docs already deferred here: `docs/webhook-integration-design.md` (per-tier webhook limits), `docs/deployment-infrastructure.md` (dedicated queue capacity), `docs/data-privacy-gdpr-compliance.md` (retention/export quotas), `docs/non-functional-requirements.md` (submissions/seats/storage quotas), `docs/observability-incident-response.md` (SLA commitments), `docs/multi-tenancy-rbac-design.md` (seat/role quotas), `docs/ux/form-filling-ux-flow.md` (save-and-resume token gating), `docs/security-threat-model.md` (per-tier upload-size quota), and `docs/api-specification.md` §2.5 (the plan-tier-adjustable API rate-limit ceiling). Every one of those is resolved concretely below, not left open a second time.

---

## 1. Tier Activation by Phase

`docs/data-dictionary.md`'s `PlanTier` enum supports 5 values (`free`, `starter`, `professional`, `business`, `enterprise`) but states "Phase 1 ships with 2–3 active." **Concretely**: `free`, `starter`, `professional` activate in Phase 1; `business` activates in Phase 3 (it exists specifically to gate the custom-domain/advanced-analytics features that ship then, per `docs/PRD.md`'s roadmap); `enterprise` activates in Phase 4 (SSO/SAML, dedicated-DB tenancy). Rows for all 5 tiers may be seeded from day one (the schema already supports it), but `business`/`enterprise` are not offered for signup until their gated features actually exist — no tenant can buy a tier whose headline features don't ship yet.

---

## 2. Quota Matrix

Every column below is one of the 7 already-fixed `UsageMetric` values (`docs/data-dictionary.md` §16) — this document does not invent new metrics, only pins numbers per tier (all figures are Phase 1 planning defaults, explicitly **not pinned/final** in the same spirit as this project's other indicative-pricing caveats — re-verify against actual usage data before a real pricing page ships):

| Metric | Free | Starter | Professional |
|---|---|---|---|
| `forms_count` | 3 | 20 | Unlimited |
| `submissions_count` (per month) | 100 | 2,000 | 20,000 |
| `storage_bytes` | 500 MB | 5 GB | 50 GB |
| `active_seats` | 2 | 10 | 50 |
| `api_requests` (per month) | 0 (no API access — §3) | 10,000 | 100,000 |
| `webhook_deliveries` (per month) | 0 (no webhook access — §3) | 5,000 | 50,000 |
| `exports_count` (per month) | 5 | Unlimited | Unlimited |

**Enforcement policy — a deliberate split, not a single uniform rule**:
- **Hard-blocked quotas** (`forms_count`, `storage_bytes`, `active_seats`): the tenant cannot create a new form/upload a file/invite a member past the limit until they upgrade or free up capacity — these are resource-provisioning limits, not data-loss risks, so blocking is safe.
- **Never-hard-blocked**: `submissions_count`. **A respondent's submission is never rejected because the tenant exceeded their monthly quota** — losing a real respondent's already-completed data over the *tenant's* billing status would be a serious, avoidable harm, especially for guest/public submissions where the respondent has no visibility into or control over the tenant's plan. Exceeding this quota instead triggers an overage notification to the tenant (and, past a grace threshold, a prompt to upgrade) — it is a soft, informational/upsell quota, never a data-collection gate.
- **Rate-limitable, not data-loss-risking** (`api_requests`, `webhook_deliveries`): these can be safely throttled (`429`, per `docs/api-specification.md` §2.5's rate-limit mechanism) since throttling an API call or delaying a webhook delivery doesn't destroy a respondent's data — `docs/webhook-integration-design.md`'s existing retry/backoff mechanism already handles a delayed delivery gracefully.

---

## 3. Feature-Flag Matrix

Uses the **exact flag key names** `docs/data-dictionary.md` §16 already gives as its worked example (`offline_sync`, `xlsform_export`, `custom_domain`) rather than inventing parallel names:

| `feature_flags` key | Free | Starter | Professional | Business (Phase 3+) | Enterprise (Phase 4) |
|---|:---:|:---:|:---:|:---:|:---:|
| `api_access` | ✗ | ✓ | ✓ | ✓ | ✓ |
| `webhooks` | ✗ | ✓ (3 endpoints max) | ✓ (10 endpoints max) | ✓ (25 endpoints max) | ✓ (unlimited) |
| `xlsform_export` (bidirectional import/export) | ✗ | ✓ | ✓ | ✓ | ✓ |
| `offline_sync` (installable PWA/offline collection) | ✗ | ✓ | ✓ | ✓ | ✓ |
| `ocr_single` / `ocr_linelist` | ✗ | ✗ | ✓ | ✓ | ✓ |
| `save_and_resume` | ✗ | ✓ | ✓ | ✓ | ✓ |
| `native_connectors` (Slack / Sheets / Airtable) | ✗ | ✓ | ✓ | ✓ | ✓ |
| `branding` (tenant logo / theme) | ✗ | ✓ | ✓ | ✓ | ✓ |
| `form_templates` (reusable whole-form blueprints) | ✗ | ✓ | ✓ | ✓ | ✓ |
| `field_library` (reusable questions) | ✗ | ✓ | ✓ | ✓ | ✓ |
| `advanced_analytics` (advanced cross-form analytics) | ✗ | ✗ | ✗ | ✓ | ✓ |
| `custom_domain` | ✗ | ✗ | ✗ | ✓ | ✓ |
| Embedded payments (Stripe Checkout) — **deferred to Phase 4** | ✗ | ✗ | ✓ | ✓ | ✓ |
| `sso_saml` | ✗ | ✗ | ✗ | ✗ | ✓ |
| Dedicated-DB tenancy option | ✗ | ✗ | ✗ | ✗ | ✓ |
| Data-residency options | ✗ | ✗ | ✗ | ✗ | ✓ |

**Rationale for the two gating decisions most worth explaining**: `xlsform_export`/`offline_sync` are excluded from Free but included from Starter onward — both are genuine product differentiators (the Kobo/ODK migration lever, and the core "offline-first" pitch) that should be a *reason to upgrade from Free*, not something every trial user gets for nothing; `ocr_single`/`ocr_linelist` start at Professional, not Starter, because OCR processing carries real, ongoing third-party provider cost (`docs/ocr-pipeline-design.md`) that scales with usage — gating it to a higher-margin tier is a direct cost-alignment decision, not an arbitrary one.

**Added by ADR-0008 (entitlement & metering)** — the two rows the original matrix left open, now resolved: `native_connectors` (the Slack/Sheets/Airtable connector framework, one capability behind one flag key) is **Starter+ uniformly**, not a per-connector matrix; `branding` (tenant logo/theme) is **unbundled from `custom_domain`** and set to **Starter+**, because branding is a broad differentiator that should not require the Business-tier custom-domain purchase — while `custom_domain` itself **stays Business**. Embedded payments is annotated **deferred to Phase 4** (nothing consumes the Stripe-shaped columns until then, per ADR-0008 §D1); its Professional+ tier is retained as forward intent, not a Phase-3 offering.

**Pinned by ADR-0011 (H1e, 2026-08-03)** — the advanced-analytics row was the one capability row that never named its code key; it is `advanced_analytics`, and it is seeded in `PlanCatalog` for Business and Enterprise exactly as shown. Two consequences worth stating where the gate is documented: because **Business is `is_active = false`** (built and gated, held from sale per ADR-0008 §D6), the key is reachable in production only by a super-admin plan assignment or a per-tenant legacy override — so the analytics surface is **hidden** for tenants without it rather than shown with an upgrade prompt, since the prompt would point at a plan nobody can buy (ADR-0011 §D9). The same decision means any test asserting a real refusal must seed the catalog, and the e2e accessibility fixture must seed a Business-entitled tenant or the surface is never loaded at all.

**Added by H5c (the retro-gate)** — `form_templates` (the reusable whole-form blueprint gallery, Increment G9a) and `field_library` (the reusable-question library, Increment G9b) were shipped **ungated** in Phase 2 as route names only, with no feature-flag keys. H5c introduces the two keys and sets both to **Starter+**, alongside the other Phase-2 productivity differentiators (`xlsform_export`/`offline_sync`) — a reason to upgrade from Free, not something every trial user gets for nothing. Tenants existing at the H5c merge keep both permanently via the per-tenant legacy override (ADR-0008 §D5), so the retro-gate does not regress any live tenant.

---

## 4. Resolving the Specific Forward References

- **Save-and-resume tokens** (`docs/ux/form-filling-ux-flow.md` Appendix A item 9): confirmed as **Starter tier and above**, 30-day expiry, per that doc's own already-stated default — this document is the "reconciliation" that entry was waiting on, and it ratifies rather than changes that default.
- **Dedicated queue capacity per tier** (`docs/deployment-infrastructure.md` §6): **No tier gets dedicated queue infrastructure in Phase 1–3** — fairness is enforced entirely via the per-tenant job-rate ceiling `docs/deployment-infrastructure.md` §6 already specifies, applied uniformly regardless of tier (a Professional tenant's jobs aren't infrastructurally separated from a Starter tenant's, only rate-fairness-protected the same way every tenant is). Dedicated compute only becomes available bundled with **Enterprise's dedicated-DB tenancy option** (Phase 4) — reusing that already-planned infrastructure separation rather than inventing a second, parallel "dedicated queues but shared database" configuration nobody has asked for.
- **SLA commitments per tier** (`docs/observability-incident-response.md` §8): Free/Starter/Professional get **best-effort** service against the platform-wide 99.5% target (`docs/non-functional-requirements.md` §2) with no contractual guarantee; **Business** is the first tier where that 99.5% target becomes a **stated contractual commitment** (not a higher number, just a formalized promise of the same existing target); **Enterprise** SLA terms are negotiated per-contract, which may include the 99.9% aspiration `docs/non-functional-requirements.md` §2 already named as a later-phase target.
- **Retention/export quotas** (`docs/data-privacy-gdpr-compliance.md` §4): the tenant-configurable retention *policy* mechanism itself (the `settings` key, `retention.submission_retention_days`) is available on **every tier**, including Free — a compliance capability should never be paywalled, since that would create a perverse incentive against good data-privacy practice. What *is* tier-gated is `exports_count` (§2 above) — export *volume*, not retention *configurability*.
- **Seat/role quotas** (`docs/multi-tenancy-rbac-design.md` §12): `active_seats` (§2 above) is a **total-membership** cap, not a per-role cap — a tenant's 10 Starter-tier seats can be allocated across Owner/Admin/Form Editor/Reviewer/Viewer in any mix the tenant chooses; there is no separate "max 5 Form Editors" sub-limit, since a tenant's own internal role distribution is their business, not a billing lever this product should micromanage.
- **Per-tenant upload-size quota** (`docs/security-threat-model.md` §6): a flat per-file maximum size (e.g., 25 MB for Free/Starter, 100 MB for Professional+, covering the realistic range from a signature/photo up to a short video capture) applies alongside the aggregate `storage_bytes` quota (§2) — the two are independent controls (one bounds a single file, the other bounds total accumulated storage).
- **API rate-limit ceiling** (`docs/api-specification.md` §2.5): the per-minute API request ceiling is **uniform at 600 req/min across all Phase-1 tiers** (Starter, Professional). The per-*month* `api_requests` quota (§2) is the tier-differentiated value-metering lever; the per-minute burst ceiling is a uniform abuse/stability control, not a billing lever. Business/Enterprise may negotiate a higher ceiling per contract. This resolves the API spec's deferral of the ceiling to this document.

---

## 5. Sales Tax / VAT (Phase 1)

Subscription charges are tax-correct from Phase 1 via **Stripe Tax**, which computes per-line tax at checkout from the customer's `tenants.billing_country`/address and, where provided, `tax_id` (`docs/data-dictionary.md` §1). Meridian stores no local tax tables and hard-codes no rates — Stripe Tax owns rate determination, EU/UK VAT and reverse-charge handling, and tax-correct invoice generation; `tenants.is_tax_exempt` suppresses tax for qualifying customers (e.g. certain NGOs/institutions). This is a **compliance prerequisite** for invoicing business customers in tax jurisdictions, not a plan-tier quota — it applies identically on every paid tier and is therefore not a gating lever.

---

## 6. Out of Scope / Deferred

- Actual, publishable pricing (dollar amounts) — every number in §2 is a planning default for quota *ratios* between tiers, not a priced offer; real pricing is a commercial decision made closer to launch, informed by real infrastructure-cost data this document cannot yet have.
- Annual-vs-monthly discount structure — a `BillingInterval` mechanism already exists (`docs/data-dictionary.md` §17) but the actual discount percentage is a commercial decision, not an architectural one.
- Enterprise custom-contract terms beyond what §3/§4 already states — negotiated per-deal, not standardized here by design.
- Promotional/discount codes, referral programs — no existing doc requests these; not designed speculatively.
