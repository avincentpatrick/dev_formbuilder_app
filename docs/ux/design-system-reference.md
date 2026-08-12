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

1. **Primitive tokens** — the raw scale, e.g. `--mds-primary-600: #0E6FE8`. Primitives never change meaning; they are simply "the color/space/size at this step of this scale." Components and pages **never** reference primitives directly.
2. **Semantic tokens** — purpose-named aliases that map onto primitives, e.g. `--mds-color-action-primary-bg: var(--mds-primary-600)`, `--mds-color-text-body: var(--mds-neutral-800)`. Components and pages reference **only** semantic tokens.

This indirection is also why dark mode ships **from day one**, not as a deferred phase: because every component consumes semantic tokens rather than primitives, a full dark theme is just a second re-pointing of the semantic layer onto different primitive steps (e.g., `--mds-color-bg-canvas` maps to `--mds-neutral-50` in light mode, `--mds-neutral-900` in dark mode) with **zero component-level changes**. Both mappings are specified below — light is not a placeholder awaiting a future dark pass. The product's chosen visual concept (§2.2) makes this an easy, non-arbitrary commitment rather than a naive color inversion: the light theme reads as **paper and ink** (a technical-drawing/field-notebook surface), and the dark theme reads as an actual **blueprint print** (pale linework on a deep drafting-blue ground) — the two are two authentic states of the same instrument, not one theme and its algorithmic negative.

> **Decision (ratified, superseding an earlier draft of this paragraph):** an earlier version of this document treated a user-facing theme-toggle *feature* as "an open product-scope question." That question is now closed: **PRD Feature #9 commits a manual Light/Dark/Match-System toggle to Phase 1** (§2.9 below is its technical mechanism). What remains true from the original reasoning is *why* this was cheap to commit to: the automatic OS-level `prefers-color-scheme` behavior is a zero-cost consequence of the primitive→semantic indirection alone, and the manual per-user override in §2.9 is simply one small additional layer on top of infrastructure that already had to exist for the automatic case. Nothing here was an expensive feature to greenlight.

