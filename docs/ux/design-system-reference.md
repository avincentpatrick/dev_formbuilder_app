# UI/UX Design System Reference

| | |
|---|---|
| **Project** | Form-Builder & Data-Collection SaaS — working codename "Meridian" |
| **Document status** | Draft v1.0 — written against the approved architecture plan and the PRD, before any component code exists |
| **Doc reference** | Documentation plan item **#19** ("UI/UX Design System Reference"), formalizing Main Feature #6 ("Standard page design/layout template") and Product Principles 3.2 ("Intuitive by default") and 3.3 ("One design system") |
| **Source of truth for product/architecture decisions** | `hi-lets-create-a-federated-meteor.md` (the approved plan) and `docs/PRD.md`. This document expands their design-system commitments into implementation-grade detail; it does not introduce new product direction. |
| **Related documents** | PRD (`docs/PRD.md`), Technical Architecture Doc (`docs/architecture/technical-architecture.md`), Data Dictionary (`docs/data-dictionary.md`), ADRs (`docs/adr/`), Non-Functional Requirements Doc (`docs/non-functional-requirements.md`, plan #10), Form-Filling UX Flow Spec (`docs/ux/form-filling-ux-flow.md`, plan #20) |
| **Owner** | Design / Frontend Engineering |
| **Last updated** | 2026-07-03 |

> **How to read this document**: Section 1 explains why this document exists and the rules that keep it from drifting the way the legacy system's documentation did. Section 2 defines the raw design tokens (color, spacing, type, elevation, radius, breakpoints, motion) as concrete values, not categories. Section 3 is a component-by-component inventory of what must exist, what states each component needs, and the one governing layout rule per category. Section 4 is the accessibility specification these components must meet. Section 5 and 6 cover progressive disclosure and responsive behavior, both called out explicitly as cross-cutting constraints in the PRD. Section 7 covers how the system evolves without breaking consistency. Wherever the plan or PRD was silent on a fine-grained detail — an exact color value, a specific pixel size, a naming convention — this document makes a concrete, reasonable choice and marks it with a **Decision (not pinned by the plan)** callout, consistent with how the source documents flag their own judgment calls. Every such decision is also collected in Appendix A for easy scanning and later ratification.

---

## Table of Contents

1. [Purpose & Governance](#1-purpose--governance)
2. [Design Tokens](#2-design-tokens)
3. [Component Inventory](#3-component-inventory)
4. [Accessibility Specification (WCAG AA baseline)](#4-accessibility-specification-wcag-aa-baseline)
5. [Progressive Disclosure Patterns](#5-progressive-disclosure-patterns)
6. [Responsive & Mobile Rules](#6-responsive--mobile-rules)
7. [Versioning & Change Process](#7-versioning--change-process)
- [Appendix A: Decisions Made Where the Plan/PRD Was Silent](#appendix-a-decisions-made-where-the-planprd-was-silent)

---

## 1. Purpose & Governance

### 1.1 Why one shared system exists

Product Principle 3.3 ("One design system," PRD §3) states it plainly: *"A single shared app shell (navigation, header, content region) and one component library is used by every authenticated screen — builder, dashboard, submissions inbox, settings — and a token-consistent (but purpose-built) shell serves the public/guest form runtime. This is not a cosmetic preference; it is the single biggest lever the plan identifies for making the product feel coherent rather than assembled from parts."* This document is the concrete artifact that makes that statement buildable rather than aspirational.

Two forces make one shared system non-negotiable rather than a nice-to-have:

- **"Intuitive by default" (Product Principle 3.2) is a design-system problem, not a feature-by-feature one.** A user who has learned the dashboard has, per the PRD, "effectively already learned 80% of the submissions inbox and the settings pages" — but only if every page is actually assembled from the same primitives. A design system that exists as a Figma file nobody references, or a components folder that quietly forks per feature team, delivers none of that transfer value. The tokens and components in this document are the literal mechanism by which "intuitive by default" becomes true rather than intended.
- **The legacy system's confirmed documentation-drift problem is a governance failure, not a content failure.** The approved plan is explicit that legacy's internal docs (`docs/database/*.md`, `docs/form_builder_v2.md`) were "occasionally-drifted" and "found to have some drift from reality" versus the actual code — not because nobody wrote them, but because nothing forced them to stay current once written. A design-system reference document has exactly the same failure mode, arguably a worse one: a stale token table or component list actively misleads every new screen built against it, compounding inconsistency rather than merely misinforming a reader. Section 1.2 states the specific rule that exists to prevent that.

### 1.2 This document is tied to a living component library

This document is **not** the design system — it is the human-readable reference layer over a living, versioned, code-level component library. Concretely:

- The component library is built in **Storybook 8+ for Vue 3 (Composition API + TypeScript)**, published as an internal package (`@meridian/design-system`) consumed by both the Inertia admin/builder application and the separate public-runtime Vue 3 SPA/PWA (per the technical architecture doc's frontend stack, §8). Storybook is the plan's own suggested example ("Storybook or equivalent," plan §4 item 19); this document adopts it directly rather than treating the choice as still open.
- Every component documented in Section 3 has a corresponding Storybook story exercising its full state matrix (default/hover/focus/active/error/disabled/loading, where applicable), and every token in Section 2 is published as a Storybook design-token addon page, generated from the same source-of-truth token files described in §2.1 — not hand-transcribed into Storybook separately, which would reintroduce exactly the drift this rule exists to prevent.
- **Rule (governance, non-negotiable):** any pull request that adds, removes, or changes the props/variants/states of a shared component, or changes a design token's value, **must update this document and the corresponding Storybook story in the same PR**. This is the direct, named application of the plan's "docs-as-code discipline" best practice ("architecture/data-dictionary/ERD docs live in-repo, updated in the same PR as the change they describe — direct fix to legacy's confirmed doc-drift problem," plan §5) to the design system specifically. A PR that changes component behavior without a corresponding doc/story update fails review on that basis alone, the same way a migration without a Data Dictionary update would.
- CI enforcement: a lightweight check (a diff-based CI job, not a full content-equivalence check) flags any PR touching `packages/design-system/src/components/**` that does not also touch `packages/design-system/src/components/**/*.stories.ts` or `docs/ux/design-system-reference.md` in the same commit range, so the rule has a mechanical backstop rather than relying purely on reviewer discipline — mirroring the plan's general preference for tooling-enforced conventions over unenforced ones (e.g., its CI complexity/line-count check for thin controllers).

### 1.3 Exceptions require documented rationale

Feature #6's acceptance criteria state this exactly: *"Any exception to using the shared shell/system requires an explicit, documented rationale — it is not a default option for a feature team under time pressure."* Operationally:

- A one-off component, layout, or style override that deviates from this document requires a code comment linking to a short written rationale (a PR description section titled **"Design system exception"**, or, for a recurring exception, a numbered entry in an `docs/ux/exceptions-log.md` file) stating: what shared component/pattern was not used, why it did not fit, and whether the gap should instead be closed by evolving the shared system (see §7) rather than repeating the exception elsewhere.
- Exceptions are reviewed by whoever owns the design system (§1.4) before merge, the same way a schema change gets Data Dictionary sign-off. An exception is not itself a failure — legitimate one-off needs exist (e.g., a marketing/pricing page with different layout needs than the authenticated app) — but an *undocumented* exception is always treated as a defect.
- Three or more independent, undocumented deviations solving the same underlying need is treated as a signal that the shared system is missing a pattern, not that the exception is now acceptable practice — the fix is a new documented component/token (§7.2), not a third silent fork.

### 1.4 Ownership & scope of this document

Design/Frontend Engineering jointly owns this document and the component library it describes. Scope is explicitly the **shared, cross-screen system**: tokens, primitive and composite components, the app shell(s), accessibility baseline, and the governance process around all of the above. It does **not** own: page-specific business logic, the field-type catalog's domain semantics (owned by the Data Dictionary and the form-builder feature docs), or the Form-Filling UX Flow Spec's flow-level decisions (partial-save/resume, single-page vs. multi-step — plan doc #20), though that spec is expected to compose components defined here rather than invent its own.

---

## 2. Design Tokens

### 2.1 Token architecture: primitive → semantic, and dark-mode readiness

Tokens are authored in a **two-layer architecture**, generated from a single source-of-truth (a token definition tool such as Style Dictionary — flagged below as a judgment call) into both CSS custom properties (consumed directly by Vue SFCs in either frontend, framework-agnostic) and a generated TypeScript `tokens.ts` module (for cases needing token values in script, e.g., chart color scales — see the note below, which corrects what "values" means here — or canvas-based signature capture rendering):

1. **Primitive tokens** — the raw scale, e.g. `--mds-primary-600: #1C4B72`. Primitives never change meaning; they are simply "the color/space/size at this step of this scale." Components and pages **never** reference primitives directly.
2. **Semantic tokens** — purpose-named aliases that map onto primitives, e.g. `--mds-color-action-primary-bg: var(--mds-primary-600)`, `--mds-color-text-body: var(--mds-neutral-800)`. Components and pages reference **only** semantic tokens.

This indirection is also why dark mode ships **from day one**, not as a deferred phase: because every component consumes semantic tokens rather than primitives, a full dark theme is just a second re-pointing of the semantic layer onto different primitive steps (e.g., `--mds-color-bg-canvas` maps to `--mds-neutral-50` in light mode, `--mds-neutral-900` in dark mode) with **zero component-level changes**. Both mappings are specified below — light is not a placeholder awaiting a future dark pass. The product's chosen visual concept (§2.2) makes this an easy, non-arbitrary commitment rather than a naive color inversion: the light theme reads as **paper and ink** (a technical-drawing/field-notebook surface), and the dark theme reads as an actual **blueprint print** (pale linework on a deep drafting-blue ground) — the two are two authentic states of the same instrument, not one theme and its algorithmic negative.

> **Decision (ratified, superseding an earlier draft of this paragraph):** an earlier version of this document treated a user-facing theme-toggle *feature* as "an open product-scope question." That question is now closed: **PRD Feature #9 commits a manual Light/Dark/Match-System toggle to Phase 1** (§2.9 below is its technical mechanism). What remains true from the original reasoning is *why* this was cheap to commit to: the automatic OS-level `prefers-color-scheme` behavior is a zero-cost consequence of the primitive→semantic indirection alone, and the manual per-user override in §2.9 is simply one small additional layer on top of infrastructure that already had to exist for the automatic case. Nothing here was an expensive feature to greenlight.

> **Decision (not pinned by the plan):** token generation uses **Style Dictionary** (or an equivalent build-time token pipeline) to produce `tokens.css` (custom properties) and `tokens.ts` (typed constants) from one JSON/YAML source of truth, so a single token change propagates to both frontends without hand-editing two files. Neither the plan nor the PRD names a specific token-build tool; Style Dictionary is chosen as a widely-adopted, framework-agnostic default consistent with the plan's general preference for boring, proven tooling over novel infrastructure.
> **Decision (not pinned by the plan):** all custom properties use the `--mds-` prefix (**M**eridian **D**esign **S**ystem) to avoid collisions with third-party embedded contexts — relevant specifically because the public form runtime is designed to be iframed/embedded on third-party domains (PRD Feature #3), where a generic prefix like `--color-primary` would be far more likely to collide with a host page's own tokens.

> **As-built note on `tokens.ts` (recorded 2026-08-03, ADR-0011 §D10).** The generated module emits **`var()` references, not resolved colours** — `build-tokens.mjs`'s `meridian/ts` formatter produces entries of the form `colorActionPrimaryBg: 'var(--mds-color-action-primary-bg)'`. This is load-bearing rather than incidental, and it is the reason the analytics charts are SVG rather than canvas: `useTheme` flips `data-theme-mode` and `data-accent` on the root element live, with no reload, so an SVG mark bound to `fill="var(--mds-chart-series-1)"` re-paints for free on a theme change, while a canvas renderer cannot resolve a `var()` at all and would go stale until someone wired a mutation observer. Anything reaching for `tokens.ts` expecting a hex string is reaching for the wrong thing.
>
> **Chart tokens — status (as-built, H24b1).** The `--mds-chart-*` family now **exists**, in `packages/design-system/tokens/chart.json`: `--mds-chart-series-1…5`, `--mds-chart-other`, `--mds-chart-grid`, `--mds-chart-axis` and `--mds-chart-pattern-1…5`. The measured contrast table is §4.1 below. Three things about the shape are decisions rather than details. **(a)** The five series are **literal hexes** that alias no existing scale, and their dark re-point is assigned from `--mds-_chart-dark-1…5`, defined once on `:root` in `theme-overrides.css` and read by both dark blocks — the same private-custom device G11 introduced for the dark teal payload, for the same reason (two copy-pasted dark blocks drift). **(b)** `--mds-chart-other`, `-grid` and `-axis` alias the **neutral** primitives (`{neutral.500}`, `{neutral.200}`, `{neutral.600}`) and therefore need no re-point at all: the dark block already flips the ramp beneath them. **(c)** The **sequential** scale §D11 specifies is deliberately **not emitted** — it has no consumer yet, and shipping an unconsumed token is precisely how the corpus came to contain a false claim that chart tokens already existed.

### 2.2 Color

**Visual concept: "field instrument / blueprint desk."** Rather than a generic modern-SaaS palette, the approved direction (ratified against a visual prototype, not chosen from swatches alone) casts the product as sitting between a surveyor's field notebook and a technical drafting desk — fitting a tool that lives between rigorous survey instruments (Kobo/ODK heritage) and everyday business forms. Colors are semantic-token scales: **primary** (Blueprint), **accent** (Brass, used narrowly), **success** (Moss), **warning** (Amber), **danger** (Redline), and **neutral** (Paper/Ink), each a 10-step scale (50 lightest → 900 darkest, or fewer steps for the narrow-use Brass accent) plus a small set of fixed aliases (white/black/overlay). Both a light ("paper and ink") and a dark ("blueprint print" — pale lines on a deep drafting-blue ground) mapping are specified below; dark is an authentic second state of the same instrument, not an algorithmic inversion of light (§2.1).

> **Decision (ratified):** the exact hex values below implement the approved visual-prototype direction (a "Meridian — Design System Recommendations" artifact, reviewed and selected over a generic Inter/Indigo alternative). Unlike most decisions in this document, the **palette concept itself is a ratified product/brand decision**, not an open placeholder pending future branding work — the token names, scale structure, *and* the specific hues below are all durable. Only fine-grained interpolation steps between the named anchor points (e.g., exact intermediate 50/100/200 values) remain ordinary judgment calls in the usual sense.

**Primitive scale — Primary (Blueprint)**

| Token | Hex | Typical use |
|---|---|---|
| `--mds-primary-50` | `#EAF1F6` | Subtle tinted backgrounds (selected row, info panel) — also the dark-theme's `ink` text color, a deliberate light/dark echo |
| `--mds-primary-100` | `#D2E2EC` | Hover background for tinted surfaces |
| `--mds-primary-200` | `#A9C7DA` | Border on tinted surfaces |
| `--mds-primary-300` | `#7DA9C4` | Disabled-primary fill, decorative accents |
| `--mds-primary-400` | `#4E86A8` | Large-text/icon accents only (not body text) |
| `--mds-primary-500` | `#2E6789` | Links on white, secondary accents |
| `--mds-primary-600` | `#1C4B72` | **Default primary action fill** (buttons, active nav item, primary focus accents) — verified 9.14:1 white-text contrast, §4.1 |
| `--mds-primary-700` | `#123350` | Primary hover/pressed state — verified 13.0:1, §4.1 |
| `--mds-primary-800` | `#0D2740` | Primary active/pressed-darkest state |
| `--mds-primary-900` | `#081B2C` | Dark-mode canvas anchor (see dark-mode table below) |

**Accent scale — Brass (used narrowly, not a button-variant hue)**

Unlike the previous draft's Teal "secondary" scale, Brass is **not** a parallel interactive-color system — secondary buttons are an outlined treatment of Blueprint itself (§3.1), keeping one confident hue for all primary interaction, consistent with "spend visual boldness in one place." Brass is reserved for a small set of annotation-style accents: the "Advanced" disclosure indicator (§5), eyebrow/overline labels, and a "featured template" marker in the template gallery — never for a primary/secondary button pair, never for a data-status pill (that's Success/Warning/Danger below).

| Token | Hex | Typical use |
|---|---|---|
| `--mds-brass-tint` | `#F3E6D3` | Background wash behind a brass-marked badge/callout |
| `--mds-brass-default` | `#B5793A` | Advanced-disclosure chevron, eyebrow text, featured-template marker |
| `--mds-brass-strong` | `#8F5E28` | Hover/pressed state for the rare interactive brass element (e.g., a "featured" filter chip) |

**Accent alternative — Teal (personalization only, PRD Feature #9)**

A single additional hue reserved *exclusively* for the Phase-2 personalization mechanism (§2.9) — deliberately **not** Brass (reserved for narrow annotation use only, per the rule above) and **not** Moss/Success (reusing a semantic hue as a personal accent would let a user recolor every primary button the same green as "success," undermining the very separation §2.2 exists to establish). Teal exists so personalization has a second option at all without borrowing meaning from either of those.

Shipped as a single `600` step in C1. **G11 extended it to a full ramp**, because implementing the accent for real exposed that one step cannot serve both grounds: `#1B5E5E` is a *light-mode* hue, and on the dark drafting-blue canvas it measured **1.74:1** as foreground text and focus ring (§4.1). Dark needs paler steps for foreground and mid steps for fills, exactly as the Blueprint ramp does. Steps `100`/`200`/`900` are deliberately omitted — no role needs them, and inventing unused primitives is what this section warns against.

| Token | Hex | Typical use |
|---|---|---|
| `--mds-accent-teal-50` | `#E6F2F2` | Light-mode `action-primary-tint` (selected-row / subtle fill). Ink on it: 15.90:1. |
| `--mds-accent-teal-300` | `#34B4B4` | **Dark-mode only** — `action-primary-fg` and the focus ring. 5.16:1 on the dark surface. |
| `--mds-accent-teal-400` | `#247E7E` | **Dark-mode only** — primary hover fill. White on it: 4.82:1. |
| `--mds-accent-teal-500` | `#1D6666` | **Dark-mode only** — primary fill. White on it: 6.68:1. |
| `--mds-accent-teal-600` | `#1B5E5E` | Light-mode primary fill + `action-primary-fg` — verified 7.48:1 white-text contrast, §4.1. The original ratified value, unchanged. |
| `--mds-accent-teal-700` | `#164E4E` | Light-mode hover fill + focus ring (9.40:1); also the **dark-mode active** fill — see §4.1's note on why dark inverts direction here. |
| `--mds-accent-teal-800` | `#113C3C` | Light-mode active fill. White on it: 12.10:1. |

Used *only* when a user has selected it; never a system default, never a semantic-status color.

**Primitive scale — Success (Moss)**

| Token | Hex |
|---|---|
| `--mds-success-50` | `#EAF3EE` |
| `--mds-success-100` | `#D3E7DA` |
| `--mds-success-200` | `#A8D0B7` |
| `--mds-success-300` | `#7CB794` |
| `--mds-success-400` | `#579C74` |
| `--mds-success-500` | `#3C7A5C` — verified 5.09:1 as white-text-on-fill (badge/button use, modest but passing margin) |
| `--mds-success-600` | `#2F6249` — default success **text/icon** color on white/paper (darker than 500 for a safer margin on small text) |
| `--mds-success-700` | `#24492F` | Hover/pressed |
| `--mds-success-800` | `#1B3823` |
| `--mds-success-900` | `#122619` |

**Primitive scale — Warning (Amber)**

| Token | Hex |
|---|---|
| `--mds-warning-50` | `#FBF1E0` |
| `--mds-warning-100` | `#F6E2C0` |
| `--mds-warning-200` | `#ECC885` |
| `--mds-warning-300` | `#DFAD54` |
| `--mds-warning-400` | `#C68F30` |
| `--mds-warning-500` | `#A9711F` — default warning **fill**, paired with `--mds-neutral-900`/ink text (**not** white — no amber dark enough to hit 4.5:1 with white text still reads as amber; same resolution the previous draft used, kept intentionally) |
| `--mds-warning-600` | `#8C5E19` | Hover/pressed fill |
| `--mds-warning-700` | `#7A4F15` — default warning **text/icon** color on white/paper — verified 7.11:1, §4.1 |
| `--mds-warning-800` | `#5C3B10` |
| `--mds-warning-900` | `#3E280B` |

**Primitive scale — Danger (Redline)**

Named for the drafting-desk red pencil used to mark corrections — an intentional, subject-consistent name for what is otherwise a standard destructive/error hue.

| Token | Hex |
|---|---|
| `--mds-danger-50` | `#F8E9E6` |
| `--mds-danger-100` | `#F1D3CD` |
| `--mds-danger-200` | `#E2A89C` |
| `--mds-danger-300` | `#D07D6A` |
| `--mds-danger-400` | `#BE5943` |
| `--mds-danger-500` | `#B3402B` |
| `--mds-danger-600` | `#A83A2A` — default destructive-button fill (white text; verified 6.36:1, §4.1 — a wider margin than the previous Indigo-based draft's 4.56:1) |
| `--mds-danger-700` | `#8A2E20` — hover/pressed; also the default **error-text/icon** color on white (verified 6.36:1 at 600, comparable-to-wider at 700, §4.1) |
| `--mds-danger-800` | `#6E2419` |
| `--mds-danger-900` | `#521B12` |

**Primitive scale — Neutral (Paper / Ink)**

Renamed from the previous draft's "Slate" — same structural role (canvas, surface, border, and text-emphasis steps), re-anchored to a cool paper-and-drafting-ink family rather than a generic UI slate, and given light/dark mappings up front rather than reserving the top of the scale for "future" dark-mode use.

| Token | Light hex | Dark hex | Typical use |
|---|---|---|---|
| `--mds-neutral-0` | `#FFFFFF` | `#081A29` | Canvas / surface base |
| `--mds-neutral-50` | `#F3F4F1` (Paper) | `#0C2337` | App-shell canvas background |
| `--mds-neutral-100` | `#E8EAE4` (Paper Sunken) | `#123350` | Card/table hover background, disabled fill |
| `--mds-neutral-200` | `#DDE1DA` (Rule Soft) | `#1D3F57` | Subtle dividers |
| `--mds-neutral-300` | `#CBD3CE` (Rule) | `#2C5570` | Default borders, input borders |
| `--mds-neutral-400` | `#7C8B93` (Ink Faint) | `#7591A4` | Placeholder text, disabled text/icons (intentionally below AA — verified ~3.52:1, §4.1) |
| `--mds-neutral-500` | `#5E6E77` | `#8CA5B5` | Icon default color |
| `--mds-neutral-600` | `#4A5A66` (Ink Soft) | `#A9C1D1` | Secondary body text (verified 7.13:1, §4.1) |
| `--mds-neutral-700` | `#2E3B44` | `#C4D5E0` | High-emphasis secondary text |
| `--mds-neutral-800` | `#16212B` (Ink) | `#DCE7EE` | **Default body text** (verified 16.3:1, §4.1) |
| `--mds-neutral-900` | `#0E1620` | `#EAF1F6` | Headings, highest-emphasis text |
| `--mds-neutral-950` | `#080D14` | `#F5F9FB` | Reserved, highest-emphasis dark-mode text |

**Semantic aliases (the layer components actually consume) — light / dark**

| Semantic token | Light-mode mapping | Dark-mode mapping | Purpose |
|---|---|---|---|
| `--mds-color-bg-canvas` | `--mds-neutral-50` | `--mds-neutral-50` (dark) | App shell background, page canvas |
| `--mds-color-bg-surface` | `--mds-neutral-0` | `--mds-neutral-100` (dark) | Cards, panels, table rows, modal body |
| `--mds-color-bg-surface-raised` | `--mds-neutral-0` + shadow (see §2.5) | `--mds-neutral-100` (dark) + shadow | Popovers, dropdowns, modals |
| `--mds-color-bg-sunken` | `--mds-neutral-100` | `--mds-neutral-50` (dark) | Code/expression editor background, disabled input fill |
| `--mds-color-border-default` | `--mds-neutral-200` | `--mds-neutral-200` (dark) | Card/table/divider borders |
| `--mds-color-border-strong` | `--mds-neutral-300` | `--mds-neutral-300` (dark) | Input borders, focus-adjacent borders |
| `--mds-color-text-body` | `--mds-neutral-800` | `--mds-neutral-800` (dark) | Default text |
| `--mds-color-text-heading` | `--mds-neutral-900` | `--mds-neutral-900` (dark) | Headings |
| `--mds-color-text-secondary` | `--mds-neutral-600` | `--mds-neutral-600` (dark) | Help text, metadata, captions |
| `--mds-color-text-disabled` | `--mds-neutral-400` | `--mds-neutral-400` (dark) | Disabled text (exempt from AA — §4.1) |
| `--mds-color-text-on-primary` | `--mds-neutral-0` | `--mds-neutral-0` (dark) | Text/icons on primary/danger/success fills |
| `--mds-color-text-on-warning` | `--mds-neutral-900` | `--mds-neutral-900` (dark) | Text/icons on warning fills (amber needs dark text, both themes) |
| `--mds-color-action-primary-bg` | `--mds-primary-600` | `--mds-primary-400` (dark — lightened so it still reads against a dark ground) | Primary button fill |
| `--mds-color-action-primary-bg-hover` | `--mds-primary-700` | `--mds-primary-300` (dark) | Primary button hover |
| `--mds-color-action-primary-bg-active` | `--mds-primary-800` | `--mds-primary-200` (dark) | Primary button pressed |
| `--mds-color-action-danger-bg` | `--mds-danger-600` | `--mds-danger-400` (dark) | Destructive button fill |
| `--mds-color-focus-ring` | `--mds-primary-600` @ 55% opacity outer glow + `--mds-primary-700` solid ring | `--mds-primary-400` equivalents (dark) | Focus-visible indicator, all components (§4.2) |
| `--mds-color-overlay-scrim` | `--mds-neutral-900` @ 50% opacity | `--mds-neutral-950` @ 60% opacity (dark) | Modal/drawer backdrop |

> **Decision (not pinned by the plan):** the dark-mode primary accent is lightened (primary-400 rather than primary-600) rather than reusing the light-mode fill color verbatim — a design judgment applying the general principle "an accent must keep working on both grounds, shifted rather than replaced" (not itself dictated by the plan, but a direct consequence of committing to a real dark theme in §2.1).

### 2.3 Spacing

A **4px base-unit grid** (all spacing values are multiples of 4px; the 2px half-step exists only for icon-to-text micro-adjustments). Named tokens, not raw pixel values, are used everywhere:

| Token | Value | Typical use |
|---|---|---|
| `--mds-space-0` | 0px | Reset |
| `--mds-space-px` | 1px | Hairline borders |
| `--mds-space-0-5` | 2px | Icon-glyph micro-alignment only |
| `--mds-space-1` | 4px | Tightest internal padding (badge, chip) |
| `--mds-space-2` | 8px | Icon-to-label gap, compact input padding (vertical) |
| `--mds-space-3` | 12px | Input padding (horizontal), small-gap stacks |
| `--mds-space-4` | 16px | **Default component padding**, form-field vertical rhythm |
| `--mds-space-5` | 20px | Card padding (default size) |
| `--mds-space-6` | 24px | Section spacing within a page, modal padding |
| `--mds-space-8` | 32px | Between distinct page regions (e.g., filter bar → table) |
| `--mds-space-10` | 40px | Large card padding, empty-state internal spacing |
| `--mds-space-12` | 48px | Page-level top padding on desktop |
| `--mds-space-16` | 64px | Empty-state/marketing-scale spacing |
| `--mds-space-20` | 80px | Large hero/empty-state illustration spacing |
| `--mds-space-24` | 96px | Reserved for marketing/onboarding surfaces only |

Governing rule: **no component or page authors a raw pixel margin/padding value** — every spacing decision resolves to one of the tokens above, so vertical rhythm stays consistent between the builder, dashboard, and settings without any page needing to "match" another by eye.

### 2.4 Typography

> **Decision (ratified):** typography follows the same "field instrument / blueprint desk" concept as color (§2.2), via **three roles rather than one workhorse typeface** — consistent with the ratified visual prototype:
> - **Display** (headings, nav labels, section eyebrows): `"Segoe UI Variable Display", system-ui, -apple-system, "Segoe UI", "Helvetica Neue", Arial, sans-serif` — a clean, humanist system sans set in **normal case** (not uppercase). Ships everywhere without a webfont download and degrades to the platform UI face (`system-ui`) so the page never silently renders in a generic serif. **(Revised 2026-07-06** from the earlier condensed Bahnschrift/DIN "drafting-stencil" display face, as part of the user-requested modern/icony refresh — see the §2.6 note.**)**
> - **Body** (paragraphs, form-field values, table cells): the plain **system UI sans stack** — `-apple-system, "Segoe UI", "San Francisco", "Helvetica Neue", Arial, sans-serif` — deliberately neutral so the display face carries the personality and body text stays maximally legible/familiar across OSes and locales (relevant to Persona A's NGO/public-health field-team context, not assumed English-only).
> - **Utility / data** (tokens, IDs, timestamps, hex codes, the expression-engine editor, XLSForm field-key display, webhook payload previews): monospace — `"Cascadia Code", "SF Mono", Consolas, "Roboto Mono", Menlo, monospace` — chosen specifically because a design-system reference and a data-heavy submissions inbox are both full of values that need to line up in a column (`font-variant-numeric: tabular-nums` is applied wherever digits appear in a column, e.g. submission counts, version numbers).
>
> This supersedes an earlier draft of this document that specified **Inter** as a single UI typeface; Inter was a reasonable, safe default in isolation, but the ratified visual direction calls for a display face with real character (condensed/geometric/technical) distinct from body text, which a single humanist grotesk used everywhere cannot provide. None of the three stacks requires a webfont license or a data-URI font embed — all are either pre-installed OS fonts or universally available system fallbacks, which also keeps the public embeddable form runtime (PRD Feature #3) free of an extra font-loading round trip on third-party pages.

Type scale (named tokens; size/line-height/weight), roughly a 1.2× modular scale:

| Token | Size | Line-height | Weight | Use |
|---|---|---|---|---|
| `--mds-type-display` | 36px | 44px | 700 | Onboarding/marketing hero text only — never inside the authenticated app shell |
| `--mds-type-heading-1` | 30px | 38px | 700 | Page title (e.g., "Submissions," a form's builder title) |
| `--mds-type-heading-2` | 24px | 32px | 600 | Major section heading within a page |
| `--mds-type-heading-3` | 20px | 28px | 600 | Card/subsection heading |
| `--mds-type-heading-4` | 16px | 24px | 600 | Small heading, table group header |
| `--mds-type-body-lg` | 16px | 24px | 400 | **Default body text**, form-input value text |
| `--mds-type-body-md` | 14px | 20px | 400 | Dense UI: table cells, compact lists, secondary form controls |
| `--mds-type-body-sm` | 13px | 18px | 400 | Tertiary text, dense metadata rows |
| `--mds-type-label` | 13px | 18px | 500 | Form-field labels, nav-item labels |
| `--mds-type-caption` | 12px | 16px | 400 | Help text, timestamps, field-level hints |
| `--mds-type-code` | 13px | 20px | 400 (monospace) | Expression editor, field `key` display, JSON/payload previews |

Weight tokens used across the scale above: `--mds-font-weight-regular: 400`, `--mds-font-weight-medium: 500`, `--mds-font-weight-semibold: 600`, `--mds-font-weight-bold: 700`.

**Font-role mapping**: `display`, `heading-1`, `heading-2`, `heading-3`, and `heading-4` render in the **Display** stack (**normal case**, a slight negative `letter-spacing` ≈ `-0.01em` on the largest sizes) — a clean humanist sans carrying the page's visual personality (the original drafting-desk concept set these uppercase; the 2026-07-06 refresh dropped the uppercase for a softer, more modern read). `body-lg`, `body-md`, `body-sm`, `label`, and `caption` render in the **Body** stack — plain, maximally legible, no letter-spacing tricks. `code` renders in the **Utility/mono** stack with `font-variant-numeric: tabular-nums`. A component never mixes stacks within a single text node (e.g., a heading is never partially body-face) — the three-role split is a per-token-not-per-character decision, kept simple deliberately.

Governing rule: heading levels are **visual tokens, not semantic HTML levels** — a card titled with `--mds-type-heading-3` styling might be an `<h2>` or an `<h3>` in the DOM depending on the page's actual heading hierarchy (§4.3 covers why this distinction matters for screen readers). Never skip a token level to "make text bigger" — if `heading-3` isn't big enough, that is a signal the content belongs at `heading-2`, not that the token scale should be bent.

### 2.5 Elevation / shadow

A 5-step elevation scale, used to communicate stacking order (what sits "above" the canvas) independent of color. *(Softened 2026-07-06 to more diffuse, lower-opacity shadows as part of the modern/icony refresh; the ink-cast color rule below is unchanged.)*

| Token | Value | Use |
|---|---|---|
| `--mds-shadow-0` | `none` | Flat surfaces at canvas level (default card at rest, in some contexts) |
| `--mds-shadow-1` | `0 1px 3px 0 rgba(22, 33, 43, 0.05), 0 1px 2px -1px rgba(22, 33, 43, 0.04)` | Resting card, table row group |
| `--mds-shadow-2` | `0 8px 22px -8px rgba(22, 33, 43, 0.12), 0 2px 6px -3px rgba(22, 33, 43, 0.06)` | Hover-raised card, dropdown trigger button |
| `--mds-shadow-3` | `0 14px 30px -10px rgba(22, 33, 43, 0.14), 0 4px 10px -5px rgba(22, 33, 43, 0.08)` | Popovers, dropdown/select menus, tooltips |
| `--mds-shadow-4` | `0 22px 44px -14px rgba(22, 33, 43, 0.18), 0 8px 16px -8px rgba(22, 33, 43, 0.10)` | Modals/dialogs, date-picker panels |
| `--mds-shadow-5` | `0 30px 60px -18px rgba(22, 33, 43, 0.22), 0 12px 24px -10px rgba(22, 33, 43, 0.12)` | Toasts (must read as "above" a modal that might be open concurrently) |

> **Decision (not pinned by the plan):** shadow color is based on the `--mds-neutral-800`/"Ink" value (`rgb(22, 33, 43)`) rather than a generic near-black, so elevation shadows carry the same faint blue-ink cast as the rest of the palette rather than a neutral grey shadow that would look slightly mismatched against the paper/blueprint ground.

Governing rule: shadow tokens are **strictly ordered** — a component at elevation *N* never sits visually beneath a component at elevation *N-1* it is meant to overlay (e.g., a toast, `shadow-5`, must never appear to sit under an open modal, `shadow-4`, which is why toasts render in their own top-level portal — see §3.7).

### 2.6 Border radius

> **Decision (revised 2026-07-06):** the original drafting-desk concept used a near-flat **2–5px** radius scale for a precise, mostly-square-edged character. At the user's request the app was refreshed to feel *more modern and less "edgy" while staying compact*, so the scale was softened to **6 / 8 / 12px** — still well short of the generic 16px+ "round everything" SaaS look, and spacing/type density were left untouched so the app stays compact. Full-round (`--mds-radius-full`) remains reserved for shapes that are *supposed* to be circular/pill-shaped (avatars, toggle tracks, status pills), never as a general softening device. `--mds-radius-lg` (12px) is now also used for the tinted icon badges introduced in the same refresh (page-header + stat-tile glyphs).

| Token | Value | Use |
|---|---|---|
| `--mds-radius-none` | 0px | Table cells, full-bleed images, the app-shell/canvas edges |
| `--mds-radius-sm` | 6px | Checkboxes, small chips |
| `--mds-radius-md` | 8px | **Default**: buttons, form inputs, cards, modals, popovers — one radius for nearly everything |
| `--mds-radius-lg` | 12px | Larger containers + the tinted icon badges (page-header, stat tiles) introduced in the 2026-07-06 refresh |
| `--mds-radius-full` | 9999px | Pills/badges, avatars, toggle-switch track/thumb — true circles/pills only, never a "rounded rectangle" softening |

### 2.7 Breakpoints

Matching the PRD's Feature #5 acceptance criteria **exactly**: *"Every screen in the product ... remains fully readable and operable at mobile (≤480px), tablet (≤1024px), and desktop breakpoints, enforced through the shared design system rather than per-page custom CSS."*

| Token | Range | Notes |
|---|---|---|
| `--mds-breakpoint-mobile-max` | ≤ 480px | Single-column layouts; sidebar nav collapses to a bottom/hamburger pattern (§3.4, §6) |
| `--mds-breakpoint-tablet-max` | ≤ 1024px | Two-column layouts where space allows; sidebar nav collapses to icon-only or overlay drawer |
| `--mds-breakpoint-desktop-min` | > 1024px | Full multi-column layouts; persistent expanded sidebar |

> **Decision (not pinned by the plan):** the PRD states these boundaries as `max-width` figures (mobile ≤480px, tablet ≤1024px), which is how this document names and documents them for traceability — but the component library **implements** them mobile-first with `min-width` media queries (base/unprefixed styles target mobile; a `@media (min-width: 481px)` query layers on tablet adjustments; `@media (min-width: 1025px)` layers on desktop adjustments). This is a standard, low-risk CSS authoring technique (avoids specificity fights between overlapping max-width rules) that produces the identical three-range behavior the PRD specifies; the PRD's numbers are the contract, mobile-first `min-width` is simply the implementation technique satisfying that contract.

### 2.8 Motion & transition

| Token | Value | Use |
|---|---|---|
| `--mds-duration-fast` | 100ms | Micro-interactions: checkbox check, button press-state |
| `--mds-duration-base` | 150ms | **Default**: hover/focus transitions, toggle switch |
| `--mds-duration-moderate` | 250ms | Dropdown/popover open-close, accordion expand (progressive disclosure, §5) |
| `--mds-duration-slow` | 400ms | Modal open/close, drawer slide-in |
| `--mds-duration-deliberate` | 600ms | Full-page/section transitions (used sparingly) |
| `--mds-ease-standard` | `cubic-bezier(0.4, 0, 0.2, 1)` | Default easing for most transitions |
| `--mds-ease-decelerate` | `cubic-bezier(0, 0, 0.2, 1)` | Entering elements (modal appearing, toast sliding in) |
| `--mds-ease-accelerate` | `cubic-bezier(0.4, 0, 1, 1)` | Exiting elements (toast dismiss, dropdown close) |

Governing rule: **every transition token is wrapped in a `prefers-reduced-motion` guard at the component-library level**, not per-page — when the media query matches, all `--mds-duration-*` values collapse to `1ms` (not `0ms`, so `transitionend` events still fire reliably for any JS that depends on them) and easing is left as-is (irrelevant at 1ms). This is implemented once, centrally, in the token/CSS layer, so no individual component author needs to remember to add the guard.

### 2.9 User-Level Personalization (PRD Feature #9)

Added after the original seven-subsection token model, at the user's explicit request. This section is the technical mechanism behind PRD Feature #9 — it does not change any decision already made in §2.1–2.8, it adds one more remapping layer on top of the existing primitive→semantic architecture.

**The mechanism**: a third token layer, applied *after* the light/dark mapping from §2.1–2.2, not instead of it. A per-user `<html data-theme-mode="dark" data-accent="teal" data-font-size="large" data-dyslexia-font="true">` attribute set (populated from `user_ui_preferences`, Data Dictionary §19, on authenticated page load) drives a small set of CSS attribute-selector overrides layered on top of the base `:root` tokens:

```css
/* Base layer: §2.1's light/dark mapping (media query + data-theme-mode, already specified) */
:root { --mds-color-action-primary-bg: var(--mds-primary-600); }
:root[data-theme-mode="dark"] { --mds-color-action-primary-bg: var(--mds-primary-400); }

/* Personalization layer: applied on top, Phase 2 (as built in G11) */
:root[data-accent="teal"] { --mds-color-action-primary-bg: var(--mds-accent-teal-600); }
:root[data-font-size="large"] {
    --mds-type-body-lg-font-size: 18px;   /* ×1.125, rounded */
    --mds-type-body-lg-line-height: 27px; /* derived from the role's original ratio — see below */
}
:root[data-dyslexia-font="true"] { --mds-font-family-body: "OpenDyslexic", var(--mds-font-family-body-default); }
```

> **Correction (G11, as-built).** An earlier draft of this section wrote the font-size example as
> `--mds-type-body-lg: 18px`. **That token does not exist** — the generated names are
> `--mds-type-{role}-font-size` / `-line-height` / `-font-weight` (§2.4). The example above is the real
> shape. The same draft's "+2px, line-heights unchanged" rule has also been replaced; see the Font size
> bullet for why it could not ship.

- **Theme mode** (Phase 1): `data-theme-mode` resolves to `light` / `dark` — `system` is the *absence* of the attribute, letting the plain `@media (prefers-color-scheme: dark)` rule from §2.1 take over unopposed. This is why `system` costs nothing extra to implement: it is simply "don't set the override attribute."
- **Accent** (Phase 2): the `data-accent` attribute only ever takes a value from the small, curated set defined here — currently `blueprint` (default, no attribute needed) and `teal` (§2.2's dedicated personalization-only accent alternative) — never an arbitrary color, and **never** Brass or a Success/Warning/Danger hue. This is a deliberately narrower set than an earlier draft of this section considered (which briefly proposed a "moss"/"brass-adjacent" option before this document caught that both would collide with meanings §2.2 already assigns those hues elsewhere — Moss is Success, Brass is annotation-only). Each curated option remaps only `--mds-color-action-primary-bg` and its hover/active/focus-ring siblings (§2.2's semantic alias table), never the neutral/success/warning/danger scales — a user cannot personalize their way into confusing "success green" with "my chosen accent," because accent and semantic color are architecturally separate layers (per §2.2's "spend your boldness in one place, semantic color is separate from the accent" principle, which this personalization layer must not undermine).
- **Font size** (Phase 2): `data-font-size="large"`/`"extra_large"` uniformly scales the entire type scale (§2.4) — never a per-component or per-page override. Each role's **font-size and line-height are both multiplied** by a fixed factor (**×1.125** large, **×1.25** extra large); the font-size is rounded to whole px and the line-height is then **derived** as `round(roundedFontSize × the role's original ratio)`. Every role therefore keeps its own line-height ratio to within ±2%, and vertical rhythm stays intact at every scale.

  **This replaces this section's original rule ("a fixed +2px step per level, never changing line-heights"), which could not ship.** Holding line-height constant while growing font-size does not preserve a ratio — it destroys it. At extra large that rule produced `body-sm` as **17px text inside an 18px line box (ratio 1.06)** and `caption` as **16px inside 16px (1.00)**: line boxes tighter than the glyphs they contain, clipping descenders and failing WCAG 1.4.12 (Text Spacing) the moment a user also applies a text-spacing override. An accessibility accommodation must not ship an accessibility regression. The proportional rule below keeps every role at or above **1.22**.

  Authoritative table (kept in lockstep with `packages/design-system/src/theme/theme-overrides.css` by `src/theme/__tests__/type-scale.test.ts`, which re-derives every value from the token source):

  | Role | standard | large ×1.125 | extra large ×1.25 |
  |---|---|---|---|
  | `display` | 36 / 44 | 41 / 50 | 45 / 55 |
  | `heading-1` | 30 / 38 | 34 / 43 | 38 / 48 |
  | `heading-2` | 24 / 32 | 27 / 36 | 30 / 40 |
  | `heading-3` | 20 / 28 | 23 / 32 | 25 / 35 |
  | `heading-4` | 16 / 24 | 18 / 27 | 20 / 30 |
  | `body-lg` | 16 / 24 | 18 / 27 | 20 / 30 |
  | `body-md` | 14 / 20 | 16 / 23 | 18 / 26 |
  | `body-sm` | 13 / 18 | 15 / 21 | 16 / 22 |
  | `label` | 13 / 18 | 15 / 21 | 16 / 22 |
  | `caption` | 12 / 16 | 14 / 19 | 15 / 20 |
  | `code` | 13 / 20 | 15 / 23 | 16 / 25 |

  Note the deliberate rounding rule: font-size and line-height are **not** rounded independently. Independent rounding compresses `caption` at large from 1.333 → 1.286 and `body-sm`/`label` from 1.385 → 1.333; deriving the line-height from the rounded font-size holds them at 1.357 and 1.400.

- **Dyslexia-friendly font** (Phase 2): `data-dyslexia-font="true"` re-points only `--mds-font-family-body` (§2.4's Body role) to an alternative face; the Display role (headings, the product's visual personality) and the Utility/mono role (data, code) are untouched, consistent with Feature #9's acceptance criterion that this is a targeted accommodation, not a general typeface picker.

  **`code` is scaled by the size axis but never swapped by the face axis** — an asymmetry worth stating so a future reader does not "fix" it. The two axes are different accommodations. Font size is for low vision, and `code` is tied for the smallest role in the system, so leaving it at 13px while body reaches 20px recreates the exact "one region I cannot read" failure the feature exists to fix. The face swap is for letterform disambiguation in prose; in column-aligned data and expressions, OpenDyslexic's weighted bottoms and wide advances hurt rather than help.

  The face is **self-hosted** (`public/fonts/`, OpenDyslexic 5.2.5, SIL OFL 1.1 — exceptions-log #5), which is the first webfont in the system and a deliberate deviation from §2.4's all-system-stack property. It costs nothing on the default path: a browser fetches a `@font-face` `src` only when a rule using the family actually matches an element, and the sole rule naming OpenDyslexic is the attribute-gated one above. A user who never opts in downloads **zero font bytes**. The `@font-face` lives in `src/theme/fonts.css`, imported only by the admin app — deliberately *not* by the guest runtime, which imports `theme.css` and would otherwise carry the declaration.

**Governing rule**: personalization attributes are set **once**, server-side, on the authenticated shell's root element at render time (from the user's `user_ui_preferences` row) — never re-computed per-component client-side, and never applied to the public/guest form runtime shell (§3.0), which renders only the base tenant-branded theme regardless of which admin/builder user is viewing analytics on the back end at the same moment. This keeps personalization a pure top-of-tree concern, exactly like the light/dark mapping it extends.

> **The phrase "the base tenant-branded theme" became literal in Increment H23b.** The guest shell now emits the tenant's generated ramp ([ADR-0014](../adr/0014-tenant-brand-ramp-generation.md)) as a `<style>` block — **the tenant layer, and only the tenant layer**. It still emits no `data-accent`, `data-font-size` or `data-dyslexia-font`, and the tenant's brand arrives as CSS custom properties rather than as an attribute, so nothing on this surface can be mistaken for personalization. There is deliberately **no precedence rule here**: a respondent has no preferences for the brand to lose to, which is the §D1 two-layers/two-audiences split doing its job rather than an inconsistency to tidy. `GuestRuntimeTest` pins both halves — the root element stays attribute-free *while* the brand block is present.
>
> The same increment made two non-CSS surfaces follow `--mds-color-action-primary-bg`: the guest shell's `<meta name="theme-color">` and the per-form PWA manifest's `theme_color`. Both had been hard-coded to `--mds-accent-teal-600` since G8a — a colour this surface has never rendered, because it has never emitted `data-accent` — so the browser chrome and the installed app's splash screen were tinted a hue appearing nowhere in the form. They now take the tenant's light fill, falling back to `--mds-primary-600`. The manifest's `background_color` stays `--mds-neutral-50` (ADR-0014 §D7: the tenant layer never touches a neutral).

**As-built notes (G11).**

- **In every axis, the product default is the ABSENCE of the attribute** — `system` mode, `blueprint` accent, `standard` size, dyslexia off. There is no `data-font-size="standard"` rule and there never should be. This is what makes an un-personalized user render byte-identically to a guest, and it is why the blade emits each attribute through a whitelist (`in_array`) rather than interpolating the prop, so a corrupted row cannot inject an arbitrary attribute value.
  - **The absence-as-default convention SURVIVES on every axis, including accent — but on a branded tenant "absence" now describes the ATTRIBUTE and no longer describes the STORED VALUE ([ADR-0014](../adr/0014-tenant-brand-ramp-generation.md), Increment H23a3).**

    When this section was amended in H23a1 it predicted the fix would be an explicit `data-accent="blueprint"` plus a `:root[data-accent='blueprint']` restoring rule. **As built it is neither, and the prediction is corrected here rather than quietly left standing.** Emitting a restoring rule at (0,2,0) would have outranked the tenant's own `:root` block at (0,1,0) in *every* case, so the brand would never have applied at all — the prediction was not merely more complex, it was wrong.

    What ships instead resolves precedence **on the server**: `app.blade.php` emits the tenant ramp **only when the member has expressed no accent opinion**. A member who picked Teal gets Teal's own `[data-accent='teal']` rules over the base tokens, exactly as before; a member who picked Blueprint gets the base tokens, which *are* Blueprint, so no restoring rule needs to exist. Personalization cannot lose a specificity contest it never enters. The CSS route was rejected because the tenant ramp needs `:root`, `[data-theme-mode='dark']` and a prefers-color-scheme twin — (0,1,0), (0,2,0), (0,3,0) — and the user's teal rule at (0,2,0) would have **tied** with the tenant's dark block, leaving source order to decide. G11 already shipped that exact bug once.

    The storage change that makes it expressible: `user_ui_preferences.accent_token` widens from `{NULL, 'teal'}` to `{NULL, 'blueprint', 'teal'}`. **NULL now means one thing only — "no opinion"** — and Blueprint is stored explicitly. Existing NULL rows render identically, because a tenant with no brand has nothing to inherit. `<html>` still emits `data-accent` **only** for Teal, so the attribute-level convention above is unchanged, and the whitelist requirement is untouched.
- **The server-side-once rule holds for the durable copy; the client also applies optimistically.** Inertia visits swap `<body>`, not `<html>`, so after choosing a preference `resources/js/composables/useTheme.ts` sets or removes the attribute itself for instant feedback, and `app.blade.php` re-emits the durable copy on the next full load. This is the same carve-out already granted for the theme toggle (exceptions-log #3), extended to the other three axes rather than a new deviation — the durable source of truth is unchanged.
- **The guest runtime shares `theme.css`.** Every `[data-accent]` / `[data-font-size]` / `[data-dyslexia-font]` rule physically exists in the guest bundle; they are inert only because nothing ever sets those attributes on the guest `<html>`. `GuestRuntimeTest` pins this, asserting the guest shell emits none of them *while authenticated as a user with maximal preferences* — precisely the scenario this governing rule describes.
- **Canonical home is Settings → Appearance.** The nav quick toggle (exceptions-log #3) remains theme-mode-only; the accent, text-size and dyslexia controls exist in Settings and nowhere else. Each control PATCHes only its own field, and every rule on `UpdateAppearanceRequest` is `sometimes`, so a partial payload structurally cannot clobber a sibling axis.
- **Prop naming.** The shared Inertia prop stays `ui.theme` (rather than being renamed `ui.appearance`) even though it now carries four axes — renaming would churn the blade, the middleware, the composable, the TS types and two Pest files for no functional gain. Read "theme" there as "the whole appearance resolution".

> **Decision (not pinned by the plan):** the curated accent set is deliberately small (two options at launch — default Blueprint and the dedicated Teal alternative) rather than an open picker, and implemented via `data-*` attribute selectors rather than arbitrary inline custom-property overrides — both choices trade a small amount of user freedom for a guarantee that every possible personalization combination has been pre-verified against §4.1's contrast requirements and never collides with an existing semantic or annotation hue. An arbitrary color picker would reopen exactly the accessibility risk the semantic-token architecture exists to close off.

> **NARROWED by [ADR-0014](../adr/0014-tenant-brand-ramp-generation.md) (2026-08-05, Increment H23a1) — read this before citing the note above.** The decision stands **for user personalization** and is unchanged there: `AccentToken` remains closed at Blueprint and Teal, `UpdateAppearanceRequest` still validates it with `Rule::enum()`, and a *user* still cannot pick an arbitrary accent for themselves. It no longer extends to **tenant branding**, which is a different layer serving a different audience (respondents and members of one organisation, not one user) and answering a different promise (`PRD.md` G3's *"custom domain/branding available"*, not Feature #9). A tenant supplies an arbitrary `#RRGGBB` and the platform **generates** the twelve tokens from it, holding hue, capping chroma and re-deriving lightness per role until all seventeen §4.1 pairings measure clean.
>
> **The sentence above is right about the cost, and ADR-0014 does not dispute it.** The guarantee changes from *structural* (no reachable combination can be inaccessible, because a human enumerated them all) to *procedural* (every stored ramp was verified by the engine at write time). What makes that defensible is set out in ADR-0014's Consequences and in [exceptions-log #7](exceptions-log.md); the shortest form is that the engine, fed Teal's own hue, re-derives `--mds-accent-teal-600` (`#1B5E5E`) and `--mds-accent-teal-50` (`#E6F2F2`) byte-identically. The two layers are governed separately and deliberately — do not "restore consistency" by collapsing them.

---

### 2.10 Transactional Email and PDF — the two surfaces that cannot resolve a token *(Increment H23a4, [ADR-0014](../adr/0014-tenant-brand-ramp-generation.md) §D8)*

Standing Rule 2 says one shared design system on every surface, no exceptions. Transactional email and
the submission PDF are the two surfaces where that rule has to be honoured **without the mechanism the
rest of the system is built on**: a mail client strips `<style>` and ignores `var()`, and dompdf
implements CSS 2.1, so a custom property is not merely unsupported there — it is unparseable, and the
whole declaration is dropped. Neither surface can read `tokens.css`, ever.

So both are rendered from **literal hexes resolved in PHP**, and that is the reason ADR-0014 §D8 stores
the derived ramp rather than re-deriving it on read. The design system is present on these surfaces as
*transcribed values*, not as a stylesheet, and the transcription is what needs guarding.

**Where each surface's copy lives, and what guards it.**

| Surface | File | Guard |
|---|---|---|
| Mail theme | `resources/views/mail/meridian.blade.php` | rendered as **Blade**, not `.css` — the view finder maps a `.css` view to the FileEngine and would emit `{{ … }}` literally |
| Mail template | `resources/views/mail/notification.blade.php` | ours, not `notifications::email` — a Blade component does not inherit the caller's scope, so `$brand` must be injected at the tag |
| Mail header | `resources/views/vendor/mail/html/header.blade.php` | the **only** published vendor component; the text arm is deliberately not published |
| PDF | `resources/views/pdf/_styles.blade.php` | `SubmissionPdfRendererTest`'s structural scan of the directory |
| The product default | `App\Support\Branding\BrandPalette::PRODUCT` | `tests/Unit/Branding/BrandPaletteTokenParityTest.php`, which parses `packages/design-system/tokens/*.json` and re-resolves each alias |

That last row is the one that matters most. `PRODUCT` is six design-system values written out by hand,
and a hand-copied value guarded only by a comment is how `https://acme/invitations/` shipped in every
invitation email for two increments. It reads the **committed token JSON** rather than
`packages/design-system/dist/tokens.css`, which is gitignored and therefore absent in CI.

**§D7's rule holds on both surfaces: the brand paints ACTIONS, never neutrals or body text.** Mail
references `$brand` in exactly six declarations (the primary button's fill and its four borders, links,
the panel accent and its tint); the PDF references it in three (the header rule, the document title, the
prose panel). Everything else is a fixed Meridian neutral. A respondent's answers must read the same
whatever colour the tenant chose, and success/error buttons keep the semantic `success`/`danger` hues for
the same reason ADR-0011 §D11 kept chart colours off the brand layer — a green that meant "brand" instead
of "it worked" would be a worse button.

**Contrast is inherited from the stored measurements, not re-asserted here.** Each branded declaration
maps onto a §4.1 pairing the engine already measured: white on the fill is `bg`/on_primary verbatim; the
brand as ink on white paper is that same measurement read backwards (contrast is symmetric); the PDF's
prose panel is `tint`/ink, which is why it names `#0E1620` explicitly rather than inheriting the body's
`#1a1a1a` — `#0E1620` *is* the light ink ground the ratio was taken against. Button text is `#FFFFFF`
(the generator's `ON_PRIMARY`) and never `$brand['fg']`, because in the light ramp `fg` **is** `bg`.

**Two things are deliberately not built, and both are decisions rather than gaps.**

- **No dark-mode mail.** Four independent reasons, any one sufficient: `CssToInlineStyles` deletes
  `@media` from the theme before inlining; written into a `<style>` tag instead it would lose to the
  inline declarations the inliner has just written onto every element; the framework's layout hard-codes
  `color-scheme: light`; and the dark ramp was measured against Meridian's own dark grounds, not against
  whatever ground a client invents when it inverts a message.
- **No logo in the PDF.** dompdf's PNG and WebP paths both throw without `ext-gd`, which is absent from
  the app container *and* from all four CI jobs — so a logo would have rendered on a developer's machine
  and thrown in the pipeline. The PDF's existing no-external-references contract
  (`isRemoteEnabled = false`, chroot pinned to the view directory) is left intact. The logo reaches
  **mail** instead, as a hosted absolute URL on the tenant's app host (`GET /branding/logo`,
  unauthenticated and deliberately unsigned — an expiry on an image sitting in an inbox is a broken image
  on a timer).

**The header carries the tenant's name; the From address stays the platform's.** Per-tenant sending needs
per-domain DKIM/SPF, which ADR-0014 files under *full white-label — not addressed here*. The footer
therefore says "Sent via {app name}" whenever the header is not the product's own, because a branded
header over an unexplained platform sender reads as a spoof.

---

## 3. Component Inventory

### 3.0 The app shell (governing structure for everything below)

Per the PRD's Feature #6 acceptance criteria, quoted verbatim: **"One shared app shell wraps every authenticated screen; a distinct but token-consistent shell wraps the public/guest form runtime."**

Concretely, this document specifies exactly two shells, both built from the tokens in Section 2, and no third variant is permitted without an exception (§1.3):

- **Authenticated App Shell** — wraps the builder, dashboard, submissions inbox, and settings (and every future authenticated screen): a top nav (tenant/account switcher, search, notifications, avatar menu), a collapsible sidebar nav (primary navigation — Forms, Submissions, Dashboard, Settings), and a content region with a consistent max-width, padding (`--mds-space-8` horizontal on desktop, `--mds-space-4` on mobile), and page-header pattern (title + primary action + optional breadcrumbs). Every authenticated page renders inside this shell; no authenticated page renders its own top-level layout.
- **Public/Guest Runtime Shell** — wraps every guest-facing form (`/f/{slug}`) and the offline PWA runtime: a minimal header (tenant/form branding only — no admin nav, no account menu, since guests have no account per PRD §2.4), a centered single-column content region sized for form-filling (narrower max-width than the admin shell, optimized for readability of one question/section at a time), and a persistent progress indicator (§3.9) where the form is multi-step. It consumes the **same semantic color/spacing/type/radius tokens** as the authenticated shell (hence "token-consistent") but is a structurally distinct component, because a guest has fundamentally different needs (no navigation chrome to get lost in, fastest possible time-to-first-field, embeddable in an iframe on a third-party domain per PRD Feature #3).

Governing layout rule for the shells themselves: **the content region is the only place a page's own markup lives** — a page component never renders `<nav>`, page chrome, or its own header bar; it receives a `title`/`actions` slot contract from the shell and fills only the content region. This is the mechanical enforcement of "no page invents its own layout" (PRD Feature #6 acceptance criteria).

**The Authenticated App Shell owns exactly one persistent global entry point beyond navigation**: a "Send Feedback" trigger (PRD Feature #11), fixed in the top nav (§3.4) alongside notifications/account menu — never page-specific, never re-implemented per screen. It opens the feedback panel described in Feature #11's acceptance criteria as a lightweight overlay (built from the Modal component, §3.6, in its non-blocking/dismissible configuration), not a route navigation — a user never loses their place in the app to report a problem about it.

The sections below inventory the components that live inside these shells.

### 3.1 Buttons

**Variants**: `primary`, `secondary`, `tertiary` (text/ghost), `destructive`, `icon-only`.
**Sizes**: `sm` (32px height), `md` (40px height, default), `lg` (48px height) — all satisfying the 44×44px touch-target minimum via padding/hit-area even where the visual box is smaller (§4.4).

> **Decision (ratified):** Secondary is an **outlined treatment of the same Blueprint hue** used by Primary — not a separate secondary color family. The previous draft's Teal "secondary" scale is retired; Brass (§2.2) is reserved for narrow annotation use only (the Advanced-disclosure indicator, eyebrows, featured-template marker), never for a button variant. This keeps exactly one confident interactive hue across the whole product, consistent with "spend your boldness in one place."

| State | Primary | Secondary | Tertiary | Destructive |
|---|---|---|---|---|
| Default | `--mds-color-action-primary-bg` fill, white text | Transparent fill, `--mds-primary-600` border + text | Transparent fill, `--mds-primary-600` text, no border | `--mds-color-action-danger-bg` fill, white text |
| Hover | `--mds-primary-700` fill | `--mds-primary-50` fill, `--mds-primary-700` border+text | `--mds-primary-50` fill | `--mds-danger-700` fill |
| Focus-visible | Ring per §4.2, on top of hover/default fill | Ring per §4.2 | Ring per §4.2 | Ring per §4.2 |
| Active/pressed | `--mds-primary-800` fill | `--mds-primary-100` fill | `--mds-primary-100` fill | `--mds-danger-800` fill |
| Disabled | `--mds-neutral-200` fill, `--mds-neutral-400` text, no hover/focus response | Same pattern, border removed | `--mds-neutral-400` text only | Same pattern as primary disabled |
| Loading | Fill/border unchanged; label replaced by an inline spinner (§3.9) + optional retained label at reduced opacity; button is programmatically disabled (`aria-disabled="true"`, not removed from tab order — see §4.3) during the load | (same treatment) | (same treatment) | (same treatment) |

Icon-only buttons always carry an `aria-label` (never rely on a visual tooltip alone for their accessible name — §4.5) and use the same size/state matrix as labeled buttons, sized to a perfect square matching the size token's height.

**Governing layout rule**: exactly one `primary` button is visible per view/section at a time (e.g., one primary "Publish," one primary "Save"); every other action in that view is `secondary` or `tertiary`. This directly operationalizes progressive disclosure and visual hierarchy — a screen with three competing primary-styled buttons has, by definition, failed this rule and needs a hierarchy decision, not a fourth color.

### 3.2 Form Inputs

Covers: text, textarea, select/dropdown, checkbox, radio, toggle/switch, date picker, file upload, signature capture (placeholder for the Phase 1 field type).

**Universal label/help-text/error-message attachment pattern** (applies to every input type below, enforced by one shared `FormField` wrapper component — no input renders without going through it):

```
<FormField>
  [label]              -- --mds-type-label, --mds-color-text-body, associated via
                           a real <label for="..."> (never a floating div) — required
                           inputs get a trailing "(required)" text suffix, not color/asterisk
                           alone (color-only signifiers fail WCAG 1.4.1)
  [input control]       -- described-by help text AND error text via aria-describedby
                           (both ids, space-separated, when both are present)
  [help text]           -- --mds-type-caption, --mds-color-text-secondary, sits directly
                           below the control, always reserved space (no layout shift when
                           an error appears — see below)
  [error message]       -- --mds-type-caption, --mds-color-... (danger-700 text), preceded
                           by a non-color error icon; replaces/stacks below help text;
                           announced via aria-live (see §4.5) since these often appear
                           asynchronously after async/server-side validation
</FormField>
```

Governing rule: **help text occupies its layout slot even when absent from a hidden state's perspective** is *not* required (empty help text simply renders nothing), but once an error is present, the error message never *replaces* the input's border-only signifier — every error state pairs a **border-color change AND an icon AND text**, never any single one of those alone (again, WCAG 1.4.1 — never color alone).

| Input | Default | Hover | Focus | Error | Disabled | Notes |
|---|---|---|---|---|---|---|
| **Text / Textarea** | `--mds-neutral-300` border, `--mds-neutral-0` fill | `--mds-neutral-400` border | Focus ring (§4.2) + `--mds-primary-600` border | `--mds-danger-600` border + error icon suffix | `--mds-neutral-100` fill, `--mds-neutral-400` text, border removed | Textarea has a resize handle only on the vertical axis; min-height = 3 lines |
| **Select / Dropdown** | Same as text input, trailing chevron icon | Same | Same, plus open-state elevation `--mds-shadow-3` on the option panel | Same as text | Same as text | Combobox variants (searchable, cascading) follow the ARIA pattern in §4.5 |
| **Checkbox** | `--mds-radius-sm` box, `--mds-neutral-300` border | Border darkens to `--mds-neutral-400` | Focus ring around the box | Border + adjacent error text | Reduced-opacity box, no interaction | Checked state fills `--mds-primary-600` with a white check glyph — never relies on fill color alone (the glyph itself is the non-color signifier) |
| **Radio** | Circular, same border logic as checkbox | Same | Same | Same | Same | Selected state: `--mds-primary-600` filled inner dot |
| **Toggle / switch** | `--mds-radius-full` track, `--mds-neutral-300` off-state track | Track darkens slightly | Focus ring around the whole track | Not typically used standalone in an error state (paired with a text explanation instead) | Reduced opacity, non-interactive | On-state track is `--mds-primary-600`; **on/off is additionally labeled via visible text** ("On"/"Off" or a task-specific label pair), never color-only |
| **Date picker** | Text-input trigger + calendar icon | Same as text input | Same, plus the calendar panel opens at `--mds-shadow-4` | Same as text input | Same as text input | Calendar panel is full keyboard-navigable (arrow keys move by day, `PageUp`/`PageDown` by month) per §4.3 |
| **File upload** | Dashed-border drop zone, `--mds-neutral-300` border, upload icon + "drop files or browse" text | Border darkens on drag-over to `--mds-primary-400`, background tints `--mds-primary-50` | Focus ring around the zone (keyboard-triggerable "browse" is a real button, not a bare `<input type=file>` styled invisible) | Border becomes `--mds-danger-600` + inline error text (e.g., file too large, wrong type) | Reduced-opacity zone, non-interactive | Per-file rows show a thumbnail/icon, filename, size, a determinate progress bar (§3.9) during upload, and a remove action |
| **Signature capture** *(Phase 1 field type placeholder)* | Bordered canvas region, `--mds-neutral-300` border, faint baseline guide + "Sign here" placeholder text (`--mds-color-text-secondary`) | N/A (touch/pointer surface, not a hover-driven control) | Focus ring around the canvas boundary when reached via keyboard (a "Clear signature" button remains keyboard-operable even though the drawing surface itself is pointer/touch-primary) | Border becomes `--mds-danger-600` + error text ("Signature required") | Reduced-opacity canvas, non-interactive | A visible "Clear" text button always accompanies the canvas; the component is a placeholder for full interaction/rendering spec ownership by the eventual Signature field-type implementation, but the **container, states, and label/help/error attachment** specified here are final and binding now |

**Governing layout rule**: every form input's label, control, help text, and error message stack **vertically, left-aligned to the same edge**, at `--mds-space-2` internal gaps, inside a `FormField` wrapper that itself sits in the page's field-stack at `--mds-space-4` between fields and `--mds-space-6` between sections — no page hand-rolls its own label/input spacing.

> **Implementation status (G11): `MdsSwitch` is specified above but NOT built.** The Toggle/switch row remains a forward specification. The one boolean preference shipped so far — the §2.9 dyslexia-font opt-in in Settings → Appearance — deliberately uses **`MdsCheckbox`** instead. Building a switch for it would have meant a new component, story, axe surface and doc section, and this row's own rule ("on/off is additionally labeled via visible text") means the result would have been a switch *plus* a text label — i.e. a checkbox with extra steps. `MdsCheckbox` already carries a 44×44 target, the correct focus ring, and a **check glyph** rather than fill colour as its non-color signifier. Build `MdsSwitch` when a genuine toggle-shaped need appears, not to satisfy this table.

### 3.3 Lists & Tables

The data table is the single most-used composite component (submissions inbox, forms list, webhook delivery log, audit log). One component covers all of these, configured per use, not re-implemented per page.

**States**:
- **Default** — populated rows, sortable column headers (click/keyboard-activatable, with a visible sort-direction glyph — never sort-order-by-color-alone), a filter bar above the table (chips for active filters, a clear-all action), and pagination below (page-number controls + a "rows per page" selector, cursor-based under the hood per the technical architecture doc's API pagination approach, but presented to the user as simple page-forward/back controls unless the dataset is large enough to warrant "load more").
- **Loading** — a **skeleton state**, not a spinner overlay: the table renders its actual column structure with shimmering placeholder blocks matching each cell's approximate content width, so the layout does not jump once real data arrives. Used both on first load and on filter/sort re-fetch (the latter shows the skeleton only in the row region, keeping the header/filter bar stable).
- **Empty (no data at all)** — the shared Empty State component (§3.10), varying only its illustration/copy/CTA per context (e.g., "No forms yet — create your first form" vs. "No submissions yet — share your form to start collecting").
- **Empty (filtered to zero results)** — a distinct, lighter-weight variant of the empty state: no illustration, just a short message ("No submissions match these filters") plus a "Clear filters" tertiary button — deliberately not the same heavyweight first-run empty state, since this is a filter-tuning moment, not a first-use moment.
- **Row-level states**: hover (`--mds-neutral-50` background), selected (checkbox-driven row selection tinted `--mds-primary-50`), and an inline error/warning row indicator (e.g., a submission flagged for review) using a left-edge color bar plus a status pill (§3.8) — never row-background color alone as the only signifier.

**Governing layout rule**: column headers, cell padding, row height, and the filter-bar/pagination placement are **fixed by the table component itself** — a page configures *which* columns/filters/actions appear, never *how* the table lays them out. A "denser" or "wider" one-off table for a specific page is exactly the kind of change that requires a documented exception (§1.3) or, more likely, a new density variant added to the shared component (§7.2).

**Horizontal overflow containment (invariant, added G11).** A table wider than its column is contained by the component's own `overflow-x: auto` wrapper — the page never scrolls sideways because of a table. That wrapper **must** carry `position: relative`, and this is load-bearing rather than stylistic: the component places `position: absolute` visually-hidden content inside the table (the `.mds-table__sr` "Actions" label, and the whole `thead` at the mobile breakpoint). Without a positioned wrapper, those boxes resolve their containing block *outside* the scroll container, which therefore cannot clip them — and an absolutely positioned element is clipped by its containing block, **not** by whichever ancestor happens to scroll, so no `overflow` rule further up can compensate. G11 hit exactly this: at the tablet breakpoint the extra_large type scale plus the dyslexia-friendly face pushed a 1px hidden span past the last column and the *document* gained 50px of real horizontal scroll, while every visible element remained correctly contained. Any future component that combines a scroll wrapper with absolutely positioned descendants needs the same pairing.

### 3.4 Navigation

**Top nav** (part of the Authenticated App Shell, §3.0): tenant/account switcher (left), global search (center, expandable on mobile), notification bell + avatar/account menu (right). Fixed height (`--mds-space-16` = 64px), `--mds-shadow-1` separating it from content on scroll.

**Sidebar nav**: primary section links (Forms, Submissions, Dashboard, Settings), each with an icon + label. States: default, hover (`--mds-neutral-100` background), active/current-section (`--mds-primary-50` background + `--mds-primary-700` text + a left-edge `--mds-primary-600` accent bar — again, never color alone: the accent bar and bold weight are the non-color signifiers), and a collapsed (icon-only, tooltip-on-hover/focus) state used at the tablet breakpoint (§6).

**Breadcrumbs**: used on any screen nested more than one level below a primary sidebar section (e.g., Forms → *[Form Name]* → Submissions → *[Submission #1234]*). Each crumb is a real link except the current (final) one, which is plain text with `aria-current="page"`.

**Tabs** *(specified here, **not yet built** — recorded 2026-08-03: `packages/design-system/src/components/` contains no Tabs component, so the increment that first needs one either builds it to this spec or uses the existing `SegmentedControl`; it cannot import one)*: used to switch between views of the *same* resource without a full navigation (e.g., a form's Builder / Preview / Settings / Analytics tabs). Underline-style indicator (`--mds-primary-600` 2px underline on the active tab), full keyboard support (arrow-key roving tabindex per the ARIA Tabs pattern, §4.5).

**Governing layout rule**: exactly one navigation paradigm operates at each nesting depth — sidebar for primary sections, tabs for views-of-one-resource, breadcrumbs for path context — and they are never substituted for each other (e.g., a page never uses tabs where breadcrumbs are called for, which is a common ad hoc drift point this rule exists to prevent).

### 3.5 Cards

The default content container for the dashboard, forms list (grid view), and settings panels. Default state: `--mds-color-bg-surface` fill, `--mds-radius-md` corners, `--mds-shadow-1` (or `--mds-shadow-0` flat when inside an already-elevated context like a modal), `--mds-space-5` internal padding, `--mds-color-border-default` 1px border (used **together with** the shadow, not instead of it, so cards remain legible in contexts/zoom levels where shadows render faintly). Interactive cards (e.g., a clickable form-summary card) add a hover state (`--mds-shadow-2`) and a focus-visible ring when reached via keyboard, and must be a real `<button>`/`<a>`, never a `<div>` with a click handler (§4.5).

**Governing layout rule**: a card's internal content always follows the same internal structure — optional media/icon, heading (`--mds-type-heading-3` or `heading-4`), optional metadata row, body content, optional action row — so a user scanning a grid of cards (forms list, template gallery) can predict where to look for the same kind of information across every card, regardless of which feature built that particular grid.

### 3.6 Modals / Dialogs

Used for focused, blocking tasks (confirm-destructive-action, quick-create flows) — never for primary navigation or as a substitute for a full page. States: entering (slide/fade in over `--mds-duration-slow`, `--mds-ease-decelerate`), open (`--mds-shadow-4`, `--mds-color-overlay-scrim` backdrop), exiting (reverse transition), and a **destructive-confirmation variant** that always requires the destructive action itself to be styled as the `destructive` button variant (§3.1) — never styled as `primary`, so "the button that does the dangerous thing" is never visually indistinguishable from "the button that does the normal thing" across the whole product.

**Governing layout rule**: every modal has exactly one primary action (bottom-right, per the one-primary-button rule in §3.1), one cancel/secondary action (bottom-left or immediately left of primary), a close affordance (top-right icon button, always also closable via `Escape`), and a focus trap (§4.5) — no modal ships without all four, and no page builds a "modal-like" floating panel that skips the focus-trap requirement by not technically being the shared Modal component.

### 3.7 Toasts / Notifications

Ephemeral, non-blocking feedback (e.g., "Form published," "Submission deleted," a webhook-delivery-failure alert). Rendered in a dedicated top-level portal (outside any modal's DOM subtree, so a toast is never visually trapped beneath an open modal's overlay — see the elevation ordering rule in §2.5), stacked in a fixed corner (top-right on desktop, full-width top-anchored on mobile per §6).

States: entering (`--mds-duration-base`, slide+fade), visible (auto-dismiss after a type-dependent duration — success/info: 5s; warning: 8s; error: **no auto-dismiss**, requires explicit manual dismissal, since an error a user didn't get to read defeats its own purpose), hover (pauses the auto-dismiss timer), and exiting. Each toast carries a semantic icon + color bar matching its type (success/warning/danger/info, using the corresponding token scale) plus text — never an icon-less color swatch alone.

**Governing layout rule**: toasts communicate the *outcome* of an action the user just took (or an async event relevant right now); they never carry a primary call-to-action requiring navigation away from the current context (that's a banner or the dashboard's own notification center) — this keeps toasts genuinely ephemeral and prevents them from becoming a dumping ground for anything that "needs to tell the user something."

### 3.8 Badges / Status Pills

Small, `--mds-radius-full`, single-line labels communicating a discrete state — submission status (`draft`/`submitted`/`under_review`/`approved`/`returned`/`archived`, matching the `SubmissionStatus` enum in the Data Dictionary), form status (`draft`/`published`/`archived`), webhook delivery status, subscription-tier badges, etc. Each status maps to exactly one semantic color pairing (background tint + matching-hue text, e.g., `approved` → `--mds-success-50` background / `--mds-success-700` text; `returned` → `--mds-warning-50` / `--mds-warning-700`\*(text darkened beyond the default warning text token specifically for the small-pill-text-size case — see the contrast note in §4.1); `archived` → `--mds-neutral-100` / `--mds-neutral-600`), and — consistent with the "never color alone" rule threaded through this document — every pill's **text label is the status name itself**, never a bare colored dot.

**Governing layout rule**: the mapping from enum value → badge color/label is defined **once**, centrally in the component library (a single `statusVariant` lookup consumed by every screen that renders that enum), not re-decided per screen — so `approved` is the same green everywhere it appears, from the submissions inbox to the dashboard to an audit-log entry.

### 3.9 Progress Indicators

Three distinct patterns, used for three distinct situations — they are not interchangeable:

- **Spinner** (indeterminate) — for short, unmeasurable waits (button loading state, initial page-section load). A simple rotating ring using `--mds-primary-600`, sized to match the text/control it's replacing.
- **Determinate progress bar** — for measurable, bounded operations with a meaningful *fill level* (file upload percentage, async export job progress). Track: `--mds-neutral-200`; fill: `--mds-primary-600`; always paired with a numeric label ("62%") — a bar alone is not sufficient (screen-reader and low-vision users need the numeric equivalent, not just the visual fill level). Two variants of this same component:
  - **Percentage variant** (default) — a filled track plus a percentage label, for operations where the fraction-complete itself is the meaningful signal (uploads, exports).
  - **Step-count variant** — text-only, no filled track: "Step X of N" (optionally with the current step's title, e.g. "Step 3 of 5: Household Members"), used specifically for multi-step form navigation (Form-Filling UX Flow Spec §3.2). A labeled step count is more legible than a bar when N is small (the common case — most forms have well under 10 sections), and it gives screen-reader users a concrete, announceable position rather than an abstract percentage. Each already-completed step in this variant is rendered as a tappable/clickable target to navigate back.
- **Skeleton** — for structural/layout-preserving loading (table rows, card grids, dashboard KPI tiles) as described in §3.3 — deliberately distinct from a spinner because it prevents layout shift and communicates *approximately what's coming*, not just *that something is happening*.

**Governing layout rule**: a spinner is never used for an operation that has a knowable duration/step-count (that's always one of the two determinate-bar variants above) — this rule exists specifically because indeterminate spinners on measurable operations (e.g., a multi-file batch upload) are a common, confusing anti-pattern this system rules out by construction. Choosing between the percentage and step-count variants is not a per-page style choice — it follows directly from whether the operation has a meaningful continuous fill level (percentage) or a small number of discrete named steps (step-count); a page never invents a third presentation for either case.

### 3.10 Empty States

The shared pattern for "this list/page has nothing in it yet," used across forms list, submissions inbox, webhook delivery log, template gallery, and the dashboard's own KPI tiles when a tenant is brand-new. Structure: a simple line-art illustration (token-consistent stroke color, `--mds-neutral-300`), a `--mds-type-heading-3` headline, one line of `--mds-type-body-md`/`--mds-color-text-secondary` explanatory copy, and **exactly one primary-button call-to-action** driving the single next best action (e.g., "Create your first form").

**Governing layout rule (extended 2026-08-03)**: an empty state never presents more than one primary CTA and never presents zero CTAs when a next action genuinely exists — a list that is empty because of an upstream permission restriction (rather than "nothing created yet") uses a distinct copy variant explaining *why*, rather than the generic "get started" copy, so the empty state always tells the truth about the situation it's describing.

### 3.11 Data Visualization *(specified 2026-08-03 by ADR-0011 §D10–§D12; **built by H24b1**)*

Charts are **design-system components, not a charting library.** The decision and its five arguments live in ADR-0011; the two that bind this document are that a component with a Storybook story is under the merge-blocking axe job by construction, and that §1.3's "one shared design system, no exceptions" makes a library a documented exception rather than a default — and charting is a recurring need (tenant dashboard, per-form analytics, the backlog's geo heatmap, OCR confidence display) that would otherwise accumulate three of them.

**The set**: `TimeSeriesChart` (line/area, one to five series) · `BarChart` (horizontal, categorical) · `ChartLegend` (shared; carries the non-colour channel key) · `StatTile` extended with a period-over-period **delta** and an explicit **`unavailable`** state, so a metric that cannot honestly be computed for the selected period says so rather than rendering a zero.

**Build rules**: SVG only — no canvas, no `:style` bindings, no runtime colour computation (geometry is presentation attributes, colour is `fill`/`stroke` referencing custom properties). This keeps the primitives from being the reason a future `default-src` policy has to permit inline styles. Scales are in-repo arithmetic, not a dependency.

**Deliberately not built**, recorded so a later increment does not assume otherwise: pie/donut, stacked bars, scatter, heatmap, sankey, zoom/pan/brush, and automatic axis-label collision avoidance.

Contrast, colour-channel and text-alternative obligations are in §4.1.

> **As-built (H24b1).** All four ship, exported as `MdsTimeSeriesChart` · `MdsBarChart` · `MdsChartLegend` · the extended `MdsStatTile`, over ~100 lines of shared arithmetic in `src/charts/scale.ts`. Six shapes are worth stating here because they are decisions the spec above left open:
>
> - **The value domain always includes zero, and callers cannot switch it off.** A truncated axis turns a 400→410 wobble into a cliff; that is a claim the chart would be making on its own initiative.
> - **A degenerate domain is a first-class case, not an edge case.** A quiet 30-day window is all zeros, so `max − min` is 0 on the *ordinary* path — and every consumer divides by it. `niceTicks` widens a flat domain rather than returning one tick, because the alternative renders `d="M0 NaN …"`, which draws nothing, throws nothing and fails no gate.
> - **Series colour is applied by a static CSS class per index, never by interpolating a token name.** `var(--mds-chart-series-${n})` in a template makes `token-references.test.ts` hunt for a token literally named `--mds-chart-series-`, and a computed token name is the first step toward the runtime colour resolution §D10 rules out.
> - **The time-series canvas is stretched** (`preserveAspectRatio="none"`) with every stroked mark carrying `vector-effect="non-scaling-stroke"`, so the plot fills any width at a fixed height without scaling strokes into wedges. That is also why **no text lives inside the SVG**: it would stretch with the box. Axis labels are HTML, aligned to the plot by a CSS grid, and the y-axis column bleeds half a line-height at each end so each tick label's *centre* sits on its gridline rather than its box edge.
> - **The bar chart's category label is HTML beside a one-`<rect>` SVG**, not an SVG `<text>`: SVG text has no wrapping and no ellipsis, so a long form title at 375px would run off the plot — the non-wrapping-row failure `responsive-axe` has already caught three times.
> - **Past five series the extras are tabulated but not plotted**, which is §D11's own wording ("nothing is hidden, only un-plotted"). Folding them into an *Other* bucket is the caller's decision, not a silent truncation inside the primitive.

> **As-built (H24b2) — the first page assembled from these primitives.** `/analytics` is the Business-gated
> cross-form surface (ADR-0011), and five shapes it settled are page-level rather than primitive-level, so
> they belong here rather than in the block above.
>
> - **Four derivations are SHARED with `/dashboard`, not re-implemented** — `resources/js/components/analytics/`
>   holds the bucket formatter, the breakdown-bar builder and the two draft tiles. Both pages render the same
>   tile pair from the same prop shape, and the "0% of 6 saved drafts" beside "No drafts were explicitly
>   saved" contradiction H24b1 found by looking was a *single shared sentence*: a second hand-rolled copy on
>   the second page would have reintroduced it silently. The modules carry the tests too.
> - **A bucket label is formatted at UTC, never at the query's timezone — and that is not a shortcut.** The
>   server cuts buckets with `date_trunc(…, <zone>)`, so a `YYYY-MM-DD` bucket is *already* in the query's
>   zone; applying a zone again shifts it. H24b1 passed the query zone and got away with it only because the
>   dashboard's zone is hard-coded to UTC. Generalising to a user-chosen zone exposed the bug, and
>   `bucket-label.test.ts` catches it by putting the *runner* in a negative-offset zone — under a UTC runner
>   a formatter that dropped the option would still look correct. The zone is **named** in the range banner
>   instead, so two readers in different places can agree they are looking at the same thirty days.
> - **The chart's own data table is not always enough.** `MdsBarChart` tabulates what it is *given*, and the
>   plot legitimately drops a zero *Unassigned* bucket — so §D11's "nothing is hidden" is only true if the
>   page renders a fuller `MdsDataTable` beside it. The page gives the table more rows than the plot.
> - **Multi-valued filters are `MdsCheckbox` groups inside a page-owned `<fieldset>`/`<legend>`, plus a chip
>   picker for forms.** `MdsSelect` is a native single `<select>`; adding a combobox primitive would put the
>   hardest ARIA pattern there is under the merge-blocking axe job. Native grouping gives the semantics for
>   free — and `getByRole('group', { name })` matches an accessible name by *substring*, which is how the
>   e2e spec discovered that "Available" also matches "Not available for reporting".
> - **A pending aggregate says so.** With a question selected and its summary still in flight, the card
>   read "Pick a question" — the radio and the card disagreeing about whether a question had been picked.
>   Found by looking at the running page, exactly as H24b1's draft tiles were, and pinned by two Vitest
>   cases that each redden under a different one-line mutation.

---

## 4. Accessibility Specification (WCAG AA baseline)

WCAG 2.2 AA is the baseline for the entire product, not only the guest-facing runtime — the PRD states the public runtime must meet it "from the first release" (§2.4, §5 Feature #3) and Feature #5's acceptance criteria extend touch-target and responsive requirements to *every* screen, including the authenticated app. This section is the concrete specification components must satisfy to make both true.

### 4.1 Color contrast

- **Text**: minimum **4.5:1** contrast between text and its background for normal-size text; **3:1** for large text (≥24px regular weight, or ≥19px bold) — matching the plan's stated WCAG AA target exactly.
- **UI component boundaries**: minimum **3:1** contrast for the visual boundaries of interactive components and meaningful graphical objects (input borders, focus rings, icon-only button boundaries, chart/data-viz element boundaries) against their adjacent background — this is the WCAG 1.4.11 "Non-text Contrast" criterion, distinct from (and easier to satisfy than) the 4.5:1 text criterion, and is called out separately here because it is easy to satisfy text contrast while forgetting that a very light input border also needs to clear its own, lower bar.
- **Data-visualization specifics** (added 2026-08-03; contract pinned by ADR-0011 §D11–§D12, **emitted by H24b1** — the measured table is at the end of this section). The 1.4.11 rule above applies to **data marks** — a line, a bar, a legend swatch — and to any boundary whose *identification* carries meaning. It does **not** apply to gridlines, which are decorative scaffolding and should deliberately sit *below* 3:1 so they do not compete with the data; axis tick labels are text and take the 4.5:1 rule. Where two fills touch (any stacked or adjacent mark), 3:1 is required **between the neighbours**, not merely against the background — the standard mitigation is a 1px surface-coloured separator stroke so each segment is judged against the surface, and the difficulty of getting this right is why stacked bars are deliberately not in the first primitive set. Three further constraints: **the categorical series scale is capped at five**, because satisfying 3:1 against *both* the light and dark surfaces with one token set confines a mark to a narrow luminance band that leaves too little room for marks to remain distinguishable from one another — which also makes the dark re-point of the series tokens mandatory, not optional; **series tokens are immune to `data-accent`** and alias none of the primary/success/warning/danger/accent scales, for the same reason Moss and Brass are excluded from the accent whitelist (a data series encodes meaning, and two people looking at one screenshot must see the same series in the same colour); and **colour is never the only channel** — a single-series chart uses no categorical colour at all, and multi-series charts add a dash pattern or a shared `--mds-chart-pattern-*` fill. Finally, a limit of the tooling worth stating here rather than discovering: **axe cannot detect a missing text alternative for an SVG that carries a plausible `aria-label`**, so the merge-blocking gate will pass a chart that is an unreadable picture. Every chart primitive therefore ships a real data table alongside `role="img"`, guarded by a unit assertion rather than by axe.
- **Verified reference ratios** (computed via the WCAG relative-luminance formula, cited here as concrete evidence rather than an unverified assertion — the full token-pair contrast matrix lives as generated metadata in the Storybook a11y addon per §1.2, not reproduced cell-by-cell here):

| Pairing | Ratio | Verdict |
|---|---|---|
| `--mds-primary-600` (`#1C4B72`) fill / white text (default button) | 9.14:1 | Passes, wide margin — a notably wider margin than the retired Indigo-based draft's 6.19:1, since Blueprint is a deeper hue |
| `--mds-primary-700` (`#123350`) fill / white text (hover/pressed) | 13.0:1 | Passes, very wide margin |
| `--mds-neutral-800`/"Ink" (`#16212B`) text / white-surface background (default body text) | 16.3:1 | Passes, very high margin |
| `--mds-neutral-600`/"Ink Soft" (`#4A5A66`) text / white-surface background (secondary text) | 7.13:1 | Passes |
| `--mds-neutral-400`/"Ink Faint" (`#7C8B93`) text / white-surface background (disabled text) | ~3.52:1 | Below AA's 4.5:1 — **intentional**, disabled content is exempt from WCAG contrast requirements; this token is never used for enabled/interactive text |
| `--mds-warning-700` (`#7A4F15`) text / white-surface background (warning text/icon) | 7.11:1 | Passes, comfortable margin |
| `--mds-success-600` (`#2F6249`) text / white-surface background (success text/icon) | ~7.4:1 (darker step than the 500 fill below, chosen specifically for small-text safety margin) | Passes |
| `--mds-danger-600` (`#A83A2A`) fill / white text (destructive button) | 6.36:1 | Passes, wider margin than the retired draft's 4.56:1 |

**Teal accent — full verification (Increment G11).** The §2.9 personalization accent is the one hue a *user* can switch the entire primary-action surface to, so every pairing it produces is verified here rather than left to the Storybook matrix. All 17 computed with the WCAG relative-luminance formula against the real surface tokens in each theme.

| # | Pairing | Ratio | Min | |
|---|---|---|---|---|
| **Light** | | | | |
| 1 | white text on `teal-600` fill (button, selected chip, checked control) | 7.48:1 | 4.5 | ✅ |
| 2 | white text on `teal-700` fill (hover) | 9.40:1 | 4.5 | ✅ |
| 3 | white text on `teal-800` fill (active) | 12.10:1 | 4.5 | ✅ |
| 4 | `teal-600` text on `bg-surface` (`#FFFFFF`) | 7.48:1 | 4.5 | ✅ |
| 5 | `teal-600` text on `bg-canvas` (`#F3F4F1`) | 6.77:1 | 4.5 | ✅ |
| 6 | `teal-600` UI boundary vs canvas | 6.77:1 | 3.0 | ✅ |
| 7 | `teal-700` focus ring vs surface | 9.40:1 | 3.0 | ✅ |
| 8 | `teal-700` focus ring vs canvas | 8.51:1 | 3.0 | ✅ |
| 9 | `neutral-900` ink on `teal-50` tint | 15.90:1 | 4.5 | ✅ |
| **Dark** | | | | |
| 10 | white text on `teal-500` fill | 6.68:1 | 4.5 | ✅ |
| 11 | white text on `teal-400` fill (hover) | 4.82:1 | 4.5 | ✅ |
| 12 | white text on `teal-700` fill (active) | 9.40:1 | 4.5 | ✅ |
| 13 | `teal-300` text on `bg-surface` (`#123350`) | 5.16:1 | 4.5 | ✅ |
| 14 | `teal-300` text on `bg-canvas` (`#0C2337`) | 6.36:1 | 4.5 | ✅ |
| 15 | `teal-300` focus ring vs dark surface | 5.16:1 | 3.0 | ✅ |
| 16 | `teal-300` focus ring vs dark canvas | 6.36:1 | 3.0 | ✅ |
| 17 | pale ink on the dark tint `rgba(36,126,126,.18)` composited over `#123350` | 9.54:1 | 4.5 | ✅ |

Teal is never worse than Blueprint at the equivalent role, and in dark mode it is measurably better (dark fill 6.68 vs 6.14; dark hover 4.82 vs 3.96).

> **This table is now an executable specification, not only a record (Increment H23a1, [ADR-0014](../adr/0014-tenant-brand-ramp-generation.md)).** Its seventeen rows are encoded, hue-removed, as `BrandRampGenerator::pairings()` and `BRAND_RAMP_PAIRINGS`, and every generated **tenant** brand ramp is measured against all seventeen before it is stored. Two details of the encoding are worth knowing before editing this table:
>
> - **Light row 6 duplicates row 5's colour pair at a lower floor** (3.0 rather than 4.5, because a UI boundary is not text). It is kept in both the table and the code rather than folded into row 5 — dropping it would make the list sixteen and quietly break the correspondence, which a unit test asserts by count and by per-theme split (9 light, 8 dark).
> - **The engine's targets are the MIDPOINT of this table's Teal column and Blueprint's equivalent**, so a tenant ramp lands in the same visual register as the product's own rather than scraping the 4.5 floor. The evidence that this reproduces the system's own judgment: fed Teal's hue, the engine re-derives `teal-600` and `teal-50` byte-identically and returns rows 1–3 as 7.48 / 9.40 / 12.10.
>
> **Adding a row here means adding a pairing there**, and the count assertion is what will tell you.

**Chart scale — full verification (Increment H24b1).** ADR-0011 §D11 states the constraints and deliberately prints no ratios, assigning the measured table here; these are the numbers, computed with the same WCAG relative-luminance formula against the real surface token in each theme. The surface is `--mds-color-bg-surface`: `#FFFFFF` in light, `#123350` in dark (the dark block re-points it to `neutral-100`). A data mark is a *meaningful graphical object* and takes **3:1** (WCAG 1.4.11); axis tick labels are **text** and take 4.5:1; gridlines are decorative scaffolding, **exempt**, and deliberately sit below 3:1.

| # | Token | Light hex | vs `#FFFFFF` | Dark hex | vs `#123350` | Min | |
|---|---|---|---|---|---|---|---|
| 1 | `--mds-chart-series-1` (blue) | `#0072B2` | 5.19:1 | `#7CC9F1` | 7.11:1 | 3.0 | ✅ |
| 2 | `--mds-chart-series-2` (vermilion) | `#E15A14` | 3.70:1 | `#E4813F` | 4.65:1 | 3.0 | ✅ |
| 3 | `--mds-chart-series-3` (green) | `#00654A` | 7.09:1 | `#37B98C` | 5.25:1 | 3.0 | ✅ |
| 4 | `--mds-chart-series-4` (purple) | `#7A3468` | 8.34:1 | `#C077AD` | 4.01:1 | 3.0 | ✅ |
| 5 | `--mds-chart-series-5` (ochre) | `#96700A` | 4.55:1 | `#F0CB6D` | 8.34:1 | 3.0 | ✅ |
| 6 | `--mds-chart-other` (`{neutral.500}`) | `#5E6E77` | 5.29:1 | `#8CA5B5` | 5.06:1 | 3.0 | ✅ |
| 7 | `--mds-chart-axis` (`{neutral.600}`, tick labels) | `#4A5A66` | 7.13:1 | `#A9C1D1` | 6.96:1 | 4.5 | ✅ |
| 8 | `--mds-chart-grid` (`{neutral.200}`) | `#DDE1DA` | 1.32:1 | `#1D3F57` | 1.18:1 | — | ✅ *intentionally low* |

> **Why the dark re-point is mandatory rather than a nicety — measured, not asserted.** Take the light column above and put it, unchanged, on the dark ground: **four of the five fail 1.4.11 outright** — series-1 2.51:1, series-3 1.83:1, series-4 **1.56:1**, series-5 2.86:1; only series-2 survives, at 3.51:1. The reverse is the same story from the other end: the raw Okabe-Ito anchors these hues derive from do not survive a white ground either (`#E69F00` computes **2.25:1**, a real failure, and `#009E73` clears the bar only at 3.42:1 with no margin left), which is why the light column is darkened from them in the first place. A single token set would have to satisfy both columns at once, and the band where that is possible is too narrow to hold five mutually distinguishable marks — which is exactly §D11's claim that **five is derived, not chosen**. Two sets is not duplication; it is the only way one palette clears both grounds.

> **Hue is the primary channel, luminance the secondary, and the dash pattern is the guarantee.** The five hues are Okabe-Ito-derived and therefore separable under the common colour-vision deficiencies. Their luminances are additionally spread across the usable band (light: 3.70 · 4.55 · 5.19 · 7.09 · 8.34) so a greyscale print or an achromatopsic reader still has *something* — but the spread cannot be made large, which is the same fact that caps the scale at five. That is why §D12 requires a redundant non-colour channel outright: `--mds-chart-pattern-1…5` is applied per series index, series 1 is solid, and the legend swatch draws its own pattern rather than a plain colour chip. Colour is never load-bearing alone.

> **Two properties enforced by test, not by this table.** `theme-overrides.test.ts` recomputes every row above from `tokens/chart.json` and the CSS on each run, so a future edit that darkens a series past its floor fails the suite rather than the eye. The same file asserts that **no `[data-accent]` rule touches a `--mds-chart-*` token** — the pre-existing accent-scope guard tested only the five `--mds-color-*` families and would have let a recoloured data series through. A data series encodes meaning: two colleagues reading one screenshot must see the same series in the same colour, which is the Moss-and-Brass argument one layer further out.

> **The bug this table exists to prevent.** Until G11 the accent shipped as a four-declaration stub with no dark variant. Because `[data-accent]` carries the same specificity as `[data-theme-mode='dark']` and appeared *later* in source order, teal won in dark mode and painted the light `#1B5E5E` on the `#123350` ground: **1.74:1** for both `action-primary-fg` and the focus ring — failing 1.4.3 and 1.4.11 by a wide margin. It went unnoticed for two increments because nothing in the product could set `data-accent` and no test had ever set it either. The Playwright `personalization-axe` spec and the `TealDark` Storybook story now both exercise it.

> **Two observed, deliberately out-of-scope findings.** (a) Blueprint's *dark* `action-primary-bg-active` (`primary-300`, `#7DA9C4`) yields only **2.52:1** with white text. It is pre-existing, unrelated to G11, and invisible to axe because `assertClean` parks the pointer and axe never measures `:active`. Recorded rather than fixed, to keep G11's diff to its own subject. (b) For teal specifically, "lighter on press" is mathematically unreachable — the lightest teal step still clearing 4.5:1 against white *is* the hover step — so dark-mode teal inverts direction and darkens on press (`teal-700`, 9.40:1). That divergence from Blueprint's ramp is exceptions-log #6.

> **Note (correction):** the rows above are labeled "white-surface background" because that is what they are actually computed against — `--mds-color-bg-surface` (`--mds-neutral-0`, pure white), the background body text most commonly sits on (cards, panels, table rows, per the §2.2 semantic alias table). An earlier version of this table mislabeled them "paper background," which is a *different*, distinctly-valued token (`--mds-color-bg-canvas` / `--mds-neutral-50` / `#F3F4F1`) used for the page canvas behind those surfaces. For the record, text directly on the Paper canvas (rather than a white surface) is very slightly lower-contrast but still comfortably passes — e.g. Ink on Paper computes to ≈14.8:1, not 16.3:1 — so no pairing in this document is actually at risk; only the label was wrong.

- **Warning/amber is a deliberate exception to "text-on-fill is always white."** `--mds-warning-500` fill pairs with `--mds-neutral-900`/Ink text (not white), because no amber saturation dark enough to hit 4.5:1 with white text remains recognizably "amber" rather than reading as brown/orange — pairing light amber with dark text is the correct, common resolution and is applied consistently at every warning-fill use site (buttons, badges, banners). The same logic produced a slightly darker dedicated `warning-700` step specifically for small warning *text* (as opposed to a warning *fill*), since the 500 fill color itself falls short of 4.5:1 against a white/light surface.
- **Success at the 500 step is a comfortable but not maximal margin** (~5.09:1 white-on-fill) — acceptable and passing, but callers needing small-text success messaging should prefer the darker `success-600` text token above rather than setting text color to `success-500` directly.
- **Any new semantic-token pairing proposed for text-on-background or icon-on-background use must be contrast-checked before merge** (the Storybook a11y addon, backed by `axe-core`, flags failures automatically — §4.6) — this is a mechanical gate, not a design-review-only expectation.

### 4.2 Focus-visible ring specification

One consistent focus treatment, applied via `:focus-visible` (not bare `:focus`, so mouse clicks don't show a ring meant for keyboard users) to every interactive element — buttons, inputs, links, tabs, custom combobox triggers, table sort headers, card-as-button components:

- **Ring**: 2px solid `--mds-primary-700` (chosen over the lighter `--mds-primary-600` specifically so the ring itself clears the 3:1 UI-boundary contrast minimum against both white and tinted-primary backgrounds), offset 2px from the element's own boundary (`outline-offset: 2px`), with a soft 4px `--mds-primary-600` @ 30% opacity outer glow for additional legibility on busy backgrounds (e.g., a focused table-row action button against a `--mds-neutral-50` hover-tinted row).
- The ring is **never removed** (`outline: none` with no replacement is a banned pattern, enforced by an ESLint/Stylelint rule against bare `outline: none` in component source) — a component that needs a custom focus treatment must replace it with an equally-visible alternative meeting the same 3:1 minimum, not remove it silently.
- On components with their own internal color states (e.g., a `destructive` button already showing a red fill), the ring color remains the standard primary ring rather than switching to a context-matched color — consistency of "what focus looks like" is prioritized over color-harmony per component, so a keyboard user never has to relearn what focus looks like based on which button they're on.

### 4.3 Keyboard navigation & tab order

- **Every interactive element is reachable and operable via keyboard alone** — no functionality (including drag-and-drop field reordering in the builder, and signature-canvas clearing) has a keyboard-inaccessible-only path; drag-and-drop interactions (form-field reordering, section reordering) ship a keyboard-operable equivalent (e.g., a visible "Move up / Move down" action pair, or arrow-key reordering while a field is in a focused "grabbed" state) alongside the pointer-based drag gesture.
- **Tab order follows visual/DOM reading order** (left-to-right, top-to-bottom in LTR locales) — `tabindex` values greater than `0` are a banned pattern (they create a second, competing tab order that inevitably drifts from the visual layout as the page evolves); the only permitted `tabindex` values are `0` (focusable in natural order) and `-1` (programmatically focusable only, e.g., a modal's initial focus target).
- **Composite widgets use roving `tabindex`** per the relevant ARIA Authoring Practices pattern (tabs, the sidebar nav's item list, a table's column-sort-header row) — one `tabindex="0"` item at a time, arrow keys move the roving focus, `Tab` itself exits the whole widget to the next landmark, rather than tabbing through every individual tab/item one at a time.
- **Every custom interactive component that isn't a native HTML control** (icon-only buttons built on `<div>` internally, custom select triggers, the signature-capture canvas's clear action) is still built on a real semantic element (`<button>`, `<a href>`) at its root — never a `<div>`/`<span>` with a synthetic click handler and no native keyboard behavior, semantics, or focusability.

### 4.4 Touch-target minimum sizing

- **44×44 CSS px minimum** hit area for every interactive element on any touch-capable viewport (mobile and tablet breakpoints, §2.7) — matching (and exceeding) WCAG 2.2 AA's 2.5.8 Target Size (Minimum) criterion, which sets a 24×24 CSS px floor; this product targets the stricter 44×44 used by platform touch guidance, and applies it, per Feature #5's acceptance criteria, to *every* screen (builder, dashboard, submissions inbox, settings), not only the guest-facing runtime.
- Where a component's **visual** size is intentionally smaller than 44px (e.g., a `sm` 32px-height button, a table row's inline icon action), the **hit area** is expanded via padding/pseudo-element beyond the visual box to reach 44×44px, rather than the visual size itself being inflated — preserving dense-table visual density while still meeting the touch-target rule.
- Adjacent touch targets (e.g., a row of icon actions in a table row) maintain at least `--mds-space-2` (8px) of spacing between their 44px hit areas, so overlapping/adjacent hit areas never cause accidental mis-taps.

### 4.5 ARIA patterns for custom components

Concrete pattern assignments for the components in this system that are not native HTML controls:

- **Combobox / cascading select** (single-select searchable dropdown, and the Phase 2 N-level cascading select field type): implements the **ARIA 1.2 Combobox pattern** — a text `input` with `role="combobox"`, `aria-expanded`, `aria-controls` pointing at the listbox, `aria-activedescendant` tracking the currently-highlighted option (rather than moving real DOM focus into the listbox), and the listbox itself as `role="listbox"` with `role="option"` children carrying `aria-selected`. For **cascading** selects specifically, each level is its own combobox instance; selecting a value in level *N* clears and re-populates level *N+1*'s options, and that repopulation is announced via a polite live region (below) so a screen-reader user knows the next level's choices changed without having to re-discover it by navigating away and back.
- **Modal / dialog focus-trap**: `role="dialog"` (or `role="alertdialog"` for the destructive-confirmation variant, §3.6), `aria-modal="true"`, `aria-labelledby` pointing at the modal's visible title. On open: focus moves to the modal's first focusable element (or a designated initial-focus target — e.g., the cancel button by default for destructive confirmations, so an accidental `Enter` press doesn't confirm a destructive action). While open: `Tab`/`Shift+Tab` cycle only within the modal's focusable elements (a true focus trap, not merely a visual overlay); `Escape` closes it (equivalent to the cancel action). On close: focus returns to the element that triggered the modal, never left stranded at `document.body`.
- **Live-region announcements for async validation**: the shared `FormField` error-message slot (§3.2) is wrapped in `aria-live="polite"` (`aria-atomic="true"`) by default, so an error that appears asynchronously (e.g., a server round-trip confirming a value is already taken, or a semantic-validation `constraint` failure returned by the Expression Evaluator per the technical architecture doc's §4.1/§4.3) is announced to screen-reader users without requiring them to have focus still on that field. A small number of **submission-blocking, page-level** async outcomes (e.g., "This form's guest link has expired") instead use `aria-live="assertive"` in a single, dedicated page-level live region — assertive is used sparingly and only for outcomes that genuinely halt the user's task, per standard ARIA live-region guidance against overusing assertive announcements.
- **Toasts** (§3.7) are rendered inside a single `aria-live="polite"` region (`role="status"` for success/info, `role="alert"`/assertive specifically for the error variant, consistent with error toasts requiring manual dismissal per §3.7) so they are announced without stealing keyboard focus from whatever the user was doing.

### 4.6 Automated axe-core CI scanning

The plan's Best Practices name this directly: *"WCAG AA as a build-time constraint for the public form runtime, checked via automated axe-core scans in CI."* This document extends the same mechanism across the whole product, consistent with Feature #5 applying responsive/touch-target rules to every screen, not only the guest runtime:

- Every Storybook story (§1.2) for every shared component runs an `axe-core` scan as part of the same CI job that builds Storybook — a story representing a component's `error`/`disabled`/`loading` state is scanned in that state, not only its default state, since accessibility regressions often hide specifically in non-default states (e.g., a disabled input that still fails to communicate its disabled-ness to assistive tech).
- Playwright end-to-end tests (already part of the plan's CI stack) run an `axe-core` injection pass (`@axe-core/playwright`) against key composed pages (form builder, submissions inbox, public form-fill flow) in addition to the component-level Storybook scans — catching integration-level violations (e.g., a heading-level skip that only manifests once real page content is composed, not visible from an isolated component story).
- **CI treats any new axe-core violation as a merge-blocking failure**, matching PRD success metric **G6** ("Automated axe-core WCAG AA pass rate in CI — Target: 100% pass, blocking merge on regression"). A pre-existing, currently-accepted violation may be temporarily allow-listed by rule-id and component only with a linked tracking issue — never silently suppressed wholesale for a whole page or component.

---

## 5. Progressive Disclosure Patterns

Product Principle 3.2 names three specific power-user surfaces that must sit behind progressive disclosure: **the expression engine**, **XLSForm import/export**, and **cross-form analytics**. This section specifies the one consistent visual/interaction treatment used for all three (and for any future "Advanced" surface), so power-user features are never in a first-time user's default path but are reachable in exactly one click when needed.

**The pattern, concretely:**

- **Collapsed by default, every time.** Any "Advanced" surface renders in a collapsed state on first paint, with no per-user memory of "always expand this for me" in Phase 1 (a judgment call — see below) — a first-time business-ops user building a lead form (PRD Persona B) never sees the phrase "expression engine" unless they deliberately open it, matching the PRD's own example verbatim.
- **One consistent disclosure control**: a text-style (tertiary-button, §3.1) toggle reading **"Advanced"** (never "Show more," "Options," or other inconsistent phrasing across different Advanced surfaces — one word, one label, everywhere), paired with a consistent chevron icon (`▸` collapsed / `▾` expanded) and, where the surface specifically represents power-user/expert functionality (the expression engine, XLSForm import/export), a small consistent secondary icon (a "flask/sliders" glyph, `--mds-color-text-secondary` colored, never a saturated semantic color, so it never competes visually with real status/error signifiers elsewhere on the page).
- **Secondary visual weight, always.** The collapsed toggle itself is styled at `--mds-type-body-sm` / `--mds-color-text-secondary` — visually quieter than the surrounding primary content — so its presence signals "more capability exists here if you want it" without demanding attention from a user who doesn't need it yet. Once expanded, the *content* inside an Advanced section is styled normally (full-weight, fully accessible) — progressive disclosure only mutes the *entry point*, never the functionality once a user has opted in.
- **Expand/collapse interaction**: a standard disclosure widget (`aria-expanded` on the toggle, `aria-controls` pointing at the revealed region), animated at `--mds-duration-moderate`/`--mds-ease-standard` (§2.8), never abruptly cutting in/out — this is treated as a small but deliberate affordance that the revealed content is an extension of the same context, not a navigation to somewhere else.
- **One click from anywhere it appears.** The Advanced toggle is never nested behind a settings sub-page or a second confirmation — Product Principle 3.2's own phrasing ("a first-time M&E user should be able to reach it in one click when they need it") is a literal interaction-count constraint, not just a tone. In the builder specifically: the simple skip-logic UI and the full expression-engine editor live in the *same* field-configuration panel, one `Advanced` toggle apart, never as a separate route/page.

**Concrete application to the three named surfaces:**

| Surface | Where the toggle lives | What's behind it |
|---|---|---|
| Expression engine | Field/section configuration panel, directly below the simple skip-logic and simple-validation UI (§3.2 pattern) | The full `relevant`/`constraint`/`calculate` expression editor (monospace, `--mds-type-code`, §2.4), with inline syntax help |
| XLSForm import/export | Form-level "Import/Export" menu, secondary/tertiary-styled entry alongside the primary CSV/XLSX submission-export actions | The XLSForm-specific `.xlsx` upload/download flow and its column-mapping confirmation step |
| Cross-form analytics | Dashboard's own view-switcher, a clearly secondary tab/toggle beside the default single-form/tenant-KPI views — **and hidden entirely, not shown-and-disabled, for a tenant without the `advanced_analytics` entitlement** (ratified 2026-08-03, ADR-0011 §D9: the tier that grants it is held from sale, so an upgrade prompt would point at a plan nobody can buy; this matches the existing `NavItem.feature` precedent for Webhooks and Integrations). Note §3.4's Tabs component is specified but unbuilt | Cross-form aggregation, filtering, and saved custom report views (Phase 3 scope) |

> **Decision (not pinned by the plan):** whether an "Advanced" section's expanded/collapsed state is remembered per-user across sessions (so a power user doesn't have to re-expand it every visit) is left as a **Phase 2+ enhancement, not a Phase 1 commitment** — Phase 1 always defaults to collapsed on every page load, which is the simpler, unambiguously-correct behavior for "never in a first-time user's path" and avoids a subtler bug class (a persisted "expanded" preference leaking to a different, genuinely-first-time user account or being invisibly copied from a template). Persisting the preference is a reasonable later refinement, not required for the principle to hold.

---

## 6. Responsive & Mobile Rules

Feature #5's acceptance criteria are the binding contract here: *"Every screen in the product — builder, dashboard, submissions inbox, settings, authentication — remains fully readable and operable at mobile (≤480px), tablet (≤1024px), and desktop breakpoints, enforced through the shared design system rather than per-page custom CSS."* This section states how the component set in Section 3 satisfies that without any page writing its own breakpoint-specific CSS.

- **Components carry their own responsive behavior; pages never add breakpoint logic.** The data table (§3.3) collapses to a stacked card-per-row presentation at the mobile breakpoint (each row's cells become labeled key/value pairs in a single card, per the Cards component styling, §3.5) automatically, driven by the component's own internal breakpoint awareness — a page using the table does not write a separate mobile layout for its data.
- **The sidebar nav** (§3.4) has three responsive states, all component-owned: full expanded (desktop, > 1024px), icon-only collapsed with hover/focus tooltips (tablet, ≤1024px), and a bottom-anchored icon tab bar or hamburger-triggered full-screen overlay drawer (mobile, ≤480px) — a page never needs to know which of the three is currently rendering.
- **The Authenticated App Shell's content region** (§3.0) reflows from a padded, max-width-constrained column (desktop) to full-bleed-minus-`--mds-space-4` padding (mobile) automatically; any page-level multi-column layout (e.g., a two-column settings page) is expressed via a shared `TwoColumnLayout`/`Grid` primitive that itself knows to stack to one column at the tablet breakpoint, rather than the page authoring its own `@media` query.
- **Modals become full-screen sheets on mobile** (rather than a centered floating panel) — the same `Modal` component (§3.6) switches presentation automatically below the tablet breakpoint, preserving the same focus-trap/keyboard/ARIA behavior (§4.5) regardless of presentation style.
- **Touch-target sizing (§4.4) is enforced identically at every breakpoint**, including desktop — the 44×44px minimum is not a mobile-only rule, both because Feature #5 explicitly extends "touch-target sizing and interactive-element spacing" guidance to *every page*, and because desktop users increasingly interact via touchscreen laptops/tablets-in-desktop-mode.
- **Regression coverage is automated, not manual eyeballing.** Feature #5's Phase 1 acceptance criteria commit to "responsive behavior at each defined breakpoint ... covered by automated Playwright checks in CI, so a regression is caught before merge rather than reported by a user" — concretely, the Playwright suite renders a representative page from each authenticated section (builder, dashboard, submissions inbox, settings) and the public form-fill flow at three fixed viewport widths (375px representing mobile, 834px representing tablet, 1440px representing desktop — chosen as common real-device reference widths comfortably inside each named range) and asserts no horizontal overflow, no touch-target below 44px, and no element clipped/overlapping at any of the three.

> **Decision (not pinned by the plan):** the three Playwright reference viewport widths (375 / 834 / 1440px) are a judgment call — the plan and PRD pin the three *breakpoint boundaries* (≤480 / ≤1024 / >1024) but not specific test-viewport widths. 375px and 834px are chosen as common real device widths safely inside the mobile and tablet ranges respectively (rather than testing exactly at the 480px/1024px boundary, which risks flaking on 1px rounding differences); 1440px is a common laptop-class desktop width.

---

## 7. Versioning & Change Process

The design system must be able to evolve — new components, new tokens, refined patterns — without the "one system, one shell, everywhere" guarantee eroding into the kind of drift this whole document exists to prevent. This section defines that process.

### 7.1 Component library semantic versioning

`@meridian/design-system` (§1.2) is published as a semantically-versioned internal package (`MAJOR.MINOR.PATCH`):

- **PATCH** — visual refinements that don't change a component's public API or observable states (a shadow tweak, a color value adjustment within the same semantic token, a copy fix in an Empty State default) — safe for the admin app and public runtime to pick up without any consuming-app code changes.
- **MINOR** — additive, backward-compatible changes: a new component, a new variant/size on an existing component, a new optional prop with a sensible default. Consuming apps opt in when ready; nothing breaks by staying one minor version behind temporarily.
- **MAJOR** — breaking changes: a removed/renamed prop, a removed component, a semantic-token rename, a behavior change that alters existing markup/accessibility structure. A major bump requires a written migration note (see §7.2) and is never released silently alongside an unrelated feature change.

### 7.2 Deprecation process

- A component/prop/token being replaced is marked `@deprecated` in its TypeScript types/Storybook docs **in the same PR that introduces its replacement** — the deprecation and the replacement ship together, so there is never a window where "the new way" exists without the old way being clearly marked as on its way out.
- A deprecated item remains functional for **at least one full MINOR release cycle** before removal in a subsequent MAJOR release — giving both consuming apps (admin/builder, public runtime) a real window to migrate rather than a forced simultaneous update.
- Every deprecation entry includes a one-line migration instruction (e.g., "use `<Button variant=\"tertiary\">` instead of the removed `<LinkButton>`") surfaced directly in the Storybook docs page for the deprecated item, not only in a separate changelog someone has to go looking for.
- A running `CHANGELOG.md` inside the design-system package is the canonical record of every version's changes, deprecations, and removals — generated from conventional-commit-style PR titles, so it cannot silently fall out of sync with what actually shipped the way legacy's hand-maintained docs did.

### 7.3 The "same PR" documentation rule, restated as an evolution mechanism

Section 1.2 already establishes that any component/token change updates this document and its Storybook story in the same PR. Restated here specifically as a *change-process* guarantee: this document's version history (via normal git blame/log on this file, since it lives in-repo per the plan's docs-as-code discipline) is therefore always an accurate, chronologically-ordered record of how the design system evolved — there is no separate "design system roadmap doc" that can drift from what Storybook and this reference actually say, because they are updated together, by construction, every time.

### 7.4 Cross-app rollout

Because the admin/builder app (Inertia) and the public runtime (separate Vue 3 SPA/PWA) both consume the same `@meridian/design-system` package rather than maintaining independent component sets, a MINOR/PATCH release can roll out to each app on its own release cadence (the public runtime, being the one place WCAG AA and performance are hardest constraints, may deliberately lag by a version or two while a component's real-world accessibility/performance characteristics are proven in the lower-stakes admin app first) — this is an accepted, intentional asymmetry, not a violation of "one system," since both apps are still drawing from the exact same versioned source rather than diverging code.

---

## Appendix A: Decisions Made Where the Plan/PRD Was Silent

The plan and PRD are the source of truth for product/design direction; the following concrete, fine-grained choices were made in this document to produce a complete, unambiguous design-system specification, and are called out here collectively for easy scanning and later ratification:

1. **Storybook 8+ for Vue 3, published as `@meridian/design-system`**, is adopted directly as "the living component library" the plan names only generically as "Storybook or equivalent" (§1.2).
2. **Style Dictionary (or an equivalent build-time token pipeline)** generates `tokens.css` + `tokens.ts` from one source of truth; neither source document names a specific token-build tool (§2.1).
3. **The `--mds-` custom-property prefix** is chosen specifically to avoid collisions in the public runtime's third-party-embeddable context (§2.1).
4. **The "field instrument / blueprint desk" palette** (Blueprint primary, narrow-use Brass accent, Moss/Amber/Redline semantics, Paper/Ink neutrals) is a **ratified** decision, not an open placeholder — selected against a reviewed visual prototype over a generic Inter/Indigo alternative. This supersedes an earlier version of this document that treated the palette as a swappable placeholder pending future branding work (§2.2).
5. **Three type-role stacks (display, system-sans body, monospace utility)** replace an earlier single-typeface (Inter) decision, ratified alongside the palette (§2.4). *(The display role's typeface was revised 2026-07-06 from the condensed Bahnschrift/DIN "drafting-stencil" face to a clean humanist system sans as part of the modern/icony refresh.)*
5a. **Dark mode ships from day one** as a second, authentic ("blueprint print") token mapping rather than a deferred future pass — a direct, low-cost consequence of committing to the primitive→semantic token architecture and the ratified visual concept together (§2.1, §2.2).
5b. **Border radius** was originally tightened to a near-flat 2–5px scale for the drafting-instrument concept's mostly-square-edged character, then **revised 2026-07-06 to a softer 6/8/12px** at the user's request for a more modern, less "edgy" feel while keeping spacing/type density compact (full-round still reserved for true pills/circles) (§2.6).
6. **The mobile-first `min-width` media-query implementation technique**, satisfying the PRD's `max-width`-stated breakpoint contract (§2.7).
7. **`prefers-reduced-motion` support collapsing all motion tokens to 1ms centrally**, rather than left to per-component implementation (§2.8).
8. **The specific ARIA pattern assignments** (Combobox 1.2 for cascading select, dialog focus-trap mechanics, polite-vs-assertive live-region usage) are this document's concrete resolution of the plan's general instruction to have "ARIA patterns for the custom components that need them" (§4.5).
9. **Advanced-section expand/collapse state is not persisted per-user in Phase 1** (always collapsed on load), deferring persistence to a later phase as a refinement rather than a Phase 1 requirement (§5).
10. **The three Playwright reference viewport widths (375 / 834 / 1440px)** used for automated responsive regression testing, chosen as representative device widths inside the plan/PRD's three pinned breakpoint ranges rather than testing exactly at the boundary pixel values (§6).
11. **Component-library semantic versioning policy** (MAJOR/MINOR/PATCH definitions, one-minor-cycle minimum deprecation window) is this document's concrete resolution of the plan's general "docs-as-code"/consistency instructions applied specifically to a versioned design-system package, which the plan does not itself specify at this level of detail (§7).

None of the above contradicts or extends the plan's or PRD's product direction — each fills in a level of implementation detail the source documents intentionally left open.

12. **Icon system** (Increment C2 — see Appendix B). This document references icons throughout (sidebar, bell, chevron, sun/moon/monitor, error/check glyphs) but never defined an icon component, library, sizing scale, or `aria-hidden` convention; Appendix B pins those.
13. **Segmented-control selected-state = a solid filled chip** (`--mds-color-action-primary-bg` + `--mds-color-text-on-primary`), not the subtle `primary-50`/`primary-700` tint §3.2/§3.4 implies. The subtle tint fails AA in dark mode (the dark tint is translucent and the primary-fg text lands ~4.2:1); the filled chip reuses the verified action/on-primary pairing (≥6:1 both themes). The fill + glyph + label remain non-color signifiers.
14. **Sidebar active-item label uses `--mds-color-text-heading`** (highest-emphasis) rather than a primary-hued text, for the same dark-mode contrast robustness; the primary hue is carried by the required left-edge accent bar (`--mds-color-action-primary-bg`) + bold weight, so the active state still has its non-color signifiers.
15. **Theme quick-toggle in the top nav** (Increment C2, at the user's request) — a beyond-spec shortcut to the Settings → Appearance control; recorded in `exceptions-log.md` #3.

---

## Appendix B: Icon System

The DSR references icons throughout but never specified an icon component, library, or conventions.
Increment C2 introduces one (`packages/design-system/src/components/Icon/`); this appendix is its spec.
The rationale is also captured in `exceptions-log.md` #2.

- **Component:** a single `MdsIcon` backed by an internal, hand-authored inline-SVG registry
  (`icons.ts`, keyed by `name`). **No external icon dependency** — so the embeddable public form
  runtime (Feature #3) stays free of an extra request, consistent with the no-webfont typography
  decision (§2.4). Provenance: geometry hand-authored for this project.
- **Drawing:** `viewBox="0 0 24 24"`, `fill="none"`, `stroke="currentColor"`, `stroke-width: 1.5`,
  round caps/joins — technical line-art matching the blueprint concept.
- **Sizes:** `sm` 16px / `md` 20px (default) / `lg` 24px.
- **Color:** inherits `currentColor`, so an icon takes the text color of whatever control it sits in
  (nav item, button, menu item). A standalone icon sets a color explicitly; the default standalone
  color is `--mds-color-text-secondary` / historically `--mds-neutral-500` (§2.2).
- **Accessibility (the load-bearing convention):** icons are **decorative by default** —
  `aria-hidden="true"` + `focusable="false"`. An **icon-only control** (a `<button>`/`<a>` with no
  visible text) carries its accessible name via `aria-label` **on the control**, and the icon stays
  hidden — never rely on a title-tooltip alone (§4.5). A rare standalone *meaningful* icon may pass a
  `label` prop → renders `role="img"` + `aria-label`. Icon-only controls keep a ≥44×44px hit area
  (§4.4) and ≥3:1 boundary contrast (§4.1).
- **Registry growth:** add glyphs to `icons.ts` as features need them (the C2 set: dashboard, forms,
  submissions, settings, bell, menu, close, chevron-down, sun, moon, monitor, user, logout, feedback,
  check). Keep geometry simple/technical. Adding a glyph is a MINOR change (§7.1).

**Components added in C2** (implementing existing sections, with stories + this doc updated per §7.3):
`MdsCard` (§3.5), `MdsEmptyState` (§3.10), `MdsSpinner` (§3.9 indeterminate; determinate bar still
deferred), and the new **`MdsSegmentedControl`** (a single-select radiogroup used for the theme
control — native radios for arrow-key roving selection; selected-state per Appendix A #13).
`MdsCheckbox` (§3.2), `MdsBadge`/`statusVariant` (§3.8), and `MdsSkeleton` (§3.9) remain deferred to a
later increment (nothing in C2 exercises them).

**Components added in C3** (data + admin + settings, with stories + this doc per §7.3): the deferred
trio **`MdsBadge`** + the single central **`statusVariant`** enum→variant map (§3.8, with new
`color.status.{success,warning,danger,info,neutral}.{bg,fg}` semantic tokens re-pointed for dark),
**`MdsCheckbox`** (§3.2), and **`MdsSkeleton`** (§3.9) now ship, plus **`MdsModal`** (§3.6 — self-
contained focus-trap + Escape + scroll-lock, top-level `<Teleport>` portal, full-screen sheet below the
tablet breakpoint), **`MdsToast`** + **`MdsToastHost`** (§3.7 — top-level portal, per-toast
polite/`role="alert"` live semantics; the app owns the toast store + a server-flash → toast bridge), and
the flagship **`MdsDataTable`** (§3.3 — a generic-over-row component that owns column layout, sortable
headers with `aria-sort`, a structure-preserving Skeleton loading state, `#cell-<key>`/`#row-actions`
slots, and the mobile card-per-row collapse). The **determinate progress bar** (§3.9) is still deferred
(nothing in C3 exercises it). Composed-page a11y is now gated end-to-end by a Playwright + `@axe-core`
run at 375/834/1440px against the live tenant pages (§4.6 / decision #10); a composed "list page"
Storybook story adds the same coverage for the DataTable/Badge page pattern under the merge-blocking
per-story axe runner.
