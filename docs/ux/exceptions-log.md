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
