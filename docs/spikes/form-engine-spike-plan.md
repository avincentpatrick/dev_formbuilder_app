# Spike Plan — Form-Rendering & Builder Engine (Build vs. Buy)

**Project:** Form-Builder SaaS (`dev_formbuilder_app`, "Meridian")
**Status:** Ready to run — Phase 0.
**Produces:** the decision in `docs/adr/0004-form-rendering-engine-build-vs-buy.md` (fills its scorecard).
**Owner:** whoever runs the Phase 0 spike.

> A **spike** is a time-boxed, throwaway investigation. The only deliverable is a *decision plus evidence* — the prototype code is discarded. Do not let a spike drift long: a spike that overruns has already told you the answer is "harder than it looks."

---

## 1. The question

**Do we build the form-rendering + builder engine ourselves, or adopt SurveyJS or Form.io (or buy one layer only)?** See ADR-0004 for why this is Phase-0-blocking and what each outcome invalidates.

---

## 2. Timebox & team

- **Timebox: 8 working days, hard stop.** One engineer serially, or two in parallel (~4 days each).
- Day 1: set up the rubric harness + read the candidate docs. Days 2–6: build the vertical slices. Day 7: score. Day 8: write the ADR decision.
- If a candidate can't clear a **gate criterion (C1/C2/C3)** by day 4, stop evaluating it — it's disqualified (ADR-0004 scoring rule).

---

## 3. Candidates

Custom (the baseline) · SurveyJS · Form.io · Partial buy. Build the *same* vertical slice in each so scores are comparable. For "Custom," a thin throwaway prototype is enough — do **not** build the real engine during the spike.

---

## 4. Reference forms (the vertical slice)

Two reference forms concentrate the risk. Every candidate must attempt both. A third, offline, pass reuses RF-1.

### RF-1 — "Household Survey" (Kobo-style rigor — exercises the deal-breakers)
- **Sections:** Household (flat) → **Household Members (repeat group, min 1 / max 20)** → Location.
- **Repeat member fields:** name (short_text), age (integer, `constraint`: 0–120), sex (single_select), relationship (single_select).
- **Skip logic (`relevant`):** show "pregnancy status" only when `sex = 'female'` **and** `age >= 15`.
- **Cross-repeat calc:** a `count()` of household members displayed/validated at the section level.
- **Cascading select (N-level):** region → province → city.
- **Rigor field types:** one `geopoint`, one `signature`.
- **XLSForm import:** author the *same* form as a real Kobo `.xlsx` and import it — does the candidate round-trip it without loss? (C1)

### RF-2 — "Event Registration + Lead Intake" (Fillout-style polish — exercises layout/UX)
- **Multi-step wizard** (`single_page_mode = false`), 3 steps.
- **Conditional branching:** if "attending in person" → show dietary needs + t-shirt size; else skip.
- **Polish fields:** a rating field, an email field with validation, a short "message" long_text.
- **Payment placeholder:** a step standing in for embedded Stripe Checkout (render only — no real charge).
- **Completion:** a custom thank-you + redirect-URL placeholder.
- **Embed:** render RF-2 inside an `<iframe>` on a throwaway host page (C3-adjacent: cross-domain / CSP).

### RF-3 — Offline pass (reuses RF-1)
- Cache RF-1's manifest, go offline, and **fill + finalize a submission with a repeat group and skip logic entirely offline**, then confirm it queues for replay. (C3)

---

## 5. What to do with each reference form, per candidate

For each (candidate × reference form), record the outcome and a friction note:

1. **Model** the form in the candidate's form model (or our thin custom prototype).
2. **Render** it in the runtime.
3. **Run the logic client-side** (skip/constraint/calc live as you type).
4. **Prototype the server-side authoritative re-evaluation** — the critical test (C2): can a **PHP** pass re-evaluate the same expressions and reach the same verdict the client showed? For "buy" candidates, prototype driving a golden-file vector set from the library's model into a PHP check.
5. **Import the RF-1 `.xlsx`** (C1).
6. **Theme** to the blueprint design tokens (`docs/ux/design-system-reference.md` §2) and run **axe-core** on the rendered output (C8).
7. **Measure:** time-to-model, lines of glue code, and every point where you had to fight the tool.

---

## 6. Scorecard (transcribe into ADR-0004)

Score each candidate **0–5** on C1–C10 (ADR-0004's rubric), apply the weights, and apply the **gate**: any candidate scoring ≤ 2 on C1, C2, or C3 is disqualified regardless of total.

| Criterion (weight) | Custom | SurveyJS | Form.io | Partial |
|---|---|---|---|---|
| C1 XLSForm semantics & round-trip (15) | | | | |
| C2 Two-engine server authority (15) | | | | |
| C3 Offline PWA rendering (12) | | | | |
| C4 Repeat groups (10) | | | | |
| C5 Version-snapshot mapping (8) | | | | |
| C6 Field-type coverage (8) | | | | |
| C7 Builder UX fit (8) | | | | |
| C8 Design-system + WCAG 2.2 AA (8) | | | | |
| C9 Licensing / lock-in / bundle (8) | | | | |
| C10 Extensibility & maintenance (8) | | | | |
| **Weighted total /100** | | | | |
| **Gate passed? (C1–C3 ≥ 3)** | | | | |

---

## 7. Exit criteria (done = all of these)

- [ ] Both reference forms attempted in every non-disqualified candidate.
- [ ] The RF-3 offline pass attempted.
- [ ] The scorecard is fully filled with evidence (screenshots/notes/time-to-model), not opinion.
- [ ] The **C2 server-authority prototype** exists for at least the leading "buy" candidate — the decision is not made on the JS renderer alone.
- [ ] ADR-0004 moved to **Accepted** with the chosen option, the scorecard, and a one-paragraph rationale; its three contingent sections (§1.3/§4.3/§8 of the Technical Architecture Doc) and Risk R7 updated in the same PR.

---

## 8. Guardrails

- **No production code.** Prototypes are throwaway; resist the urge to "just keep" the winning prototype — the real engine is built in Phase 1 against the decision, not smuggled in during the spike.
- **Verify vendor facts at spike time.** SurveyJS/Form.io licensing, pricing, and feature sets change; do not score from memory (consistent with this project's standing "indicative, not pinned" caveat on fast-changing vendor claims).
- **Weight the gates.** It is easy to be dazzled by a slick visual builder (C7) and under-weight C1/C2/C3 — the gate rule exists precisely to stop that.
