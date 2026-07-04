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

Tokens are authored in a **two-layer architecture**, generated from a single source-of-truth (a token definition tool such as Style Dictionary — flagged below as a judgment call) into both CSS custom properties (consumed directly by Vue SFCs in either frontend, framework-agnostic) and a generated TypeScript `tokens.ts` module (for cases needing token values in script, e.g., chart color scales or canvas-based signature capture rendering):

1. **Primitive tokens** — the raw scale, e.g. `--mds-primary-600: #1C4B72`. Primitives never change meaning; they are simply "the color/space/size at this step of this scale." Components and pages **never** reference primitives directly.
2. **Semantic tokens** — purpose-named aliases that map onto primitives, e.g. `--mds-color-action-primary-bg: var(--mds-primary-600)`, `--mds-color-text-body: var(--mds-neutral-800)`. Components and pages reference **only** semantic tokens.

This indirection is also why dark mode ships **from day one**, not as a deferred phase: because every component consumes semantic tokens rather than primitives, a full dark theme is just a second re-pointing of the semantic layer onto different primitive steps (e.g., `--mds-color-bg-canvas` maps to `--mds-neutral-50` in light mode, `--mds-neutral-900` in dark mode) with **zero component-level changes**. Both mappings are specified below — light is not a placeholder awaiting a future dark pass. The product's chosen visual concept (§2.2) makes this an easy, non-arbitrary commitment rather than a naive color inversion: the light theme reads as **paper and ink** (a technical-drawing/field-notebook surface), and the dark theme reads as an actual **blueprint print** (pale linework on a deep drafting-blue ground) — the two are two authentic states of the same instrument, not one theme and its algorithmic negative.

> **Decision (ratified, superseding an earlier draft of this paragraph):** an earlier version of this document treated a user-facing theme-toggle *feature* as "an open product-scope question." That question is now closed: **PRD Feature #9 commits a manual Light/Dark/Match-System toggle to Phase 1** (§2.9 below is its technical mechanism). What remains true from the original reasoning is *why* this was cheap to commit to: the automatic OS-level `prefers-color-scheme` behavior is a zero-cost consequence of the primitive→semantic indirection alone, and the manual per-user override in §2.9 is simply one small additional layer on top of infrastructure that already had to exist for the automatic case. Nothing here was an expensive feature to greenlight.

