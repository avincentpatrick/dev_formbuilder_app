# Design System — Exceptions Log

Numbered record of deliberate deviations from `design-system-reference.md`. Per DSR §1.3, any
component/layout that does not use the shared system as-specified must be logged here with what
wasn't used, why, and its intended disposition. Three-plus undocumented deviations for the same
need signal a missing shared component, not acceptable practice.

---

## #1 — Slim auth-card layout (`resources/js/Layouts/AuthLayout.vue`)

**Introduced:** Phase 0 · Increment C1 (design-system foundation + styled auth flow).

**What deviates:** DSR §3.0 defines exactly two shells — the Authenticated App Shell and the
Public/Guest Runtime Shell — and states "no third variant without a documented exception." The
pre-auth pages (sign in, register, forgot/reset password, confirm password, 2FA challenge, verify
email, invitation accept) use a third, narrower layout: a single centered card on the canvas, with
no top nav, no sidebar, and no tenant/form branding.

**Why:** These are pre-authentication guest surfaces. They have no authenticated user, no tenant
context, no navigation targets, and are intentionally narrower (~400px) than both the admin shell
and the form-runtime shell. Forcing them into either named shell would add empty chrome (a nav with
nothing to navigate to) or the wrong width.

**Disposition:** Classified as a **slim member of the Public/Guest Runtime Shell family**, not a new
top-level shell. It is built entirely from shared design-system tokens (no one-off colors, spacing,
radius, or type). When the public form-runtime shell is built (Phase 1+), this auth layout should be
folded into a shared guest-shell primitive rather than remaining a separate app-level component.
It lives in the app (`resources/js/Layouts/`) rather than the package for now because it is
Inertia-specific and the public-runtime SPA that would share it does not yet exist.

---

## #2 — Icon system (`packages/design-system/src/components/Icon/`)

**Introduced:** Phase 0 · Increment C2 (app shell).

**What deviates:** The DSR references icons throughout (sidebar, bell, chevron, sun/moon/monitor,
etc.) but defines no icon component, no named icon library, and no sizing/`aria-hidden` conventions.
C2 needs icons for the shell, so it introduces one.

**Decision (now documented in DSR "Appendix B: Icon System"):** a single `MdsIcon` component backed by
an internal, hand-authored inline-SVG registry (`icons.ts`) — no external icon dependency, so the
embeddable guest runtime stays request-free. Glyphs are 24×24 line-art (`fill:none`,
`stroke:currentColor`, `stroke-width 1.5`). Sizes `sm`=16 / `md`=20 / `lg`=24. Color inherits
`currentColor` (so an icon matches its surrounding control's text color; standalone icons set a
color, default `--mds-neutral-500`). **A11y:** decorative by default (`aria-hidden` + `focusable="false"`);
icon-only controls carry `aria-label` on the *control*, not the icon; a rare standalone meaningful
icon passes `label` → `role="img"`.

**Disposition:** the C2 registry ships exactly the glyphs the shell/dashboard/theme-toggle need;
extend it as features require. Provenance: geometry is hand-authored for this project (not copied from
a licensed set).

---

## #3 — Theme quick-toggle in the top nav (`resources/js/components/shell/ThemeQuickToggle.vue`)

**Introduced:** Phase 0 · Increment C2 (app shell), at the user's explicit request.

**What deviates:** DSR §2.9 / Feature #9 place the theme (Light/Dark/Match System) control in
**Settings → Appearance**, and the single-server-set-root-attribute mechanism discourages a
client-side quick flip. The top nav's specified contents (§3.4) don't include a theme toggle.

**Why:** during the C1 demo the user could not easily see dark mode (guests follow the OS setting).
A one-click toggle in the app bar directly serves that need.

**Disposition:** the canonical home remains Settings → Appearance; the quick toggle is an *additional*
surface writing the same `user_ui_preferences.theme_mode` row via the same endpoint. It applies the
change optimistically client-side (set/remove `data-theme-mode` on `<html>`) and persists server-side,
so the durable source of truth (the blade root-attribute emission on next full load) is unchanged.

---

## #4 — Central-domain admin console shell (`resources/js/Layouts/AdminLayout.vue`)

