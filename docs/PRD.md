# Product Requirements Document (PRD)

## Form-Builder & Data-Collection Platform — working codename "Meridian"

| | |
|---|---|
| **Document status** | Draft v1.0 — approved planning direction, pre-implementation |
| **Date** | 2026-07-03 |
| **Product stage** | Greenfield / Phase 0 (no code written yet) |
| **Source of truth** | `hi-lets-create-a-federated-meteor.md` (approved architecture & feature plan) |
| **Audience** | Founders/product, engineering, design, and prospective pilot customers — written for a mixed technical / non-technical readership |
| **Related documents (planned, not yet written)** | *(none — every documentation-plan artifact is now written)* |
| **Related documents (already written)** | Domain Glossary (#2, `docs/domain-glossary.md`), Competitive/Feature-Parity Matrix (#3, `docs/competitive-feature-parity-matrix.md`), Technical Architecture Doc (#4), Data Dictionary (#5), ERD (#6, `docs/erd.md`), ADRs (#7, `docs/adr/0001-postgresql-over-mysql.md` / `0002-multi-tenancy-shared-db-rls.md` / `0003-hosting-laravel-cloud.md` *(superseded by 0005)* / `0004-form-rendering-engine-build-vs-buy.md` / `0005-hosting-self-hosted-windows-server.md` *(queue rows superseded in part by 0007)* / `0006-geospatial-field-storage-and-map-picker.md` / `0007-async-execution-substrate.md` / `0008-entitlement-and-metering.md` / `0009-oauth-connector-token-custody.md` / `0011-analytics-substrate.md` / `0012-custom-domain-resolution.md` / `0013-published-version-immutability-trigger.md` / `0014-tenant-brand-ramp-generation.md` *(0010 is reserved for H1d, the OCR provider bake-off — the gap is deliberate, not a missing file)*), Form Versioning & Schema Migration Design Doc (#8, `docs/form-versioning-schema-migration.md`), Multi-Tenancy & RBAC Design Doc (#9, `docs/multi-tenancy-rbac-design.md`), NFR Doc (#10, `docs/non-functional-requirements.md`), Security & Threat Model Doc (#11, `docs/security-threat-model.md`), Data Privacy & GDPR/Compliance Doc (#12, `docs/data-privacy-gdpr-compliance.md`), Audit & Compliance Logging Spec (#13, `docs/audit-compliance-logging-spec.md`), API Specification (#14, `docs/api-specification.md`), Webhook & Integration Design Doc (#15, `docs/webhook-integration-design.md`), XLSForm Interop Spec (#16, `docs/xlsform-interop-spec.md`), OCR Pipeline Design Doc (#17, `docs/ocr-pipeline-design.md`), Offline-First Sync Design Doc (#18, `docs/offline-first-sync-design.md`), UI/UX Design System Reference (#19, `docs/ux/design-system-reference.md`), Form-Filling UX Flow Spec (#20, `docs/ux/form-filling-ux-flow.md`), Testing Strategy Doc (#21, `docs/testing-strategy.md`), Deployment & Infrastructure Doc (#22, `docs/deployment-infrastructure.md`), Observability & Incident Response Doc (#23, `docs/observability-incident-response.md`), Pricing & Feature-Gating Matrix (#24, `docs/pricing-feature-gating-matrix.md`), Onboarding & Template Content Plan (#25, `docs/onboarding-template-content-plan.md`), Piping & Output-Encoding Design Doc (#26, `docs/piping-output-encoding-design.md`), Workflow Branching & Step-Graph Design Doc (#27, `docs/workflow-branching-design.md`), End-to-End Testing Guide (`docs/TESTING-GUIDE.md` — unnumbered, written in I6; the walkthrough the product owner tests the built application from, and the standing record of what is deliberately not built yet) |

> **A note on the product name**: no branding decision has been made yet. "Meridian" is used in this document purely as an internal working codename so the product can be referred to consistently without writing "the platform" several hundred times — it is not a naming decision and should be replaced once branding work happens. This is flagged explicitly as a placeholder-style decision made only for document readability, not a product commitment.

> **How to read this document**: this PRD uses one consistent vocabulary throughout, reconciling the three source traditions this product draws on — KoboToolbox calls a question an "indicator," Fillout calls it a "field," and the legacy system called it an "indicator" too. This document (and the product) standardizes on **form → section → field → submission**, matching the renamed data model in the approved architecture plan. The Domain Glossary (doc #2, `docs/domain-glossary.md`) is now the authoritative reference for every such term mapping and the reasoning behind each rename — consult it directly for anything not obvious from context here.

---

## 1. Vision & Problem Statement

### 1.1 The gap between KoboToolbox and Fillout.com

Two credible, mature products currently define the "form and data collection" market from opposite ends, and neither one covers the middle:

- **KoboToolbox** (and the broader ODK/XLSForm ecosystem it's built on) is the standard tool for rigorous field research: humanitarian response, public health monitoring & evaluation (M&E), academic surveys, census-style data collection. It is excellent at the things rigor requires — offline-first collection in low-connectivity areas, repeat groups (e.g., "list every household member," "log every clinic visit"), skip/validation logic via an expression engine, geolocation capture, and interoperability via the XLSForm standard. What it is *not* good at: modern, polished, self-serve UX; native multi-tenant SaaS billing; payments; slick embeddable forms; or the kind of "build a lead form in five minutes" experience a small business expects in 2026. Its builder and interface age is visible, and it is not designed for teams whose primary need is business intake/lead-gen rather than scientific data quality.

- **Fillout.com** is the standard for the opposite need: a modern, drag-and-drop form/workflow builder with conditional logic, native integrations, embedded payments, and polished embeds for marketers, operations teams, and small businesses. It is excellent at "build something good-looking fast" but has no answer for the things rigor requires — no repeat groups, no offline collection, no geopoint/geospatial capture, no XLSForm interoperability, no concept of immutable form versioning for scientific data integrity, and no natural fit for humanitarian/NGO/government data-collection workflows.

**The actual gap**: any organization that needs *both* — for example, an NGO that runs rigorous field surveys **and** wants a polished public-facing intake/registration form for a program; or a mid-size company that wants Fillout-grade lead forms **and** occasionally needs a real data-collection instrument with skip logic and offline capture for a field team — currently has to run two disconnected tools, with two logins, two data models, and no shared reporting layer. Meridian's core bet is that a single product, done well, can serve both needs without forcing either audience to accept the other's compromises.

### 1.2 What the legacy system proves — and what it doesn't

The clearest evidence for this gap is not market research; it is a real system that already exists: **`dev_pk_new`** ("Purok Kalusugan"), a Laravel + Inertia + Vue application built for the Philippine Department of Health to collect health data from local government units. Nobody set out to build "a Kobo competitor" — the team building a government health-reporting tool organically grew, over time and without a deliberate top-down mandate, into something that is *already, in substance*, a self-built KoboToolbox-grade form engine: 30 field types across 8 categories, an XPath-like expression engine for skip/validation logic, separately-modeled repeat groups, bidirectional XLSForm import/export, and even OCR-based paper-form ingestion channels.

That is strong, concrete evidence that **the demand for this hybrid is real** — a team building for a completely different, narrower purpose ended up needing nearly every capability this PRD describes, purely because the underlying problem (rigorous, structured, versioned data collection) forces those requirements into existence regardless of who's asking.

But the same system is equally clear evidence that **demand is not the hard part — execution is**. A direct code audit of the legacy system (not just its internal documentation, which had drifted from reality) confirmed real, material technical debt: controllers exceeding 1,700 lines each ("god controllers"), inconsistent and sometimes undocumented enum conventions, **no form versioning** (a form's `version` field is free text, never linked to the submissions collected against it — meaning editing a published form after data collection has begun silently corrupts the historical meaning of past answers), no multi-tenancy (it was built for one organization only), no genuine offline data-collection mode (only a "print blank form → fill on paper → photograph → OCR" workaround), and internal procedures/documentation that grew scattered and inconsistent over time rather than being maintained as a disciplined artifact.

Meridian's founding premise is therefore precise: **build the thing the legacy system proves people need, but build it the way the legacy system's own history proves it must be built** — with immutable versioning, real multi-tenancy, one unified submission pipeline, one design system, and documentation-as-code from day one, rather than retrofitting these after the fact. See Product Principle 3.1, "Inspiration, not inheritance."

---

## 2. Target Users & Personas

Meridian is deliberately built for two audiences who, per §1, are not usually served by the same product — plus two supporting roles that exist in every tenant regardless of which primary audience they belong to.

### 2.1 Persona A — The M&E / Research Field Team Lead

> **Priya Nair, Monitoring & Evaluation Coordinator, regional public-health NGO**

- **Context**: Priya manages a team of 8–20 field enumerators collecting structured survey data across low-connectivity rural areas — household surveys, clinic-visit logs, program-monitoring indicators for donor reporting. She currently uses KoboToolbox or a similar ODK-based tool, or (in some cases) a paper-and-spreadsheet process she's trying to get away from.
- **Goals**: rigorous, consistent data across every enumerator and every visit; the ability to collect data with no connectivity and sync later without losing or duplicating records; skip/validation logic so field staff can't submit obviously wrong data; repeat groups for "list every household member" style questions; the ability to migrate her existing Kobo/ODK forms in (XLSForm import) rather than rebuild from scratch; donor-ready exports.
- **Frustrations with the status quo**: Kobo's builder and dashboards feel dated and require training to use confidently; she has no easy way to also run a polished public registration form for program beneficiaries without switching tools entirely; cross-form/cross-round reporting for donor reports is manual and spreadsheet-heavy.
- **Representative user stories**:
  - "As an M&E coordinator, I want to design a household survey with repeat groups and skip logic, publish it, and know that every enumerator is collecting against the exact same version, so my dataset is internally consistent."
  - "As an enumerator, I want to fill out a survey on my phone with no signal, and trust that it will sync correctly once I'm back near a network, without creating duplicate records."
  - "As an M&E coordinator, I want to import an existing Kobo XLSForm I already use, rather than rebuild 80 questions by hand."
- **Features she relies on most**: form building with repeat groups/geopoint/full expression engine (#8), offline-installable PWA (#5), manual encoding for office-based data entry (#7), OCR channels for the paper-based sub-teams who still can't go fully digital (#1, #2), cross-form dashboard reporting for donor cycles (#4).

### 2.2 Persona B — The Business-Ops / Lead-Gen Owner

> **Jordan Blake, Head of Business Operations, mid-market services company**

- **Context**: Jordan owns intake and lead-generation processes — website contact forms, client onboarding questionnaires, event registration, internal request forms (IT tickets, expense requests). He currently uses Fillout, Typeform, or Google Forms, and occasionally needs something Fillout doesn't do well (e.g., a slightly more structured internal data-collection form) but doesn't want to learn a research-grade tool to get it.
- **Goals**: build a good-looking, on-brand form in minutes without engineering help; embed it on the marketing site or share a link; conditional logic to shorten the form for the respondent; take a payment inline for paid intake/service requests; get a Slack/Sheets notification the moment someone submits; see basic analytics on completion/drop-off.
- **Frustrations with the status quo**: general no-code form tools are polished but shallow the moment logic gets even moderately complex; anything resembling "real" data collection (structured validation, versioned publishing so past submissions don't silently reinterpret) is absent from consumer-grade tools; he has no way to hand a slightly more rigorous internal data-collection need to the same tool his marketing team already uses.
- **Representative user stories**:
  - "As an operations lead, I want to build a lead-intake form with conditional branching in one sitting, publish it, and embed it on our site the same day."
  - "As an operations lead, I want a payment step built into a service-request form, without integrating a separate payment vendor myself."
  - "As an operations lead, I want to see, at a glance, how many people started vs. finished the form this month."
- **Features he relies on most**: drag-drop form building with simple logic (#8), guest/public link + embed (#3), design-system-driven polish everywhere (#6), basic-then-advanced dashboard (#4), and — later in the roadmap — embedded payments, integrations, and custom domains (Phase 3, outside this document's Main-Features-table scope but noted in §6).

### 2.3 Supporting Persona — The Tenant / Platform Administrator

> **Sam Okafor, IT & Compliance Lead for a mid-size customer organization**

- **Context**: Sam doesn't build forms directly but is responsible for the organization's account — inviting/removing users, assigning roles, managing billing/plan tier, and (for organizations handling sensitive data, e.g., health data) ensuring the product meets the organization's data-privacy and security obligations.
- **Goals**: confidence that tenant data is genuinely isolated from every other customer on the platform; clear role-based access control; audit trails for who did what; predictable billing; a straightforward answer to "where is our data, and can we get it out."
- **Relevant features**: tenant-scoped RBAC and invites (Phase 1), audit trail (Phase 1), billing/plan management (Phase 1), and — later — SSO/SAML, data-residency options, and GDPR export/erasure tooling (Phase 4).

### 2.4 Supporting Persona — The Guest Respondent

Not a logged-in user at all: the person on the other end of a shared link or embedded form — a survey respondent, a program beneficiary, a website visitor filling a lead form. This persona has no dashboard, no account, and (by design) the lightest possible friction: no login, a fast-loading and accessible experience on any device, and (where the tenant enables it) a clear confirmation that their submission was received. Every guest-facing screen must meet the WCAG AA accessibility bar from the first release, because this persona, unlike the other three, cannot be assumed to have any particular device, technical comfort level, or bandwidth.

---

## 3. Product Principles

These principles govern every product and design decision below; where a feature description and a principle appear to be in tension, the principle wins.

### 3.1 Inspiration, not inheritance

The legacy system (`dev_pk_new`) and the two market references (KoboToolbox, Fillout.com) are sources of **proven ideas**, not blueprints to copy. Concretely:
- Every legacy concept carried forward (the unified submission pipeline, the separate-table repeat-group storage, the `{column}_translations` i18n pattern, the `Auditable` audit trait, the 30-type field catalog) is **reimplemented cleanly** against a new, versioned data model — never lifted file-for-file.
- Every legacy gap named in the approved architecture plan (no versioning, no multi-tenancy, god controllers, inconsistent enums, no real offline mode, ad hoc file-path columns, scattered/undocumented procedures) is treated as a **named, structural fix**, not an acceptable starting condition to be cleaned up "later."
- Kobo and Fillout are studied for their strongest verified patterns (Kobo: draft/publish versioning à la ODK Central; Fillout: embeddable polish and conditional-logic UX) — but Meridian does not attempt to be a pixel-for-pixel clone of either.

### 3.2 Intuitive by default

Every feature is designed to be usable **without a manual**:
- Clear, sensible defaults everywhere (e.g., a new form defaults to single-page mode with manual encoding and guest links off until explicitly enabled).
- **One consistent page layout and design language app-wide** (Product Principle 3.3) — a user who has learned the dashboard has effectively already learned 80% of the submissions inbox and the settings pages.
- **Progressive disclosure of advanced power**: the expression engine, XLSForm import/export, and cross-form analytics all sit behind explicit "Advanced" entry points rather than being forced into a beginner's first-run experience. A first-time business-ops user should never have to see the phrase "expression engine" to build a working lead form; a first-time M&E user should be able to reach it in one click when they need it.

### 3.3 One design system

A single shared app shell (navigation, header, content region) and one component library is used by *every* authenticated screen — builder, dashboard, submissions inbox, settings — and a token-consistent (but purpose-built) shell serves the public/guest form runtime. This is not a cosmetic preference; it is the single biggest lever the plan identifies for making the product feel coherent rather than assembled from parts, and it is why the design system is built **before** any feature page, in Phase 0/1 (see Main Feature #6, §5.6).

### 3.4 Data integrity is non-negotiable

Every submission is permanently and immutably tied to the exact form version it was collected against (§2.3 of the architecture plan). A form can always be edited going forward; it can never silently rewrite the meaning of data already collected. This principle is what directly fixes the legacy system's single most consequential defect and is treated as a structural constraint from the first migration written, not a "nice to have."

### 3.5 Privacy and security by default

Because at least one target persona (M&E/public-health field teams) routinely handles sensitive personal and health-adjacent data, tenant isolation, PII classification, audit trails, and server-authoritative validation are treated as baseline requirements for every tenant — not an "enterprise tier" upsell bolted on later.

### 3.6 Build for multi-tenant scale, gradually

Multi-tenancy, RBAC, and tenant isolation (routing, queries, jobs, storage, and a Postgres Row-Level-Security backstop) are structural from day one, because retrofitting tenant isolation into a single-tenant system is precisely the kind of rewrite this plan is designed to avoid. Where scale questions are genuinely open (e.g., dedicated-database tenancy for compliance-sensitive customers), the product defers the more expensive answer to a later phase rather than over-building for a need that hasn't yet appeared (see §6, Phase 4).

---

## 4. Goals & Success Metrics

Meridian's overarching (North Star) goal: **an organization with both a rigorous field-research need and a polished intake/lead-gen need can run both entirely inside one product, on one login, with one shared reporting layer** — something no competitor currently offers. Supporting goals and how progress against them will be measured are below. Because this is a pre-implementation PRD, the specific numeric targets below are proposed initial targets, not yet validated against real usage data — they should be revisited once Phase 1 has real tenants and are noted here as concrete, reasonable starting points rather than externally benchmarked figures.

| Goal | What it means | Success metric | Target | Phase / by when |
|---|---|---|---|---|
| **G1 — Fast time-to-value** | A brand-new tenant can go from signup to a published, shareable form with no help/support ticket | Time-to-first-published-form (TTFPF) | Median < 15 minutes | Phase 1 |
| **G2 — Rigor parity with Kobo-lite use cases** | An M&E user can replace a basic Kobo/ODK deployment for standard survey needs (skip logic, repeat groups, offline, XLSForm) | % of Phase-2 field-type/logic catalog shipped vs. legacy's 30-type/8-category baseline | ≥ 90% of catalog, full expression engine, offline sync live | End of Phase 2 |
| **G3 — Polish parity with Fillout-lite use cases** | A business-ops user can replace Fillout for lead-gen/intake use cases (payments, integrations, embeds) | Native integrations shipped; embedded payments live; custom domain/branding available | ≥ 4 native integrations, Stripe Checkout embed, custom domains | End of Phase 3 |
| **G4 — Hybrid value proven** | At least one real tenant uses both a rigor feature and a polish feature in the same account | Reference customer using ≥1 Phase-2 rigor feature (offline/repeat groups) **and** ≥1 Phase-3 polish feature (payments/embeds) | 1 documented reference customer | End of Phase 3 |
| **G5 — Data integrity holds under real use** | No incident where editing a published form retroactively changed the meaning of already-collected data | Count of versioning-integrity incidents | 0, always | Ongoing from Phase 1 |
| **G6 — Accessible by default** | The public form runtime never excludes a respondent because of assistive-technology needs | Automated axe-core WCAG AA pass rate in CI | 100% pass, blocking merge on regression | Phase 1 onward |
| **G7 — Reliable offline collection** | Field data collected offline is not lost or duplicated on sync | Offline submission sync success rate (first-attempt, no data loss) | ≥ 99.5% | Phase 2 |
| **G8 — Reliable integrations** | Webhooks and integrations are trustworthy enough for teams to build real operational processes on | Webhook delivery success rate within retry window | ≥ 99.9% | Phase 3 |
| **G9 — OCR channels are actually usable** | The two OCR channels (per-form, linelist) reach a quality bar where correction is a minor step, not a rebuild | Field-level extraction requiring manual correction | < 15% of fields on a clear, well-lit single-page scan | Phase 3 (initial release), improve after |
| **G10 — Platform reliability** | The product is dependable enough to be an org's system of record for submitted data | Uptime | ≥ 99.5% monthly (Phase 1); ≥ 99.9% is a Phase 3/4 aspiration once dedicated-infra investment is justified (see NFR §2) | Phase 1 onward |
| **G11 — Adoption / retention (business goal)** | The product is retaining paying tenants, not just acquiring trials | Month-3 logo retention among paying tenants | ≥ 80% | First full quarter post-launch |

---

## 5. Main Features

This section expands each of the approved features into a full description with acceptance-criteria-style requirements. Numbering (#1–#8) matches the original approved plan for traceability; Features #9–#12 were added afterward at the user's explicit request, and Features #13–#14 were added in the Phase-0-readiness review (best-practice gaps judged table-stakes for launch) — all numbered to continue that sequence, not renumbered into it. Phase references are to the roadmap in §6.

### Feature #1 — OCR Upload, per form
**Phase: 3 (optional channel, not core MVP)**

Upload a photo or scan of one filled paper form; OCR (Google Cloud Vision or an equivalent vendor) extracts field values into a review screen — confidence-scored and editable — before the data is finally submitted. The legacy system had a working version of this; Meridian rebuilds it as one more channel through the unified Submission Pipeline (see architecture plan §2.2) rather than as a separate, bolted-on code path.

Acceptance criteria:
- A user can upload a single-page image or PDF, associated with a specific form and its currently published version.
- The system extracts a value per field and attaches a 0–100 confidence score to each extracted value.
- A review screen shows every field with its extracted value (editable inline) and a visible low-confidence indicator (default threshold: below 70%) that must be individually confirmed or corrected before submission — there is no blind "accept all" for low-confidence fields.
- On confirmation, the submission is created through the same `SubmissionPipeline` used by every other channel, tagged `source = ocr_single`, with the original image stored via the shared polymorphic `attachments` table and the raw OCR payload retained for audit/debugging.
- Availability is gated by a per-form capability flag computed from the form's composition (e.g., a form containing repeat groups or media-capture fields that OCR cannot reliably parse is automatically excluded from this channel in the initial release) and by the tenant's subscription plan.
- Full confidence-threshold tiers, error handling, and the exact `ocr_compatible` eligibility rule are specified in the OCR Pipeline Design Doc (#17).

### Feature #2 — OCR Upload, linelist (batch)
**Phase: 3**

Upload one scanned tabular sheet containing *multiple* records at once (rows = respondents, columns = fields — a "linelist," in public-health terminology) and have it split automatically into multiple individual submissions in a single pass. This was confirmed, from the legacy system's own planning notes, as scoped but **never fully completed** ("similar to OCR but handles multiple submissions per file... not yet implemented") — this is Meridian's opportunity to actually finish the idea properly.

Acceptance criteria:
- A user can upload a single tabular image/PDF mapped to one form version's flat (non-repeat) fields.
- The system detects table structure (rows and columns) and presents a column-to-field mapping step; the mapping is confirmed once per form and remembered for subsequent uploads against the same form/version.
- Each detected row becomes a candidate submission, each with its own per-cell confidence scores.
- A batch review grid displays every candidate row, highlights low-confidence cells, and allows editing or discarding individual rows before a single batch-submit action.
- All submissions created from one upload share a common `source_batch_id` (a generalization of legacy's `batch_id` concept — see Data Dictionary §7) and are tagged `source = ocr_linelist`.
- The source scan's attachment correlates to the whole batch (not one arbitrary submission within it) and column-mapping drift detection across re-uploads is specified in the OCR Pipeline Design Doc (#17).
- **Explicitly out of scope for the first release**: repeat-group columns, long free-text columns, and linelists spanning more than one physical sheet/page — these are documented as a deliberate second iteration rather than attempted in the first pass, so the common case (a clean, single-page tabular sheet of scalar fields) can be made reliable before scope expands.

### Feature #3 — Guest responses
**Phase: 1 (MVP)**

Public, no-login form submission via a shareable link (`/f/{slug}`), with the guest's identity tracked by a signed token scoped to one specific **form version** — not to a login session. This is the primary channel that embeds, QR-code distribution, and paper-flyer-style link sharing all rely on, and it is the channel Persona B (business-ops) uses most.

Acceptance criteria:
- Each form has a unique public slug; guest access is an explicit, off-by-default per-form toggle.
- The public link resolves to whichever `form_version_id` is currently marked as the live public version (defaulting to `current_published_version_id`), and a signed token embeds that version reference — so a guest who starts filling out version 3 keeps a consistent experience even if the form owner republishes to version 4 mid-session.
- Guest submissions capture `guest_ip` and `guest_user_agent`; no account creation is required at any point.
- Tenant context for a guest request is resolved from the share token/slug, never from a session — satisfying the tenant-isolation rule that public endpoints must not depend on authenticated session state.
- Per-form, configurable rate limiting / bot-challenge (e.g., CAPTCHA) is available to curb spam submissions.
- The form is embeddable via iframe or script snippet on third-party domains — which is precisely why this channel is served by the dedicated public Vue SPA/PWA on a real REST API, not by the Inertia-based admin app (architecture plan §1).
- The guest-facing runtime meets WCAG AA from its first release (this is a build-time CI constraint, not a later audit item).

### Feature #4 — Dashboard
**Phase: 1 (basic) → Phase 3 (advanced cross-form analytics)**

Role-aware analytics: an org admin/owner sees org-wide KPIs, a form owner sees per-form stats, and a guest respondent sees nothing. The legacy system's per-role-payload pattern is confirmed-good and carries forward; what carries forward *changed* is real cross-form reporting/filtering, which the legacy system's own roadmap flagged as never fully built.

Acceptance criteria (Phase 1 — basic):
- Dashboard content is derived server-side from the requesting user's role and tenant, never filtered client-side from a superset payload.
- Tenant Owner/Admin view shows: total forms, total submissions (current period vs. prior period), count of forms currently accepting responses, a submission-volume trend, and the top forms by submission volume. *(Partially shipped: H11 delivers the counts as all-time totals — `DashboardMetricsService::forUser()` takes no period — so the period comparison, the volume trend and the top-forms list are computable but still unbuilt. H24a completes them.)*
- Form Owner/Editor view shows: submissions over time for that form, a **draft-conversion rate** and the **median time from first save to submission** — both on forms with save-and-resume enabled only, both stating their denominator on the face of the tile, and both suppressed rather than estimated for any period extending beyond the tenant's draft-retention window — and a breakdown by submission channel (manual / guest / OCR / API / offline-sync).

  > **Amended by ADR-0011 (H1e), 2026-08-03.** This criterion originally promised *"a completion rate where measurable (started vs. completed), average completion time."* Neither is computable from the platform as built, for any form. A respondent who opens a form and abandons it leaves **no server-side row at all** — a `submissions` row is created only on submit, or when the respondent explicitly clicks "Save and finish later" on a save-and-resume-enabled form — so "started" is unobservable; and that explicitly-saved population is *hard-deleted* by the daily draft reaper at expiry, so a series over it is not reproducible. Elapsed time fares no better: `submitted_at − created_at` spans a single server request for a direct submit, and calendar time across the respondent's absence for a promoted draft — never time spent filling. A true completion rate, per-step drop-off and average fill time require the form-engagement event stream that ADR-0011 defers to Phase 4. "Where measurable" is made precise here rather than left for an implementer to interpret at build time.
- No analytics surface is exposed to anonymous/guest respondents.
- The dashboard lives inside the standard app shell/design system (#6) from its first release — it does not invent its own layout.

Acceptance criteria (Phase 3 — advanced):
- Cross-form aggregation and filtering within a tenant (e.g., all forms tagged to one program or campaign). *(ADR-0011 §D6: there is no tag/label/category model on `forms` — the grouping axis is `forms.scope_node_id` over the G10 hierarchy, selected as a subtree, plus an explicit form set. It is single-valued and NULL until assigned, so a grouped result always carries an explicit "Unassigned" bucket. §D3 also bounds what "aggregation" covers: submission **metadata** for every form; answer **values** only for questions an author flagged "Indexed for reporting", from the version they flagged it onward.)*
- Saved/custom report views per user.
- Streamed/chunked export of dashboard data (CSV/XLSX), using the same export pattern as submission exports.
- Advanced cross-form analytics is a paid-tier feature gate, per the Pricing & Feature-Gating Matrix (doc #24) — the `advanced_analytics` key, Business and above.

### Feature #5 — Mobile mode on all pages
**Phase: 1 (responsive baseline) → Phase 2 (installable PWA/offline)**

Not just the public form runtime — the builder, dashboard, submissions inbox, and settings are all responsive/mobile-first from day one, and the public runtime is additionally PWA-installable for the offline collection flow. This is a cross-cutting constraint enforced by the design system (#6), not a page-by-page afterthought.

Acceptance criteria (Phase 1):
- Every screen in the product — builder, dashboard, submissions inbox, settings, authentication — remains fully readable and operable at mobile (≤480px), tablet (≤1024px), and desktop breakpoints, enforced through the shared design system rather than per-page custom CSS.
- Touch-target sizing and interactive-element spacing meet WCAG 2.2 AA guidance across every page, not only the guest-facing runtime.
- Responsive behavior at each defined breakpoint is covered by automated Playwright checks in CI, so a regression is caught before merge rather than reported by a user.

Acceptance criteria (Phase 2):
- The public runtime is installable as a PWA (manifest + service worker) on both mobile and desktop.
- Previously-downloaded form schemas (pinned to a specific `form_version_id`) and in-progress/queued submissions remain available and fillable with no network connectivity.
- The Background Sync API automatically replays the queued submissions on reconnect (with a foreground/manual-sync fallback on platforms with weaker Background Sync support, notably iOS Safari — see the Offline-First Sync Design Doc #18 §7); the user sees a clear per-submission status (queued / syncing / synced / failed).
- Replay is idempotent, guaranteed by a client-generated `client_submission_uuid` plus a server-enforced `(tenant_id, client_submission_uuid)` uniqueness constraint — a device can safely retry without creating duplicate records. Full manifest schema, storage-quota handling, and conflict-detection mechanics are specified in Doc #18.

### Feature #6 — Standard page design/layout template (UI/UX)
**Phase: 0–1 (built first; every later feature sits inside it)**

One shared app shell (navigation, header, content region) and one component design system, used by *every* screen, so the builder, dashboard, submissions inbox, and settings all feel like one coherent product. This is the single biggest lever for "as intuitive as possible" (Product Principle 3.3) and is documented as its own living artifact (see doc #19, UI/UX Design System Reference).

Acceptance criteria:
- A documented design-token set (color, spacing, typography, elevation, breakpoints) and component library exist before any feature-specific page is built, and every subsequent page is built from that library — no page invents its own one-off layout or component.
- One shared app shell wraps every authenticated screen; a distinct but token-consistent shell wraps the public/guest form runtime.
- Accessible defaults (WCAG AA color contrast, visible focus states, full keyboard navigability) are built into the component library itself, not retrofitted per page.
- The system is documented as a living reference tied to an actual component library (e.g., Storybook or equivalent) and is updated in the same pull request as any component change — a direct, explicit fix to the legacy system's confirmed documentation-drift problem.
- Progressive disclosure (Product Principle 3.2) is a first-class pattern in the system itself: advanced/power-user surfaces are visually and interactionally secondary by design, not an afterthought bolted onto an already-shipped page.
- Any exception to using the shared shell/system requires an explicit, documented rationale — it is not a default option for a feature team under time pressure.

### Feature #7 — Manual encoding
**Phase: 1 (MVP)**

Direct digital data entry against a form's currently published version — the baseline, always-available submission channel, funneled through the same unified pipeline as every other channel.

Acceptance criteria:
- Any authenticated user with the appropriate role can create a submission directly against a form's current published version through a digital entry screen.
- Manual-entry submissions pass through the exact same `SubmissionPipeline` (validate → integrity checks → semantic validation → transactional persist) as every other channel — there is no separate or duplicated manual-entry validation path.
- A basic in-session save-as-draft capability is available from Phase 1 for long forms (this is a lighter version of the full partial-submission/save-and-resume feature planned for Phase 3).
- Every manual-entry submission is attributed to the entering user (`respondent_user_id` — the same column guest submissions leave `NULL`, per Data Dictionary §7) and to the specific `form_version_id` it was entered against — never to a live, mutable form record.
- Manual encoding is available on every subscription tier; it is the one channel never gated behind a paid plan.

### Feature #8 — Form building (Kobo + Fillout + legacy inspired)
**Phase: 1 (core field types + simple logic) → Phase 2 (full expression engine, repeat groups, geo, XLSForm)**

The core builder: a structured field/section editor (not raw JSON, not unrestricted freeform drag-repositioning — the legacy system's categorized-type-picker-plus-live-preview approach worked well and its *spirit* is worth keeping), the field-type catalog, skip/validation logic (a simple UI first, with the full expression engine behind an "Advanced" toggle), and XLSForm import/export for Kobo/ODK interoperability. This is the product's center of gravity — every other feature orbits it.

Acceptance criteria (Phase 1):
- The builder presents a categorized field-type picker (grouped by type family, e.g., Text / Numeric / Date-Time / Choice / Media / Structural) alongside a live preview pane — never a raw-JSON editing mode, and never unconstrained freeform drag-repositioning of fields on a canvas.
- The Phase 1 field-type catalog covers, at minimum: short text, long text/paragraph, number (integer or decimal — the Data Dictionary's `FieldType` enum models these as separate `integer`/`decimal` cases, not one generic "number" case), date, single-select, multi-select, file upload, and signature capture.
- Sections (the renamed successor to the legacy `indicator_groups` concept) organize fields, with drag-and-drop reordering both within and across sections.
- A simple, no-code validation UI (required, min/max length or value, pattern match) is available without requiring the expression engine.
- A simple, no-code skip-logic UI ("show this field if [field] [operator] [value]") is available without requiring the expression engine.
- The draft/publish model is live from Phase 1: edits happen on a single mutable draft; **Publish** snapshots the draft into an immutable `form_versions` record and increments the version number; a published version's fields are never mutated in place (see architecture plan §2.3 for the full mechanism).
- Every builder screen lives inside the standard app shell/design system (#6).
- The builder provides multi-step **undo/redo** over draft edits (add/delete/move a field or section, edit a field's config) via a client-side edit-history stack — so an accidental delete or mis-drag on an unpublished draft is always recoverable without manual reconstruction. Immutable versioning (Principle 3.4) protects *published* data; undo/redo protects *in-progress draft* editing, a distinct data-loss path the versioning model does not cover.
- A form is not limited to a single editor: its creator (attributed as the form's owner) can grant specific tenant members editor or reviewer access to that specific form, beyond the tenant-wide access already held by Owner/Admin roles. Full role/permission mechanics are specified in the Multi-Tenancy & RBAC Design Doc (#9).
- Every publish automatically writes a plain-text changelog summarizing what changed since the last published version (fields/sections added, removed, or changed in a way that affects cross-version comparability) into that version's record — the publisher may add their own note alongside it. Full mechanics, including the underlying change-classification system and the forward-only "restore an old version" flow, are specified in the Form Versioning & Schema Migration Design Doc (#8).

Acceptance criteria (Phase 2):
- The field-type catalog expands to include repeat groups (with configurable min/max instances), matrix/grid questions, Likert-scale questions (stored as a numeric score internally), N-level cascading select, and geopoint/geotrace/geoshape capture (backed by PostGIS) — working toward the legacy system's roughly 30-type/8-category catalog as a starting point, not a hard ceiling.
- The full expression engine is available behind an explicit "Advanced" toggle: `${field}` references, `if()`, `selected()`, cross-repeat `count()`, and `today()`/`now()`, modeled on XLSForm's `relevant`/`constraint` semantics. Expressions are evaluated server-side in a sandboxed evaluator — there is no dynamic `eval()`-style code execution at any point, carrying forward the legacy system's one confirmed-good safety discipline in this area.
- Bidirectional XLSForm `.xlsx` import and export is available, validated against a documented column-by-column mapping specification (doc #16), giving customers a genuine migration path to and from Kobo, ODK, and SurveyCTO.
- Calculated fields are supported without `eval()`, mirroring the legacy system's confirmed-good pattern for this specific capability.

Acceptance criteria (Phase 2/3):
- An interactive, field-by-field version-comparison view (draft vs. currently-published, or any two historical versions) — Doc #8 §5 and §11.

### Feature #9 — User style/theme preferences
**Phase: 1 (theme mode) → Phase 2 (accent color, text size, accessibility fonts)**

Each authenticated user can personalize their own view of the authenticated app — theme mode, accent color, and text sizing — independent of a tenant's own branding of the public/guest form runtime (a separate, tenant-level concern gated in Phase 3, §6). This is built directly on the Design System's primitive→semantic token architecture (doc #19 §2.1), which already ships both a light and a dark theme from day one — personalization is one more semantic-token remapping layer, not a parallel system invented for this feature.

Acceptance criteria (Phase 1):
- An "Appearance" panel (inside Settings, Feature #10) lets a user choose theme mode: **Light / Dark / Match System**. Match System is the default and simply lets the design system's existing OS-level `prefers-color-scheme` behavior take effect; Light/Dark are explicit per-user overrides.
- The choice is stored server-side against the user and persists across sessions and devices — not merely in browser local storage.

Acceptance criteria (Phase 2):
- A user may additionally choose an accent-color override from a **small, curated set** of accessible alternatives to the default Blueprint primary (doc #19 §2.2) — never an arbitrary color picker. Every option in the set is pre-verified to clear the same WCAG AA contrast minimums as the default (doc #19 §4.1), so personalizing can never produce an inaccessible combination.
- A user may choose a text-size scale (**Standard / Large / Extra Large**), uniformly remapping the design system's type-scale tokens (doc #19 §2.4) — never a per-page or per-component override.
- A **dyslexia-friendly font** toggle swaps only the Body type role (doc #19 §2.4) to an appropriate alternative face, leaving the Display and Utility/mono roles untouched — a targeted accessibility accommodation, not a general typeface picker (which would undermine the "one design system" principle, §3.3).
- Personalization is strictly a **per-user, authenticated-app-only** concern. It never affects how a tenant's public/guest-facing forms render to respondents — that remains governed by tenant branding (Phase 3), a distinct concept serving a distinct audience (respondents, not builder/admin users).

### Feature #10 — App Settings (toggles, modules, maintenance mode)
**Phase: 1**

A settings area giving tenant Owners/Admins — and, for platform-wide concerns, the platform super-admin — direct control over operational toggles. This revives the spirit of the legacy system's `Settings` singleton (site title, maintenance mode, registration toggle) but rebuilds it tenant-aware, wired into the same config-flag-gated-rollout mechanism already named as a cross-cutting best practice in the architecture plan (§5) — not a second, parallel toggle system invented for this feature.

Acceptance criteria:
- **Registration/access control**: a tenant-level toggle for whether new members can self-register or must be invited, plus a separate platform-level toggle (super-admin only) for whether new tenant signup is currently open.
  - *As built (I5).* Before this increment the "self-register" position had nothing to do: registering at `acme.<host>/register` created an account belonging to **no workspace at all**, since invitations were the only way in. I5 built the missing half — with the toggle off (open), a registration on a tenant's subdomain now creates that tenant's membership at the least-privileged role (Viewer), audited with `via: self_registration`, and an Owner promotes from the Members page. Invitation-only is the default and **404s** `/register` on that subdomain (not 403 — a 403 would confirm both that the workspace exists and that it is invitation-only, which is exactly what a subdomain-prober is after).
- **Maintenance mode**: a tenant-level toggle that blocks new guest submissions and shows a configurable message on that tenant's public forms while leaving the authenticated admin app usable (so an admin can turn it back off); a separate platform-level maintenance mode blocks the entire product with its own configurable message, for planned platform-wide maintenance windows.
- **Per-module toggles**: individual enable/disable switches for optional capability areas as they ship (e.g., OCR channels, a specific integration, webhooks), surfaced per-tenant based on what their subscription plan includes — reusing the same capability-flag mechanism that already gates per-form OCR eligibility (Feature #1), not a second flagging system.
  - *As built (I5), two qualifications.* The toggle composes into `EntitlementService::feature()` as a third layer and can only ever **subtract**: effective = (grandfather override ∥ plan flag) AND NOT tenant-disabled. It is tenant-writable and the plan flag is not, so letting it win outright would make an Owner's own settings row an escalation path. And the offered set is **curated** rather than "every plan feature": `branding` and `custom_domain` are excluded because switching either off would hide the surface ADR-0012 §D9's downgrade escape hatch depends on while a hostname is still resolving, and the four provisioning/commercial tiers (`sso_saml`, `dedicated_db`, `data_residency`, `embedded_payments`) are excluded because a self-service switch for them would be theatre.
- **Version/build info**: a simple "About" panel showing the currently deployed application version (semantic version + build/deploy timestamp), for support and debugging purposes.
- Every settings change is written through the same audit pipeline as any other tenant-owned record (Feature #12) — who changed which toggle, and when, is never lost.
- The Settings area lives inside the standard app shell/design system (#6), organized by section (Access, Maintenance, Modules; billing is covered by the existing Cashier/Pricing-Matrix work and not duplicated here) rather than as one long unstructured list of switches.

### Feature #11 — In-app feedback mechanism
**Phase: 1**

A lightweight, always-available way for a user to report a problem or leave a remark without leaving their current screen — capturing a screenshot and a short description, plus enough automatic context (page, browser, tenant) that the reporter never has to re-explain where they were.

Acceptance criteria:
- A persistent "Send Feedback" entry point lives in the standard app shell (#6) on every authenticated screen.
- Opening it presents a lightweight, dismissible panel (never a full-page navigation) with: an optional one-click screenshot of the current screen, a free-text remarks field, and automatically-attached context (current route, browser/OS, tenant and user identity) the user does not have to type in manually.
- Submitting creates a `feedback_reports` record (Data Dictionary §21); a captured screenshot is stored via the same shared polymorphic `attachments` table every other file/image in the product uses — not a separate, ad hoc upload path.
- Feedback is tenant-scoped for privacy (one tenant's users never see another tenant's submitted feedback) but visible in aggregate to the platform support team via a dedicated internal view, with a simple status lifecycle (New → Reviewed → Resolved).
- Submitting feedback never blocks or interrupts the task the user was in the middle of.

> **Decision (not pinned by the plan):** the exact client-side screenshot-capture technique (the browser's native Screen Capture API with a user permission prompt, versus a DOM-snapshot library such as html2canvas) is left open pending a small technical spike — both are viable, and the choice trades fidelity against friction (a real screen capture is pixel-accurate but requires a permission prompt each time; a DOM snapshot needs no permission but can miss some rendering, e.g., certain canvas content like the signature-capture field). This document specifies the feature's behavior and data model, not this implementation detail.

### Feature #12 — Audit trail (user-facing)
**Phase: 1**

An "Audit Log" viewer built on top of the `Auditable` trait mechanism already specified as a Best Practice in the architecture plan (§5) and modeled in the Data Dictionary's `audits` table from the very first version of this data model (§13) — this feature is the user-facing surface on top of a mechanism that was already going to exist; it does not require a new tracking mechanism of its own.

Acceptance criteria:
- A tenant-scoped "Audit Log" screen, visible to Owner/Admin roles, lists tracked events (`created`/`updated`/`deleted`/`restored`/`published`/`archived`/`exported`/`permission_changed`, per the Data Dictionary's `AuditEvent` enum) with actor, timestamp, affected entity, and a readable before/after diff for update events.
- Filterable by entity type, actor, event type, and date range; supports the same streamed/chunked export pattern already used for submission data exports (architecture plan §5, "chunked/streamed exports... applied consistently to every new export path") rather than a bespoke export mechanism built for this one screen.
- Sensitive-field redaction (already part of the `Auditable` trait's design) is enforced in this viewer too — a field redacted in the underlying audit record never becomes visible merely because it is being rendered in a UI instead of queried directly.
- Coverage is not limited to form/submission data: every settings change (Feature #10), every form publish/archive action, every role/permission change, and every billing-plan change is tracked and visible here. The precise, exhaustive list of audited models/events and the exact redaction rules (including the deliberate exception for erasure-triggered entries) are formalized in the Audit & Compliance Logging Spec (#13).

---

### Feature #13 — Notifications
**Phase: 1**

Human-facing alerts for the events that matter — a new submission arrives, a submission is returned or approved, a review is requested, an export is ready, a member is invited — delivered both in-app (the notification bell/center already in the app shell, design-system §3.7) and by email, governed by per-user preferences. This closes a real gap: before this, the only submission signals were developer-facing webhooks (Phase 1) and a Slack connector (Phase 3), leaving a non-technical Owner on Free/Starter with nothing but polling the inbox.

Acceptance criteria:
- A persistent notification bell in the standard app shell (#6) shows an unread count and opens a notification center listing recent events, each deep-linking to the relevant submission/form/screen.
- Notifications are raised from the **same post-commit domain events** that drive webhook dispatch and realtime pushes (architecture plan §2.2; Tech-Arch §4.1) — never a separate, divergent write path.
- Each user controls, per tenant and per event type, whether a notification appears in-app and/or is emailed (`notification_preferences`, Data Dictionary §23). Sensible defaults ship seeded: actionable events (returned/review-requested) default to both channels; high-volume events (a submission on a busy public form) default to in-app-only so a lead-gen owner isn't emailed per response unless they opt in.
- Notifications are tenant-scoped (a user never sees another tenant's events) and available on **every** tier — a Free tenant with no webhook access still gets submission notifications.
- Email delivery uses the transactional email provider already modeled in the architecture (invites/alerts); ~~real-time in-app delivery reuses the tenant-namespaced Reverb channel~~ — **AMENDED I4.** Reverb is **Track B** by explicit user decision (it needs a service on a host that does not exist yet), so the notification centre **polls**: a read-only JSON sidecar (`GET /notifications` → `{unread_count, items}`) on a ~60s interval, refetched on every Inertia navigation and paused while the tab is hidden. Deliberately **not** an `Inertia::optional()` shared prop, because a partial reload re-dispatches the current page's controller — a tick with `/audit-log` open would pay for a full ledger paginate plus a `count(*)` and discard it. R10 already calls realtime "a UX enhancement, not a hard dependency", and `docs/data-dictionary.md` §22 struck the equivalent line in I3. The bell, the unread badge, the deep links and the per-type preferences all ship in I4 regardless of transport; swapping the transport later changes no schema and no surface.
- Batched/scheduled digest emails and answer-conditional notification routing are **not** in Phase 1 — they are tracked in `docs/feature-backlog.md`.

### Feature #14 — Two-Factor Authentication (2FA / MFA)
**Phase: 1**

Time-based one-time-password (TOTP) two-factor authentication with recovery codes, available to **all five tenant roles** — not only the platform super-admin (whose MFA was already required). Because at least one persona routinely handles sensitive, health-adjacent data, an Owner/Admin account takeover exposes an entire tenant's PII; MFA is the standard, low-cost control against exactly that, and Laravel Fortify provides TOTP + recovery codes almost for free (auth is built in Phase 1 regardless).

Acceptance criteria:
- Any user can self-enrol in TOTP 2FA from account settings: scan a QR code, confirm a first code, and receive one-time recovery codes. Backed by Fortify's `two_factor_secret`/`two_factor_recovery_codes`/`two_factor_confirmed_at` on `users` (RBAC doc §6), stored encrypted at rest and always redacted from `audits` (Audit Spec §2).
- An **org-level enforcement policy** (a tenant Setting, Feature #10) lets an Owner/Admin require 2FA for all tenant members; unenrolled members are prompted to complete enrolment before continuing.
- **Step-up re-authentication** gates high-blast-radius actions (ownership transfer, role changes, billing changes, and the super-admin console) — a recent credential/2FA confirmation is required, via Laravel's `password.confirm` mechanism, not just a live session.
- Login throttling (Fortify default) and a breached-password check on set/reset are recorded as related hardening items in `docs/feature-backlog.md` (security cluster), not silently assumed.

---

## 6. Scope for MVP vs. Later Phases

This section states, explicitly, what ships in Phase 1 (the MVP) versus what is deliberately deferred to Phase 2, 3, or 4. Phase boundaries follow the approved roadmap; where the plan leaves a fine-grained in/out call unstated, a concrete decision is made here and flagged as such.

### Phase 1 — MVP ("Kobo-lite + Fillout-lite")

**In scope:**
- Drag-and-drop form builder with the Phase-1 field-type catalog (§5, Feature #8) and simple (non-expression-engine) validation/skip logic.
- Sections, with drag-and-drop reordering.
- Draft/publish model with immutable `form_versions` — versioning is structural from day one, not deferred.
- Manual encoding (#7) and guest responses (#3) as the two live submission channels.
- Submission inbox with streamed export (CSV/XLSX) of collected data.
- Basic, role-aware dashboard (#4, basic tier).
- Responsive/mobile-first layout baseline across every screen (#5, responsive baseline only — not yet installable/offline).
- The standard app shell and design system (#6) — built first, everything else sits inside it.
- Tenant-scoped authentication, roles, and invites (Spatie Permission, tenant-scoped).
- Stripe-based billing with 2–3 initial subscription tiers (Cashier).
- Audit trail (carried-forward `Auditable` pattern) on all tenant-owned, mutable, business-critical models, plus the user-facing Audit Log viewer (#12).
- First webhook event type, built on the full reliable-delivery infrastructure (queue-first ingestion, retries, idempotency) even though the broader webhook/integration catalog expands later.
- WCAG AA compliance on the public guest-facing form runtime, enforced via automated CI scans from the first release.
- Postgres Row-Level Security as a tenant-isolation backstop, alongside application-layer scoping.
- User theme-mode preference — Light/Dark/Match System (#9, basic tier).
- App Settings: registration/access toggles, maintenance mode (tenant- and platform-level), per-module toggles, version/build display (#10).
- In-app feedback mechanism — screenshot + remarks capture (#11).
- In-app + email notifications for submission and review events, with per-user preferences (#13).
- Two-factor authentication (TOTP + recovery codes) for all tenant roles, with an optional org-enforced policy and step-up re-auth for high-blast-radius actions (#14).
- Sales-tax / VAT handling on subscription billing via Stripe Tax — compliant, tax-correct invoicing for EU/UK and other tax jurisdictions, a legal prerequisite for charging business customers there.

**Immediate fast-follow (right after the Phase 1 MVP, ahead of Phase 2):** permissioned, audited post-submission answer editing (`submissions.edit` — correct a mis-keyed value without delete-and-re-enter, preserving id/provenance/version linkage); and a unified **Share panel** (copy-link + in-product QR-code generation + embed snippet + social share). Both are near-term additions rather than deferred-phase items — see `docs/feature-backlog.md`.

**Explicitly deferred (not in Phase 1):**
- Repeat groups, matrix/grid, Likert, cascading select, geopoint/geo capture — Phase 2.
- The full expression engine — Phase 2 (simple UI-based logic only in Phase 1).
- XLSForm import/export — Phase 2.
- Installable PWA / true offline data collection — Phase 2 (Phase 1 is responsive, not offline-capable).
- Both OCR channels — Phase 3.
- Advanced cross-form analytics — Phase 3.
- Payments embedded in forms, native integrations beyond the first webhook type, custom domains/branding — Phase 3.
- SSO/SAML, dedicated-DB tenancy, data residency, GDPR export/erasure tooling — Phase 4.
- Accent-color personalization, text-size scale, dyslexia-friendly font toggle (#9, richer tier) — Phase 2.

### Phase 2 — Kobo-style rigor + offline

**In scope:** repeat groups, matrix/grid, Likert, N-level cascading select, geopoint/geotrace/geoshape (PostGIS-backed), media capture, the full expression engine with calculated fields, bidirectional XLSForm import/export, installable PWA with true offline collection and background sync, question library and form templates, a generalized `morphTo`-based resource-scoping mechanism (the clean successor to the legacy system's PSGC/catchment-area hack — see §7), and the richer tier of user personalization (#9: accent-color choice, text-size scale, dyslexia-friendly font toggle).

**Explicitly out of scope for Phase 2:** OCR channels, embedded payments, native third-party integrations beyond the Phase-1 webhook baseline, advanced cross-form analytics, custom domains, enterprise/SSO features.

### Phase 3 — Fillout-style polish + OCR channels

**In scope:** visual multi-step logic/workflow builder, answer piping, hidden fields, partial-submission save-and-resume, embedded Stripe Checkout, native integrations (generic webhook, Zapier, Slack, Google Sheets, Airtable) and a webhook delivery-log UI for tenant admins, custom domains/branding and advanced cross-form analytics as paid-tier gates, queued PDF generation, scheduled/timed forms, and both OCR channels (#1 per-form, #2 linelist/batch) rebuilt as proper pipeline channels.

**Explicitly out of scope for Phase 3:** SSO/SAML, dedicated-database tenancy, data-residency guarantees, GDPR-specific export/erasure tooling, CRDT-based concurrent-edit conflict resolution.

### Phase 4 — Scale & enterprise

**In scope:** SSO/SAML, a dedicated-database tenancy option for compliance-driven customers, data-residency options, GDPR export/erasure tooling, further role-aware dashboard refinement, generalized config-flag-gated feature rollout, and CRDT-based conflict resolution *if* real usage data shows concurrent multi-device editing of the same submission is common enough to justify it (not committed by default — see §9, Open Questions).

---

## 7. Non-Goals / Explicitly Out of Scope

These are things Meridian deliberately will **not** do, independent of phase — either because they conflict with the product's focus, its stated principles, or its security posture. Items here are distinct from the "deferred to a later phase" items in §6: those are on the roadmap; these are not, unless a specific stated trigger condition changes.

- **Not a general-purpose database/app builder.** Meridian is not competing with Airtable, Retool, or no-code app platforms. It stays focused on the form-building-and-structured-data-collection problem; it will not grow into a generic relational-app builder.
- **Not a self-hosted/open-source distribution.** Unlike Kobo (which many organizations self-host), Meridian is SaaS-only for the foreseeable future. A dedicated-database tenancy option (Phase 4) is about isolation for compliance reasons, not about customers running their own infrastructure.
- **Not a native mobile app**, at any phase currently planned. The offline story is delivered via an installable PWA (Phase 2). A native app is explicitly deferred to a future phase-gate decision, revisited only if an enterprise or NGO customer needs a capability (e.g., background GPS tracking, biometric capture) that a PWA genuinely cannot provide.
- **Not committing to CRDT-based real-time concurrent editing** of the same submission or the same form draft by multiple users simultaneously. The committed conflict model is last-write-wins by timestamp (the dominant single-device case) with a 409 surfaced for manual merge on genuine concurrent edits; CRDTs are explicitly deferred unless real usage proves concurrent multi-device editing is common (see §9).
- **Not replicating the legacy system's Philippines-specific geography model** (regions/provinces/cities/barangays, PSGC catchment-area hack) as a built-in platform concept. Any equivalent "assign a resource to one of several types/levels" need is served by a generic, real `morphTo` relation — never a hardcoded national geography table or a PHP switch-on-a-level-column. Tenants who need a specific geographic hierarchy can model it as their own reference data.
- **Not launching with a large integration catalog.** The native integration list starts deliberately short (generic webhook, Zapier, Slack, Google Sheets, Airtable) and expands over time — not "50 integrations at once."
- **No user-supplied code execution environment.** The expression engine is a sandboxed, purpose-built evaluator (XLSForm-style `relevant`/`constraint` semantics) — there is no general-purpose scripting/`eval()` surface exposed to tenants, at any phase. This is a permanent security boundary, not a Phase-1-only limitation.
- **Not a standalone payments platform.** Payments (Phase 3) are delivered via embedded Stripe Checkout inside a form flow — Meridian is not building or intending to build its own payment-processing capability.
- **No SMS/IVR/USSD data-collection channels.** Some Kobo-ecosystem deployments support these for extremely low-connectivity contexts; they are not in current scope. This is flagged as a possible future consideration only if specific customer demand emerges (see §9), not a planned roadmap item.
- **Not real-time collaborative form editing** (Google-Docs-style simultaneous co-editing of the same draft). "Builder presence" — seeing who else is currently viewing a form — is a planned Reverb-powered feature, but simultaneous, conflict-free co-editing of the same draft by two users at once is not promised at any phase currently planned.
- **Not attempting exact feature-parity with either reference product.** Meridian does not aim to replicate every KoboToolbox or Fillout.com feature; it aims to serve the specific hybrid use case in §1, drawing selectively from both.

---

## 8. Competitive Positioning

### 8.1 Positioning statement

Plot any form/data-collection tool on two axes: **rigor** (repeat groups, offline, expression logic, versioning, geo capture — what serious field research needs) and **polish** (drag-drop builder speed, embeds, payments, integrations, brand-level UX — what business self-serve users expect). KoboToolbox sits high on rigor, low on polish. Fillout.com sits high on polish, low on rigor. Meridian's stated position is the only credible attempt to be genuinely strong on **both** axes inside one product, one login, and one shared reporting layer — not a rigor tool with a UX coat of paint, and not a polished tool with a "logic" feature bolted on.

### 8.2 Meridian vs. KoboToolbox

| Dimension | KoboToolbox | Meridian |
|---|---|---|
| Primary audience | Humanitarian/NGO/public-health/academic field research | Both the above **and** business-ops/lead-gen teams |
| Multi-tenant SaaS | Not natively designed as multi-tenant commercial SaaS; often self-hosted per organization | Multi-tenant SaaS from day one, with a dedicated-DB option for compliance-driven tenants (Phase 4) |
| Offline data collection | Mature, core strength (ODK-based) | Planned for Phase 2, modeled on the same "fill offline, queue, sync" pattern, pinned to immutable form versions |
| Form versioning | ODK Central's draft/publish model is a direct, credited inspiration | Immutable `form_versions`, submissions FK to the specific version collected against — a structural fix to a gap the legacy system had, modeled on ODK Central's approach |
| Expression/skip logic | Mature XLSForm `relevant`/`constraint` engine | XLSForm-modeled engine (Phase 2), behind an "Advanced" toggle so non-power-users aren't forced to see it |
| XLSForm interoperability | Native format | Bidirectional import/export (Phase 2) — a direct migration path for Kobo/ODK customers |
| Builder UX / polish | Functional but dated; a real, acknowledged weakness | Design-system-first (#6), drag-drop builder with live preview, modern component design from day one |
| Payments / embeds / integrations | Not a core focus | Native integrations, embedded Stripe Checkout, custom domains (Phase 3) |
| OCR paper-form ingestion | Not a native capability | Two dedicated channels — per-form and linelist/batch (Phase 3) |
| Pricing model | Free/open-source core; hosted tiers available | Tiered SaaS subscription (Cashier-based), commercial from launch |
| **Honest gap vs. Kobo at MVP** | — | Phase 1 has **no offline mode and no full expression engine yet** — for a field team that needs those two things *today*, Kobo remains the safer immediate choice until Meridian's Phase 2 ships. This is stated candidly rather than glossed over. |

### 8.3 Meridian vs. Fillout.com

| Dimension | Fillout.com | Meridian |
|---|---|---|
| Primary audience | Business ops, marketing, small-business self-serve builders | Both the above **and** rigorous research/M&E field teams |
| Builder UX | Best-in-class polish, drag-drop, fast time-to-first-form | Aims for the same bar (#6, #8), informed directly by Fillout's strongest verified patterns |
| Conditional logic | Strong, visual, workflow-style | Simple UI in Phase 1; full workflow/branching builder in Phase 3; a structured expression engine underneath for cases that need real rigor, not just branching |
| Payments | Native, mature | Embedded Stripe Checkout (Phase 3) |
| Integrations | Broad native catalog | Deliberately starts short (webhook, Zapier, Slack, Sheets, Airtable) and expands — a stated trade-off, not a gap Meridian is unaware of |
| Repeat/grouped questions | Not a native concept | First-class, separately-modeled repeat groups with min/max instances (Phase 2) |
| Offline collection | Not applicable to Fillout's use case | Full offline PWA mode (Phase 2) — a capability Fillout has no equivalent of, because its audience has never needed it |
| Data versioning / integrity | Not a stated product concern | Immutable form-version pinning for every submission — a genuine differentiator that matters once a Fillout-style customer's forms are used for anything beyond simple lead capture |
| Geospatial capture | Not native | geopoint/geotrace/geoshape, PostGIS-backed (Phase 2) |
| XLSForm / Kobo/ODK interop | Not applicable | Bidirectional XLSForm import/export (Phase 2) — opens a migration path Fillout has no reason to offer |
| **Honest gap vs. Fillout at MVP** | — | Phase 1 has **no payments, no broad integration catalog, and no visual multi-step workflow builder yet** — for a business-ops user whose primary need is exactly those things today, Fillout remains the more complete option until Meridian's Phase 3 ships. Stated candidly for the same reason as above. |

---

## 9. Key Risks & Open Questions

### 9.1 Product / market risks

- **Positioning risk — "hybrid" products can end up serving neither audience well.** The single biggest product risk is that, in trying to serve both Persona A and Persona B, Meridian ships a builder that feels too complex for the business-ops user and too shallow for the M&E user. Mitigation: Product Principle 3.2 (progressive disclosure) and the phased rollout (simple logic UI before the expression engine) are the direct structural answers to this risk, but it must be actively watched via usability testing with both personas, not assumed solved by the plan.
- **Reference-customer risk for G4 (hybrid value proven).** No specific reference customer has been identified yet who genuinely needs both a rigor feature and a polish feature in the same account. This is an open question, not yet validated with a real prospect.
- **OCR quality risk.** OCR accuracy on real-world paper forms (handwriting quality, scan quality, non-Latin scripts, low-light photos) is inherently variable; the confidence-scoring/review-and-correct UX is the primary mitigation, but the G9 target (< 15% of fields needing correction) is a planning assumption, not yet measured against real documents, and should be validated with a representative document sample before Phase 3 commitments are finalized.
- **OCR/legacy-benchmark figures are explicitly flagged as indicative, not pinned** in the source architecture plan — Fillout's current pricing/limits and EAV-vs-JSONB benchmark numbers should be re-verified before quoting them in any customer-facing material, since marketing pages and published benchmarks both change frequently.

### 9.2 Technical / architecture risks (carried from the approved architecture plan)

- ~~**Build-vs-buy for the form-rendering engine is still open.**~~ **RESOLVED (2026-07-09) — build custom** (ADR-0004, Accepted). The timeboxed Phase-0 spike gate-disqualified both SurveyJS and Form.io — neither natively speaks XLSForm, and neither offers a PHP evaluator behaviorally identical to its JS engine (server-authoritative validation requires one) — and found partial-buy dominated (the custom builder shipped in Increment D4). Phase 1 field-type work builds on our own renderer.
- ~~**Geospatial storage + PostGIS availability + the map-picker were undesigned (the one undesigned Phase-2 surface).**~~ **RESOLVED (2026-07-12) — hybrid PostGIS storage + Leaflet/OSM capture** (ADR-0006, Accepted). The Increment-G5 geo spike settled the three coupled unknowns: geo answers are stored as a canonical GeoJSON envelope in `submission_answers.answers` **and** projected at persist time into a new GiST-indexed PostGIS `geometry(4326)` table (spatial querying now enabled, honoring ADR-0001's rationale); capture uses a bundled Leaflet map over online OpenStreetMap tiles on top of a mandatory offline manual-coordinate + GPS baseline (so a geo field is never unfillable). Residual open items, tracked in ADR-0006: PostGIS-on-Windows install is verified only on Linux CI (a documented deploy-time step on the Windows host), and offline map tiles are deferred to G8. Built in Increment G5b.
- **Offline conflict resolution is intentionally minimal for now.** Last-write-wins plus a 409-on-conflict is the committed Phase 2 model; CRDT-based merge is explicitly deferred. Open question: how often will genuine concurrent multi-device edits to the same submission actually occur in real M&E field deployments? If more common than assumed, this becomes a Phase 3/4 priority rather than a Phase 4-or-never item.
- **Stack risk (Laravel vs. TypeScript-first alternatives).** The architecture plan's own honest comparison notes that AI coding tools are reported to generate somewhat higher-quality TypeScript than PHP in current comparisons, and that the hiring pool for Laravel/PHP is smaller than for Node/TypeScript in venture-backed markets. The plan's recommendation stands (Laravel + Inertia/Vue for admin/builder, a separate Vue SPA/PWA for the public runtime), but is explicitly framed as "a strong default, not a foregone conclusion" pending a Phase 0 team experience check — this PRD treats the stack choice as settled for product-planning purposes but flags it as revisitable via ADR if Phase 0 reveals a real problem.
- **Hosting carries no managed-platform bill.** The platform is now self-hosted on the owner's existing Windows Server 2016 (ADR-0005, which supersedes the earlier Laravel Cloud choice), so there is no recurring managed-hosting fee; the cost instead shifts to the owned hardware and self-managed ops time (patching, backups, monitoring), which should be accounted for as effort rather than a line-item cloud quote.

### 9.3 Compliance / data-sensitivity risks

- **Health-adjacent data handling.** Because Persona A's use cases plausibly include public-health data (echoing, though not identical to, the legacy system's original government health-data context), the product should be built assuming some tenants will handle sensitive personal or health-adjacent data from day one — even though full GDPR/data-residency tooling is not committed until Phase 4. Open question: does any specific target customer's regulatory context (e.g., a national data-privacy act, a donor's data-handling requirements) impose a compliance obligation earlier than Phase 4's planned GDPR tooling? This has not yet been assessed against a specific customer and should be revisited once early pilot customers are identified.
- **PII classification is planned but not yet designed in detail.** The Data Dictionary (doc #5) is expected to carry a PII classification flag per column, feeding later GDPR tooling — but the classification taxonomy itself (what counts as PII vs. sensitive vs. public) has not yet been defined and is an open item for that document, not this one.

### 9.4 Open questions requiring a decision before or during Phase 1

- **Product naming/branding** — "Meridian" in this document is a working codename only (see the note at the top of this document); a real naming/branding decision has not been made.
- **Exact subscription tier boundaries and pricing** — the plan calls for "2–3 tiers" at MVP but does not fix specific prices or feature boundaries; this is the explicit subject of the forthcoming Pricing & Feature-Gating Matrix (doc #24) and should be finalized before Phase 1 billing code is written.
- **Default OCR confidence threshold** (this document proposes 70% as a starting default for the "must be manually confirmed" line) — a reasonable starting point per this PRD, but not yet validated against real OCR vendor output and subject to tuning once Phase 3 OCR work begins.
- **Whether SMS/IVR/USSD channels are ever prioritized** — currently a firm non-goal (§7), but flagged here as something to revisit only if a specific customer segment (e.g., extremely low-connectivity humanitarian deployments) makes it a stated requirement.
- **Whether a native mobile app is ever justified** — currently deferred indefinitely (§7); the trigger condition (a capability a PWA genuinely cannot deliver, such as background GPS or biometric capture) has not yet been requested by any identified customer.
- **First reference/pilot customer identity** — no specific pilot customer has been named yet for either persona; identifying one early materially de-risks G1, G4, and the OCR-accuracy assumptions in G9.

---

*This PRD operationalizes the product-facing decisions in the approved architecture plan (`hi-lets-create-a-federated-meteor.md`) into a standalone document. Where this PRD makes a concrete choice the source plan left open at a fine-grained level (e.g., the OCR confidence-threshold default, the specific Phase-1 field-type list, the working codename), that choice is noted inline as a PRD-level decision, consistent with — but not dictated verbatim by — the approved plan, and should be revisited as real product and engineering work begins.*