> **Decision (not pinned by the plan):** token generation uses **Style Dictionary** (or an equivalent build-time token pipeline) to produce `tokens.css` (custom properties) and `tokens.ts` (typed constants) from one JSON/YAML source of truth, so a single token change propagates to both frontends without hand-editing two files. Neither the plan nor the PRD names a specific token-build tool; Style Dictionary is chosen as a widely-adopted, framework-agnostic default consistent with the plan's general preference for boring, proven tooling over novel infrastructure.
> **Decision (not pinned by the plan):** all custom properties use the `--mds-` prefix (**M**eridian **D**esign **S**ystem) to avoid collisions with third-party embedded contexts — relevant specifically because the public form runtime is designed to be iframed/embedded on third-party domains (PRD Feature #3), where a generic prefix like `--color-primary` would be far more likely to collide with a host page's own tokens.

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

| Token | Hex | Typical use |
|---|---|---|
| `--mds-accent-teal-600` | `#1B5E5E` | The one Phase-2 personalization alternative to the default Blueprint accent — verified 7.48:1 white-text contrast, §4.1. Used *only* when a user has selected it; never a system-default, never a semantic-status color. |

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
> - **Display** (headings, nav labels, section eyebrows): `"Bahnschrift", "DIN Alternate", "Arial Narrow", "Segoe UI Semibold", sans-serif` — a condensed, geometric, uppercase-set grotesk evoking drafting-stencil lettering. Ships on Windows (Bahnschrift) and macOS (DIN Alternate) without a webfont download; `Segoe UI Semibold`/system-default is the final fallback so the page never silently renders in a generic serif.
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

**Font-role mapping**: `display`, `heading-1`, `heading-2`, `heading-3`, and `heading-4` render in the **Display** stack (uppercase, `letter-spacing: 0.02em`–`0.03em` scaling down as size decreases) — condensed/geometric, carrying the page's visual personality. `body-lg`, `body-md`, `body-sm`, `label`, and `caption` render in the **Body** stack — plain, maximally legible, no letter-spacing tricks. `code` renders in the **Utility/mono** stack with `font-variant-numeric: tabular-nums`. A component never mixes stacks within a single text node (e.g., a heading is never partially body-face) — the three-role split is a per-token-not-per-character decision, kept simple deliberately.

Governing rule: heading levels are **visual tokens, not semantic HTML levels** — a card titled with `--mds-type-heading-3` styling might be an `<h2>` or an `<h3>` in the DOM depending on the page's actual heading hierarchy (§4.3 covers why this distinction matters for screen readers). Never skip a token level to "make text bigger" — if `heading-3` isn't big enough, that is a signal the content belongs at `heading-2`, not that the token scale should be bent.

### 2.5 Elevation / shadow

A 5-step elevation scale, used to communicate stacking order (what sits "above" the canvas) independent of color:

| Token | Value | Use |
|---|---|---|
| `--mds-shadow-0` | `none` | Flat surfaces at canvas level (default card at rest, in some contexts) |
| `--mds-shadow-1` | `0 1px 2px 0 rgba(22, 33, 43, 0.08)` | Resting card, table row group |
| `--mds-shadow-2` | `0 2px 6px -1px rgba(22, 33, 43, 0.12), 0 1px 2px -1px rgba(22, 33, 43, 0.07)` | Hover-raised card, dropdown trigger button |
| `--mds-shadow-3` | `0 8px 16px -4px rgba(22, 33, 43, 0.14), 0 2px 4px -2px rgba(22, 33, 43, 0.09)` | Popovers, dropdown/select menus, tooltips |
| `--mds-shadow-4` | `0 16px 32px -8px rgba(22, 33, 43, 0.20), 0 4px 8px -4px rgba(22, 33, 43, 0.11)` | Modals/dialogs, date-picker panels |
| `--mds-shadow-5` | `0 20px 40px -8px rgba(22, 33, 43, 0.24), 0 8px 16px -4px rgba(22, 33, 43, 0.13)` | Toasts (must read as "above" a modal that might be open concurrently) |

> **Decision (not pinned by the plan):** shadow color is based on the `--mds-neutral-800`/"Ink" value (`rgb(22, 33, 43)`) rather than a generic near-black, so elevation shadows carry the same faint blue-ink cast as the rest of the palette rather than a neutral grey shadow that would look slightly mismatched against the paper/blueprint ground.

Governing rule: shadow tokens are **strictly ordered** — a component at elevation *N* never sits visually beneath a component at elevation *N-1* it is meant to overlay (e.g., a toast, `shadow-5`, must never appear to sit under an open modal, `shadow-4`, which is why toasts render in their own top-level portal — see §3.7).

### 2.6 Border radius

> **Decision (ratified):** the previous draft's rounded scale (8px default, up to 16px for modals) reads as generic "modern SaaS" softness and works against the ratified drafting-desk concept, which favors precise, mostly-square edges — instruments and technical drawings are not rounded. The scale below is intentionally flat: one small default radius used almost everywhere, reserved for full-round only where a shape is *supposed* to be circular/pill-shaped (avatars, toggle tracks, status pills), not as a general softening device.

| Token | Value | Use |
|---|---|---|
| `--mds-radius-none` | 0px | Table cells, full-bleed images, the app-shell/canvas edges |
| `--mds-radius-sm` | 2px | Checkboxes, small chips |
| `--mds-radius-md` | 3px | **Default**: buttons, form inputs, cards, modals, popovers — one radius for nearly everything |
| `--mds-radius-lg` | 5px | Reserved for a single larger container context if one is ever needed (not currently assigned to any component in §3) |
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

/* Personalization layer: applied on top, Phase 2 */
:root[data-accent="teal"] { --mds-color-action-primary-bg: var(--mds-accent-teal-600); }
:root[data-font-size="large"] { --mds-type-body-lg: 18px; /* ...uniform +2px step across the whole body scale */ }
:root[data-dyslexia-font="true"] { --mds-font-family-body: "OpenDyslexic", var(--mds-font-family-body-default); }
```

- **Theme mode** (Phase 1): `data-theme-mode` resolves to `light` / `dark` — `system` is the *absence* of the attribute, letting the plain `@media (prefers-color-scheme: dark)` rule from §2.1 take over unopposed. This is why `system` costs nothing extra to implement: it is simply "don't set the override attribute."
- **Accent** (Phase 2): the `data-accent` attribute only ever takes a value from the small, curated set defined here — currently `blueprint` (default, no attribute needed) and `teal` (§2.2's dedicated personalization-only accent alternative) — never an arbitrary color, and **never** Brass or a Success/Warning/Danger hue. This is a deliberately narrower set than an earlier draft of this section considered (which briefly proposed a "moss"/"brass-adjacent" option before this document caught that both would collide with meanings §2.2 already assigns those hues elsewhere — Moss is Success, Brass is annotation-only). Each curated option remaps only `--mds-color-action-primary-bg` and its hover/active/focus-ring siblings (§2.2's semantic alias table), never the neutral/success/warning/danger scales — a user cannot personalize their way into confusing "success green" with "my chosen accent," because accent and semantic color are architecturally separate layers (per §2.2's "spend your boldness in one place, semantic color is separate from the accent" principle, which this personalization layer must not undermine).
- **Font size** (Phase 2): `data-font-size="large"`/`"extra_large"` uniformly shifts the entire type scale (§2.4) by a fixed step per level — never a per-component or per-page override, and never changing line-height ratios (only absolute sizes), so vertical rhythm stays intact at every scale.
- **Dyslexia-friendly font** (Phase 2): `data-dyslexia-font="true"` re-points only `--mds-font-family-body` (§2.4's Body role) to an alternative face; the Display role (headings, the product's visual personality) and the Utility/mono role (data, code) are untouched, consistent with Feature #9's acceptance criterion that this is a targeted accommodation, not a general typeface picker.

**Governing rule**: personalization attributes are set **once**, server-side, on the authenticated shell's root element at render time (from the user's `user_ui_preferences` row) — never re-computed per-component client-side, and never applied to the public/guest form runtime shell (§3.0), which renders only the base tenant-branded theme regardless of which admin/builder user is viewing analytics on the back end at the same moment. This keeps personalization a pure top-of-tree concern, exactly like the light/dark mapping it extends.

> **Decision (not pinned by the plan):** the curated accent set is deliberately small (two options at launch — default Blueprint and the dedicated Teal alternative) rather than an open picker, and implemented via `data-*` attribute selectors rather than arbitrary inline custom-property overrides — both choices trade a small amount of user freedom for a guarantee that every possible personalization combination has been pre-verified against §4.1's contrast requirements and never collides with an existing semantic or annotation hue. An arbitrary color picker would reopen exactly the accessibility risk the semantic-token architecture exists to close off.

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

### 3.3 Lists & Tables

The data table is the single most-used composite component (submissions inbox, forms list, webhook delivery log, audit log). One component covers all of these, configured per use, not re-implemented per page.

**States**:
- **Default** — populated rows, sortable column headers (click/keyboard-activatable, with a visible sort-direction glyph — never sort-order-by-color-alone), a filter bar above the table (chips for active filters, a clear-all action), and pagination below (page-number controls + a "rows per page" selector, cursor-based under the hood per the technical architecture doc's API pagination approach, but presented to the user as simple page-forward/back controls unless the dataset is large enough to warrant "load more").
- **Loading** — a **skeleton state**, not a spinner overlay: the table renders its actual column structure with shimmering placeholder blocks matching each cell's approximate content width, so the layout does not jump once real data arrives. Used both on first load and on filter/sort re-fetch (the latter shows the skeleton only in the row region, keeping the header/filter bar stable).
- **Empty (no data at all)** — the shared Empty State component (§3.10), varying only its illustration/copy/CTA per context (e.g., "No forms yet — create your first form" vs. "No submissions yet — share your form to start collecting").
- **Empty (filtered to zero results)** — a distinct, lighter-weight variant of the empty state: no illustration, just a short message ("No submissions match these filters") plus a "Clear filters" tertiary button — deliberately not the same heavyweight first-run empty state, since this is a filter-tuning moment, not a first-use moment.
- **Row-level states**: hover (`--mds-neutral-50` background), selected (checkbox-driven row selection tinted `--mds-primary-50`), and an inline error/warning row indicator (e.g., a submission flagged for review) using a left-edge color bar plus a status pill (§3.8) — never row-background color alone as the only signifier.

**Governing layout rule**: column headers, cell padding, row height, and the filter-bar/pagination placement are **fixed by the table component itself** — a page configures *which* columns/filters/actions appear, never *how* the table lays them out. A "denser" or "wider" one-off table for a specific page is exactly the kind of change that requires a documented exception (§1.3) or, more likely, a new density variant added to the shared component (§7.2).

### 3.4 Navigation

**Top nav** (part of the Authenticated App Shell, §3.0): tenant/account switcher (left), global search (center, expandable on mobile), notification bell + avatar/account menu (right). Fixed height (`--mds-space-16` = 64px), `--mds-shadow-1` separating it from content on scroll.

**Sidebar nav**: primary section links (Forms, Submissions, Dashboard, Settings), each with an icon + label. States: default, hover (`--mds-neutral-100` background), active/current-section (`--mds-primary-50` background + `--mds-primary-700` text + a left-edge `--mds-primary-600` accent bar — again, never color alone: the accent bar and bold weight are the non-color signifiers), and a collapsed (icon-only, tooltip-on-hover/focus) state used at the tablet breakpoint (§6).

**Breadcrumbs**: used on any screen nested more than one level below a primary sidebar section (e.g., Forms → *[Form Name]* → Submissions → *[Submission #1234]*). Each crumb is a real link except the current (final) one, which is plain text with `aria-current="page"`.

**Tabs**: used to switch between views of the *same* resource without a full navigation (e.g., a form's Builder / Preview / Settings / Analytics tabs). Underline-style indicator (`--mds-primary-600` 2px underline on the active tab), full keyboard support (arrow-key roving tabindex per the ARIA Tabs pattern, §4.5).

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

**Governing layout rule**: an empty state never presents more than one primary CTA and never presents zero CTAs when a next action genuinely exists — a list that is empty because of an upstream permission restriction (rather than "nothing created yet") uses a distinct copy variant explaining *why*, rather than the generic "get started" copy, so the empty state always tells the truth about the situation it's describing.

---

## 4. Accessibility Specification (WCAG AA baseline)

WCAG 2.2 AA is the baseline for the entire product, not only the guest-facing runtime — the PRD states the public runtime must meet it "from the first release" (§2.4, §5 Feature #3) and Feature #5's acceptance criteria extend touch-target and responsive requirements to *every* screen, including the authenticated app. This section is the concrete specification components must satisfy to make both true.

### 4.1 Color contrast

- **Text**: minimum **4.5:1** contrast between text and its background for normal-size text; **3:1** for large text (≥24px regular weight, or ≥19px bold) — matching the plan's stated WCAG AA target exactly.
- **UI component boundaries**: minimum **3:1** contrast for the visual boundaries of interactive components and meaningful graphical objects (input borders, focus rings, icon-only button boundaries, chart/data-viz element boundaries) against their adjacent background — this is the WCAG 1.4.11 "Non-text Contrast" criterion, distinct from (and easier to satisfy than) the 4.5:1 text criterion, and is called out separately here because it is easy to satisfy text contrast while forgetting that a very light input border also needs to clear its own, lower bar.
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
| Cross-form analytics | Dashboard's own view-switcher, a clearly secondary tab/toggle beside the default single-form/tenant-KPI views | Cross-form aggregation, filtering, and saved custom report views (Phase 3 scope) |

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
5. **Three type-role stacks (Bahnschrift/DIN-Alternate display, system-sans body, monospace utility)** replace an earlier single-typeface (Inter) decision, ratified alongside the palette for the same visual-concept reasons (§2.4).
5a. **Dark mode ships from day one** as a second, authentic ("blueprint print") token mapping rather than a deferred future pass — a direct, low-cost consequence of committing to the primitive→semantic token architecture and the ratified visual concept together (§2.1, §2.2).
5b. **Border radius is intentionally tightened to a near-flat scale** (2–5px, full-round reserved for true pills/circles) rather than the previous draft's softer 8–16px "rounded" scale, consistent with the drafting-instrument concept's precise, mostly-square-edged character (§2.6).
6. **The mobile-first `min-width` media-query implementation technique**, satisfying the PRD's `max-width`-stated breakpoint contract (§2.7).
7. **`prefers-reduced-motion` support collapsing all motion tokens to 1ms centrally**, rather than left to per-component implementation (§2.8).
8. **The specific ARIA pattern assignments** (Combobox 1.2 for cascading select, dialog focus-trap mechanics, polite-vs-assertive live-region usage) are this document's concrete resolution of the plan's general instruction to have "ARIA patterns for the custom components that need them" (§4.5).
9. **Advanced-section expand/collapse state is not persisted per-user in Phase 1** (always collapsed on load), deferring persistence to a later phase as a refinement rather than a Phase 1 requirement (§5).
10. **The three Playwright reference viewport widths (375 / 834 / 1440px)** used for automated responsive regression testing, chosen as representative device widths inside the plan/PRD's three pinned breakpoint ranges rather than testing exactly at the boundary pixel values (§6).
11. **Component-library semantic versioning policy** (MAJOR/MINOR/PATCH definitions, one-minor-cycle minimum deprecation window) is this document's concrete resolution of the plan's general "docs-as-code"/consistency instructions applied specifically to a versioned design-system package, which the plan does not itself specify at this level of detail (§7).

None of the above contradicts or extends the plan's or PRD's product direction — each fills in a level of implementation detail the source documents intentionally left open.
