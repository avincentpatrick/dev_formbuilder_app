# ADR-0004: Form-Rendering & Builder Engine — Build vs. Buy (SurveyJS / Form.io / Custom)

## Status

**Accepted — 2026-07-09.** The Phase-0 spike (`docs/spikes/form-engine-spike-plan.md`) is complete and its scorecard is transcribed into §Decision below. **Decision: build the engine custom.** Both full-buy candidates (SurveyJS, Form.io) are disqualified by the pass/fail gates — neither speaks XLSForm (C1) and neither offers a PHP evaluator behaviorally identical to its JS engine (C2); partial-buy is dominated because the single layer worth buying, the visual builder, already shipped custom in Increment D4. This confirms the architecture's working baseline and resolves Risk **R7** (§9 of the Technical Architecture Doc).

- **Deciders:** Founding engineering (architecture owner) + product.
- **Related ADRs:** ADR-0001 (PostgreSQL), ADR-0002 (multi-tenancy + RLS). This decision depends on neither, but it **blocks** Phase 1 field-type work — which is exactly why it is a Phase 0 item.
- **Related docs:** approved plan §1 (tech-stack table, "Form-rendering engine" row: *"Timeboxed Phase 0 spike… Decide via ADR after a spike, not by default"*); `docs/architecture/technical-architecture.md` §1.3, §4.3 (Expression Evaluator), §8 (stack), §9 Risk **R7**; `docs/PRD.md` §9.2 (this is named as an open technical risk); `docs/xlsform-interop-spec.md`; `docs/offline-first-sync-design.md`.

---

## Context

The form-rendering + builder engine is the product's **center of gravity** (PRD Feature #8 — "everything else orbits it"). One engine must serve, coherently, *all* of:

- the **builder** (categorized field-type picker + live preview — **not** raw-JSON, **not** freeform canvas; the legacy pattern worth keeping the spirit of),
- the **public runtime** (guest link, third-party embed, and the offline PWA — all rendering from an immutable, version-pinned schema),
- the **expression engine** (XLSForm `relevant`/`constraint`/`calculate`),
- **repeat groups**, geo capture, signature/media, and the full 31-type / 8-category catalog,
- **immutable versioning** (submissions FK to `form_version_id`; render from `form_versions.schema_snapshot`).

Choosing wrong is **expensive to reverse**, because the engine is wired into both the builder and the runtime and into the submission pipeline's semantic-validation stage. The approved plan therefore refuses to pick by default and schedules a timeboxed spike in Phase 0, before any field-type work begins.

**The core tension:** neither off-the-shelf library natively speaks this product's specific blend — XLSForm-standard expressions + Kobo-style repeat instances + offline-first + immutable version pinning + the Kobo/Fillout hybrid UX. "Buy" could save real time on the *common* field types; the risk is that it **fights the library** on exactly the rigor features that differentiate Meridian.

**The sleeper issue — server authority.** Server-authoritative validation is non-negotiable (plan §5). The architecture (§4.3) commits to **two behaviorally-identical evaluators** — a TypeScript engine for live builder/respondent UX and a **PHP** engine as the sole authority at submit time, kept in lockstep by a shared golden-file test-vector suite. A bought library ships *its own JS engine* but **no matching PHP engine** — so even a "buy" outcome likely still requires **building** the authoritative PHP evaluator, and keeping a third-party JS engine behaviorally identical to our own PHP engine may be *harder* than keeping two engines we control in sync. The spike must weight this heavily rather than assume "buy = no engine work."

---

## Options on the table