**Introduced:** Phase 0 · Increment C3 (data + admin + settings).

**What deviates:** Same clause as #1 — DSR §3.0 defines only two shells and forbids a third without a
documented exception. The super-admin platform console (Tenants, Users, mandatory-MFA setup — RBAC §9)
uses a distinct top-bar-only layout: a wordmark + a small admin nav (Tenants / Users) + logout, with no
tenant sidebar.

**Why:** the console is served on the **central domain** (`config('tenancy.central_domain')`), outside
any tenant context, and its navigation targets are platform-operations pages — not the tenant-oriented
sections (Dashboard / Forms / Submissions / Settings) the authenticated `AppLayout` sidebar lists.
Forcing it into the tenant shell would show a sidebar of destinations that don't apply, and the shell's
permission-aware nav + tenant theme/user props aren't meaningful on the central domain.

**Disposition:** classified as a **member of the Console-shell family**, a sibling to the AuthLayout
guest-shell (#1), not a new top-level shell. Built entirely from shared design-system tokens/components
(it renders the shared `MdsToastHost`, uses `MdsDataTable`/`MdsBadge`/`MdsModal` in its pages, and its
chrome is token-only — no one-off colors/spacing/radius/type). It lives in the app (Inertia-specific)
rather than the package. If a broader internal-tooling surface emerges (Phase 1+ support/billing
consoles), fold this into a shared console-shell primitive rather than adding a fifth layout.

---

## #5 — Self-hosted OpenDyslexic web font (`public/fonts/`, `packages/design-system/src/theme/fonts.css`)

**Introduced:** Phase 2 · Increment G11 (richer personalization).

**What deviates:** DSR §2.4 states that none of the three font roles "requires a webfont license or a
data-URI font embed — all are either pre-installed OS fonts or universally available system fallbacks,
which also keeps the public embeddable form runtime free of an extra font-loading round trip."
OpenDyslexic is the **first webfont in the system**, breaking that all-system-stack property.

**Why:** §2.9's dyslexia-friendly accommodation re-points `--mds-font-family-body` to OpenDyslexic. The
literal reading of the original §2.9 snippet — reference the family and let it fall back — was rejected
because OpenDyslexic is installed on essentially no machine, so the toggle would appear to work while
doing nothing for virtually every user. A feature that silently no-ops is worse than no feature: the
user believes the accommodation is applied.

**Why it does not actually cost anything:** a browser fetches a `@font-face` `src` only when a rule
*using* that family matches an element. The sole rule naming OpenDyslexic is
`:root[data-dyslexia-font='true']`, and the server emits that attribute only for users who opted in. A
user who never opts in parses two `@font-face` blocks and downloads **zero font bytes** — so §2.4's
property still holds for the default path, which is what it was actually protecting.

**Scoping:** the `@font-face` lives in a **new** `src/theme/fonts.css`, imported only by
`resources/css/app.css`. It is deliberately **not** in `theme-overrides.css`, because
`resources/public-runtime/public-runtime.css` imports that file — the declaration would otherwise ship
in the guest bundle, which is designed to be embedded on third-party domains and must stay free of
unexpected font references (§2.9's governing rule). Two weights only (400/700); 500 and 600 synthesise.
No `size-adjust`: the wide advances and weighted bottoms *are* the accommodation, so normalising the
metrics would defeat it — the extra width is absorbed by the shell guards in `AppLayout`/`TopNav`.

**Provenance:** OpenDyslexic **5.2.5**, latin subset, as distributed by `@fontsource/opendyslexic`.
© Abbie Gonzalez, **SIL Open Font License 1.1**, Reserved Font Name "OpenDyslexic" (not renamed).
Licence vendored verbatim at `public/fonts/OpenDyslexic-LICENSE.txt`, as OFL §2 requires it to ship
with the font.

**Disposition:** accepted as a **scoped, opt-in accommodation**, not a general licence to add webfonts.
Any future webfont needs its own entry here. No CSP change was required —
`PublicRuntimeSecurityHeaders` sets no `default-src`, so `font-src` has nothing to fall back to and is
unrestricted; `PublicRuntimeSecurityHeadersTest` now asserts the absence of `font-src` so a future
hardening PR introducing `default-src` is forced to add `font-src 'self'` in the same change.

---

## #6 — Dark-mode teal accent inverts its press direction (`packages/design-system/src/theme/theme-overrides.css`)

**Introduced:** Phase 2 · Increment G11 (richer personalization).

**What deviates:** every other ramp in the system gets *lighter* on press in dark mode — Blueprint's
dark `action-primary-bg-active` is `primary-300`, a paler step than its hover. Dark-mode **teal** goes
the other way: `bg` `teal-500` → `bg-hover` `teal-400` (lighter, as expected) → `bg-active` `teal-700`
(**darker**).

**Why:** it is not a stylistic choice, it is forced by the hue. The lightest teal step that still
clears 4.5:1 against white text is ≈`#247F7F` — which *is* the hover step. There is no lighter teal
available for the active state that keeps its label readable, so "lighter on press" is mathematically
unreachable for this hue. Darkening instead yields 9.40:1 and reads as a pressed-in surface, which is
the conventional pressed metaphor anyway.

⚠️ **JR1 (2026-08-12) made this entry's PREMISE obsolete while leaving its content correct.** The
first sentence above contrasts teal with "every other ramp in the system", citing Blueprint's dark
`bg-active` as a *paler* step. That is no longer true of anything: the Vivid re-skin's dark fills are
`primary-600 / 700 / 800`, the same triple light uses, and they move **deeper** on interaction —
because `--mds-color-text-on-primary` is `#FFFFFF` in both themes, so a lighter fill destroys the
contrast the fill exists to carry. Teal's darken-on-press is therefore no longer a deviation at all;
it is now what the default does. The entry is kept rather than deleted because the *reasoning* — that
for this hue "lighter on press" is mathematically unreachable — is still the reason teal's ramp looks
the way it does, and because deleting an exception whose subject became the rule loses the record of
how the rule was arrived at.

**Related observation, deliberately not fixed here:** ~~Blueprint's own dark `bg-active` (`primary-300`)
measures **2.52:1** with white text — a pre-existing latent failure, invisible to CI because
`assertClean` parks the pointer and axe never evaluates `:active`. It predates G11 and is unrelated to
personalization; fixing it means re-deriving Blueprint's dark press step and re-verifying every
component that uses it, which is its own change. Recorded here so it is not rediscovered as new.~~

✅ **FIXED IN H21d1 (`774b9ae`), AND THIS PARAGRAPH WAS LEFT STALE FOR THREE INCREMENTS —
CORRECTED IN I8c (2026-08-08).** `theme-overrides.css` now reads `--mds-color-action-primary-bg-active:
var(--mds-primary-700)` (**13.00:1**) in both the explicit dark block and its `prefers-color-scheme`
twin, and `theme-overrides.test.ts` asserts every `--mds-color-action-primary-bg*` declaration clears
4.5:1 against white — so the failure this paragraph described cannot return silently.

**The correction is worth more than the fix.** I8's original scope carried "Blueprint dark `bg-active`
2.52:1 fix" as a work item *because this document still said it was open*, and the increment planned
against the prose rather than the code. Reading `theme-overrides.css` took a minute and removed a task
from the increment. **A "recorded so it is not rediscovered as new" note becomes an active liability the
moment it outlives its own subject** — it is trusted precisely because it looks like diligence. If you
close something a log entry describes, close the entry in the same commit.

**Disposition:** accepted. Both directions are contrast-verified in DSR §4.1's 17-row table, and the
`TealDark` / `MaxPersonalization` Storybook stories plus the Playwright `personalization-axe` spec keep
the pairings under automated watch.

---

## #7 — Tenant brand colour is generated, not curated (`app/Support/Branding/`, `packages/design-system/src/theme/brand-ramp.ts`)

**Introduced:** Phase 3 · Increment H23a1 (tenant branding — the ramp engine). Ratified in **ADR-0014**.

**What deviates:** §2.9's closing note closes the accent set at two curated options and says, in terms,
*"never an arbitrary colour picker"*, on the ground that an open picker *"would reopen exactly the
accessibility risk the semantic-token architecture exists to close off."* Tenant branding now accepts an
arbitrary `#RRGGBB` and derives twelve tokens from it at write time.

**Why:** the rule is **narrowed, not overturned**, and the two halves serve different audiences. It still
governs *user personalization* — `AccentToken` stays closed at Blueprint and Teal, because a user choosing
an accent for themselves gains nothing from arbitrary colour. It no longer governs *tenant branding*,
which answers PRD G3 (*"custom domain/branding available"*) against Fillout-class competitors, and where a
tenant who cannot enter their own brand hex does not have branding — they have a second theme. The product
owner was brought the curated alternative, costed at roughly a third of this one, and declined it.

**What actually changes, stated without softening:** §2.9's guarantee was **structural** — no reachable
combination can be inaccessible, because every reachable combination was enumerated and pre-verified by a
human. It is now **procedural** — every stored ramp was verified by the engine at write time against all
seventeen §4.1 pairings. That is precisely the risk §2.9 was written to close, and calling it anything
else would be dishonest.

**What makes it defensible anyway:** the engine holds the tenant's hue and re-derives lightness per role,
so contrast is a property of the construction rather than of the input; it is deny-by-default over all
seventeen pairings and re-measured from the stored hexes by a second assertion in each language; a stored
ramp never re-derives on read, so what was verified is what renders. And the strongest available evidence
that its judgment matches the human one it replaces: **fed Teal's own hue it re-derives
`--mds-accent-teal-600` (`#1B5E5E`) and `--mds-accent-teal-50` (`#E6F2F2`) byte-identically**, and
reproduces §4.1 rows 1–3 to two decimals.

**Related observation, deliberately not fixed here:** *fill versus canvas* is not one of the seventeen
pairings, and looks like it should be — a button ought to be distinguishable from the page. Measuring the
shipped system before writing the engine showed **both ratified accents fail a 3:1 reading of it in dark
mode** (Blueprint 2.61, Teal 2.40). Adding the constraint would have made the engine stricter than the
design system it serves and rejected the product's own colours. Recorded because the omission looks like
an oversight and is not.

**Disposition:** accepted. Held by `tests/fixtures/brand-ramp.json` — twelve vectors generated by the
authoritative PHP engine and asserted hex-for-hex by both `BrandRampParityTest.php` and
`brand-ramp.test.ts`, with every pairing re-measured from the stored hexes in each language.

---

## #8 — Self-laid-out platform landing page (`resources/js/Pages/Welcome.vue`)

**Introduced:** Phase 1 completion · Increment I6 (demo seed + landings + testing guide).

**What deviates:** DSR §3.0 defines exactly two shells — the Authenticated App Shell and the
Public/Guest Runtime Shell — and states "no third variant without a documented exception." The platform
landing page uses neither. `resources/js/app.ts` excludes `Welcome` from `AppLayout` by name, so the page
lays itself out: a centred hero over a responsive card grid, full-bleed on the canvas. It is also the only
surface in the product that consumes `--mds-type-display`, `--mds-space-16` and `--mds-space-24`.

**Why:** It is a marketing surface on the central host, addressed to someone who has no account, no tenant
and nothing to navigate to. The Authenticated App Shell would give it a sidebar with no destinations; the
slim auth card (exception #1) is ~400px, which is narrower than a hero can work at. DSR §1.3 already names
"a marketing/pricing page with different layout needs" as a legitimate documented exception, and the three
tokens above are reserved in the DSR for exactly this class of surface ("marketing/onboarding hero text
only — never inside the authenticated app shell"). This is the first page to claim that reservation, which
is what turns it from a specification into a use.

**Disposition:** Accepted as a **marketing surface**, not a new top-level shell — nothing else may adopt it,
and if a second marketing page ever appears the two should share a real layout component rather than each
laying themselves out. It is built entirely from shared tokens (no one-off colours, spacing, radius or
type), so it follows theme mode and accent like everything else. It is covered in a real browser by
`tests/e2e/responsive-axe.spec.ts` at three viewports × two themes — the **first central-domain page in that
matrix**, which is legitimate because the standing exclusion recorded in `playwright.config.ts` is
specifically about `superadmin.mfa` needing a TOTP in CI, and `/` requires no authentication at all.

---

## #9 — Hand-rolled ARIA combobox in the command palette (`resources/js/components/shell/CommandPalette.vue`)

**Introduced:** Phase 1 completion · Increment J1d (the ⌘K command palette).

**What deviates:** DSR §4.3 says custom interactives are real semantic elements, and §1.3 says a widget
needed in more than one place belongs in the design system. The palette's result rows are `<div
role="option">` inside a `<div role="listbox">`, driven by `aria-activedescendant` — not buttons, not a
shared `MdsCombobox`, and not a roving `tabindex`. It is the first modal in the product with **no action
row**, which §3.6's "no modal ships without all four" would otherwise forbid.

**Why:** Three separate reasons, each of which the DSR itself anticipates.

The markup is not a choice. A `<button role="option">` trips axe's `nested-interactive` **and** breaks
`aria-activedescendant`, and `<li>` inside a listbox fails `aria-required-children`. §4.5's named ARIA 1.2
Combobox pattern requires DOM focus to stay on the input while the active option changes — which is the
exact opposite of §4.3's roving-`tabindex` rule for composites. DSR §3.4.1 resolves that conflict in
§4.5's favour *for this pattern*, and §4.3 keeps governing composites with no text entry. So the deviation
is a spec decision already taken, recorded here because the code looks like a violation to a reader who
has only read §4.3.

The missing action row is the same shape of argument: §3.6 was written for the destructive confirmations
this component was built for, where a primary and a cancel are the whole point. A palette has no decision
to confirm — every option IS the action — and adding a disabled "OK" would be a control that never does
anything.

It is hand-rolled rather than a primitive because the increment that owns the ~15 missing primitives
(`MdsCombobox`, `MdsTabs`, `MdsMenu`, an input-adornment wrapper) runs **after** this one. Generalising a
palette whose options are heterogeneous — forms, submissions, members, destinations, and a synthetic "see
all" row — into a reusable API from a single consumer would be inventing the API from one example. DSR
§1.3's own consolidation trigger is three-plus undocumented deviations for the same need; this is the
first, and it is documented.

> **Amendment (J2a).** **None of the four primitives named in the list above has been built** — this entry
> stands unchanged, and its retirement still waits on `MdsCombobox`.
>
> J2a did ship two *different* package components, `MdsTabNav` and `MdsBreadcrumb`, and they are noted here
> only so a reader who sees them does not mistake either for the list. ⚠️ **`MdsTabNav` is NOT the `MdsTabs`
> named above**: that means the ARIA-1.2 in-page tablist, which remains J4's. `TabNav`'s items are links
> that load a page, so it is a navigation landmark with `aria-current`; building it as a tablist would have
> removed every non-active destination from the tab sequence. DSR §3.4 carries the split. *(The first draft
> of this amendment said "two of the primitives named in that list have since been built" and then, one
> sentence later, that neither was in the list — kept visible here rather than silently rewritten, because
> a log that miscounts its own entries is the failure mode the log exists to prevent.)*
>
> Both went in `packages/design-system/src/` **because of the coverage note below** rather than for taxonomy
> reasons: an app-tree component gets no story and no `checkA11y` scan at all, and these two carry contracts
> a page-level e2e scan would never isolate — `aria-current` exactly once, and list semantics surviving
> `list-style: none`. **No new exceptions entry is owed by J2a.**

**⚠️ Coverage note, recorded because a green gate will otherwise be misread.** Storybook globs
`packages/design-system/src/**/*.stories.@(ts|tsx)` only, so this app-tree component gets **no story and
no `checkA11y` scan**. The `design-system-a11y` job passing says nothing whatsoever about this file. Its
only automated accessibility gate is `tests/e2e/command-palette.spec.ts`, and its ARIA contract — the
things axe cannot see, such as an `aria-activedescendant` that points at a non-existent id — is asserted
directly in `CommandPalette.test.ts`.

**Retire when:** the primitives increment lands `MdsCombobox`. At that point this component should become
a consumer of it, and this entry should be deleted rather than amended.

## #10 — Success and the Teal personalization accent now share a hue (`packages/design-system/tokens/primitive.json`)

**Introduced:** Phase 3 · Increment JR1 (the approved Vivid Product re-skin).

**What deviates:** §2.2's accent/semantic separation rule says a personalization accent must never
borrow a semantic hue — the stated example being that reusing Moss would let a user recolour every
primary button the same green as "success". JR1 re-hues **success** from Moss `#2F6249` to
`#0B7F76`, which sits within a few degrees of `--mds-accent-teal-600` `#1B5E5E`. A user who selects
the Teal accent now sees primary fills in the same family as success pills.

**Why it ships anyway:** the collision arrives from the direction the rule did not anticipate. The
rule governs what an *accent* may borrow; here a *semantic* hue moved onto an accent that was already
there. `#0B7F76` is part of the visual direction the user chose from four fully-rendered
alternatives, so it is a ratified product decision in the same sense the palette itself is — and the
alternative was to silently substitute a different success colour than the one they approved.

**Why it is not resolved in JR1:** the fix is to re-hue the Teal accent, and that is a user-facing
personalization change — `data-accent='teal'` is a stored preference on real accounts and the accent
is named for its colour. Making that call inside a token diff would be exactly the kind of scope
creep the JR row split exists to prevent. It also affects nobody today: no shipped surface sets
`data-accent`, so the collision is currently reachable only through the personalization settings page.

**Disposition:** accepted for now, with a named owner in the sequence. Both hues remain independently
contrast-verified in DSR §4.1 (success 4.87:1 on white; the full 17-row teal table). Revisit when the
personalization accent set is next opened — the honest options are re-hueing teal toward violet or
retiring the second accent in favour of the tenant brand ramp, which did not exist when teal was
introduced.

---

## #11 — The stat value's colour comes from a gradient, not a text token (`packages/design-system/src/components/StatTile/StatTile.vue`)

**Introduced:** Phase 3 · Increment JR2 (the Vivid component pass).

**What deviates:** §2.2 requires a component to take its text colour from a semantic text token.
`.mds-stat-tile__value` sets `color: transparent` and paints the figure with a `background-clip: text`
gradient running from `--mds-color-text-heading` into `color-mix(… 78%, --mds-color-action-primary-bg)`.
The declared colour of the largest number in the product is therefore *no colour at all*, and the
visible one is a background.

**Why it ships anyway:** it is the approved direction's signature treatment for the stat value, and
the user selected it explicitly from the four flourishes JR2 offered. Both gradient endpoints are
themselves token-derived and both clear **10:1** against their ground in both themes, so nothing about
legibility is being traded — what is being traded is the *auditability* of the rule, which is why this
entry exists rather than a comment.

**The three guards, all load-bearing:**

1. The plain `color: var(--mds-color-text-heading)` stays the **base** declaration and the gradient
   lives inside a `@supports` block that tests **both** capabilities the block actually uses:
   `((background-clip: text) or (-webkit-background-clip: text)) and (color: color-mix(in srgb, red 50%, blue))`.
   An engine missing either renders an ordinary heading-ink figure rather than nothing.

   ⚠️ **The first version of this guard tested only `background-clip` and would have shipped an
   invisible number.** `-webkit-background-clip: text` has been available since 2016; `color-mix()`
   since 2023. On every engine in that window the block opened, the `background-image` — which carries
   a `var()` and is therefore substituted late — turned out to contain an unparseable `color-mix()`,
   went **invalid at computed-value time**, and fell back to its initial value `none`; while
   `color: transparent` and `-webkit-text-fill-color: transparent`, being plain values, survived. The
   largest figure on the dashboard would have rendered as nothing at all on Safari ≤16.1, Chrome ≤110,
   Firefox ≤112 and pinned Android WebViews. **The lesson is narrow and worth carrying: an `@supports`
   condition must name every feature the guarded declarations depend on, not the one the technique is
   named after.** The sibling glow tokens legitimately need no guard, because `box-shadow`'s initial
   value is `none` and `none` is the pre-JR2 rendering — same failure mechanism, opposite consequence,
   which is exactly why the reasoning did not transfer and had to be redone rather than copied.
2. `-webkit-text-fill-color: transparent` is set alongside `color: transparent`. In WebKit that is the
   property the clipped background actually paints through; setting only `color` produces a solid figure
   on Safari and looks like the `@supports` block silently failed.
3. `@media (forced-colors: active)` restores `background-image: none` and `CanvasText`. Without it a
   Windows High Contrast user gets transparent text over a background the mode has already stripped —
   an invisible number. **This is the guard that turns the pattern from a defect into an exception.**

**Known cost, stated rather than discovered later:** axe's `color-contrast` rule cannot evaluate text
over a gradient and returns **incomplete** for this element. An incomplete does not fail
`checkA11y`, so the merge-blocking Storybook job stays green — but it also means this one figure is no
longer *automatically* contrast-checked anywhere. The endpoints are pinned by tokens the theme tests
already guard, which is the mitigation; it is not the same thing as a scan.

**Disposition:** accepted, scoped to this one element. **Do not spread the pattern** — a second
gradient-filled text node doubles the un-scannable surface for no additional signature. If the accent
mix ever changes, re-measure both endpoints against light `#FFFFFF` and dark `#1a2130` by hand,
because nothing else will.

---

## #12 — The data table collapses on its container, not on a tokenized viewport band (`packages/design-system/src/components/DataTable/DataTable.vue`)

**The rule being excepted:** §2.7 names three breakpoint tokens (`mobile-max` 480px, `tablet-max`
1024px, `desktop-min` 1025px) and §6 says the library implements the bands mobile-first with `min-width`
media queries. `MdsDataTable`'s card-per-row collapse is now a **`@container (max-width: 56em)`** query
on a query container the component establishes itself — neither a viewport query, nor one of the three
tokenized numbers, nor `min-width`-first.

**Why (measured, JR4).** The shell's sidebar is 240px above 1024px and 64px at or below it, so a tenant
page's content box is **896px at a 1024px viewport and 721px at 1025px** — *narrower on the wider
screen*. A `max-width` viewport query therefore fires its densest layout in the box with the least room,
and a `min-width` one does the same thing from the other direction. The cost of the old
`@media (max-width: 480px)` rule was concrete: across 481–1024px every table with five or more columns
(`/submissions`, `/members`, `/audit-log`, `/webhooks`, `/feedback`, `/integrations`, `/admin/feedback`)
was a sideways-scrolling strip — the component's own docblock had already called that "a latent defect
in this component rather than that page's problem". No viewport number can express the fix, because the
same viewport gives different tables different room.

**Why 56em rather than a token.** 896px is the widest content box that can exist while the sidebar is
still a rail (1024 − 64 − 64), so collapsing at exactly that width is what makes the transition
*continuous* across the sidebar swap: cards at 1024, cards at 1025, table from 1201 up. Anything smaller
reintroduces the inversion from the other side — a table that turns back into cards as the window
widens. `em` rather than `px` because §2.9's font-size axis scales the type but not a px literal; the
threshold resolves to 896 / 1008 / 1120px across the three scales. A `var()` is not available: container
and media query conditions cannot read custom properties, which is also why the three §2.7 tokens have
never had a single reference anywhere in the stylesheets.

**Scope of the exception.** One component, one query. The three breakpoint tokens remain the contract
for every layout that is genuinely keyed on the window — the sidebar's three states, the shell padding,
the modal's full-screen sheet — and none of those moved. The gate is a column count of three or more,
so a two-column table inside a ~300px chart card is never dragged in.

**Known cost, stated rather than discovered later:** nothing in this repo can execute a container query.
happy-dom lays nothing out, the Storybook axe run renders one viewport, and the e2e overflow assertion
reads a `documentElement.scrollWidth` that `.app-shell { overflow-x: clip }` pins flat. The threshold and
its reasoning are therefore pinned as **source-text assertions** in `DataTable.test.ts`, the collapsed
layout gets three Storybook stories at a narrow container so axe sees it at all, and
`tests/e2e/list-layout.spec.ts` measures the scroll wrapper's own box rather than the document's.

**Disposition:** accepted. If the shell's sidebar widths or the content padding ever change, re-derive
the threshold from the same two edges rather than adjusting it by eye.

---
