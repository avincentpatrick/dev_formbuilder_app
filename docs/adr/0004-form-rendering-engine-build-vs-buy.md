# ADR-0004: Form-Rendering & Builder Engine — Build vs. Buy (SurveyJS / Form.io / Custom)

## Status

**Proposed — decision deferred to the Phase 0 spike.** This ADR records the *frame* for the decision: context, options, the weighted evaluation rubric, and what each outcome would confirm or invalidate elsewhere in the architecture. It moves to **Accepted** once the timeboxed spike (`docs/spikes/form-engine-spike-plan.md`) completes and its scorecard is transcribed into §Decision below.

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

> **DEFERRED.** To be completed after the spike. Transcribe the filled scorecard here, state the chosen option (Custom / SurveyJS / Form.io / Partial), and give the one-paragraph rationale.

| Candidate | C1 | C2 | C3 | C4 | C5 | C6 | C7 | C8 | C9 | C10 | Weighted total | Gate (C1–C3 ≥ 3?) |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Custom | | | | | | | | | | | | |
| SurveyJS | | | | | | | | | | | | |
| Form.io | | | | | | | | | | | | |
| Partial buy | | | | | | | | | | | | |

**Chosen:** _TBD_ — **Rationale:** _TBD_

---

## Consequences (by outcome — to be pruned to the chosen path once decided)

- **If Custom:** confirms `docs/architecture/technical-architecture.md` §1.3, §4.3, and §8's "custom" baseline as-is; the two-engine evaluator + golden-file suite (§4.3, Risk R3) proceed as designed; Phase 1 field-type work builds on our own renderer.
- **If SurveyJS or Form.io (full buy):** **invalidates** §4.3's custom-JS-engine assumption and requires a new sub-decision — how the authoritative **PHP** evaluator stays in lockstep with the library's JS logic (golden-file vectors driven from the library's model), plus a mapping layer from the library's JSON to `schema_snapshot`, and a theming/accessibility conformance pass to meet WCAG 2.2 AA. Bundle size and licensing become live constraints on the embeddable public runtime.
- **If Partial buy:** document exactly which layer is bought and which is custom, and the seam between them (most likely: custom expression engine + data model, library renderer or builder canvas), so the boundary doesn't erode over time.
- **In every case:** the outcome is recorded here and the three contingent sections above (§1.3, §4.3, §8) are updated in the same PR (docs-as-code discipline, plan §5), and Risk **R7** in §9 is marked resolved.

---

## References

- Approved plan (`hi-lets-create-a-federated-meteor.md`) §1 (tech-stack table), §5 (server-authoritative validation).
- `docs/architecture/technical-architecture.md` §4.3 (two-engine Expression Evaluator), §8, §9 Risk R3 & R7.
- `docs/spikes/form-engine-spike-plan.md` — the concrete method that produces this ADR's decision.
- SurveyJS and Form.io product/licensing documentation — **verify current pricing, licensing, and feature claims at spike time**; treat any figure quoted from memory as indicative, not pinned (consistent with this project's standing caveat on fast-changing vendor facts).