1. **Custom** *(the architecture's working baseline)* — build the renderer + builder ourselves (Vue 3 + TS front, PHP back). Exact fit; highest up-front build cost for common field types.
2. **SurveyJS** — commercial JS survey library: renderer + a visual builder (Survey Creator), JSON form model, conditional logic, broad question set.
3. **Form.io** — form-building platform/SDK: JSON-schema renderer + builder + a data layer.
4. **Partial buy** — adopt a library for *one* layer only (e.g., the renderer, or the builder canvas) while keeping a custom expression engine and data model. Kept explicitly on the table so the decision is not a false binary.

---

## Decision drivers — the weighted rubric

Each candidate is scored **0–5** per criterion; weights sum to 100. Rigor + server authority + offline deliberately dominate (~60 pts), because those are where a general-purpose library is most likely to break and where Meridian differentiates. Common field types are table-stakes all options can do and are weighted lightly.

| # | Criterion | Weight | What "5" looks like |
|---|---|---:|---|
| C1 | **XLSForm expression semantics & round-trip** — `${field}`, `if()`, `selected()`, cross-repeat `count()`, `today()/now()`; import a real `.xlsx` | 15 | Models all of it natively and round-trips a real Kobo XLSForm without loss |
| C2 | **Two-engine server authority** — a PHP evaluator behaviorally identical to the client engine, verifiable via shared golden-file vectors (§4.3) | 15 | Clean path to a matching PHP authority; drift is mechanically testable |
| C3 | **Offline PWA rendering** — renders fully from a cached manifest, no network, embeddable cross-domain under CSP | 12 | Works offline out of the box; small, embeddable bundle |
| C4 | **Repeat groups** — nested instances stored as JSONB arrays; min/max; cross-repeat aggregates | 10 | First-class repeat model matching the data dictionary |
| C5 | **Immutable version pinning** — the form model maps cleanly onto `form_versions.schema_snapshot` | 8 | JSON model ≈ our snapshot shape; no impedance mismatch |
| C6 | **Field-type catalog coverage** — 31 types incl. geo/signature/media capture | 8 | Covers or cleanly extends to the full catalog |
| C7 | **Builder UX fit** — categorized picker + live preview; not raw-JSON, not freeform canvas | 8 | Matches the intended builder UX without fighting it |
| C8 | **Design-system + accessibility** — themable to the blueprint tokens, dark mode, **WCAG 2.2 AA** (axe-core-clean output) | 8 | Fully themable; rendered output passes axe-core |
| C9 | **Licensing / lock-in / bundle size** — cost, source availability, weight on the embeddable runtime | 8 | Permissive/affordable; light bundle; low lock-in |
| C10 | **Extensibility & maintenance** — add a new field type or submission channel without forking the library | 8 | Clean extension points; we control our own roadmap |

**Scoring rule:** any candidate scoring **≤ 2 on C1, C2, or C3** is disqualified regardless of total — those three are pass/fail gates, not just weighted points.

---

## Decision

> **CUSTOM** — build the form-rendering + builder engine ourselves (Vue 3 + TS front, PHP back), as the architecture's working baseline. Both off-the-shelf engines are **gate-disqualified**; partial-buy is dominated.

| Candidate | C1 | C2 | C3 | C4 | C5 | C6 | C7 | C8 | C9 | C10 | Weighted total | Gate (C1–C3 ≥ 3?) |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Custom** | 5 | 5 | 5 | 5 | 5 | 4 | 5 | 5 | 5 | 5 | **98.4** | ✅ **PASS** |
| SurveyJS | 2 | 2 | 4 | 4 | 5 | 3 | 5 | 4 | 4 | 4 | 69.6 | ❌ **FAIL** (C1, C2) |
| Form.io | 1 | 2 | 3 | 4 | 4 | 2 | 4 | 2 | 3 | 4 | 54.6 | ❌ **FAIL** (C1, C2) |
| Partial buy | 5 | 5 | 5 | 5 | 5 | 4 | 4 | 4 | 3 | 3 | 88.8 | ✅ PASS (but dominated) |

**Chosen:** **Custom.** — **Rationale:** Both off-the-shelf engines fail the two non-negotiable gates. Neither speaks XLSForm — SurveyJS and Form.io each use a proprietary JSON expression model with no `.xlsx` import or lossless round-trip (C1 = 2 / 1) — and neither offers a path to a **PHP** evaluator behaviorally identical to its JavaScript engine: SurveyJS ships only JS with no PHP port (a lone, incomplete community port aside), and Form.io's authority is Node (`@formio/core` in a V8 isolate) whose built-in validators and calculated values are core JavaScript, **not** the portable JSONLogic subset a PHP authority could reuse (C2 = 2 / 2). Server-authoritative validation (plan §5) is therefore only achievable against **two evaluators we control**, kept in lockstep via golden-file vectors (§4.3) — which is *easier* to guarantee than keeping a third-party, evolving JS engine identical to a hand-ported PHP one. Custom is the only candidate that models XLSForm natively (the `docs/xlsform-interop-spec.md` mapping — 27/31 types round-tripping) **and** clears all three gates; partial-buy is dominated because the single layer worth buying — the visual builder — already shipped as a custom, axe-clean, categorized-picker + live-preview surface in **Increment D4**, while the renderer must stay custom to own the expression engine, offline rendering, and version pinning. Choosing Custom invalidates nothing in the architecture (lowest-churn outcome) and resolves Risk R7.

**Method note:** the spike was executed as a current-vendor-fact-verified **desk evaluation** against the rubric (web-verified July-2026 licensing/feature facts + architectural reasoning), not the full hands-on RF-1/RF-2/offline prototype build. That is decisive here because both buys fail on *published* capabilities — no XLSForm model, no PHP authority — which hands-on prototyping would only have confirmed. Key verified facts: SurveyJS has no XLSForm import/export and no PHP expression engine; Form.io has no XLSForm path and its server authority is Node/`@formio/core` (only a JSONLogic *subset* is PHP-portable); both make the two-engine golden-file lockstep architecturally unattainable in PHP without reimplementing the vendor's evolving JS core.

---

## Consequences (chosen path: **Custom**)

- **Confirmed as-is:** `docs/architecture/technical-architecture.md` §1.3, §4.3, and §8's "custom" baseline stand as designed; the two-engine Expression Evaluator + golden-file suite (§4.3, Risk R3) proceed as designed; Phase 1 field-type work builds on our own renderer. The custom builder already shipped (Increment D4), the immutable-versioning data model already shipped (Increment D1–D2), and the XLSForm mapping is specified (`docs/xlsform-interop-spec.md`) — so the Custom path is not just chosen but partly de-risked already.
- **Not chosen (recorded for the audit trail):** the SurveyJS / Form.io **full-buy** path (would have *invalidated* §4.3's custom-JS-engine assumption and required a PHP-lockstep sub-decision, a library-JSON→`schema_snapshot` mapping layer, and a theming/WCAG-2.2-AA conformance pass, with bundle size + licensing as live runtime constraints) and the **partial-buy** path (dominated — the only buyable layer, the builder canvas, already shipped custom in D4; the renderer must stay custom to own C1/C2/C3) are not taken.
- **Done in this PR** (docs-as-code, plan §5): this ADR is **Accepted** with the filled scorecard + rationale; the three contingent sections (§1.3, §4.3, §8) are annotated with the resolved outcome; Risk **R7** in §9 and PRD §9.2 are marked resolved.

---

## References

- Approved plan (`hi-lets-create-a-federated-meteor.md`) §1 (tech-stack table), §5 (server-authoritative validation).
- `docs/architecture/technical-architecture.md` §4.3 (two-engine Expression Evaluator), §8, §9 Risk R3 & R7.
- `docs/spikes/form-engine-spike-plan.md` — the concrete method that produces this ADR's decision.
- SurveyJS and Form.io product/licensing documentation — **verify current pricing, licensing, and feature claims at spike time**; treat any figure quoted from memory as indicative, not pinned (consistent with this project's standing caveat on fast-changing vendor facts).
