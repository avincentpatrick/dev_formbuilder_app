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

> **AS-BUILT (J5, 2026-08-17) — §2 SHIPPED ON `/dashboard`, AND §3's "no forced tour" SURVIVED IT.** The landing is `resources/js/Pages/Dashboard.vue`; the choices are gated in `DashboardController::firstRunChoices()`; the checklist §2 gained is `app/Services/Onboarding/GettingStartedChecklist.php` + `MdsChecklist`.
>
> ⚠️ **STEP 2 WAS HALF-BUILT BEFORE J5, AND THE MISSING HALF WAS NOT THE OBVIOUS ONE.** `Dashboard.vue` had rendered a zero-forms empty state since H11 — headline, copy, one *Create form* button. What it did not have was §2's **second** choice: the template gallery existed, was linked from the forms list, and was absent from the moment this section is about. The row that scheduled this work called the landing "never built", which was wrong in a way worth recording — the defect was one choice short, not zero screens.
>
> ⚠️⚠️ **THE MOMENT IS A CARD GRID (§3.5), NOT AN EMPTY STATE (§3.10), AND THAT RESOLVES A CONFLICT THIS DOCUMENT DID NOT KNOW IT HAD.** §3.10's governing rule is *never more than one primary CTA* — which is exactly why the forms list's empty state renders one primary (*Start from a template*) and one tertiary (*Start from blank*) and is **correct** to. Two equally-weighted choices cannot be expressed in that component without breaking its rule. §2 already said so in its own sentence — *"presented as the standard card-grid pattern"* — so the two documents never actually disagreed; the resolution is recorded here because the empty state was the obvious thing to reach for and it is the wrong one.
>
> ⚠️ **AND THE TWO CHOICES WERE NOT EQUALLY WEIGHTED IN *OUTCOME* EITHER, WHICH NO LAYOUT COULD HAVE FIXED.** `POST /forms/templates/{t}/instantiate` has always redirected into the **builder**; `POST /forms` returned `back()`. So one choice landed you in the product and the other returned you to where you started holding a toast. J5c changed `FormController::store()` to redirect into the builder for **every** caller (user decision 2026-08-17), which is safe by construction rather than by inspection: `FormService::create()` writes the creator an explicit Editor `ResourceGrant`, so the builder's `can:update,form` holds for anyone who could create at all.
>
> **THE FIRST RUN SUPPRESSES THE KPI TILES AND THE TREND SECTION**, which is this section's own instruction taken literally — *"rather than dropping a brand-new tenant onto a literal empty dashboard"*. Four zeroes and an empty chart above the moment **are** that literal empty dashboard.
>
> ⚠️ **§2's TWO CHOICES ARE NOT BOTH AVAILABLE TO EVERY TENANT, AND THIS SECTION AS WRITTEN CANNOT BE SATISFIED FOR A FREE ONE.** `forms.templates.index` carries `feature:form_templates`, which the pricing matrix puts at **Starter+**. The refused case is **absent, never locked with an upgrade prompt** (ADR-0011 §D9 — Business is held from sale, so an upsell would point at a plan nobody can buy), and the blank card then stands alone. A reader who cannot author at all gets a permission-flavoured explanation and **no** CTA, per §3.10's extended rule.
>
> ⚠️ **THE GATE IS ASKED SERVER-SIDE, THROUGH `FeatureAdmission::admits()`, AND THE CLIENT'S OWN ENTITLEMENT SNAPSHOT WOULD HAVE BEEN WRONG.** `useEntitlements()` reads `EntitlementService::feature()`, which returns **false** when there is no plan catalog at all, while the `feature:` middleware **admits** the same request — a sign flip in a state that occurs on a deploy that migrates before it seeds, on a restored database, and in every feature test that seeds no plan. A client-side gate would have withheld the template card on a request the server would have served.
>
> **THE GETTING-STARTED CHECKLIST (the 2026-08-09 "light gamification" decision) IS A PASSIVE CARD BELOW THE TILES — NOT A WIZARD, AND THIS SECTION IS WHY.** Four rows (create · publish · first response · invite a teammate), each ticked from the same numbers the tiles above it show, each linking only where its own route would admit the reader. It renders **only once a form exists**: below that, the first-run moment already says *create your first form*, and one screen must not say it twice. It retires itself when every row it shows is done, and may be dismissed per person per workspace. §6's "no scripted, multi-step product tour" is unchanged — nothing here is a step the user must pass through.

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