> **Decision (not pinned by the plan):** token generation uses **Style Dictionary** (or an equivalent build-time token pipeline) to produce `tokens.css` (custom properties) and `tokens.ts` (typed constants) from one JSON/YAML source of truth, so a single token change propagates to both frontends without hand-editing two files. Neither the plan nor the PRD names a specific token-build tool; Style Dictionary is chosen as a widely-adopted, framework-agnostic default consistent with the plan's general preference for boring, proven tooling over novel infrastructure.
> **Decision (not pinned by the plan):** all custom properties use the `--mds-` prefix (**M**eridian **D**esign **S**ystem) to avoid collisions with third-party embedded contexts — relevant specifically because the public form runtime is designed to be iframed/embedded on third-party domains (PRD Feature #3), where a generic prefix like `--color-primary` would be far more likely to collide with a host page's own tokens.

> **As-built note on `tokens.ts` (recorded 2026-08-03, ADR-0011 §D10).** The generated module emits **`var()` references, not resolved colours** — `build-tokens.mjs`'s `meridian/ts` formatter produces entries of the form `colorActionPrimaryBg: 'var(--mds-color-action-primary-bg)'`. This is load-bearing rather than incidental, and it is the reason the analytics charts are SVG rather than canvas: `useTheme` flips `data-theme-mode` and `data-accent` on the root element live, with no reload, so an SVG mark bound to `fill="var(--mds-chart-series-1)"` re-paints for free on a theme change, while a canvas renderer cannot resolve a `var()` at all and would go stale until someone wired a mutation observer. Anything reaching for `tokens.ts` expecting a hex string is reaching for the wrong thing.
>
> **Chart tokens — status (as-built, H24b1).** The `--mds-chart-*` family now **exists**, in `packages/design-system/tokens/chart.json`: `--mds-chart-series-1…5`, `--mds-chart-other`, `--mds-chart-grid`, `--mds-chart-axis` and `--mds-chart-pattern-1…5`. The measured contrast table is §4.1 below. Three things about the shape are decisions rather than details. **(a)** The five series are **literal hexes** that alias no existing scale, and their dark re-point is assigned from `--mds-_chart-dark-1…5`, defined once on `:root` in `theme-overrides.css` and read by both dark blocks — the same private-custom device G11 introduced for the dark teal payload, for the same reason (two copy-pasted dark blocks drift). **(b)** `--mds-chart-other`, `-grid` and `-axis` alias the **neutral** primitives (`{neutral.500}`, `{neutral.200}`, `{neutral.600}`) and therefore need no re-point at all: the dark block already flips the ramp beneath them. **(c)** The **sequential** scale §D11 specifies is deliberately **not emitted** — it has no consumer yet, and shipping an unconsumed token is precisely how the corpus came to contain a false claim that chart tokens already existed.

### 2.2 Color

**Visual concept: "field instrument / blueprint desk."** Rather than a generic modern-SaaS palette, the approved direction (ratified against a visual prototype, not chosen from swatches alone) casts the product as sitting between a surveyor's field notebook and a technical drafting desk — fitting a tool that lives between rigorous survey instruments (Kobo/ODK heritage) and everyday business forms. Colors are semantic-token scales: **primary** (Blueprint), **accent** (Brass, used narrowly), **success** (Moss), **warning** (Amber), **danger** (Redline), and **neutral** (Paper/Ink), each a 10-step scale (50 lightest → 900 darkest, or fewer steps for the narrow-use Brass accent) plus a small set of fixed aliases (white/black/overlay). Both a light ("paper and ink") and a dark (pale lines on a deep cool-ink ground) mapping are specified below; dark is an authentic second state of the same instrument, not an algorithmic inversion of light (§2.1).

> **Amended 2026-08-12 by JR1 (the approved Vivid Product re-skin).** The *structure* above is untouched — same scales, same step counts, same semantic layer. What moved is the palette's temperature: the primary is re-saturated toward `#0E6FE8`, the neutral ramp goes from warm paper/drafting-blue to a cool blue-tinted family, success is re-hued from Moss to `#0B7F76`, and warning to `#8A5A00`. **Danger (Redline) and Brass are deliberately untouched** — the approved direction specifies neither, so changing them would have been invention rather than execution; both were re-measured against the new grounds and still pass. The direction was chosen by the user from four fully-rendered alternatives, which makes it a ratified product decision in exactly the sense the note below describes.

> **Decision (ratified):** the exact hex values below implement the approved visual-prototype direction (a "Meridian — Design System Recommendations" artifact, reviewed and selected over a generic Inter/Indigo alternative). Unlike most decisions in this document, the **palette concept itself is a ratified product/brand decision**, not an open placeholder pending future branding work — the token names, scale structure, *and* the specific hues below are all durable. Only fine-grained interpolation steps between the named anchor points (e.g., exact intermediate 50/100/200 values) remain ordinary judgment calls in the usual sense.

**Primitive scale — Primary (Blueprint → Vivid)**

*(Re-saturated 2026-08-12 by **JR1**, the approved Vivid Product re-skin — the app's own navy turned up rather than a rebrand, so the H23a tenant ramp is unaffected in shape. The 10-step structure and every role below are unchanged; only the hues moved.)*

| Token | Hex | Typical use |
|---|---|---|
| `--mds-primary-50` | `#F2F7FF` | Subtle tinted backgrounds (selected row, info panel), `action-primary-tint` |
| `--mds-primary-100` | `#E0ECFF` | Hover background for tinted surfaces |
| `--mds-primary-200` | `#C0D9FF` | Border on tinted surfaces; dark-mode `status-info` text (6.83:1 on `primary-800`) |
| `--mds-primary-300` | `#8FBCFF` | **Dark-mode `action-primary-fg` + focus ring** — 8.29:1 on the dark surface, §4.1 |
| `--mds-primary-400` | `#589BFD` | Decorative accents; the rgb behind the dark `action-primary-tint` |
| `--mds-primary-500` | `#3783F0` | Decorative only — **3.71:1 on white, so never text and never a fill under white text** |
| `--mds-primary-600` | `#0E6FE8` | **Default primary action fill** (buttons, active nav item) — 4.71:1 white-text contrast, §4.1. **The tightest number in the system**; see the note below |
| `--mds-primary-700` | `#1156B2` | Primary hover fill, **`action-primary-fg`** (brand-coloured TEXT) and the focus ring — 7.01:1 on surface, 6.54:1 on canvas |
| `--mds-primary-800` | `#09418B` | Primary active/pressed state — 9.81:1 |
| `--mds-primary-900` | `#032B61` | Deepest anchor |

> ⚠️ **`action-primary-fg` is `primary-700`, NOT `primary-600` — and the two were the same token until JR1.** `#0E6FE8` measures 4.71:1 on the white surface (passing) but only **4.27:1 on the canvas** (`neutral-50`), which is a real WCAG 1.4.3 failure for brand-coloured body text on any page background. The fill is the ratified colour and stays; the *text* role takes the darker step. This is the single most likely "simplification" a future reader will attempt, because collapsing `fg` back onto `bg` looks like tidying and is a regression on every canvas-backed surface.

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

**Form identity — a six-hue MNEMONIC scale (JR3, PRD Feature #3's list surface)**

The Vivid direction leads every row of a list with a per-form colour chip, and JR1 deferred the scale to JR3 because it needed a dark re-point, its own contrast guard and a hue-collision rule. `tokens/identity.json` holds `--mds-form-identity-1` … `-6`; `theme-overrides.css` re-points all six from `--mds-_form-identity-dark-N` private customs, in both dark blocks.

> **This scale is deliberately NOT semantic, and that is what makes it permissible under the separation rule above.** A form's hue is a mnemonic — the thing that lets you find "the purple one" in a grid of twelve — and it encodes nothing. The state is the status pill sitting beside it on the same card, the identity glyph carries the form's initials as a second channel, and the index is derived from the form id (`crc32 % 6`) rather than chosen, so no user ever assigns meaning to it either. Nothing is lost to a viewer who cannot distinguish the hues.

> **⚠️ SIX HUES REQUIRED DOUBLING UP, AND THE ARITHMETIC IS THE INTERESTING PART.** The semantic palette already occupies FOUR of the six perceptual colour families — red (Danger), amber (Warning), teal-green (Success) and blue (Primary/Info) — and the chart scale spends five more. What is genuinely unclaimed is yellow-green, green, indigo, magenta and rose: **five families for six slots**. So the scale doubles up symmetrically, three in the green band and three in the purple band, rather than crowding one side and leaving the other thin. Within-band separation is **34.4°/36.1°** in the green band and **39.7°/42.2°** in the purple one. The index ORDER alternates the two families, so two consecutive indices are never same-family.

> **⚠️ THE BINDING CONSTRAINT IS THE STATUS HUES, NOT THE CHART SERIES.** The status pill is co-located on the card; the chart series never appear on this page at all. Minimum measured separation from any semantic status hue is **32.54°** (identity-4 vs Warning); minimum mutual separation within the scale is **33.54°** (dark identity-4 vs identity-6). **The guard asserts a 30° floor rather than either number** — deliberately, because a threshold transcribed from today's hexes only tests that nobody edited them; 30° is the policy and the measurements are where this palette lands. The status hues are resolved through `semantic.json` rather than copied, so re-hueing Warning in some later row surfaces the collision in the test instead of on the page.

| Token | Light | Dark | Family |
|---|---|---|---|
| `--mds-form-identity-1` | `#6850E2` | `#9989ec` | Indigo |
| `--mds-form-identity-2` | `#0F7B37` | `#15ac4d` | Emerald |
| `--mds-form-identity-3` | `#C31D6B` | `#e868a4` | Rose |
| `--mds-form-identity-4` | `#5E7307` | `#84a30a` | Lime |
| `--mds-form-identity-5` | `#AA27BE` | `#d26fe2` | Violet |
| `--mds-form-identity-6` | `#287B0F` | `#39ac15` | Spring green |

> **⚠️ THE GLYPH IS A 12% TINT WITH THE HUE AS ITS TEXT, NOT A SOLID FILL WITH WHITE INITIALS — and that choice is what makes the scale affordable at all.** A solid fill carrying white text would force every one of the six to clear **4.5:1 against white**, which confines the palette to hues dark enough that the dark-theme twin goes muddy (the approved mockup draws solid fills, and three of its six chips would not have cleared it). Painting the initials in the hue on a 12% mix of itself makes the measurement hue-on-tint, which every hue clears with room — it is the same device JR2 used for the empty-state medallion. Each hue was **searched for lightness** against that exact ground rather than picked, in both themes.

Two bars apply, and they are different: the glyph initials are small bold TEXT ⇒ 4.5:1 (all six measure **4.507–4.668** on their own tint across both themes); the 4px card edge is a non-text indicator ⇒ 3:1 (WCAG 1.4.11), measured against **all four** grounds a card can sit on — the worst is **4.984** (identity-4 on the light canvas). Per the J2a finding, an edge is an `-fg`-strength value and never a `-bg` fill.

**Primitive scale — Success (Moss → Vivid teal-green, JR1)**

| Token | Hex |
|---|---|
| `--mds-success-50` | `#DFF4F2` |
| `--mds-success-100` | `#CBEAE5` |
| `--mds-success-200` | `#99CEC7` — dark-mode badge text (5.50:1 on `success-800`) |
| `--mds-success-300` | `#60AEA5` |
| `--mds-success-400` | `#34988E` |
| `--mds-success-500` | `#15837A` — verified 4.61:1 as white-text-on-fill (badge/button use, modest but passing margin) |
| `--mds-success-600` | `#0B7F76` — default success **text/icon** color on white/paper, 4.87:1 |
| `--mds-success-700` | `#006B63` | Hover/pressed; badge text on the `success-50` tint (5.59:1) |
| `--mds-success-800` | `#004E48` |
| `--mds-success-900` | `#003834` |

> ⚠️ **This hue collides with the Teal personalization accent, and the collision is recorded rather than resolved.** `success-600` (`#0B7F76`) and `accent-teal-600` (`#1B5E5E`) are near-neighbours in hue, so a user who selects the Teal accent now sees primary buttons in the same family as success pills — which is the confusion the accent/semantic separation rule above exists to prevent, arriving from the opposite direction (the rule anticipated an accent borrowing a semantic hue, not a semantic hue moving onto an accent). The approved direction specifies this success colour, so JR1 ships it; re-hueing a *personalization* accent is a user-facing change and does not belong inside a token diff. Logged as **exceptions-log #10**.

**Primitive scale — Warning (Amber)**

| Token | Hex |
|---|---|
| `--mds-warning-50` | `#FDF0D8` |
| `--mds-warning-100` | `#F0DBC0` |
| `--mds-warning-200` | `#DEB989` — dark-mode badge text (4.87:1 on `warning-800`) |
| `--mds-warning-300` | `#CE9F61` |
| `--mds-warning-400` | `#C18E47` |
| `--mds-warning-500` | `#AD7B33` — default warning **fill**, paired with `--mds-neutral-900`/ink text (**not** white — no amber dark enough to hit 4.5:1 with white text still reads as amber; same resolution the previous draft used, kept intentionally). Ink on it: **4.69:1** |
| `--mds-warning-600` | `#966617` | Hover/pressed fill |
| `--mds-warning-700` | `#8A5A00` — default warning **text/icon** color on white/paper — verified 5.93:1, §4.1 |
| `--mds-warning-800` | `#664200` |
| `--mds-warning-900` | `#4A2E00` |

> ⚠️ **`warning-500` is the one step in the whole system that is NOT derived by its white-text contrast**, and deriving it that way is how JR1 first got it wrong. Every other `500` targets a ratio against white; this one carries **ink**, so its target is a ratio against ink — and a value picked the usual way landed at 4.25:1 ink-on-fill, a quiet failure of the one pairing that matters for it. It is deliberately the lightest amber whose ink contrast still clears 4.5.

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
*(Re-anchored 2026-08-12 by JR1 from the warm paper / drafting-blue columns to a cool blue-tinted family. Every step keeps the contrast ROLE it held — `400` is still deliberately sub-AA, `500` still clears 3:1 as the chart "Other" bucket, `600` still clears 4.5:1 as the axis/tick colour. **This is the change that forced `BrandRampGenerator::VERSION` to 2**: four of its six measurement grounds are the values in this table.)*

| Token | Light hex | Dark hex | Typical use |
|---|---|---|---|
| `--mds-neutral-0` | `#FFFFFF` | `#060911` | Canvas / surface base — in dark this is the INPUT fill, so it must sit below the canvas |
| `--mds-neutral-50` | `#F5F7FC` (Paper) | `#0F131C` | App-shell canvas background — **an engine ground** |
| `--mds-neutral-100` | `#EEF3FE` (Paper Sunken) | `#1A2130` | Card/table hover background, disabled fill; **the dark surface, an engine ground** |
| `--mds-neutral-200` | `#E3E8F3` (Rule Soft) | `#2C374C` | Subtle dividers, chart gridlines |
| `--mds-neutral-300` | `#CFD8EA` (Rule) | `#3A475F` | Default borders, input borders |
| `--mds-neutral-400` | `#7F899F` (Ink Faint) | `#6D7588` | Placeholder text, disabled text/icons (intentionally below AA — verified 3.51:1, §4.1) |
| `--mds-neutral-500` | `#687387` | `#838DA0` | Icon default color, chart "Other" |
| `--mds-neutral-600` | `#5A6478` (Ink Soft) | `#9AA5BD` | Secondary body text (verified 5.95:1, §4.1) |
| `--mds-neutral-700` | `#333C4F` | `#C0C7D8` | High-emphasis secondary text |
| `--mds-neutral-800` | `#1A2233` (Ink) | `#E8ECF5` | **Default body text** (verified 15.90:1, §4.1) |
| `--mds-neutral-900` | `#121A2A` | `#EFF4FD` | Headings, highest-emphasis text — **the `ink` engine ground in both themes** |
| `--mds-neutral-950` | `#080E1E` | `#F7FAFF` | Reserved, highest-emphasis dark-mode text |

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
| `--mds-color-action-primary-bg` | `--mds-primary-600` | `--mds-primary-600` | Primary button fill |
| `--mds-color-action-primary-bg-hover` | `--mds-primary-700` | `--mds-primary-700` | Primary button hover |
| `--mds-color-action-primary-bg-active` | `--mds-primary-800` | `--mds-primary-800` | Primary button pressed |
| `--mds-color-action-primary-fg` | `--mds-primary-700` | `--mds-primary-300` | Brand-coloured **text/icons on a surface** — never a fill. See the §2.2 primary-table warning |
| `--mds-color-action-danger-bg` | `--mds-danger-600` | `--mds-danger-500` (dark) | Destructive button fill |
| `--mds-color-focus-ring` | `--mds-primary-700` | `--mds-primary-300` (dark) | Focus-visible indicator, all components (§4.2) |
| `--mds-color-overlay-scrim` | `rgba(14, 22, 32, 0.5)` | `rgba(8, 13, 20, 0.6)` (dark) | Modal/drawer backdrop |

> **⚠️ Correction (JR1, 2026-08-12): the dark column of the four `action-primary-*` rows above was WRONG, and had been since H21d1.** It read `primary-400 / 300 / 200` — an accent *lightened* on a dark ground, which is the intuitive rule and which H21d1 had already found to be a real WCAG 1.4.3 failure and fixed **in the CSS only**. The document was never updated, so for four increments the reference described a palette the product did not ship and specifically described the bug as the design. Nothing failed, because no test reads this table. Recorded rather than quietly overwritten: a stale doc row is the same class of defect as a stale second copy of a hex, and this section is where a reader would have gone to check.
>
> **The rule, correctly stated:** every primary fill carries `--mds-color-text-on-primary`, which is `#FFFFFF` in *both* themes — so a fill that lightens on hover destroys the contrast the fill exists to carry. Dark therefore uses the **same** fill/hover/active triple as light and moves *deeper* on interaction, not lighter. Only `-fg` and the focus ring — which are text and a boundary on the ground rather than fills under text — take a pale step in dark.

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
| `--mds-type-display` | 44px | 54px | 750 | Onboarding/marketing hero text only — never inside the authenticated app shell. Tracking `-0.028em` |
| `--mds-type-heading-1` | 38px | 46px | 750 | Page title (e.g., "Submissions," a form's builder title). Tracking `-0.028em` |
| `--mds-type-heading-2` | 24px | 32px | 600 | Major section heading within a page |
| `--mds-type-heading-3` | 20px | 28px | 600 | Card/subsection heading |
| `--mds-type-heading-4` | 16px | 24px | 600 | Small heading, table group header |
| `--mds-type-body-lg` | 16px | 24px | 400 | **Default body text**, form-input value text |
| `--mds-type-body-md` | 14px | 20px | 400 | Dense UI: table cells, compact lists, secondary form controls |
| `--mds-type-body-sm` | 13px | 18px | 400 | Tertiary text, dense metadata rows |
| `--mds-type-label` | 13px | 18px | 500 | Form-field labels, nav-item labels |
| `--mds-type-caption` | 12px | 16px | 400 | Help text, timestamps, field-level hints |
| `--mds-type-code` | 13px | 20px | 400 (monospace) | Expression editor, field `key` display, JSON/payload previews |

Weight tokens used across the scale above: `--mds-font-weight-regular: 400`, `--mds-font-weight-medium: 500`, `--mds-font-weight-semibold: 600`, `--mds-font-weight-bold: 700`, `--mds-font-weight-heavy: 750`.

**Tracking tokens (added by JR1):** `--mds-tracking-base: -0.004em`, applied **once** on `body` (and again on the guest runtime's own `body`, which does not import `app.css`) so it inherits everywhere rather than being repeated per role; and `--mds-tracking-display: -0.028em`, carried by the two display roles as `--mds-type-{display,heading-1}-letter-spacing`. These replaced three hand-written magic numbers (`-0.01em` in `PageHeader.vue` and `AdminLayout.vue`, `-0.015em` in `Welcome.vue`) that had drifted apart from each other — tracking is a property of the type *role*, so it now lives beside the size it belongs to.

**`--mds-tracking-wide: 0.06em` (added by JR2)** — positive letting for an **uppercased micro-label**. Uppercase closes the gaps a lowercase word opens, so a caps label needs letting where a sentence-case one does not; this is the one place in the system tracking goes positive. Three consumers, and they are the whole set: `MdsStatTile`'s label, `MdsDataTable`'s column headers, and `MdsFilterBar`'s heading — which until JR2 carried a literal `0.04em`, **the only hard-coded letter-spacing in the package** (a draft of this sentence said "typographic value", which is false: `Button.vue`'s and `TimeSeriesChart.vue`'s `line-height: 1` are both literals and both pre-date JR2. They are deliberate — a button and an axis label are single-line boxes sized by their padding — but they are still literals, and the narrower claim is the true one). It lives under `tracking`, deliberately not as a twelfth `type` role: `type-scale.test.ts` re-derives every `[data-font-size]` declaration from the eleven roles and hard-pins that count.

> ⚠️ **`--mds-type-*-letter-spacing` exists for `display` and `heading-1` ONLY.** Every other role emits no letter-spacing property, so a component wanting tracking on `caption` or `label` reaches for `--mds-tracking-wide`/`-base` directly. Referencing `--mds-type-caption-letter-spacing` compiles, resolves to nothing, and **invalidates the whole declaration at computed-value time** — the H21d1 failure mode. `token-references.test.ts` catches it.

> **JR1 enlarged only the two display roles** (display 36→44, heading-1 30→38, both to weight 750). Heading-2/3/4, every body role and `code` are unchanged: the approved direction's density comes from the page title carrying real weight, not from inflating the whole scale. Note the knock-on — `MdsStatTile` renders its value at `heading-1`, so stat figures grew with the page titles, which is intended and matches the mockup.

**Font-role mapping**: `display`, `heading-1`, `heading-2`, `heading-3`, and `heading-4` render in the **Display** stack (**normal case**, a slight negative `letter-spacing` ≈ `-0.01em` on the largest sizes) — a clean humanist sans carrying the page's visual personality (the original drafting-desk concept set these uppercase; the 2026-07-06 refresh dropped the uppercase for a softer, more modern read). `body-lg`, `body-md`, `body-sm`, `label`, and `caption` render in the **Body** stack — plain, maximally legible, no letter-spacing tricks. `code` renders in the **Utility/mono** stack with `font-variant-numeric: tabular-nums`. A component never mixes stacks within a single text node (e.g., a heading is never partially body-face) — the three-role split is a per-token-not-per-character decision, kept simple deliberately.

Governing rule: heading levels are **visual tokens, not semantic HTML levels** — a card titled with `--mds-type-heading-3` styling might be an `<h2>` or an `<h3>` in the DOM depending on the page's actual heading hierarchy (§4.3 covers why this distinction matters for screen readers). Never skip a token level to "make text bigger" — if `heading-3` isn't big enough, that is a signal the content belongs at `heading-2`, not that the token scale should be bent.

### 2.5 Elevation / shadow

A 5-step elevation scale, used to communicate stacking order (what sits "above" the canvas) independent of color. *(Softened 2026-07-06 as part of the modern/icony refresh; **lifted and brand-tinted 2026-08-12 by JR1** — the 5%-alpha ink shadows were a measured contributor to the app reading flat.)*

| Token | Value | Use |
|---|---|---|
| `--mds-shadow-0` | `none` | Flat surfaces at canvas level (default card at rest, in some contexts) |
| `--mds-shadow-1` | `0 2px 4px 0 rgba(14, 111, 232, 0.06), 0 10px 26px -12px rgba(14, 111, 232, 0.22)` | Resting card, table row group |
| `--mds-shadow-2` | `0 10px 20px -6px rgba(14, 111, 232, 0.20), 0 24px 48px -20px rgba(14, 111, 232, 0.26)` | Hover-raised card, dropdown trigger button |
| `--mds-shadow-3` | `0 14px 30px -10px rgba(14, 111, 232, 0.24), 0 4px 10px -5px rgba(14, 111, 232, 0.14)` | Popovers, dropdown/select menus, tooltips |
| `--mds-shadow-4` | `0 22px 44px -14px rgba(14, 111, 232, 0.28), 0 8px 16px -8px rgba(14, 111, 232, 0.18)` | Modals/dialogs, date-picker panels |
| `--mds-shadow-5` | `0 30px 60px -18px rgba(14, 111, 232, 0.32), 0 12px 24px -10px rgba(14, 111, 232, 0.20)` | Toasts (must read as "above" a modal that might be open concurrently) |

> **Decision:** shadow colour is the **brand hue** (`rgb(14, 111, 232)` — `--mds-primary-600`) rather than ink or a generic near-black, so elevation carries the palette's own cast. This supersedes the 2026-07-06 rule that based it on `--mds-neutral-800`; the *principle* (a shadow tinted with the palette, never neutral grey) is unchanged and is what JR1 applied to the new palette.
>
> **Dark mode re-points `shadow-1` and `shadow-2` only**, in `theme-overrides.css`, to `0 2px 6px rgba(0,0,0,.5), 0 14px 32px -16px rgba(127,178,255,.28)` and a heavier twin. A blue shadow on a near-black ground is invisible — what reads as "raised" there is a true black drop plus an accent **glow**, which is a different device for the same job. Steps 3–5 are overlay surfaces that already sit above a scrim, so their light values degrade acceptably and are left alone. Both re-points are duplicated into the `prefers-color-scheme` twin block and a test asserts the two blocks stay byte-identical.

Governing rule: shadow tokens are **strictly ordered** — a component at elevation *N* never sits visually beneath a component at elevation *N-1* it is meant to overlay (e.g., a toast, `shadow-5`, must never appear to sit under an open modal, `shadow-4`, which is why toasts render in their own top-level portal — see §3.7).

#### The two glow steps (added by JR2) — a second axis, NOT rungs of the ladder

| Token | Value | Use |
|---|---|---|
| `--mds-shadow-accent` | `0 4px 12px -4px color-mix(in srgb, var(--mds-color-action-primary-bg) 55%, transparent)` | The resting glow under a **filled primary** button |
| `--mds-shadow-danger` | the same geometry, mixed off `--mds-color-action-danger-bg` | The resting glow under a **filled destructive** button |

> **These are outside the strict ordering above and must never be substituted for a numbered step.** `shadow-0..5` describe *distance from the page* — a stacking claim, which is why the ordering rule can be stated at all. A glow describes *a fill bleeding into its surroundings*; a glowing button is not "above" a card, it is coloured. Two different questions, two different token families.
>
> **Why `color-mix` off the live token rather than a literal rgba** — and this is the whole reason there are two tokens: `--mds-color-action-primary-bg` is **re-pointed per tenant by the brand ramp** and again by `[data-accent='teal']`. A literal blue halo would sit under a teal-branded tenant's button and under every destructive button in the product. Mixing off the fill means the glow follows whatever that fill currently is, including a colour no one has chosen yet.
>
> **No `@supports` guard, deliberately.** An engine without `color-mix` treats the whole declaration as invalid and drops it, so the button renders flat — exactly as it did before JR2. The failure mode is the previous design.
>
> **No dark re-point.** One value works on both grounds, which also keeps JR2 out of the two duplicated dark blocks that `theme-overrides.test.ts` asserts are byte-identical. The glow is a property of the fill, and the fill is already theme-correct.
>
> ⚠️ **Only filled variants carry it, and they drop it on `:active`, `:disabled` and `--loading`.** A lifted thing that stays lifted while you press it is the one motion cue users read as broken; dropping the shadow is what makes the darker active fill read as *settling*. A disabled button whose grey face still casts a coloured halo reads as enabled-but-broken.

### 2.6 Border radius

> **Decision (revised 2026-07-06):** the original drafting-desk concept used a near-flat **2–5px** radius scale for a precise, mostly-square-edged character. At the user's request the app was refreshed to feel *more modern and less "edgy" while staying compact*, so the scale was softened to **6 / 8 / 12px** — still well short of the generic 16px+ "round everything" SaaS look, and spacing/type density were left untouched so the app stays compact. Full-round (`--mds-radius-full`) remains reserved for shapes that are *supposed* to be circular/pill-shaped (avatars, toggle tracks, status pills), never as a general softening device. `--mds-radius-lg` (12px) is now also used for the tinted icon badges introduced in the same refresh (page-header + stat-tile glyphs).

> **Decision (revised again 2026-08-12 by JR1):** the approved Vivid direction sets **controls at 12px and cards at 20px**, so the scale steps up one notch across the board and gains a fifth step. Cards were the flattest thing in the product at 8px; 20px is what makes a card read as an object rather than a ruled box. Controls stay visibly tighter than cards, which is the proportion the direction depends on — a 20px button next to a 20px card reads as a blob.

| Token | Value | Use |
|---|---|---|
| `--mds-radius-none` | 0px | Table cells, full-bleed images, the app-shell/canvas edges |
| `--mds-radius-sm` | 6px | Checkboxes, small chips — **deliberately NOT stepped up with the rest of the scale** |
| `--mds-radius-md` | 12px | **The control tier.** Buttons, form inputs, selects, chips, menu items, popovers, the modal close button, and any panel nested *inside* another surface |
| `--mds-radius-lg` | 16px | **The compact-surface tier.** Toasts, the collapsed card-per-row (§6 — container-keyed since JR4, not ≤480px), the tinted icon badges (page-header, stat tiles) |
| `--mds-radius-xl` | 20px | **The page-level card/dialog tier.** `MdsCard`, `MdsStatTile`, the `MdsModal` panel, and standalone page-local cards — wired by **JR2** |
| `--mds-radius-full` | 9999px | Pills/badges, avatars, toggle-switch track/thumb — true circles/pills only, never a "rounded rectangle" softening |

> ⚠️ **`sm` stays at 6px on purpose, and the reason is §3.2.** The rest of the scale stepped up one notch; `sm` did not, because the checkbox box is **18px** and a circle at that size is a 9px radius — an 8px `sm` would render a checkbox as a near-circle, which is the **radio's** shape. §3.2 makes shape the non-colour signifier separating the two controls, so stepping `sm` up would have quietly traded a checked-state affordance for visual consistency. The approved direction specifies only the control (12px) and card (20px) values; `sm` was an extrapolation, and this is where the extrapolation was wrong.
>
> ✅ **The JR1→JR2 interim is over (2026-08-12).** `Card`, `StatTile` and the `Modal` panel now read `xl`; the warning that used to sit here — "cards sit at 12px and that is the intended intermediate state" — is discharged and has been removed rather than left to mislead.
>
> ⚠️ **THE THREE TIERS ARE A RULE, NOT A LIST, AND JR2 EXISTS BECAUSE THE LIST WAS NOT ENOUGH.** The row split said "six components"; the measurement said otherwise. Counted on the branch point: **`resources/` held 84 `--mds-radius-md` declarations, and 59 of them sit in the 30 files that also paint `--mds-color-bg-surface`** — page-local surfaces hand-styled to look like a card. (An earlier draft of this paragraph said "84 declarations across 26 files", which reads as one measurement and is two: 84 is *every* `--mds-radius-md` in `resources/`, and the file count is 30, not 26. Corrected here rather than left, because the whole point of the paragraph is that the number is bigger than the row title suggests, and a number that cannot be reproduced by grep argues nothing. After JR2: 81 / 56 / 29.) Move `MdsCard` alone and `/domains` shows a 12px `DomainCard` beside a 20px `MdsCard`, and the sign-in screen — the first surface a new user ever sees — is still speaking the old language. Moving all 30 would be equally wrong: most are inputs, banners and nested panels, which belong at 12px. **Decide by what the surface IS, using the table above**, and when a hand-built surface duplicates `MdsCard`'s fill + border + shadow, the real fix is usually to reach for `MdsCard`.
>
> Applied by JR2 beyond the six: `DomainCard.vue`, `AuthLayout.vue`'s `.auth__card` and the guest runtime's `ConfirmationScreen.vue` → `xl`; `Toast.vue` → `lg`. **Deliberately left at `md`:** every builder inner panel, the `AccountMenu`/`NotificationBell` popovers, `.auth-alert`, `.conflict__col`, and every input — all nested or control surfaces. `ConflictDialog` needed nothing at all: it is an `MdsModal` and inherited the panel.
>
> **Applied by JR3 (the forms-list card grid):** the form card **is** an `MdsCard`, so it inherits `xl` and needed no decision. Its two inner pieces are where the rule earned its keep: the 38px identity glyph reads **`lg`**, the compact-surface tier, because §2.6 already puts tinted icon fields there alongside the page-header and stat-tile glyphs it shares a screen with — **the approved mockup draws that glyph at 12px, which is the CONTROL tier, and the app's own rule beat the mockup's pixel.** The facet chips are `full`, being true pills.

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
prose panel is `tint`/ink, which is why it names `#121A2A` explicitly rather than inheriting the body's
`#1a1a1a` — `#121A2A` *is* the light ink ground the ratio was taken against. Button text is `#FFFFFF`
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
| **Elevation (JR2)** | `--mds-shadow-accent` at rest; **`none`** on active, disabled and loading | none | none | `--mds-shadow-danger` at rest; **`none`** on active, disabled and loading |

> **JR2 — why the filled variants gained a glow.** Before JR2 `MdsButton` read **no shadow token at any state**: it was the flattest component in the design system, and since a button is the thing on a page a user is most looking for, that flatness was a disproportionate share of why the product read plain. The glow is a coloured spill, not a stacking claim — see §2.5's note on why it is a separate token axis and why it is mixed off the live fill rather than written as a literal.
>
> ⚠️ **The `mds-button-spin` duration is a literal `600ms` ON PURPOSE and must not be "tidied" into `--mds-duration-deliberate`,** which holds the same number and therefore looks like a free improvement. Reduced motion collapses every duration token to 1ms centrally (§2.8), so a tokenised spinner would run at roughly a thousand revolutions a second — a strobing disc, and strictly worse for the very user the setting protects. `MdsSpinner` reached this conclusion first and states the principle: a loading spinner is a **functional** indicator, so under reduced motion it keeps moving, just slower. JR2 gave `MdsButton`'s spinner the same 1500ms reduced-motion arm, which it had been missing entirely.

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
| **Search** *(added J1a)* | Author-styled box identical to Text — same border, fill, radius and `min-height: 40px` — **plus** the user-agent clear glyph inside the box once the field is non-empty (note 2) | Same as text | Same as text | Supported but unused: the component applies `mds-input--invalid` and `aria-invalid` for this type exactly as for text, and nothing prevents a consumer from doing so. A keyword query has no invalid state — a query matching nothing is an *empty state* (§3.3), not an error — so a search field rendering an error border is a smell, not a feature | Same as text | `MdsTextInput type="search"`. See the four binding notes below. |
| **Select / Dropdown** | Same as text input, trailing chevron icon | Same | Same, plus open-state elevation `--mds-shadow-3` on the option panel | Same as text | Same as text | Combobox variants (searchable, cascading) follow the ARIA pattern in §4.5 |
| **Checkbox** | `--mds-radius-sm` box, `--mds-neutral-300` border | Border darkens to `--mds-neutral-400` | Focus ring around the box | Border + adjacent error text | Reduced-opacity box, no interaction | Checked state fills `--mds-primary-600` with a white check glyph — never relies on fill color alone (the glyph itself is the non-color signifier) |
| **Radio** | Circular, same border logic as checkbox | Same | Same | Same | Same | Selected state: `--mds-primary-600` filled inner dot |
| **Toggle / switch** | `--mds-radius-full` track, `--mds-color-input-border` off-state border over a `--mds-color-bg-sunken` track, thumb parked left | Track border darkens to `--mds-color-input-border-hover` | Focus ring around the whole track | Not typically used standalone in an error state (paired with a text explanation instead) | Reduced opacity, non-interactive | On-state track fills `--mds-color-action-primary-bg`; **the non-color signifier is THUMB POSITION**, with a check glyph inside the thumb as a redundant second cue — never fill colour alone |
| **Date picker** | Text-input trigger + calendar icon | Same as text input | Same, plus the calendar panel opens at `--mds-shadow-4` | Same as text input | Same as text input | Calendar panel is full keyboard-navigable (arrow keys move by day, `PageUp`/`PageDown` by month) per §4.3 |
| **File upload** | Dashed-border drop zone, `--mds-neutral-300` border, upload icon + "drop files or browse" text | Border darkens on drag-over to `--mds-primary-400`, background tints `--mds-primary-50` | Focus ring around the zone (keyboard-triggerable "browse" is a real button, not a bare `<input type=file>` styled invisible) | Border becomes `--mds-danger-600` + inline error text (e.g., file too large, wrong type) | Reduced-opacity zone, non-interactive | Per-file rows show a thumbnail/icon, filename, size, a determinate progress bar (§3.9) during upload, and a remove action |
| **Signature capture** *(Phase 1 field type placeholder)* | Bordered canvas region, `--mds-neutral-300` border, faint baseline guide + "Sign here" placeholder text (`--mds-color-text-secondary`) | N/A (touch/pointer surface, not a hover-driven control) | Focus ring around the canvas boundary when reached via keyboard (a "Clear signature" button remains keyboard-operable even though the drawing surface itself is pointer/touch-primary) | Border becomes `--mds-danger-600` + error text ("Signature required") | Reduced-opacity canvas, non-interactive | A visible "Clear" text button always accompanies the canvas; the component is a placeholder for full interaction/rendering spec ownership by the eventual Signature field-type implementation, but the **container, states, and label/help/error attachment** specified here are final and binding now |

**Five binding notes on the search input (four added J1a; note 4 resolved and note 5 added in J1e).**

1. **Its implicit role is `searchbox`, not `textbox`.** Every test locator for one of these — Playwright or Vitest — must be `getByRole('searchbox')`. A `getByRole('textbox')` finds nothing, and the failure reads as "the input is missing", which points at the wrong file. ⚠️ **Amended J1d: an EXPLICIT `role` overrides the implicit one, and the command palette is the first place that bites.** `CommandPalette.vue` renders `MdsTextInput type="search" role="combobox"` (the ARIA 1.2 pattern §3.4.1 mandates), so its locator is `getByRole('combobox')` — `getByRole('searchbox')` finds nothing there. The rule above still governs every *plain* search field, including the nav's; it is not a general "search inputs are comboboxes" statement. Stated here rather than left to be rediscovered, because the failure mode is identical to the one this note was written to prevent.
2. **The user-agent clear button (`::-webkit-search-cancel-button`) is deliberately NOT suppressed.** It is a real affordance — the one-tap "clear the query" every mobile user already knows — and removing it without building a replacement is a net loss. Its ~14px box does **not** violate WCAG 2.5.8 (Target Size, Minimum), because SC 2.5.8's *User-Agent Control* exception applies verbatim: "the size of the target is determined by the user agent and is not modified by the author." Do not "fix" this by hiding it.
3. **No `appearance` reset is applied.** The box model is fully authored (border, padding, radius, `min-height`), which modern engines honour on a search field. `appearance: none` is precisely the change that kills the cancel button in WebKit — i.e. the opposite of note 2. Playwright runs Desktop Chrome at all three viewports, so Safari's rendering of this type is **unverified here**; recorded rather than assumed.
4. ~~**`resources/js/components/builder/LibraryPicker.vue` predates this row and does NOT satisfy it.**~~ ✅ **MIGRATED IN J1e, as this note said it would be.** It rendered a raw `<input type="search">` with its own geometry (34px min-height, `--mds-radius-sm`, `--mds-space-2` padding, `body-md` type); it now renders `MdsTextInput type="search"` and the hand-rolled box and focus ring are deleted. **Two things the migration turned up, recorded because neither is obvious from the diff.** (a) The box grows **34 → 40px** — `.mds-input`'s `min-height` — in the builder's left pane, the tightest vertical budget in the app; `builder-axe.spec.ts` and `field-library-axe.spec.ts` are the gates and were re-run for it. (b) Its `aria-label` survives **only because `MdsTextInput`'s root element IS the `<input>` under Vue's default `inheritAttrs`**, which `TextInput.test.ts`'s fall-through case pins — the day that component gains a wrapper element, this label silently moves onto a `<div>` and the field loses its accessible name. It keeps `aria-label` rather than gaining an `MdsFormField`, because the pane already carries an `<h2>` and a one-line hint directly above and a third "Search" caption in 240px would be noise: §4.2's visible-label preference yielding to its own *"wherever there is room"* clause. That is the opposite call from the six list pages below, which is exactly why it is written down.

5. **The list-page keyword filters are `MdsSearchField` inside `MdsFilterBar` (added J1e), not hand-assembled per page.** Six pages — forms, submissions, members, webhooks, feedback, audit — render the same labelled search box and the same `<section>` + `<h2>Filters</h2>` + `minmax(12rem, 1fr)` grid. Three things about that pairing are binding rather than stylistic:

   - **The `<h2>` is unconditional, and it fails only in the empty state.** `PageHeader` renders the `<h1>`, `MdsEmptyState` renders an `<h3>`, and a *populated* `MdsDataTable` renders no heading at all — so `heading-order` can only break once a list is empty, which is the state a keyword filter creates more often than anything else and the state a seeded e2e database never reaches. Two pages had independently worked this out and written the same comment before J1e; the component is where it stops being rediscovered. There is deliberately **no `headingLevel` prop** — every list page sits directly under a `PageHeader`, so the right answer is always 2 and a prop would only offer wrong ones.
   - **`MdsSearchField` has no `disabled` prop, and that is the design.** Sibling one-shot `<select>`s correctly bind `:disabled="busy"` during a round-trip; doing it to a focused text input **blurs it**, and the rest of the user's word goes nowhere. There is no prop to pass, so no page can reintroduce it by copying its neighbour.
   - **It commits on Enter or blur, never on a debounce.** Each of these is a full Inertia page render with its presenter queries behind it — unlike §3.4.1's palette, which has a dedicated JSON endpoint. Browsers fire the native `change` on Enter *as well as* on blur, so both handlers run for one keystroke; the `applied` prop (what the SERVER last ran) is what collapses that to one visit, and what lets "search, Clear, retype the same word" still submit.

   Both components live in `packages/design-system/src` rather than the app tree **for a coverage reason, not a taxonomy one**: Storybook globs that path only, so an app-tree component gets no story and no `checkA11y` scan at all (exceptions-log #9). A heading contract that only fails in a state the e2e suite rarely visits is exactly the thing that should be scanned per-component.

   ✅ **The three pages J1e left hand-rolling this markup were migrated in J2e, so the list is now closed at NINE surfaces**: the six tenant row-lists plus `Pages/search/Index.vue`, `Pages/admin/AuditLog.vue` and `Pages/admin/Feedback.vue`. Three things about that follow-up are worth keeping:

   - **The two `admin/` pages were a zero-pixel swap, and that was measured rather than assumed.** Their `.admin-audit__filters*` / `.admin-fb__filters*` rules were byte-identical to `FilterBar`'s on every property; the component's one extra declaration — `font-family: var(--mds-font-family-body)` on the heading — is a no-op, because `app.css` already sets that family on `body` and nothing overrides it for `h1..h6`. `AdminLayout` applies `--mds-font-family-display` only to its own wordmark and title, neither of which is an ancestor of the filter block.
   - **`Pages/search/Index.vue` DID change visually, deliberately.** It was the only filter surface in the product wearing card chrome (padding, border, `bg-surface`, radius); the other eight are bare, and one design system means it loses the card. It also lost an explicit **Search** button and an in-bar **Clear filters**: `MdsSearchField` commits on Enter or blur like every other list, and the empty state already carries a "Clear search" action. The honest cost, recorded rather than discovered later: `commit()` returns early when the box already matches what the server ran, so there is no longer any way to *re-run an identical query* — acceptable, because an identical query returns identical results and this page has no refresh semantics.
   - ⚠️ **A correction to what this note used to claim.** It said "all three are already axe-scanned where they live". **`/admin/audit-log` is not**, and still is not: `admin-console-axe.spec.ts` covers `/admin/settings` and `/admin/feedback` only, parking the rest on a backlog row. Its Vitest heading case is therefore its only automated guard — and `admin/Feedback.vue`, which *is* scanned, had no such case at all until J2e added one.

   `components/analytics/AnalyticsFilterBar.vue` is **not** part of this set: it is a purpose-built rail with checkbox groups and saved views, not the same component wearing different controls.

⚠️ **Two limits of note 2 worth stating, since it is the note most likely to be re-litigated.** (a) §4.4 requires a **44×44** hit area for every interactive element on a touch-capable viewport, stricter than SC 2.5.8's 24×24, and its sanctioned remedy — expand the hit area with padding or a pseudo-element — is **unavailable** here, because the cancel button lives in a closed user-agent shadow root that cannot be padded or targeted. Preserving it is therefore a judgement that a familiar affordance beats a hit area we cannot legally enlarge without removing it altogether; the alternative is not "a bigger button" but "no button". (b) Blink scales that glyph with the input's computed `font-size`, which `.mds-input` sets and §2.9's `extra_large` scale grows — so "not modified by the author" holds for the *declared* size and not for every input in every personalization state.

**Governing layout rule**: every form input's label, control, help text, and error message stack **vertically, left-aligned to the same edge**, at `--mds-space-2` internal gaps, inside a `FormField` wrapper that itself sits in the page's field-stack at `--mds-space-4` between fields and `--mds-space-6` between sections — no page hand-rolls its own label/input spacing.

> **Implementation status (I5): `MdsSwitch` is BUILT.** G11 deliberately declined to build it — the only boolean shipped at the time was the §2.9 dyslexia-font opt-in, and a switch for one preference would have been "a checkbox with extra steps" — and recorded the trigger as *"a genuine toggle-shaped need"*. Increment I5 (App Settings, PRD Feature #10) is that need: Access · Maintenance · Modules across two consoles, plus fourteen notification channels, every one of them a *state* rather than a selection out of a set.
>
> **The amendment this row needed.** The original rule said on/off must be "additionally labeled via visible text ('On'/'Off')". As built, the non-colour signifier is **thumb position** — which is what makes a switch legible in greyscale and is why the control has a travelling thumb at all — with a check glyph inside the thumb as a redundant second cue. A per-control state word beside fourteen switches reads as noise rather than help; the control's own label ("Email", "Maintenance mode") is always rendered and always visible, so nothing is conveyed by colour alone. WCAG 1.4.1 asks that colour not be the *only* visual means; position and glyph are two others.
>
> **Which control to reach for.** `MdsSwitch` for "is this capability on for me/my organisation" — a setting whose effect is immediate. `MdsCheckbox` stays correct for choosing items out of a set, for consent ("I accept the terms"), and for anything inside a form that is submitted by a button. `MdsSwitch` deliberately mirrors `MdsCheckbox`'s prop and event contract over a real `<input type="checkbox">` (with `role="switch"`), so the two are interchangeable at a call site — which is how I5 swapped fifteen live controls with the existing test locators untouched.

### 3.3 Lists & Tables

The data table is the single most-used composite component (submissions inbox, forms list, webhook delivery log, audit log). One component covers all of these, configured per use, not re-implemented per page.

**States**:
- **Default** — populated rows, sortable column headers (click/keyboard-activatable, with a visible sort-direction glyph — never sort-order-by-color-alone), a filter bar above the table (chips for active filters, a clear-all action), and pagination below (page-number controls + a "rows per page" selector, cursor-based under the hood per the technical architecture doc's API pagination approach, but presented to the user as simple page-forward/back controls unless the dataset is large enough to warrant "load more").
- **Loading** — a **skeleton state**, not a spinner overlay: the table renders its actual column structure with shimmering placeholder blocks matching each cell's approximate content width, so the layout does not jump once real data arrives. Used both on first load and on filter/sort re-fetch (the latter shows the skeleton only in the row region, keeping the header/filter bar stable).
- **Empty (no data at all)** — the shared Empty State component (§3.10), varying only its illustration/copy/CTA per context (e.g., "No forms yet — create your first form" vs. "No submissions yet — share your form to start collecting").
- **Empty (filtered to zero results)** — a distinct, lighter-weight variant of the empty state: no illustration, just a short message ("No submissions match these filters") plus a "Clear filters" tertiary button — deliberately not the same heavyweight first-run empty state, since this is a filter-tuning moment, not a first-use moment.
- **Row-level states**: hover (`--mds-neutral-50` background), selected (checkbox-driven row selection tinted `--mds-primary-50`), and an inline error/warning row indicator (e.g., a submission flagged for review) using a left-edge color bar plus a status pill (§3.8) — never row-background color alone as the only signifier.

**Governing layout rule**: column headers, cell padding, row height, and the filter-bar/pagination placement are **fixed by the table component itself** — a page configures *which* columns/filters/actions appear, never *how* the table lays them out. A "denser" or "wider" one-off table for a specific page is exactly the kind of change that requires a documented exception (§1.3) or, more likely, a new density variant added to the shared component (§7.2).

**Table typography and density (set by JR2).** Column headers are `caption` size, **bold**, **uppercase**, tracked at `--mds-tracking-wide`; cells carry 20px of vertical padding, up from 12px. That is the increment's one deliberate density change and it is the part a user feels.

⚠️ **The row is "61px" only for a cell whose tallest content is one line of body text, and almost no real row is.** 20 + 20 (body-md leading) + 20 + 1 (border) = 61px, but a status badge is 24px tall (JR2 also grew its padding), an `MdsIconButton size="sm"` is 28px and a `--sm` button is 32px. Measured in the browser on the seeded app: **68.5px** on `/forms`, which carries both a badge and row actions. So the honest range is **61px text-only, 65–73px in practice**, against the direction's 60px — the mockup's own row contains a 30px colour chip, so it is not comparing like with like either. The earlier draft of this paragraph quoted 61px flatly, which was derived from the padding token without measuring what the cells contain; the pre-JR2 equivalents were ~47–53px. Below the collapse threshold the cell padding returns to 12px — there a cell is one key/value line inside a card, not a table row. End-aligned columns carry `font-variant-numeric: tabular-nums`, because right alignment in a table means "these are numbers and I want to compare them", and proportional digits fringe the very edge being aligned.

Contrast recomputed for the retyped header, and **12px bold is still small text** (large starts at 18.66px bold), so the bar stays 4.5: `--mds-color-text-secondary` on `--mds-color-bg-surface` measures **5.95:1** light and **6.51:1** dark. Uppercase does not change the ratio, but it does reduce word-shape cues, which is the other reason the size did not go below `caption`.

> ⚠️ **The header keeps `--mds-color-bg-surface`, diverging from the approved mockup, and the reason is measured rather than aesthetic.** The direction paints its header band `--m-surface-2` `#EEF3FE`, which is byte-identical to `--mds-color-bg-sunken` in light — so the swap looks free. It is not. In dark, `bg-sunken` re-points to `neutral-50` `#0f131c`, which **is the dark canvas**. The mockup can assume a tinted band because every table it draws sits inside a card; this app's busiest list does not (`forms/Index.vue` mounts the table bare on the canvas), so a sunken header there would be the same colour as the page behind it and the band would disappear entirely in dark. **The Vivid header therefore reads through type, not fill.** Should a future increment put every table inside a card, this is re-openable — but re-check the dark ground first, because that is the half that fails.
>
> **Registered, not fixed: the retype makes headers wider, and the gate that would catch it runs where headers are hidden.** Uppercase glyphs are ~15–25% wider than lowercase and `--mds-tracking-wide` adds ~6%, against an ~8% size reduction — net roughly +15–20% on a `white-space: nowrap` header, which is what sets a table's minimum content width. On the widest tables that can push `.mds-table__scroll` into horizontal scrolling at viewports that previously fit. It does **not** overflow the page (the wrapper scrolls, that is its job), and the `responsive-axe` overflow sweep asserts at 375px — where `thead` is visually hidden, so the gate literally cannot render the thing that got wider. This was the 481–1024px tablet band that **JR4** existed to address, and it is closed: the collapse is keyed on the container now, so a table too narrow for its columns becomes cards rather than a strip — see §6.
>
> Related and intended: `.mds-table__td::before`, the collapsed key/value label, inherits from `__td` and not from `__th`, so the same column header reads uppercase-tracked on desktop and sentence-case on the card-per-row. That is correct — on mobile it is a field label beside a value, not a column heading — but it is a real divergence and was not obvious.
>
> ⚠️ **The card-per-row reads `--mds-radius-lg`, not `xl`** (§2.6's compact-surface tier). At that width a row *is* a card, so leaving it on the control tier put 12px corners beside every 20px `MdsCard` on the same screen; but a full-bleed card at 375px with 20px corners is heavier than the direction's own phone mock, which draws its panels at 16–18px.

> ⚠️ **`MdsDataTable` did NOT gain a row-link prop in J2a, and that is a decision rather than an omission.** The J2 plan carried an opt-in `rowHref` so a row could link to its object. It was refused on two grounds. **(1) `#cell-<key>` already links a row's first cell**, in `forms/Index.vue` and elsewhere, and has since before J2 — a second mechanism for one job is how a component comes to disagree with itself. **(2) 11 of the 17 tables in the app ship `#row-actions` containing buttons**, so for those a row-wrapping anchor would put interactive content inside interactive content (axe's `nested-interactive`), which means the only universally safe shape *is* "link the first cell" — i.e. the mechanism that already exists.
>
> ⚠️ **An earlier version of this note claimed "every one of these tables ships `#row-actions`", which is false and was asserted without counting.** Six do not: `components/analytics/AnalyticsChartsCard.vue`, `components/analytics/QuestionResultCard.vue`, `Pages/admin/TenantDetail.vue`, `Pages/admin/Users.vue`, `Pages/Dashboard.vue`, `Pages/integrations/RuleShow.vue`. That mattered, because the same paragraph then set the reconsideration trigger at "a table with no row actions needs one" — a condition six tables already met, which would have made the decision look self-refuting to the next reader. **The real trigger is a consumer**: none of those six wants a row link today (a dashboard channel row and a question-result row name no object to open). Build `rowHref` when one does, and have it link the first cell.

**Horizontal overflow containment (invariant, added G11).** A table wider than its column is contained by the component's own `overflow-x: auto` wrapper — the page never scrolls sideways because of a table. That wrapper **must** carry `position: relative`, and this is load-bearing rather than stylistic: the component places `position: absolute` visually-hidden content inside the table (the `.mds-table__sr` "Actions" label, and the whole `thead` at the mobile breakpoint). Without a positioned wrapper, those boxes resolve their containing block *outside* the scroll container, which therefore cannot clip them — and an absolutely positioned element is clipped by its containing block, **not** by whichever ancestor happens to scroll, so no `overflow` rule further up can compensate. G11 hit exactly this: at the tablet breakpoint the extra_large type scale plus the dyslexia-friendly face pushed a 1px hidden span past the last column and the *document* gained 50px of real horizontal scroll, while every visible element remained correctly contained. Any future component that combines a scroll wrapper with absolutely positioned descendants needs the same pairing.

### 3.4 Navigation

**Top nav** (part of the Authenticated App Shell, §3.0): tenant/account switcher (left), global search (center, expandable on mobile), notification bell + avatar/account menu (right). Fixed height (`--mds-space-16` = 64px), `--mds-shadow-1` separating it from content on scroll.

> **Implementation status (updated J1b): THE NAV CENTRE REGION IS BUILT; THE PALETTE IS NOT.** §3.4.1 below was written *before* the code, deliberately (the I12 precedent — a spec the increment consumes rather than invents). J1a shipped the design-system primitives (`MdsTextInput type="search"`, `MdsModal`'s `initialFocus`) and **J1b shipped the centre region as-specified**: a real `<form role="search" method="GET" action="/search">` above the mobile breakpoint, an icon-only link to `/search` at or below it, both JavaScript-free. `TopNav.vue`'s stale header comment deferring global search to increment **C3** is gone. **The ⌘K palette remains J1d's**, and is still specified-not-built.
>
> ⚠️ **J1b's geometry is gated by `tests/e2e/search-nav.spec.ts`, not by the axe suite.** `.app-shell` is `overflow-x: clip`, so `assertClean`'s document-level overflow check cannot see a nav control that runs off the right edge — the containment and non-overlap assertions live in that dedicated spec. Anything added to the centre region (J1d's `⌘K` hint especially) must be re-measured there.
>
> When built, the centre region is a two-state control rather than the single inline field the line above implies: above the mobile breakpoint a real `<form role="search" method="GET" action="/search">` wrapping a `MdsTextInput type="search"`; at or below it, an icon-only link to `/search`. Both states are ordinary navigation that works with JavaScript disabled. The ⌘K palette is an **accelerator layered over** that field, never its only entry point. The tenant/account switcher half of the line remains unspecified and unbuilt.

#### 3.4.1 Global search and the command palette *(added J1a; PRD §3.7 makes global search a non-negotiable product requirement)*

**The trigger, and why it is two states.** Above the mobile breakpoint (`--mds-breakpoint-mobile-max`, 480px) the nav renders a real search form; at or below it, an icon-only link. **The breakpoints are the two §6 already defines — 480px and 1024px — and no third one is invented for this**: §6 states the mobile/tablet/desktop bands are "the binding contract … enforced through the shared design system rather than per-page custom CSS", and the token set defines only those two maxima. The collapse is needed because the bar at 375px already carries a hamburger, a wordmark, and four right-hand controls, and `TopNav.vue`'s own note records only ~31px of headroom at the `extra_large` type scale *before* a search field existed. The keyboard hint drops one band earlier, at ≤1024px, where the sidebar already collapses. The centre region carries `min-width: 0` like its two siblings — without it a flex item's automatic minimum size is its content width, and the bar is pushed wider than the viewport rather than the field shrinking.

⚠️ **`.app-shell` is `overflow-x: clip`, so the standard `documentElement.scrollWidth` overflow assertion is structurally blind to anything in this bar.** A mis-sized centre region is *clipped and invisible*, not caught — the scan stays green over an unreadable nav.

Be precise about the precedent, because copying it verbatim is not enough. `responsive-axe.spec.ts`'s notification-panel workaround is **one assertion — `box.x >= 0`**. It does not assert right-edge containment (`x + width <= viewport`), it asserts no non-overlap, and it runs at default personalization; the `extra_large` + dyslexia + accent matrix lives in a different spec that never opens the panel and measures no boxes. So the existing workaround still has the right-hand half of the blind spot open. **J1b must therefore ADD what the precedent lacks** — containment at both edges, a non-overlap check against the wordmark and the right-hand controls, and the whole thing run at 375px under `extra_large` + the dyslexia face with `document.fonts.ready` awaited.

**The palette is a dialog, not an anchored panel.** It is built on `MdsModal`, which supplies the entire hard half: the `inert` stack and its paint-order handling, the scroll lock, Escape with `stopPropagation`, the Tab trap, return-focus, and the ≤480px full-screen sheet that is exactly the right mobile palette. A bespoke floating panel is forbidden by §3.6, and an anchored listbox in the nav would hit the `overflow-x: clip` trap above from the other direction.

⚠️ **It is the first modal in the product with no action row, and §3.6 says "no modal ships without all four".** That clause was written for the confirmation dialogs this component was built for, where a primary and a cancel are the whole point. A palette has no primary action — every result *is* an action, activated by `Enter` — so a footer would hold either nothing or a redundant "Close" beside the close affordance already present. **The clause is hereby scoped**: it binds *decision* dialogs (anything asking the user to confirm, choose, or destroy) and does not bind *navigational* ones, which must still carry the close affordance and the focus trap. That is two of the four, deliberately, and it is recorded here rather than as an exceptions-log entry because it is a narrowing of §3.6's scope, not a deviation from it — the first `MdsModal` story with no `#actions` slot ships in the same increment as this sentence.

**Keyboard model.**

| Key | Behaviour |
|---|---|
| `Ctrl/Cmd + K` | Opens. Registered as a **capture-phase** `document` listener — bubble phase is silently dead on any page whose editor calls `stopPropagation()`, and "from anywhere" is the entire point. Calls `preventDefault()`, because Firefox and Safari bind this chord to their own address bars. |
| `Ctrl/Cmd + K` while open | Closes (toggle). |
| `Ctrl/Cmd + K` while another dialog is open | **No-op**, guarded by `openModalCount()`. The user has an unfinished blocking task; `inert-stack` would legitimately *stack* the palette on top of it, after which `popModalRoot`'s contract correctly declines to return focus. Refusing is the honest behaviour. |
| Inside an `<input>` / `<textarea>` / contenteditable | **Fires — deliberately no tag guard.** A modifier chord does not collide with typing, and the builder's editors are exactly where a user wants it. |
| `Escape` | `MdsModal`'s own handler. Nothing to build. |
| `↓` / `↑` | Move the active option over the flattened list, wrapping, with `preventDefault()` (otherwise the caret jumps). **DOM focus never leaves the input.** |
| `Home` / `End` | First / last option. |
| `Enter` | Activate the highlighted option; with no list, go to `/search?q=…`. |
| `Tab` | `MdsModal`'s trap. Only the input and the close button are tabbable — rows are not, by design. |

⚠️ **`/` is NOT a shortcut, and this is a conformance decision rather than a preference.** WCAG **2.1.4 Character Key Shortcuts is Level A**, and it requires a single-character shortcut to be disableable, remappable, or active only on focus. Shipping a bare `/` would oblige us to build a shortcut-preference UI to stay conformant, and would need a tag guard that breaks silently inside any `role="textbox"`. `Ctrl/Cmd+K` is a modifier combination and is explicitly exempt. **Do not re-propose `/`.**

**ARIA.** The palette implements the ARIA 1.2 Combobox pattern of §4.5: a `role="combobox"` input with `aria-autocomplete="list"`, `aria-expanded`, `aria-controls`, and `aria-activedescendant` tracking the highlighted option **without moving DOM focus**; the results are a `role="listbox"` of `role="option"` children carrying `aria-selected`.

- **`aria-controls` and `aria-activedescendant` are omitted, never left dangling.** ⚠️ **Do not rely on the a11y gate to enforce this — it largely will not, and the reason is worth knowing before someone "tests" the rule and concludes it is folklore.** Read from `axe-core`'s `ariaValidAttrValueEvaluate`: `aria-controls` carries a **pre-check** that skips validation entirely when `aria-expanded="false"` — which is exactly the empty-query state — and its `idrefs` type passes if *any* referenced id resolves, so a partly-dangling list is green too. `aria-labelledby`/`aria-describedby` downgrade to *incomplete*, not violation. Only **`aria-activedescendant`** (no pre-check, single `idref`) actually fails the build. The rule stands on its own merits — a dangling reference is a real bug for AT regardless of what axe reports — but it is enforced by review and by Vitest, not by the merge gate.
- **Options are `role="option"` on a `<div>`, never `<button>`.** A `<button role="option">` inside a listbox trips axe's `nested-interactive` **and** breaks `aria-activedescendant`. This is in tension with **two** §4.3 bullets, and both need answering rather than the easier one:
  - *"Custom interactives are real semantic elements"* — that bullet governs **standalone controls** (its examples are icon-only buttons, custom select triggers, and the signature-capture clear action), not the descendants of a composite widget. Keyboard operability here is supplied by the combobox input, which *is* a real control.
  - *"Composite widgets use roving `tabindex` … arrow keys move the roving focus"* — the palette does the opposite: DOM focus never leaves the input and rows are not tabbable. **This is a genuine conflict between §4.3 and §4.5**, not an oversight: §4.5 mandates the ARIA 1.2 Combobox pattern, and that pattern is defined in terms of `aria-activedescendant` precisely *because* focus must stay in the text field for typing to keep working. **Where the two disagree, §4.5's named pattern wins for that pattern's widgets**, and §4.3's roving-tabindex rule continues to govern composites with no text entry (tabs, toolbars, the scope tree). Recorded so the next reviewer reaches for the resolution rather than the citation.
- ⚠️ **The palette's live region must live INSIDE the dialog.** `inert` removes the background from the accessibility tree, so *every* `aria-live` region outside an open modal stops announcing and nothing replays on close (§4.5's I10a amendment). The result count is announced by a `role="status"` inside the panel. The palette must **not** become a second `data-mds-inert-exempt` surface; that exemption belongs to the toast host alone.
- Initial focus goes to the input via `MdsModal`'s `initialFocus` prop. Without it focus lands on **Close** — `focusable()` walks the panel in DOM order and the header precedes the body — which is the §4.5 designated-initial-focus target finally being built rather than a palette-specific workaround.
- The chord path moves focus to the trigger **before** opening, so the modal captures a focusable opener rather than `<body>`, whose `.focus()` is a silent no-op. Otherwise closing a keyboard-opened palette strands focus — the outcome §4.5 forbids.

**Results shape — the palette and the results page deliberately differ.** The palette **groups by entity**, caps each group at 5, appends a synthetic *"See all N results"* option so `Enter` always means "activate the highlighted option", and never paginates. The `/search` page **ranks flat across entities** and paginates, because relevance *across* types is the point of a global search and "page 2 of a grouped list" is incoherent. Both are right for their surface; the asymmetry is intentional.

**Auto-highlight consequence, recorded rather than left to be discovered:** the first option is highlighted whenever a non-empty list arrives, which keeps `aria-activedescendant` valid and makes `Enter` useful. A screen-reader user therefore hears the first result on each debounce tick. That is the documented behaviour of `aria-autocomplete="list"` with auto-highlight, and it is the price of `Enter` doing something.

**Disclosure rule (binding on every search surface).** Zero-result copy is **byte-identical** whether nothing matched or everything that matched is invisible to this user — one string, one code path, no branch that could produce a different message. Never `illustration="lock"` on a zero-result search: a padlock *is* the disclosure, since it says something is there. Counts are computed after permission filtering, and a section the user may not search is **absent**, never rendered as "0".

**Consolidation trigger.** The combobox is hand-rolled in the app tree because the increment that owns the ~15 missing primitives (`MdsCombobox`, `MdsTabs`, `MdsMenu`, an input-adornment wrapper) runs *after* this one, and generalising a palette — whose options are navigation targets, which writes no value back, and which has no persisting selection — would produce the wrong primitive. **When `MdsCombobox` is built, the command palette is its first refactor target.** The `exceptions-log.md` entry is owed by **J1d**, the increment that actually builds the component — an exceptions entry describes a live deviation, and the log's entries all carry an `Introduced:` increment, so filing one against a directory that does not exist yet would make the log lie about its own count.

⚠️ **Coverage gap, recorded here because a green gate will otherwise be misread as coverage.** Storybook globs `packages/design-system/src/**/*.stories.@(ts|tsx)` only, so an app-tree component gets **no story and no `checkA11y` scan**. The palette's only automated accessibility gate is Playwright. A green `design-system-a11y` job says nothing whatsoever about it.

**Sidebar nav**: primary section links (Forms, Submissions, Dashboard, Settings), each with an icon + label. States: default, hover (`--mds-neutral-100` background), active/current-section (`--mds-primary-50` background + `--mds-primary-700` text + a left-edge `--mds-primary-600` accent bar — again, never color alone: the accent bar and bold weight are the non-color signifiers), and a collapsed (icon-only, tooltip-on-hover/focus) state used at the tablet breakpoint (§6).

**Breadcrumbs** *(**AS-BUILT since J2a** — `MdsBreadcrumb`)*: used on any screen nested more than one level below a primary sidebar section (e.g., Forms → *[Form Name]* → Submissions → *[Submission #1234]*). Each crumb is a real link except the current (final) one, which is plain text with `aria-current="page"`.

> **As-built note (J2b) — `linkComponent`, and why the default must never change.** Both this component and `MdsTabNav` render a bare anchor by default, and in an Inertia application that is a **full document load**: the persistent `AppLayout` that `app.ts` goes out of its way to keep mounted is torn down and rebuilt, and every long-lived piece of client state (the ⌘K palette, the notification poll, the resolved theme) re-initialises. On a form's tab strip — its primary navigation — that is the difference between an application and a set of documents. The package imports **nothing** from Inertia and must not start, because Storybook renders these components with no router present, so the consumer injects its own element instead: `linkComponent` takes a component or a tag name and the item renders through a dynamic component with the same `href`, class and `aria-current`. **The default stays the string `'a'`**, which is what let every existing story, every J2a call site and the whole of `TabNav.test.ts` / `Breadcrumb.test.ts` pass **unedited** — the evidence this added a capability rather than changed a behaviour. On `MdsBreadcrumb` it reaches the LINK crumbs only; routing the last crumb through it would resurrect the self-link the component exists to refuse. Two things the tests pin that reading the diff will not tell you: the injected component must receive `href` as a real **prop** (Inertia's `Link` declares it; bound as a fallthrough attribute the anchor looks right and never becomes a client visit), and a call site must pass a `markRaw` component — a component object handed through a reactive props bag gets deep-proxied and Vue warns. Inertia's `Link` is a module-level import and therefore already raw.
>
> ⚠️ **In a template the prop is `:ariaLabel`, in camelCase, and it must not be "tidied" to `:aria-label`.** Vue's runtime camelizes hyphenated prop names, but `vue-tsc` keeps `aria-label` as an HTML attribute because it is a real one — so the kebab spelling type-checks as an *attribute* while the required `ariaLabel` **prop** reads as missing, and the type-check gate fails with an error that names neither.
>
> **As-built note (J2a).** The component decides link-ness from the crumb's **position**, not from whether it carries an `href`: a caller who supplies one for every crumb still gets a correct trail, because a self-link at the tail is both a 2.4.4 nuisance and the thing that makes the trail's end indistinguishable from its body. `Breadcrumb.test.ts` pins that direction specifically, since a `v-if="item.href"` implementation passes every other assertion in the file. It renders an `<ol role="list">` — `role="list"` survives `list-style: none`, which Safari/VoiceOver otherwise strips list semantics for, and that is load-bearing rather than tidy here: the separators are `aria-hidden` *because* the list structure conveys the relationship. An empty trail renders nothing at all, never an empty named landmark.
>
> **Six pages hand-rolled a single `← Back to X` link with their own scoped CSS before this**, and they split into two groups that cost different amounts to migrate. **Two already render inside `PageHeader`'s `#breadcrumbs` slot** and need only their link swapped: `forms/Analytics.vue` and `submissions/Encode.vue`. **Four sit outside `PageHeader` entirely** and must adopt the slot first: `forms/Builder.vue`, `submissions/Show.vue`, `webhooks/Show.vue`, `integrations/RuleShow.vue`. Either way, twice over the exceptions-log's "three-plus deviations signal a missing shared component" threshold. *(An earlier version of this note, and of the component's own docblock, said the slot had **zero** consumers. It never did — the two above have used it since they were written, which is precisely why they were on the hand-rolled list.)*
>
> **Migration status (J2c): four of the six are done — `forms/Builder.vue` and `forms/Analytics.vue` in J2b, `submissions/Show.vue` and `submissions/Encode.vue` in J2c. Two remain: `webhooks/Show.vue` and `integrations/RuleShow.vue`.** The two J2c migrations were not cosmetic — both back-links pointed at the wrong place, which is the more interesting half. `submissions/Show.vue` went to the global inbox while its own h1 printed the form's title unlinked, and `submissions/Encode.vue`'s non-editing crumb was a bare `← Forms`, returning a keyer who arrived from one form to the list of all of them. ⚠️ **`Encode.vue` carries its Cancel action TWICE** (the header and the sticky footer) and the crumb's tail must keep naming the same destination as both; three sites, one rule.
>
> ⚠️ **A migration deletes the old scoped CSS as well as the markup.** `.detail__back` and `.encode__crumb` were left behind by the first pass of each and removed in the same commit: dead rules for an element that is no longer on the page read to the next author as a component still in use.

**Tab navigation — `MdsTabNav`** *(**AS-BUILT since J2a**)*: the strip that moves a reader between the pages of **one resource** (a form's Overview / Submissions / Builder / Analytics). 2px underline on the active item, with a heavier weight and `aria-current="page"` as the two non-colour channels.

> **⚠️ SHARE IS NOT A TAB, AND AN EARLIER VERSION OF THIS LINE SAID IT WAS (corrected in J2b).** The J2 plan's tab table listed Share as a fifth item "opening `ShareModal` on the hub", which is precisely the `TabNav`-versus-`Tabs` confusion the notes below exist to prevent: **every item in this strip is a URL with its own route and its own gate**, and a modal-opener is neither. Dressing one as a tab would put an `aria-current`-capable link in the strip whose activation navigates nowhere, and would make the one item that is not a destination indistinguishable from the four that are. Share ships as a **`PageHeader` action button** on the hub, beside Publish and Edit, gated on the presence of the `share` payload key. The builder keeps its own toolbar button — J2b widened Share's reach, it did not move it.

> ⚠️ **THE UNDERLINE IS `--mds-color-action-primary-fg`, AND `-bg` — WHICH IS WHAT THIS SECTION'S ORIGINAL `--mds-primary-600` MAPS TO — IS A REAL 1.4.11 FAILURE.** `MdsTabNav` shipped with `-bg` first and the J2a adversarial review measured it. `-bg` is a **fill**, and the only contrast the system guarantees for it is against the text printed *on* it: `BRAND_RAMP_PAIRINGS` pairs `bg` solely with `on_primary`. Against the surface *behind* it nothing is guaranteed. An underline is a non-text UI component and owes 3:1.
>
> ⚠️ **JR1 moved the evidence without moving the conclusion.** The measurement that produced this rule was `primary-500` `#2E6789` on `bg-surface` `#123350` = **2.12:1**, an outright failure. Under the Vivid ramp the same pairing is `primary-600` `#0E6FE8` on `#1A2130` = **3.42:1**, which passes — the default accent no longer demonstrates the bug, though teal still does (**2.41:1**). The argument never rested on either number: a fill that happens to clear 3:1 against today's surface for today's palette is a coincidence the next re-skin can revoke, in either direction. Do not fold this back to `-bg` on the strength of the 3.42.
>
> `action-primary-fg` carries exactly the guarantee needed, and for **every tenant brand** rather than the two shipped accents: the ramp pairs `fg` against both `surface` and `canvas`, in both themes, at `TEXT_MIN` (4.5:1). Measured anyway — 9.14:1 light, 5.17:1 dark, 7.48:1 light-teal, 5.16:1 dark-teal. `ConfigPanel.vue` already used this token for the same job. **axe does not check border contrast, so no gate we run would have caught it** — which makes this the class of defect that only a measurement finds. When specifying a coloured rule, edge, or indicator anywhere in this document, reach for `-fg`, never `-bg`.

> ⚠️ **`TabNav` AND `Tabs` ARE TWO COMPONENTS, AND THE SPLIT IS A CORRECTNESS DECISION RATHER THAN A NAMING ONE (J2a).** The paragraph below still specifies `Tabs`, the ARIA-1.2 tablist: `role="tablist"`/`tab"`/`tabpanel"` with a roving tabindex, for switching between panels **already in the document**. Its entire contract is *"no navigation happens"*.
>
> A form's tab strip is the opposite: each item is a URL with its own route and its own gate. Dressing those links in tab roles is not a harmless relabelling — a screen reader announces "tab, 2 of 5", implying activation reveals a panel and leaves the reader in place, and instead the document is replaced and the announced position describes a page that no longer exists. **The roving tabindex is the sharper half**: it removes every non-active item from the tab sequence, so a keyboard user can reach exactly one destination by Tab and has to discover that arrow keys now move between things that look like ordinary links. And `aria-selected` would claim a selection state the browser's history, not the component, actually owns.
>
> So `MdsTabNav` is a navigation landmark of plain links with `aria-current="page"` — what the APG itself prescribes for navigation that merely *looks* like tabs. It is named for the resource, never "Tabs", because `landmark-unique` distinguishes navigation landmarks by accessible name. Its scroll region is deliberately **not** focusable, unlike `MdsDataTable`'s: `scrollable-region-focusable` fires only on a scrollable region with no focusable descendants, and every item here is a link, so a tabindex would mint a redundant stop in front of the very links it would exist to reach.
>
> **No automated gate catches the wrong choice** — a tablist of links is valid ARIA and axe has no rule for "the thing being switched is a page". `TabNav.test.ts` asserts the absence of `tablist`, `role="tab"`, `aria-selected` and any `tabindex` on the links, and that file is the only place in the repo that does.

> **⚠️ AS-BUILT (J2b): THE STRIP SHIPS ON THE HUB AND THE ANALYTICS PAGE, AND DELIBERATELY *NOT* ON THE BUILDER** (user decision, 2026-08-11). `forms/Builder.vue` is a three-pane workspace on a `grid-template-rows: auto 1fr; height: 100%` grid with a bespoke fixed toolbar rather than `PageHeader`; a second ~44px header row costs vertical space on the one screen in the product that cannot spare it. It takes the **breadcrumb alone** — `Forms / {title} / Builder`, with the middle crumb reaching the hub — which is enough to end the dead end the strip exists to remove. This is the governing-layout rule holding rather than bending: breadcrumbs carry path context, and a workspace that has one does not also need the strip to say where it is.
>
> **Three crumbs, not two, wherever a form's sub-page adopts the trail** (the builder and the analytics page both). `MdsBreadcrumb` renders the last item as text whatever it carries, so the natural-looking `Forms / {title}` would print the hub's name **with no link on it** — the dead end intact, now with a separator. The trail must end on the page you are actually standing on.

> **As-built note (J2d) — EVERY TRAIL IS SERVER-BUILT NOW, AND `formsCrumb()` IS GONE.** Trails are composed
> by `App\Support\Navigation\CrumbTrail`, which asks each destination's own gate per crumb and hands the page
> a `crumbs` prop; the six client `computed`s and the `useFormsCrumb` composable were deleted. The reason is
> not tidiness: a crumb's href lived in a client `computed` where **no Pest test could reach it**, and J2c had
> already shipped a `/forms` crumb that 403s for a Reviewer and a Viewer through exactly that gap. Two more
> live defects were found the same way on `submissions/Show.vue` — a respondent-only reader 403ing off both
> middle crumbs, and a soft-deleted form rendering an em dash as a live link to a 404. **Do not rebuild a
> trail in a page component**; if a page needs one, its controller composes it.
>
> **A refused CRUMB keeps its label and loses only its href — the deliberate opposite of `MdsTabNav`, where a
> refused item is ABSENT.** Both are correct for their own shape and the difference must not be "aligned":
> dropping a crumb renumbers the trail and makes one page render a different DEPTH per role, which destroys
> the only thing a breadcrumb conveys; a tab strip has no such structure to lose, so ADR-0011 §D9's
> absent-not-locked doctrine applies there and text-degradation applies here.
>
> **The tail is enforced by the type, not by a convention.** `CrumbTrail::current()` returns the finished
> array rather than `self`, so there is no spelling of a trail whose last crumb carries an href. That matters
> because the component renders the last item as text whatever it carries: an href leaking onto the tail is
> invisible in the browser, in Vitest and to axe, and would surface only as a dead payload key a later author
> trusts. `CrumbTrailReachabilityTest` is the only place it is observable.
>
> **Page tests assert PASS-THROUGH, never a spelled-out trail.** A page owes one thing now — rendering the
> server's array untouched — plus one case proving an href-less middle crumb really renders as text, which is
> what would catch a page "helpfully" restoring a default href. Which crumbs are linked for which role is
> `CrumbTrailGateTest`'s question, against the real gates.
>
> **⚠️ AS-BUILT (J2c): THE STRIP NOW SHIPS ON A THIRD PAGE — `/forms/{form}/submissions` — AND THAT PAGE IS THE SAME VUE COMPONENT AS THE GLOBAL INBOX.** `submissions/Inbox.vue` serves both routes, switching on the **presence** of a `form` prop the server omits entirely on `/submissions` (absent, never empty — the same rule the hub's `share` block follows). A second component was the alternative and was rejected for the J1e reason: the filter bar, the URL builder, the export href and the empty-state branching would each have been spelled twice, and two copies of a filter surface are what the audit-export defect was made of. Per-form mode adds the trail and the strip, drops the Form column and the Form dropdown, and makes Export unconditional — the route already *is* a form, so the global inbox's "pick a form first" precondition is satisfied by construction.
>
> ⚠️ **EVERY NAVIGATION ON A DUAL-MODE PAGE MUST GO THROUGH ONE BASE URL.** Three call sites hard-coded `/submissions` — the filter visit, "Clear filters", and the export href. On the per-form route the first two would silently have widened the list to every form in the tenant while the breadcrumb still named one, and the third built `/forms//submissions/export` once "Clear filters" blanked the form ref: a 404 from a button that looked live. The export URL therefore prefers the **route-bound** form, which cannot be cleared.
>
> **Three crumbs is the minimum, not the number.** `submissions/Show.vue` takes **four** (`Forms / {title} / Responses / Response`) because it genuinely is four deep, and `Encode.vue` in edit mode takes five. The rule is unchanged and is about the tail: the last crumb is never a link, so the trail must end on the page you are standing on and every ancestor must be reachable.
>
> **Two `aria-current="page"` elements on one page is correct here and is not a defect.** A form's hub carries the trail (whose tail marks the page) and the strip (whose active item marks the tab); both are legitimately "current", ARIA puts no uniqueness constraint on the attribute, and axe raises nothing. It does mean a test locator for the active tab must be **scoped to the strip** — a page-wide `[aria-current="page"]` matches the breadcrumb first, which is ambiguous by construction rather than by accident.

**Tabs** *(specified here, **not yet built** — the in-page tablist, still owed by J4; J2a built `MdsTabNav` above for the route-navigation case and did **not** repurpose this spec for it)*: used to switch between views of the *same* resource without a full navigation, where the panels are already in the document. Underline-style indicator (`--mds-primary-600` 2px underline on the active tab), full keyboard support (arrow-key roving tabindex per the ARIA Tabs pattern, §4.5). `MdsSegmentedControl` remains the shipped stand-in for the small in-page case (it is a `radiogroup`, so it is not a substitute for either component's semantics — see the builder's Structure ⇄ Logic toggle).

**Governing layout rule**: exactly one navigation paradigm operates at each nesting depth — sidebar for primary sections, tabs for views-of-one-resource, breadcrumbs for path context — and they are never substituted for each other (e.g., a page never uses tabs where breadcrumbs are called for, which is a common ad hoc drift point this rule exists to prevent).

### 3.5 Cards

The default content container for the dashboard, forms list (grid view), and settings panels. Default state: `--mds-color-bg-surface` fill, **`--mds-radius-xl` corners** (the page-level card tier, §2.6 — wired by JR2), `--mds-shadow-1` (or `--mds-shadow-0` flat when inside an already-elevated context like a modal), `--mds-space-5` internal padding, `--mds-color-border-default` 1px border (used **together with** the shadow, not instead of it, so cards remain legible in contexts/zoom levels where shadows render faintly). Interactive cards (e.g., a clickable form-summary card) add a hover state (`--mds-shadow-2` **plus an accent edge, `--mds-color-action-primary-fg`**) and a focus-visible ring when reached via keyboard, and must be a real `<button>`/`<a>`, never a `<div>` with a click handler (§4.5).

> **The hover edge is `-fg`, never `-bg`, and this is the recurrence the J2a finding predicted.** A border is a coloured edge, and `-bg` guarantees contrast only against text printed *on* it — nothing against the surface behind it (§2.2). Measured for this pairing: `#1156B2` on `#FFFFFF` is **7.01:1** light, `#8FBCFF` on `#1a2130` is **8.29:1** dark, both far past the 3:1 a non-text indicator needs. **No hover transform** was added: the approved mockup shows none, and a translate would raise a reduced-motion question that a shadow and a border colour do not.
>
> **Padding stayed at `--mds-space-5` when the radius moved, deliberately.** The two approved artifacts disagree about card padding (24px on a panel, 18px on a grid card) and 20px sits between them; padding is also the one value here with 52 call sites and no visual-regression net behind it. Radius, elevation, type and table row height already carry the direction's density. `MdsStatTile` holds the same 20px for the same reason.
>
> ⚠️ **Three consumers set `padding: 0` on a Card to seat an `MdsEmptyState` flush** — `Dashboard.vue`, `analytics/Index.vue`, `SavedViewList.vue`. With the padding gone, the EmptyState's own 40/24px is the only thing keeping content off a corner that is now 8px rounder. It clears comfortably, but these three are where a future padding change would show first.

**Governing layout rule**: a card's internal content always follows the same internal structure — optional media/icon, heading (`--mds-type-heading-3` or `heading-4`), optional metadata row, body content, optional action row — so a user scanning a grid of cards (forms list, template gallery) can predict where to look for the same kind of information across every card, regardless of which feature built that particular grid.

### 3.6 Modals / Dialogs

Used for focused, blocking tasks (confirm-destructive-action, quick-create flows) — never for primary navigation or as a substitute for a full page. The panel takes **`--mds-radius-xl`** (the page-level card/dialog tier, §2.6 — wired by JR2); its close button stays at `--mds-radius-md`, because that is a control. States: entering (slide/fade in over `--mds-duration-slow`, `--mds-ease-decelerate`), open (`--mds-shadow-4`, `--mds-color-overlay-scrim` backdrop), exiting (reverse transition), and a **destructive-confirmation variant** that always requires the destructive action itself to be styled as the `destructive` button variant (§3.1) — never styled as `primary`, so "the button that does the dangerous thing" is never visually indistinguishable from "the button that does the normal thing" across the whole product.

**Governing layout rule**: every modal has exactly one primary action (bottom-right, per the one-primary-button rule in §3.1), one cancel/secondary action (bottom-left or immediately left of primary), a close affordance (top-right icon button, always also closable via `Escape`), and a focus trap (§4.5) — no modal ships without all four, and no page builds a "modal-like" floating panel that skips the focus-trap requirement by not technically being the shared Modal component.

> **Known and deliberately not fixed by JR2: the panel has no `overflow: hidden`, so a child that paints to the edge — `.mds-modal__body`'s scrollbar, `AuditChangeModal`'s table — squares off the rounded bottom corners.** The bleed pre-dates JR2; going 12 → 20px made it 8px more visible. It is left alone because `overflow: hidden` on the panel would clip any popover a modal's body might raise, and swapping in per-corner radii on `__body` only works while a modal has no footer. Worth a row of its own, not a drive-by in a re-skin.
>
> ⚠️ **The `border-radius: 0` in `Modal.vue`'s ≤480px block is the full-screen sheet and must be kept in step by hand.** It is the only literal radius left in the entire package — every other corner in every component resolves through a token — so it is also the only one a token change cannot reach. It was correct before JR2 and is correct after; the point is that nothing will tell you if it ever stops being.

### 3.7 Toasts / Notifications

Ephemeral, non-blocking feedback (e.g., "Form published," "Submission deleted," a webhook-delivery-failure alert). Rendered in a dedicated top-level portal (outside any modal's DOM subtree, so a toast is never visually trapped beneath an open modal's overlay — see the elevation ordering rule in §2.5), stacked in a fixed corner (top-right on desktop, full-width top-anchored on mobile per §6).

States: entering (`--mds-duration-base`, slide+fade), visible (auto-dismiss after a type-dependent duration — success/info: 5s; warning: 8s; error: **no auto-dismiss**, requires explicit manual dismissal, since an error a user didn't get to read defeats its own purpose), hover (pauses the auto-dismiss timer), and exiting. Each toast carries a semantic icon + color bar matching its type (success/warning/danger/info, using the corresponding token scale) plus text — never an icon-less color swatch alone.

**JR2 moved the toast to `--mds-radius-lg`** (§2.6's compact-surface tier). A toast is a *floating surface* — the same species as a card or a modal panel, and the only other component in the package that pairs a radius with an elevation — so leaving it on the 12px control tier made it read as a chip hovering above 20px cards. It does not take `xl` either: at roughly 56px tall, 20px corners consume most of the height and the strip reads as a lozenge.

**Governing layout rule**: toasts communicate the *outcome* of an action the user just took (or an async event relevant right now); they never carry a primary call-to-action requiring navigation away from the current context (that's a banner or the dashboard's own notification center) — this keeps toasts genuinely ephemeral and prevents them from becoming a dumping ground for anything that "needs to tell the user something."

### 3.8 Badges / Status Pills

Small, `--mds-radius-full`, single-line labels communicating a discrete state — submission status (`draft`/`submitted`/`screened_out`/`under_review`/`approved`/`returned`/`archived`, matching the `SubmissionStatus` enum in the Data Dictionary; `screened_out` was added in I9a and is **neutral**, not danger — a settled non-failure, the same rule `wont_fix`/`disabled`/`revoked` follow), form status (`draft`/`published`/`archived`), webhook delivery status, subscription-tier badges, etc. Each status maps to exactly one semantic color pairing (background tint + matching-hue text, e.g., `approved` → `--mds-success-50` background / `--mds-success-700` text; `returned` → `--mds-warning-50` / `--mds-warning-700`\*(text darkened beyond the default warning text token specifically for the small-pill-text-size case — see the contrast note in §4.1); `archived` → `--mds-neutral-100` / `--mds-neutral-600`), and — consistent with the "never color alone" rule threaded through this document — every pill's **text label is the status name itself**, never a bare colored dot.

**Governing layout rule**: the mapping from enum value → badge color/label is defined **once**, centrally in the component library (a single `statusVariant` lookup consumed by every screen that renders that enum), not re-decided per screen — so `approved` is the same green everywhere it appears, from the submissions inbox to the dashboard to an audit-log entry.

**The `dot` prop (added by JR2)** puts a 6px `currentColor` disc before the label, for the direction's list-status pill. Three things about it are deliberate:

- **It is off by default, and the rule is: a pill whose descriptor comes from `statusVariant()` and keeps that descriptor's label carries `dot`. Every one of them. 23 of 23 as of JR2.** Check it with `grep -rn 'MdsBadge v-bind="\(statusVariant(\|badge"\)' resources/js --include=*.vue` — every hit must carry `dot`. The only `statusVariant()` callers that stay dotless are the three builder badges that borrow a status *colour* for a non-status label (`BuilderCanvas.vue` ×2 and `LogicRail.vue`, all rendering "Repeatable"); they are annotations wearing a status hue, not statuses.

  ⚠️ **This rule was arrived at twice, and both earlier versions were wrong in the same way.** The first draft dotted two call sites and claimed they were "the product's only scannable status columns" — there were eleven more. The second dotted all thirteen `#cell-status` columns and drew the line at "a column you scan versus a value shown once" — which sounds principled and breaks in the place a user actually notices: on `/feedback` the row read `● New` and the detail panel **in the same view** read `New`, the same enum rendered two ways one click apart. Same for `/domains`, where the tenant's own page showed a dotless status while the platform console showed a dotted one. **An inconsistent dot is worse than no dot**, and "scannability" is not a property a component can see. Keying it to `statusVariant()` is, because that function *is* the product's answer to "is this a status?".
- **It never replaces the label.** Read the paragraph above literally: the pill's text is the status name, "never a bare colored dot". The disc is `aria-hidden` decoration sitting *beside* the word, so WCAG 1.4.1 is satisfied exactly as it was before — this prop cannot be used to build the thing that rule forbids.
- **It paints `currentColor`, and it is suppressed when an `icon` is also passed.** `currentColor` means the disc tracks whichever `-fg` the variant set, so it cannot drift out of step with its own label and a variant added later needs no new rule. The suppression is a guard with a named victim: `MdsStatTile` passes `trend-up`/`trend-down` to its delta badge, and a caller who set both would otherwise get a disc *and* an arrow.

JR2 also moved the pill to `--mds-font-weight-semibold` with 4px of vertical padding (from medium/2px) — a slightly taller, firmer object, matching the direction. It still has no border and no shadow.

### 3.9 Progress Indicators

Three distinct patterns, used for three distinct situations — they are not interchangeable:

- **Spinner** (indeterminate) — for short, unmeasurable waits (button loading state, initial page-section load). A simple rotating ring using `--mds-primary-600`, sized to match the text/control it's replacing.
- **Determinate progress bar** — for measurable, bounded operations with a meaningful *fill level* (file upload percentage, async export job progress). Track: `--mds-neutral-200`; fill: `--mds-primary-600`; always paired with a numeric label ("62%") — a bar alone is not sufficient (screen-reader and low-vision users need the numeric equivalent, not just the visual fill level). Two variants of this same component:
  - **Percentage variant** (default) — a filled track plus a percentage label, for operations where the fraction-complete itself is the meaningful signal (uploads, exports).
  - **Step-count variant** — text-only, no filled track: "Step X of N" (optionally with the current step's title, e.g. "Step 3 of 5: Household Members"), used specifically for multi-step form navigation (Form-Filling UX Flow Spec §3.2). A labeled step count is more legible than a bar when N is small (the common case — most forms have well under 10 sections), and it gives screen-reader users a concrete, announceable position rather than an abstract percentage. Each already-completed step in this variant is rendered as a tappable/clickable target to navigate back.
- **Skeleton** — for structural/layout-preserving loading (table rows, card grids, dashboard KPI tiles) as described in §3.3 — deliberately distinct from a spinner because it prevents layout shift and communicates *approximately what's coming*, not just *that something is happening*.

**Governing layout rule**: a spinner is never used for an operation that has a knowable duration/step-count (that's always one of the two determinate-bar variants above) — this rule exists specifically because indeterminate spinners on measurable operations (e.g., a multi-file batch upload) are a common, confusing anti-pattern this system rules out by construction. Choosing between the percentage and step-count variants is not a per-page style choice — it follows directly from whether the operation has a meaningful continuous fill level (percentage) or a small number of discrete named steps (step-count); a page never invents a third presentation for either case.

### 3.10 Empty States

The shared pattern for "this list/page has nothing in it yet," used across forms list, submissions inbox, webhook delivery log, template gallery, and the dashboard's own KPI tiles when a tenant is brand-new. Structure: a simple line-art illustration **inside a tinted medallion** (JR2 — 96px square at `--mds-radius-xl`, `--mds-color-action-primary-tint` field, `--mds-color-action-primary-fg` stroke; it was a bare 64px glyph stroked `--mds-color-border-strong`), a `--mds-type-heading-3` headline, one line of `--mds-type-body-md`/`--mds-color-text-secondary` explanatory copy, and **exactly one primary-button call-to-action** driving the single next best action (e.g., "Create your first form").

> **The medallion is a derivation, not a transcription — neither approved artifact draws an empty state.** What it derives from is the direction's own construction for carrying brand in a non-text element: a tinted field with an accent glyph, the same shape as `MdsStatTile`'s icon chip and the sidebar's active item. It is worth doing because the measured diagnosis behind the whole re-skin names *"empty states are plain text, no illustration"* as one of the thirty compounding causes, and a grey stroke on a white ground is the plainest thing on any page that has one.
>
> ⚠️ **`--mds-color-action-primary-tint` is the load-bearing token choice here, and `primary-50` is the trap.** `tint` is the one accent fill that is *redeclared* for dark — `rgba(88,155,253,0.18)` in both dark blocks — rather than riding the primary ramp, which is not theme-flipped. Reach for `primary-50` because it is "the light accent" and dark mode gets a near-white slab where the medallion should be.
>
> The change is CSS-only: padding on an inline `<svg>` insets its viewBox, so `box-sizing: border-box` with 96px − 2×16px keeps the glyph at its original 64px and grows only the field around it. No wrapper element was added, which is why every consumer and `dashboard.test.ts`'s `.mds-empty__desc` assertions are untouched.

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

> ⚠️ **As-built (J2a) — `BarDatum.href`, AND WHY IT CHANGES THE PLOT'S ACCESSIBLE STRUCTURE.** PRD §3.7 forbids dead ends, and the dashboard's *Top forms* chart named five forms while `breakdown-bars.ts` had each form's uuid in hand and discarded it. A per-datum `href` now makes a bar's **label** a link.
>
> That could not simply be added to the markup. `MdsBarChart`'s plot carries `role="img"`, and **an element with that role is a leaf — every descendant leaves the accessibility tree.** The visible labels and values inside it are already not announced today (the summary sentence plus the sr-only data table are what a screen reader gets, which is the existing deliberate design). A link left inside would therefore be **unreachable**, not merely unlabelled — worse than the dead end it was meant to remove, and green under every gate we run, because axe has no rule for interactive content buried in an image.
>
> So when **any** datum carries an `href` the plot stops being one image: `role="img"` and its `aria-label` come off, and the summary moves into a visually-hidden paragraph immediately before the plot so §D12's one-sentence overview is not lost. The tracks stay `aria-hidden` either way, so nothing gains "a dozen unlabelled images". With no hrefs the output is unchanged, and `BarChart.test.ts` asserts **both** directions — an `isInteractive` that collapsed to a constant would otherwise silently strip `role="img"` from every chart already shipped.
>
> ⚠️ **AND THE sr-ONLY DATA TABLE COMES OFF WITH IT — the J2a review caught that the first version announced the whole dataset TWICE.** Un-pruning the plot exposes its labels and values to a screen reader for the first time; the table below was still rendered unconditionally, so a reader got the summary, then every row, then every row again. The table is the plot's **alternative**, so it is rendered when the plot is an image and omitted when the plot is readable. It survives whenever `tableVisible`, which is a visible feature rather than an alternative. §D12's text-alternative obligation is met either way: by the table in the static shape, by the plot's own text plus the summary in the interactive one.
>
> ⚠️ **One predicate, not two.** `isInteractive` and the per-row `v-if` must test the same thing. The first version tested `href !== undefined` in the script and truthiness in the template, so `href: ''` — the obvious shape of a `cond ? url : ''` call site — stripped `role="img"` *and* its label *and* rendered zero links. **The value stays outside the anchor**: the link goes to the category, not to its count. `MdsTabNav` deliberately does the opposite with its badge, and the shared rule is that a count belongs **inside** the accessible name when it describes the destination ("Submissions 128" — how much is behind this link) and outside it when it is the data being plotted.
>
> **`MdsStatTile` gained `href` in the same increment**, as a link and never a click emit — the `MdsCard` `interactive`/`as` precedent, and §3.5's standing rule that an interactive card is a real `<button>`/`<a>`. Optional, so the **17** pre-existing tiles keep rendering a plain container rather than gaining 17 tab stops; `href: ''` is treated as absent for the same call-site reason as the chart. ⚠️ **A linked tile's accessible name is its WHOLE text** — the delta badge, its comparison label and the caption all join it, because an anchor's name is its contents. That is correct for a card-shaped link, and it is why no `aria-label` is added: one would replace that name and hide the delta from screen-reader users while sighted readers keep seeing it. `StatTile.test.ts` asserts the exact string, after the review found that the case named for this contract asserted only `toContain` and could not have failed it.

> **`MdsStatTile` under JR2 — four changes, and the arrangement is deliberately NOT one of them.**
> The approved mockup draws its tile label-on-top with a 13px inline accent icon; this component keeps
> its 44px tinted medallion on the left with the value first. That is a decision of record (user, 2026-08-12):
> the medallion is a Vivid-compatible element the mockup happens not to have, and it puts more accent
> colour on the dashboard than an inline glyph would. Keeping it also means no markup moves, so the
> exact-accessible-name assertion above and the three page-level `tile()` helpers stay valid.
>
> What did change: the container took `--mds-radius-xl` (the chip stays `lg`, so the nesting now reads
> 20 outside / 16 inside); the hover edge became the accent, matching `MdsCard--interactive`; the label
> became an uppercased `caption` at `--mds-tracking-wide`; and the value gained **`font-variant-numeric: tabular-nums`**
> plus a `1.05` line-height.
>
> ⚠️ **The tabular figures were a JR1 comment that shipped without its declaration.** The code carried
> the sentence *"tabular-nums plus tight tracking is what makes a column of them line up"* while only the
> tracking existed — both charts had `font-variant-numeric` and the one number a user actually reads did
> not, so a column of tiles jittered by a fraction of a digit width. A comment is not a guard; this is the
> second time that lesson has been paid for in this file.
>
> **The value is gradient-filled** (`exceptions-log #11`) — heading ink at the top, ink mixed with 22%
> accent at the bottom, via `background-clip: text`. Three guards make that safe and all three are
> load-bearing: the plain `color` stays the base declaration so an engine without `background-clip: text`
> renders an ordinary number; `-webkit-text-fill-color` is set alongside `color`, because in WebKit *that*
> is the property the clipped background paints through; and `@media (forced-colors: active)` restores
> `CanvasText`, without which a Windows High Contrast user gets transparent text over a background the
> mode has already stripped — an invisible number, which is the usual reason this pattern is a defect.

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

*(Every row below recomputed 2026-08-12 for the JR1 palette.)*

| Pairing | Ratio | Verdict |
|---|---|---|
| `--mds-primary-600` (`#0E6FE8`) fill / white text (default button) | **4.71:1** | Passes AA — and it is the **tightest margin in the entire system**. The direction's signature colour; anything smaller than normal body text must not sit on it, and any future darkening of `text-on-primary` breaks it |
| `--mds-primary-700` (`#1156B2`) fill / white text (hover/pressed) | 7.01:1 | Passes |
| `--mds-primary-700` (`#1156B2`) **text** / white surface (`action-primary-fg`) | 7.01:1 | Passes — and 6.54:1 on the canvas, which is why `fg` is 700 and not 600 (`#0E6FE8` on canvas is **4.27:1**, a fail) |
| `--mds-primary-800` (`#09418B`) fill / white text (active) | 9.81:1 | Passes |
| `--mds-neutral-800`/"Ink" (`#1A2233`) text / white-surface background (default body text) | 15.90:1 | Passes, very high margin |
| `--mds-neutral-600`/"Ink Soft" (`#5A6478`) text / white-surface background (secondary text) | 5.95:1 | Passes |
| `--mds-neutral-400`/"Ink Faint" (`#7F899F`) text / white-surface background (disabled text) | 3.51:1 | Below AA's 4.5:1 — **intentional**, disabled content is exempt from WCAG contrast requirements; this token is never used for enabled/interactive text |
| `--mds-warning-700` (`#8A5A00`) text / white-surface background (warning text/icon) | 5.93:1 | Passes |
| `--mds-success-600` (`#0B7F76`) text / white-surface background (success text/icon) | 4.87:1 | Passes |
| `--mds-danger-600` (`#A83A2A`) fill / white text (destructive button) | 6.36:1 | Passes — unchanged by JR1, which specifies no destructive hue |
| `--mds-neutral-900` ink on `--mds-warning-500` (`#AD7B33`) fill | 4.69:1 | Passes — the exception below, and the one step derived by its **ink** contrast rather than its white contrast |

**Dark theme, the rows a light-only check cannot see** (surface `--mds-neutral-100` `#1A2130`, canvas `#0F131C`):

| Pairing | Ratio | Min | |
|---|---|---|---|
| `--mds-neutral-800` (`#E8ECF5`) body text on the dark surface | 13.61:1 | 4.5 | ✅ |
| `--mds-neutral-600` (`#9AA5BD`) secondary text on the dark surface | 6.51:1 | 4.5 | ✅ |
| `--mds-primary-300` (`#8FBCFF`) `action-primary-fg` + focus ring on the dark surface | 8.29:1 | 4.5 | ✅ |
| white text on the dark `action-primary-bg` (`primary-600`) | 4.71:1 | 4.5 | ✅ |
| white text on the dark hover (`primary-700`) / active (`primary-800`) | 7.01 / 9.81 | 4.5 | ✅ |
| pale ink on the dark tint `rgba(88,155,253,.18)` composited over `#1A2130` | 10.11:1 | 4.5 | ✅ |

**Teal accent — full verification (Increment G11).** The §2.9 personalization accent is the one hue a *user* can switch the entire primary-action surface to, so every pairing it produces is verified here rather than left to the Storybook matrix. All 17 computed with the WCAG relative-luminance formula against the real surface tokens in each theme.

| # | Pairing | Ratio | Min | |
|---|---|---|---|---|
| **Light** | | | | |
| 1 | white text on `teal-600` fill (button, selected chip, checked control) | 7.48:1 | 4.5 | ✅ |
| 2 | white text on `teal-700` fill (hover) | 9.40:1 | 4.5 | ✅ |
| 3 | white text on `teal-800` fill (active) | 12.10:1 | 4.5 | ✅ |
| 4 | `teal-600` text on `bg-surface` (`#FFFFFF`) | 7.48:1 | 4.5 | ✅ |
| 5 | `teal-600` text on `bg-canvas` (`#F5F7FC`) | 6.97:1 | 4.5 | ✅ |
| 6 | `teal-600` UI boundary vs canvas | 6.97:1 | 3.0 | ✅ |
| 7 | `teal-700` focus ring vs surface | 9.40:1 | 3.0 | ✅ |
| 8 | `teal-700` focus ring vs canvas | 8.77:1 | 3.0 | ✅ |
| 9 | `neutral-900` ink on `teal-50` tint | 15.90:1 | 4.5 | ✅ |
| **Dark** | | | | |
| 10 | white text on `teal-500` fill | 6.68:1 | 4.5 | ✅ |
| 11 | white text on `teal-400` fill (hover) | 4.82:1 | 4.5 | ✅ |
| 12 | white text on `teal-700` fill (active) | 9.40:1 | 4.5 | ✅ |
| 13 | `teal-300` text on `bg-surface` (`#1A2130`) | 6.39:1 | 4.5 | ✅ |
| 14 | `teal-300` text on `bg-canvas` (`#0F131C`) | 7.37:1 | 4.5 | ✅ |
| 15 | `teal-300` focus ring vs dark surface | 6.39:1 | 3.0 | ✅ |
| 16 | `teal-300` focus ring vs dark canvas | 7.37:1 | 3.0 | ✅ |
| 17 | pale ink on the dark tint `rgba(36,126,126,.18)` composited over `#1A2130` | 11.27:1 | 4.5 | ✅ |

*(Rows 5–8 and 13–17 recomputed 2026-08-12: the teal ramp itself is UNCHANGED by JR1 — its light rows are measured against `#FFFFFF`, which cannot move — but every row measured against a canvas or a dark ground moved with the neutral ramp. All improved.)*

Teal is no longer uniformly better than the primary in dark: the Vivid fill carries white at 4.71:1 against teal's 6.68:1, because JR1 traded fill contrast for saturation deliberately and teal did not move. Both clear AA.

> **This table is now an executable specification, not only a record (Increment H23a1, [ADR-0014](../adr/0014-tenant-brand-ramp-generation.md)).** Its seventeen rows are encoded, hue-removed, as `BrandRampGenerator::pairings()` and `BRAND_RAMP_PAIRINGS`, and every generated **tenant** brand ramp is measured against all seventeen before it is stored. Two details of the encoding are worth knowing before editing this table:
>
> - **Light row 6 duplicates row 5's colour pair at a lower floor** (3.0 rather than 4.5, because a UI boundary is not text). It is kept in both the table and the code rather than folded into row 5 — dropping it would make the list sixteen and quietly break the correspondence, which a unit test asserts by count and by per-theme split (9 light, 8 dark).
> - **The engine's targets are the MIDPOINT of this table's Teal column and Blueprint's equivalent**, so a tenant ramp lands in the same visual register as the product's own rather than scraping the 4.5 floor. The evidence that this reproduces the system's own judgment: fed Teal's hue, the engine re-derives `teal-600` byte-identically and returns rows 1–3 as 7.48 / 9.40 / 12.10. **JR1 halved that claim, instructively.** It used to cover `teal-50` as well; the tint is searched against the light `ink` ground, which JR1 moved, so the engine now derives `#EBF7F7` where G11's human hand-picked `#E6F2F2`. `--mds-accent-teal-50` was deliberately NOT updated to match — doing so would leave the test comparing the engine against a number copied *from* the engine, destroying exactly the independence the claim asserts. Only the half anchored to `#FFFFFF`, a ground that cannot move, was ever robust.
>
> **Adding a row here means adding a pairing there**, and the count assertion is what will tell you.

**Chart scale — full verification (Increment H24b1).** ADR-0011 §D11 states the constraints and deliberately prints no ratios, assigning the measured table here; these are the numbers, computed with the same WCAG relative-luminance formula against the real surface token in each theme. The surface is `--mds-color-bg-surface`: `#FFFFFF` in light, `#1A2130` in dark (the dark block re-points it to `neutral-100`). *(Dark column recomputed 2026-08-12 — the ten series hexes are UNCHANGED by JR1 and every one gained headroom against the deeper ground.)* A data mark is a *meaningful graphical object* and takes **3:1** (WCAG 1.4.11); axis tick labels are **text** and take 4.5:1; gridlines are decorative scaffolding, **exempt**, and deliberately sit below 3:1.

| # | Token | Light hex | vs `#FFFFFF` | Dark hex | vs `#1A2130` | Min | |
|---|---|---|---|---|---|---|---|
| 1 | `--mds-chart-series-1` (blue) | `#0072B2` | 5.19:1 | `#7CC9F1` | 8.80:1 | 3.0 | ✅ |
| 2 | `--mds-chart-series-2` (vermilion) | `#E15A14` | 3.70:1 | `#E4813F` | 5.76:1 | 3.0 | ✅ |
| 3 | `--mds-chart-series-3` (green) | `#00654A` | 7.09:1 | `#37B98C` | 6.50:1 | 3.0 | ✅ |
| 4 | `--mds-chart-series-4` (purple) | `#7A3468` | 8.34:1 | `#C077AD` | 4.97:1 | 3.0 | ✅ |
| 5 | `--mds-chart-series-5` (ochre) | `#96700A` | 4.55:1 | `#F0CB6D` | 10.33:1 | 3.0 | ✅ |
| 6 | `--mds-chart-other` (`{neutral.500}`) | `#687387` | 4.78:1 | `#838DA0` | 4.82:1 | 3.0 | ✅ |
| 7 | `--mds-chart-axis` (`{neutral.600}`, tick labels) | `#5A6478` | 5.95:1 | `#9AA5BD` | 6.51:1 | 4.5 | ✅ |
| 8 | `--mds-chart-grid` (`{neutral.200}`) | `#E3E8F3` | 1.23:1 | `#2C374C` | 1.35:1 | — | ✅ *intentionally low* |

> **Why the dark re-point is mandatory rather than a nicety — measured, not asserted.** Take the light column above and put it, unchanged, on the dark ground: series-1 3.11:1, series-2 4.35:1, series-3 **2.27:1**, series-4 **1.93:1**, series-5 3.54:1 — **two fail 1.4.11 outright and the three that pass do so with almost nothing to spare.** *(Recomputed for JR1's deeper dark ground; on the previous `#123350` ground FOUR of the five failed. The evidence weakened and the conclusion did not — a scale where two marks are illegal and three clear the bar by 0.1 is not a scale, and a re-skin that moves the ground again could push any of them back under.)* The reverse is the same story from the other end: the raw Okabe-Ito anchors these hues derive from do not survive a white ground either (`#E69F00` computes **2.25:1**, a real failure, and `#009E73` clears the bar only at 3.42:1 with no margin left), which is why the light column is darkened from them in the first place. A single token set would have to satisfy both columns at once, and the band where that is possible is too narrow to hold five mutually distinguishable marks — which is exactly §D11's claim that **five is derived, not chosen**. Two sets is not duplication; it is the only way one palette clears both grounds.

> **Hue is the primary channel, luminance the secondary, and the dash pattern is the guarantee.** The five hues are Okabe-Ito-derived and therefore separable under the common colour-vision deficiencies. Their luminances are additionally spread across the usable band (light: 3.70 · 4.55 · 5.19 · 7.09 · 8.34) so a greyscale print or an achromatopsic reader still has *something* — but the spread cannot be made large, which is the same fact that caps the scale at five. That is why §D12 requires a redundant non-colour channel outright: `--mds-chart-pattern-1…5` is applied per series index, series 1 is solid, and the legend swatch draws its own pattern rather than a plain colour chip. Colour is never load-bearing alone.

> **Two properties enforced by test, not by this table.** `theme-overrides.test.ts` recomputes every row above from `tokens/chart.json` and the CSS on each run, so a future edit that darkens a series past its floor fails the suite rather than the eye. The same file asserts that **no `[data-accent]` rule touches a `--mds-chart-*` token** — the pre-existing accent-scope guard tested only the five `--mds-color-*` families and would have let a recoloured data series through. A data series encodes meaning: two colleagues reading one screenshot must see the same series in the same colour, which is the Moss-and-Brass argument one layer further out.

> **The bug this table exists to prevent.** Until G11 the accent shipped as a four-declaration stub with no dark variant. Because `[data-accent]` carries the same specificity as `[data-theme-mode='dark']` and appeared *later* in source order, teal won in dark mode and painted the light `#1B5E5E` on the then-`#123350` ground: **1.74:1** for both `action-primary-fg` and the focus ring — failing 1.4.3 and 1.4.11 by a wide margin. It went unnoticed for two increments because nothing in the product could set `data-accent` and no test had ever set it either. The Playwright `personalization-axe` spec and the `TealDark` Storybook story now both exercise it.

> **Two observed findings, one now CLOSED.** (a) Blueprint's *dark* `action-primary-bg-active` (`primary-300`, `#7DA9C4`) yielded only **2.52:1** with white text — pre-existing, unrelated to G11, and invisible to axe because `assertClean` parks the pointer and axe never measures `:active`. It was recorded rather than fixed to keep G11's diff to its own subject; **H21d1 fixed the CSS and JR1's re-point settles it at `primary-800`, 9.81:1.** Kept here because the finding outlived two increments purely on the strength of "axe never measures `:active`", which is still true of every other pressed state in the system. (b) For teal specifically, "lighter on press" is mathematically unreachable — the lightest teal step still clearing 4.5:1 against white *is* the hover step — so dark-mode teal inverts direction and darkens on press (`teal-700`, 9.40:1). That divergence from Blueprint's ramp is exceptions-log #6.

> **Note (correction):** the rows above are labeled "white-surface background" because that is what they are actually computed against — `--mds-color-bg-surface` (`--mds-neutral-0`, pure white), the background body text most commonly sits on (cards, panels, table rows, per the §2.2 semantic alias table). An earlier version of this table mislabeled them "paper background," which is a *different*, distinctly-valued token (`--mds-color-bg-canvas` / `--mds-neutral-50` / `#F5F7FC`) used for the page canvas behind those surfaces. For the record, text directly on the Paper canvas (rather than a white surface) is very slightly lower-contrast but still comfortably passes — Ink on Paper computes to **14.84:1**, not 15.90:1 — so no pairing in this document is actually at risk; only the label was wrong. **The one place the distinction is load-bearing rather than pedantic is `action-primary-fg`**, where the surface/canvas gap is what separates a pass from a fail (see §2.2).

- **Warning/amber is a deliberate exception to "text-on-fill is always white."** `--mds-warning-500` fill pairs with `--mds-neutral-900`/Ink text (not white), because no amber saturation dark enough to hit 4.5:1 with white text remains recognizably "amber" rather than reading as brown/orange — pairing light amber with dark text is the correct, common resolution and is applied consistently at every warning-fill use site (buttons, badges, banners). The same logic produced a slightly darker dedicated `warning-700` step specifically for small warning *text* (as opposed to a warning *fill*), since the 500 fill color itself falls short of 4.5:1 against a white/light surface.
- **Success at the 500 step is a modest but passing margin** (4.61:1 white-on-fill after JR1) — callers needing small-text success messaging should prefer the `success-600` text token above rather than setting text color to `success-500` directly.
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
  - **As-built amendment (Increment J1a): the designated initial-focus target is BUILT** as `MdsModal`'s `initialFocus` prop — a CSS selector resolved inside the panel. Two corrections to how the sentence above reads against the code. **(1)** The default is not "the first focusable element" in any meaningful sense: `focusable()` walks the panel in DOM order and the header precedes the body, so it is always the **close (X) affordance** — which §3.6 enumerates as a *different* element from the cancel button this bullet names. The safety property matches (a stray `Enter` dismisses rather than confirms); the control does not. **(2)** A designated target that cannot take focus — an invalid selector, a disabled or non-rendered element — must fall back, and the component verifies this **after** focusing rather than trusting the lookup. That is not defensiveness: the page is already `inert` by then and the keydown handler is bound to the panel, so a silent no-op leaves focus on `document.body` with Escape and the Tab trap both unreachable — a keyboard trap (WCAG 2.1.2) reached through the very prop that serves this bullet.
  - **As-built amendment (Increment I10a): the Tab cycle is not the trap — `inert` is.** The keydown cycle above is bound to the panel, so it only ever sees Tab events whose target is already inside the dialog; anything that puts focus outside (a browser-chrome round trip, a screen reader's virtual cursor, a programmatic `focus()` from the background page) walks straight past it. From I10a `MdsModal` additionally marks every non-ancestor sibling of the dialog `inert` while it is open, which removes the background from focus order **and** from the accessibility tree — the guarantee `<dialog>`'s native modal mode gives, and the one WCAG 2.4.3 actually asks for. Three consequences worth knowing: (a) the walk is an **ancestor-sibling** walk, never "every `<body>` child except the dialog", because with `teleport: false` the naive rule would inert the Storybook/test root and hand axe an empty accessibility tree to scan; (b) `inert` is **released before** focus is restored to the opener, since `focus()` inside an inert subtree is a silent no-op; (c) a portal that must stay reachable across an open modal opts out with `data-mds-inert-exempt` — `MdsToastHost` (§3.7) is the only such element today, and it is exempt for the same reason it is teleported outside the modal in the first place. Stacked modals are handled as a stack: only the topmost dialog's background is inert, so a lower dialog is background too, matching native `<dialog>`. **The stack also assigns paint order, and that half is not cosmetic** — every backdrop shares one `z-index`, and a teleported modal's position in `<body>` is frozen at *mount* time by template declaration order rather than by open order, so without an explicit raise the walk can inert the dialog the user is looking at and leave the invisible one live (found by adversarial review, on `Builder.vue`'s always-mounted `ConflictDialog` opening over the Share dialog). Two further consequences, stated rather than left to be discovered: **every `aria-live` region outside the dialog stops announcing while a modal is open**, and nothing replays on close — native `<dialog>` behaves the same way, and a surface that must keep talking across a modal has to be a body-level portal carrying the exemption attribute; and focus is deliberately **not** returned to the opener when a *lower* modal closes while an upper one is still open, because focus must not jump to a control sitting behind an open dialog. See `packages/design-system/src/components/Modal/inert-stack.ts`.
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

- **Components carry their own responsive behavior; pages never add breakpoint logic.** The data table (§3.3) collapses to a stacked card-per-row presentation (each row's cells become labeled key/value pairs in a single card, per the Cards component styling, §3.5) automatically, driven by the component's own internal breakpoint awareness — a page using the table does not write a separate mobile layout for its data.

  > ⚠️ **AMENDED BY JR4 (2026-08-13): THE TABLE COLLAPSES ON ITS OWN CONTAINER, NOT ON THE VIEWPORT, AND IT IS THE ONE DOCUMENTED EXCEPTION TO THE TOKENIZED BANDS ABOVE.** The rule as written said "at the mobile breakpoint", and on this shell a viewport breakpoint is the wrong question: the sidebar is 240px above 1024px and 64px at or below it, so a tenant page's content box is **896px at a 1024px viewport and 721px at 1025px** — narrower on the wider screen. A `max-width` media query therefore fires its densest layout in the box with the least room, and the measured cost was that every table between 481 and 1024px was a sideways-scrolling strip. `MdsDataTable` now carries `container-type: inline-size` and collapses below **56em of container**, gated on a column count of three or more so a two-column table inside a ~300px card is left alone. 56em is derived, not chosen: 896px is the widest content box that can exist while the sidebar is still a rail, which is exactly what makes the switch continuous across the 1024→1025 swap. It is stated in `em` rather than `px` so it scales with §2.9's font-size axis. Logged in `exceptions-log.md`.
  >
  > The collapsed layout also hides the header row, so the sortable-column toggles are re-rendered as a chip row above the cards — `display: none` on both sides, never a visually-hidden duplicate, because a clipped control is still a focus stop the user cannot see (WCAG 2.4.7).
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
- **Glyphs added in I1** (the Share surface, PRD Feature #3): **`share`** — three nodes joined by two
  edges, the conventional share mark drawn as a graph so it survives 16px where a filled arrow blots;
  **`link`** — two chain links at 45°; **`qr`** — a QR reduced to its three finder patterns plus two
  data modules. Two notes on why each is its own glyph rather than a reuse. `share` is not
  `external-link`: that mark means "this navigates away", and the Share toolbar button opens a modal
  that goes nowhere. `link` is not `copy`: the Share panel puts a copy-the-link control and a
  copy-the-embed-code control on screen **at the same time**, and two identical glyphs on two
  adjacent controls that do different things is the defect. `qr` is deliberately **not** a literal
  code — at 24×24 real modules alias into grey mush, so it draws the part of a QR a person actually
  recognises.

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
