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

**Related observation, deliberately not fixed here:** Blueprint's own dark `bg-active` (`primary-300`)
measures **2.52:1** with white text — a pre-existing latent failure, invisible to CI because
`assertClean` parks the pointer and axe never evaluates `:active`. It predates G11 and is unrelated to
personalization; fixing it means re-deriving Blueprint's dark press step and re-verifying every
component that uses it, which is its own change. Recorded here so it is not rediscovered as new.

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
