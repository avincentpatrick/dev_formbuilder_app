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

> ⚠️ **AMENDED 2026-08-14 (J3b) — THE "INTENTIONALLY NARROWER (~400px)" RATIONALE NO LONGER DESCRIBES TWO
> OF THE NINE CONSUMERS, AND A STALE ENTRY IS THE FAILURE MODE #6 ALREADY RECORDS.** `AuthLayout` now
> takes a `variant` prop. `card` remains everything above and is still the default, which is why seven
> consumers needed no edit at all. `split` adds a value panel beside the card and is used by **Login and
> Register only** — a user decision of record (2026-08-13). See **#14** for that variant's own
> derivation, cost and scope.
>
> Two corrections to the text above while it is being read: the consumer list is **nine**, not the seven
> named ("sign in, register, forgot/reset password, confirm password, 2FA challenge, verify email,
> invitation accept" omits `auth/TwoFactorRequired.vue`); and the claim of "no tenant/form branding" has
> been false since the invitation page began passing a tenant name into the title.

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

> ⚠️ **AMENDED 2026-08-14 (J3b) — THE EXCLUSIVITY CLAIM ABOVE WAS ALREADY FALSE IN TWO WAYS BEFORE J3b
> TOUCHED IT, AND ONLY ONE OF THEM IS J3b's DOING.** The sentence "the only surface in the product that
> consumes `--mds-type-display`, `--mds-space-16` and `--mds-space-24`" was wrong when it was written:
>
> · **`--mds-space-16` is used inside the authenticated app shell** — `TopNav.vue` reads it as the nav's
>   64px height, with DSR §3.4 explicitly sanctioning that, and `NotificationBell.vue` positions its panel
>   from it. So the token was never exclusive to this page.
> · **`--mds-space-24` is not used by `Welcome.vue` at all.** Nor is it used anywhere else; grep returns
>   only the comment in that file claiming it. `--mds-space-20` has **zero** references repo-wide. The
>   marketing-scale spacing this page really uses is `--mds-space-16` and `--mds-space-10`.
>
> What genuinely was exclusive to `Welcome.vue` is the **`--mds-type-display-*` role**, and J3b's split
> auth panel (**#14**) is now its second consumer. That is a deliberate, in-policy use rather than a
> breach: DSR §2.9 scopes the role to "onboarding/marketing hero text only — **never inside the
> authenticated app shell**", and an unauthenticated front door is not the authenticated app shell.
>
> ⚠️ The disposition below ("if a second marketing page ever appears the two should share a real layout
> component") is deliberately **not** actioned. The auth panel is not a second marketing *page*; it is a
> panel inside an existing layout that already had an exception of its own. Extracting a shared component
> for one heading and three list items would be the invention DSR §2.2 warns against. Revisit if a third
> surface wants the display role.

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

## #13 — The form builder switches panes on its container, and takes a control the builder was refused (`resources/js/Pages/forms/Builder.vue`)

**The rules being excepted.** Two. §2.7 names three breakpoint tokens (`mobile-max` 480px, `tablet-max`
1024px, `desktop-min` 1025px) and §6 says the library implements the bands mobile-first with `min-width`
media queries — the builder's compact layout is a **`@container (max-width: 60em)`** query on a container
the page establishes on `.builder` itself. And §6's *"components carry their own responsive behaviour;
pages never add breakpoint logic"* — this is a **page**, because the thing being switched is a three-pane
workspace that no component owns.

**Why (measured, JR5).** The rule replaced was `@media (max-width: 1024px)`, and it was **not broken**:
nothing was hidden, nothing was off-screen, nothing scrolled sideways. It was wrong twice over.
**Ergonomically**, it stacked all three panes into one scrolling column, so reaching the canvas at 375px
meant scrolling past roughly thirty-one palette buttons and reaching the config panel meant scrolling past
the whole canvas. **Arithmetically**, 1024 is not a width this page ever has. The builder is the app's only
fluid page (`AppLayout.vue`'s `FLUID_PAGES`), so its box is `viewport − sidebar`, and the sidebar is 240px
above 1024px and a 64px rail at or below it — the box is therefore **785px at a 1025px viewport and 960px
at a 1024px one**, the same inversion #12 documents, arriving on the builder. The three-column grid stayed
on above 1024, so across **1025–1200 the canvas track was `785 − 260 − 340 = 185px`**. Playwright's
projects are 375 / 834 / 1440, so **no gate in this repo has ever rendered that state**.

**Why 60em rather than a token.** 960px is the answer to two independent questions and they agree. It is
`260 (palette) + 340 (config) + 360 (the smallest canvas worth having)`. It is
also `1024 − 64`: the widest box that can exist while the sidebar is still a rail — so, exactly as #12's 896
does, it makes the transition **continuous across the sidebar swap**: compact at 1024, compact at 1025,
three panes from 1201 up. **The inclusivity of `max-width` is load-bearing.** At `59.9375em` the 1024px
viewport (box exactly 960) would flip to the WIDE layout while 1025px (box 785) stayed compact —
reintroducing the inversion from the other side. `em` rather than `px` because §2.9's font-size axis scales
the type but not a px literal; the threshold resolves to **960 / 1080 / 1200px** across the three scales,
which is pinned by declaring `font-size: var(--mds-type-body-lg-font-size)` on the container (`app.css`
already puts that value on `body`, so it is a no-op today and a guarantee afterwards). A `var()` is not
available: container-query conditions cannot read custom properties — the same dead end #12 records, and the
same reason the three §2.7 tokens have never had a single reference anywhere in the stylesheets.

**Why `container-type` is on `.builder` and not on `.builder__panes`.** A container query never matches its
own container, and the compact layout has to rewrite `.builder__panes`'s `grid-template-columns` — declare
it there and that one declaration is unreachable (`DataTable.vue`, verbatim). The toolbar compaction is
keyed on the same threshold and the toolbar is a *sibling* of `.builder__panes`, so `.builder` is the only
element that contains both. **`contain: layout` was audited before it was declared**, since `container-type`
implies it and it makes the element a containing block for `position: fixed` *and* `position: absolute`
descendants and opens a new stacking context: there is no `position: fixed` anywhere in the builder tree,
the one `position: absolute` (`BuilderCanvas.vue`'s `.canvas__sr`) is the sr-only clip pattern with every
offset `auto`, and all six dialogs on the page are `MdsModal`, which `<Teleport to="body">`s outside
`.builder` entirely. Block size is not contained, so the `height: 100%` chain still resolves.

**Two live defects fixed en route, because this increment would otherwise have inherited both.**

1. `.builder` was `grid-template-rows: auto 1fr`, which assumes two children; with an import- or
   publish-warnings banner up there are three, so the **banner** took the `1fr` row — sunken background and
   border, visibly filling the page — while `.builder__panes` fell into an implicit `auto` row. The old
   linearized panes were `height: auto` and survived it. A single-pane workspace does not, so `.builder` is
   a column flex container now, which is correct for any number of banners.
2. **`MdsSegmentedControl` had no `position` on its fieldset while its `<legend>` and its radio `<input>`s
   are the `position: absolute` + `clip: rect(0 0 0 0)` visually-hidden pattern** — so their containing
   block resolved outside the control and no scroll container between it and the document could clip them.
   Measured by this increment's sweep: at 375px with the config pane selected, the group's 1px hidden
   legend sat **73px below** a workspace that is `height: 100%` from `<body>` down, and **the page gained
   73px of real vertical scroll** — directly falsifying this layout's central claim that the shown pane
   scrolls inside itself. **This is the second time the repo has hit this exact bug**: G11 found it
   horizontally on `MdsDataTable`, whose `position: relative` carries a comment saying it is "a latent bug
   in this component". It was latent in `MdsSegmentedControl` too, in every consumer, and only became
   visible once an ancestor finally tried to clip. Fixed in the component with the same one line, and
   pinned by a new `SegmentedControl.test.ts` — the component had no unit test until now (six Storybook stories already ran under the merge-blocking axe gate). **No gate here
   can execute the check**: the e2e overflow assertion reads `documentElement.scrollWidth`, which
   `.app-shell { overflow-x: clip }` pins flat, no GATE asserts on document `scrollHeight`, happy-dom lays nothing out,
   and axe has no rule for a hidden node extending the page.

**Scope of the exception.** One page, one query, one toolbar. §2.7's three tokens remain the contract for
everything genuinely keyed on the window — the sidebar's three states, the shell padding, the modal's
full-screen sheet — and none of those moved.

**Known costs, stated rather than discovered later.** Five.

1. **Nothing in this repo can execute a container query.** The threshold and its reasoning are pinned as
   **source-text assertions** in `resources/js/Pages/forms/builder-layout.test.ts`, and the layout itself by
   a case in `tests/e2e/list-layout.spec.ts` that counts the panes which actually have a box. **There are no
   Storybook stories, deliberately** — unlike #12, whose collapse lived in a component whose scoped CSS
   Storybook loads. These rules live in a page's scoped `<style>`, which Storybook cannot reach without
   importing the page, and a hand-built composition story would exercise a *copy* of the CSS: green while
   the page regressed.
2. **A whole-page axe scan below the threshold covers one pane instead of three.** `builder-axe.spec.ts` and
   `personalization-axe.spec.ts` now walk the panes and scan each. That recovers the per-pane coverage but
   **not the composed state** — heading order across panes, landmark uniqueness, and one pane's text against
   another's surface were all previously measured with the three panes on screen together, and three
   separate scans do not reproduce it. That loss is real and irreducible at these widths.
3. **Selecting a field in the canvas does not bring the config panel with it.** At compact widths the author
   taps the field, then taps *Settings*. An auto-switch is the right product answer and is filed to
   `docs/feature-backlog.md`; it is not here because the page auto-selects a field on mount, so the watcher
   would fire on load and override the default pane, and because pane state driven by store events reorders
   what eight e2e sequences see in a suite that cannot run on the development host.

   ⚠️ **THE ONE EXCEPTION TO THAT, AND AN ADVERSARIAL PASS IS WHAT FOUND IT.** `saveError` is rendered in
   exactly **one** place in the entire client — `ConfigPanel.vue`'s `<p v-if="saveError" role="alert">` —
   which lives inside the config pane. So a write that failed while the author was on *Add* or *Form* would
   have mounted that alert inside a `display: none` subtree: not painted, not in the accessibility tree,
   never announced. The replaced rule only linearized, so the alert was reachable at every width before this
   increment — **the silence would have been new**, and new on exactly the paths most likely to fail
   (deleting or duplicating a field, adding a section, inserting from the library, undo/redo). A
   `watch(saveError, …)` that pulls the config pane on screen is therefore shipped, and it is acceptable
   where the `selection` watcher was not for two reasons: `saveError` starts null so nothing fires on
   mount, and above the threshold all three panes are shown so it is a no-op. **WCAG 3.3.1 / 4.1.3.**

   **What is *not* fixed here, because it is genuinely older than this increment:** `.builder__save`
   (`role="status" aria-live="polite"`) is driven by `saving = pending.count > 0`, and `guard()` catches
   the throw, so the count returns to zero on the failure path and the page politely announces *"All
   changes saved"* at the moment the write failed — at every width, on `phase1-completion` today. Filed to
   `docs/feature-backlog.md` rather than folded in, because inferring success from "nothing in flight" is a
   store defect, not a layout one.
4. **At `extra_large` the threshold is 1200px of container, so a 1440px desktop is compact.** That is the
   `em` choice working as designed — the 260/340 pane columns are px literals that do not grow with the type
   — but it means any test helper keyed on `info.project.name` rather than on what is actually on screen
   would be wrong at the desktop project, and only there. `showBuilderPane()` asks the page.
5. **`MdsSegmentedControl` has no wrap and no overflow handling**, and `.app-shell { overflow-x: clip }`
   means a switcher that does not fit would spill invisibly. The three labels measure ~272px of 351px at
   375px under `extra_large` + OpenDyslexic; `personalization-axe.spec.ts` asserts the element's own
   `scrollWidth` rather than trusting that arithmetic.

**Disposition:** accepted. If the sidebar widths, the pane widths, or `body`'s font-size token ever change,
re-derive 60em from the same two edges rather than adjusting it by eye.

---

## #14 — The split-panel auth layout on the two front doors (`resources/js/Layouts/AuthLayout.vue`)

**Introduced:** Phase 1 completion · Increment J3b (the auth design vertical).

**The rules being excepted.** Two. DSR §3.0 defines exactly two shells and permits no third variant
without a documented exception — already covered for this file by **#1**, which this entry extends rather
than replaces. And DSR §2.9 reserves `--mds-type-display-*` for "onboarding/marketing hero text only",
which **#8** recorded as belonging to `Welcome.vue` alone; the panel's headline is its second consumer.

**What deviates.** `AuthLayout` gains `variant?: 'card' | 'split'`, defaulting to `card`. The `split`
variant renders a value panel — one display-type headline and three icon-led proof points, compressed
from `Welcome.vue`'s own `capabilities` so the landing page and the front door cannot describe the
product differently — beside the existing card. It is used by **Login and Register only**, a user
decision of record (2026-08-13): the seven utility pages keep the card, because a one-field "check your
email" page inside a two-column marketing frame reads as a mistake rather than as a brand.

**Why a container query rather than a media query.** The threshold is `@container (min-width: 54em)` on
`.auth`, which sets `container-type: inline-size` and pins `font-size: var(--mds-type-body-lg-font-size)`.
A media query's `em` resolves against the ROOT font size, which `[data-font-size]` never moves — it
re-points the `--mds-type-*` tokens instead — so a media query would be correct at exactly one of the
three type scales. A container query's `em` resolves against the container's own font size, so the
threshold grows with the text it protects. The pin is a no-op today (`resources/css/app.css` already sets
that value on `body`), which is the point: it is a guarantee against a future edit to that rule.

The precedent is **#13**, which made the same argument for the builder, and **#12** for `MdsDataTable`.

**⚠️ 54em is a CONTAINER width and the first draft of the code comment gave it as a viewport width.**
A container query measures the CONTENT box, so `.auth`'s 2 × `--mds-space-6` of padding is outside it:
54em = 864px of container is reached at a **912px viewport**. Measured on the running app — viewport 911
is the card and 912 is the split, with the container reading 863 and 864 respectively. **#12** records
JR3 paying for precisely this on `MdsDataTable` (a 260px threshold written in the belief it would never
fire, firing on every 300px card); it has now cost two increments, which is why it is in the log twice.

The number itself is derived from what must fit rather than from a device: a 400px card + a
`--mds-space-8` gap + a 26rem panel is 848px, and 864 clears it with the slack a shrinking flex item
needs.

**⚠️ The layout deliberately does NOT take `overflow-x: clip`, diverging from `AppLayout`.** That is the
opposite of a tidy-up and it is the entry's most reversible-looking decision. `assertClean` measures
`document.documentElement.scrollWidth`, and **#12** and **#13** both record that `.app-shell`'s clip pins
that flat — so on every authenticated page the assertion can no longer fail. The auth pages are the one
place in the product where it still measures something real, and J3b added four more scans that depend on
it. Clipping here would hide exactly the regressions those scans exist to catch. The layout is required
to genuinely not overflow instead, which the panel's `display: none` below the threshold provides.

**Three constraints that are not design choices.**

- **Exactly one `<h1>`.** `responsive-axe.spec.ts` settles on `getByRole('heading', { level: 1 })` and the
  card's title already is one, so the panel's headline is a `<p>` carrying the display role.
- **No control in the panel whose accessible name contains "Sign in".** `global-setup.ts` locates the
  submit button with a NON-exact `getByRole('button', { name: 'Sign in' })`, twice, before any spec runs.
  A second match is a strict-mode violation in global setup — not a handful of failures but the whole
  suite with none executed. The panel therefore carries no link and no button at all.
- **The panel is an `<aside>`**, so its content sits inside a landmark and axe's `region` rule is
  satisfied without a second `<main>`.

**What was migrated rather than restyled.** The layout's second, non-scoped `<style>` block exported five
global classes to all nine consumers. Two are gone, replaced by real components: `.auth-remember` →
`MdsCheckbox` (its only consumer was Login's raw 16px checkbox, which had neither the 44px touch target
nor a non-colour state signifier) and `.auth-alert`/`.auth-alert--error` → `MdsBanner` across its three
consumers. Three remain global — `.auth-form`, `.auth-note`, `.auth-links`.

**⚠️ `:slotted()` is the obvious way to scope those three and it does not work here**, which is worth
recording so it is not re-attempted: it applies to slot-content ROOT nodes only, and
`auth/VerifyEmail.vue` nests a `.auth-note` inside a `.auth-form`. Covering every nesting a page might
invent needs descendant chains more fragile than the leak, and the leak is bounded — the file is
chunk-loaded by those nine pages and nothing else.

**Known costs, stated rather than discovered later.**

- **Nothing in this repository can execute a container query.** happy-dom computes no layout and Vitest
  never lays out CSS, so the threshold is held by the e2e scans and by the measurement above, not by a
  unit test. Same cost **#12** and **#13** each record.
- **The panel is invisible to CI's accessibility gate below 912px and only there.** `auth-axe.spec.ts`
  runs at the suite's three viewports; the desktop project is 1440px, so the split IS scanned — but a
  regression that only appears between 912px and 1440px would be seen by no automated check.
- **`AuthLayout` still hard-codes the wordmark "Meridian"** while `Welcome.vue` takes `appName` from
  config. Pre-existing, untouched here: threading a prop through seven Fortify view closures and two
  controllers for one string is not this increment's work, and doing it halfway would be worse.

**Scope of the exception.** One layout, one variant, two pages. The three breakpoint tokens remain the
contract for everything genuinely keyed on the window, and no other surface may adopt the display role
without amending **#8** again.

---

## #15 — Google's four-colour mark, as local markup outside the icon set (`resources/js/components/auth/GoogleMark.vue`)

**Introduced:** Phase 1 completion · Increment J3c2 (first-party Google sign-in, ADR-0019).

**The rules being excepted.** Two, and the first is written into the icon set itself.
`packages/design-system/src/components/Icon/icons.ts` opens by declaring the set "hand-authored line-art
glyphs (24×24, stroke-based, no external icon dependency so the embeddable guest runtime stays
request-free)", and its `plug` glyph carries the standing decision in as many words: *"Deliberately
generic rather than a vendor mark — the set is hand-authored line art, and a brand logo would be neither
line art nor ours."* And DSR §2.1 makes the token palette the single source of colour; this component
hard-codes four hexes.

**What deviates.** A local SFC rendering Google's "G" as four filled paths — `#4285F4`, `#34A853`,
`#FBBC05`, `#EA4335` — passed through `MdsButton`'s default slot on the "Continue with Google" control.
`icons.ts` is **not amended** and `IconName` never gains a `google` key.

**Why it cannot be an `MdsIcon`, structurally rather than by preference.** Every value in `icons` is a
SINGLE path `d` string, and `Icon.vue` renders it `fill: none; stroke: currentColor`. A four-colour
filled mark is not expressible in that model at all — it is four paths with four fills and no stroke. The
only way to make `iconLeft="google"` typecheck would be to widen both the data shape and the renderer,
for one glyph, in a package the guest runtime also ships.

**Why the hexes are raw literals, which is the sharper half of this entry.** They are Google's brand
colours, not this product's. A token would promise three things that must all be false here: that the
value follows the theme, that it responds to the tenant's accent ramp, and that light and dark differ.
Google's Branding Guidelines require the mark to be reproduced exactly, so the correct behaviour is to be
*inert* to everything the token system exists to vary. `token-references.test.ts` is unaffected — it
asserts that every `var(--mds-*)` reference RESOLVES, and this file makes none.

**Rejected alternatives.**

- **No mark at all.** Cheapest, and it becomes a compliance problem the day real credentials arrive:
  Google's guidelines require the G on the button.
- **A `brand/` icon set beside the line-art one.** It makes `MdsIcon` polymorphic over two rendering
  models — `stroke: currentColor` line art and multi-fill vendor artwork — so every consumer's mental
  model of what an icon *is* would depend on which set the name came from.
- **An `<img>` or a data URI.** Both defeat the set's own "no external dependency, request-free" premise
  or bloat the bundle with a base64 blob that no reviewer can read in a diff.

**Known costs, stated rather than discovered later.**

- **The mark does not respond to the theme, and that is the whole point** — so on a future surface with a
  saturated background it may need a white plate behind it. Nothing in the repo enforces contrast for it,
  because axe cannot judge decorative `aria-hidden` artwork.
- **A second provider means a second file like this**, not a shared abstraction. That is deliberate at
  n=1; ADR-0019 §D1 names the same threshold for the `google_id` column, so both would be revisited by
  the same trigger.
- **The label is a CI constraint, not a design choice.** It must read "Continue with Google" and may
  never contain "Sign in": `tests/e2e/global-setup.ts` locates the submit control with a NON-exact
  `getByRole('button', { name: 'Sign in' })`, twice, before any spec runs, so a second substring match is
  a strict-mode violation that fails global setup and takes the whole suite with it. **#14** records the
  identical constraint for the marketing panel. A Vitest case asserts the accessible name does not match
  `/sign in/i`, which is that failure caught in five milliseconds instead of a CI cycle.

**Scope of the exception.** One component, one glyph, one consumer. The icon set's line-art rule is
unamended and still binds everything else.

---

