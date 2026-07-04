# Onboarding & Template Content Plan

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v1.0 — the final document in the architecture plan's §4 documentation list. Builds directly on `docs/ux/design-system-reference.md`'s already-specified Empty State component (§3.10 — "No forms yet — create your first form") and card-grid pattern (§3.5 Cards, "a grid of cards (forms list, **template gallery**)") and `docs/data-dictionary.md` §12's `form_templates` table (`tenant_id IS NULL` = platform-provided, global, per ADR-0002's nullable-tenant-global pattern). This document supplies the actual template *content* and the onboarding *flow* around it — neither of which any prior doc specifies.

---

## 1. What's Already Decided (not repeated in full)

The Empty State component and its "get started" CTA pattern (`docs/ux/design-system-reference.md` §3.10); the card-grid layout a template gallery reuses (§3.5); `form_templates`'s schema, including `schema_blueprint` (clone-on-instantiate, never a live reference) and `is_public` (§12 of the Data Dictionary); onboarding/marketing-only typography and spacing tokens reserved for exactly this kind of first-run surface (`--mds-type-display`, `--mds-space-24`).

---

## 2. Onboarding Flow

1. **Signup → tenant creation**: a new tenant's founding Owner (`docs/multi-tenancy-rbac-design.md` §3) completes signup; `tenants`/`tenant_users` rows are created (the Owner's own `tenant_users` row has `invited_by = NULL`, per that doc's §7 lifecycle).
2. **First-login landing**: rather than dropping a brand-new tenant onto a literal empty dashboard, the first authenticated screen is a lightweight **"Create your first form"** moment — not a multi-step guided wizard/product tour (which this project's "intuitive by default" principle, architecture plan intro, argues against — a wizard the user must click through *before* reaching the product delays the very thing that demonstrates the product's value). Two equally-weighted choices, presented as the standard card-grid pattern:
   - **Start from a template** — opens the template gallery (§3).
   - **Start from blank** — the ordinary builder flow, for a user who already knows exactly what they want.
3. **No forced tour beyond this one choice point.** Subsequent discoverability (advanced expression engine, XLSForm import, webhooks) relies on the product's own progressive-disclosure principle (already established design-system doctrine — advanced features sit behind explicit "Advanced" toggles, not a mandatory tour) rather than a scripted walkthrough that must be maintained in lockstep with every future feature addition.

---

## 3. Template Gallery — Starter Content Set

Spans both personas `docs/PRD.md` §2 names explicitly, since a gallery showing only one persona's use cases would silently tell the other persona "this product isn't really for you":

**M&E / research-oriented** (Persona A):
| Template | Demonstrates |
|---|---|
| Household Survey | Sections, repeat groups *(Phase 2 field types — the template itself ships pre-authored, but its repeat-group section only becomes usable once Phase 2's field-type catalog exists; until then it's a Phase-1-compatible subset)*, cascading select (region/province/city), mixed field types. |
| Health Facility Assessment | Yes/no fields, Likert-scale questions, numeric fields with validation ranges. |
| Beneficiary Registration | Guest/public submission suitability, geopoint capture, photo/signature capture. |
| Post-Distribution Monitoring (PDM) | Skip logic (simple, no-code tier — `docs/PRD.md` Feature #8 Phase 1), a demonstration of the review/validation workflow (Reviewer role). |
| Training/Workshop Attendance & Feedback | A short, single-page-mode form — demonstrating the "simple things stay simple" end of the spectrum, not every template needs to be complex. |

**Business / general-purpose** (Persona B):
| Template | Demonstrates |
|---|---|
| Event Registration | Guest submission, email field, date/time fields. |
| Job Application Form | File upload (resume), long-text fields, required-field validation. |
| Customer Feedback Survey | Likert-scale, multi-select, a short single-page form (same "simple stays simple" point as Training/Workshop above, for the business-persona side). |
| Contact / Lead Capture Form | The shortest, simplest template in the whole gallery — deliberately minimal, since a lead-capture form's entire value proposition is friction reduction. |
| Employee Onboarding Checklist | Sections, conditional (skip-logic) fields, demonstrating the builder for an internal-operations use case distinct from external data collection. |

**Ten templates total at launch** — deliberately not a large catalog. A sprawling, mediocre template library is worse than a small, genuinely well-built one; the gallery is expected to grow **based on observed `form_templates.usage_count`** (§4), not be front-loaded speculatively.

---

## 4. Content Governance

- **Authored and seeded by the platform team**, via a versioned database seeder — not user-generated at launch. Each template's `schema_blueprint` (`docs/data-dictionary.md` §12) is reviewed the same way any other shipped content would be, since a broken or confusing starter template actively damages first-impression trust in the product.
- **`usage_count` drives curation, not just display order**: the gallery's default sort surfaces the most-instantiated templates first (a simple, honest "popular" signal), and a template with persistently near-zero `usage_count` after a reasonable window is a candidate for revision or retirement — the gallery is treated as a living, measured surface, not a fire-and-forget launch asset.
- **Every template is validated against the current field-type catalog on every release** — a template referencing a field type or capability not yet shipped in the active phase (e.g., a template using Phase 2 repeat groups, shipped before Phase 2 lands) is either excluded from the gallery until that phase ships, or authored as a Phase-1-compatible subset (as noted for Household Survey, §3) — never silently broken by drift between the gallery's content and the product's actual current capability.

---

## 5. Localization

English-only at launch, matching `docs/non-functional-requirements.md` §6's stated i18n scope (UI-chrome and platform content localization is a Phase 2+ candidate, not a Phase 1 commitment) — template *content* (labels, hints) follows the same rule as the rest of the product's own copy, not a separately-accelerated localization effort just because it's onboarding-facing.

---

## 6. Out of Scope / Deferred

- User-submitted/community template contributions — no existing doc requests this, and it would need its own moderation/review workflow not currently justified by a stated need.
- A scripted, multi-step product tour beyond the single template-vs-blank choice point (§2) — deliberately not built, consistent with this project's "intuitive by default" principle valuing low-friction first use over a guided walkthrough.
- Template categories/gallery filtering UI beyond the existing card-grid pattern — an implementation detail for whichever team builds the gallery screen, not an architectural decision.
