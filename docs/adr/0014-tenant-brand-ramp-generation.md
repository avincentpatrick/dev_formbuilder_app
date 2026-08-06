# ADR-0014: Tenant Brand Colour as a Generated Ramp (an open picker that snaps, not a curated set)

## Status

**Accepted — 2026-08-05.** Authored inside its own code increment (**H23a1**), on the ADR-0012/H22a and ADR-0013/H25 precedent. An ADR rather than prose because it **reverses a ratified decision**: `docs/ux/design-system-reference.md` §2.9's closing note closes the accent set at two curated options and says, in terms, *"never an arbitrary colour picker"* — and that sentence is load-bearing, not decorative. Overturning it belongs in a numbered record.

- **Deciders:** Product owner (the fork was brought as three costed options and answered on 2026-08-05); founding engineering (architecture owner).
- **Supersedes in part:** **design-system-reference.md §2.9's accent-set decision**, narrowed rather than deleted — see §D1. The curated set still governs **user personalization**; it no longer governs **tenant branding**.
- **Related ADRs:** **ADR-0008** (entitlement + metering) — §D7 places `branding` at Starter+, which this increment does not revisit. **ADR-0009** (§D2) and **ADR-0012** scope a custom host to the public guest runtime only, which is why H23a4's mail logo URL is composed from `TenantUrl`'s **app** arm and never its public one. **ADR-0011** (§D11) forbids any `[data-accent]` rule touching the chart scale; §D7 below extends that prohibition to the tenant layer.
- **Related docs:** `docs/ux/design-system-reference.md` §2.9 (amended), §4.1 (the seventeen pairings this engine encodes) and §6 governing rules; `docs/ux/exceptions-log.md` #7; `docs/data-dictionary.md` §114–115; `docs/PRD.md` §290.

---

## Context

1. **Three ratified statements disagreed, and H23a was unplannable until they were reconciled.** §2.9 closes the accent set at two options and forbids a picker. `docs/data-dictionary.md` §114–115 already specifies `tenants.primary_color varchar(7)` — *an open hex*. `docs/PRD.md:290` says personalization never touches guest rendering, while the H-map says *"user personalization wins over tenant branding for accessibility tokens"* — a rule that is vacuous unless tenant branding reaches the authenticated app. None of the three could be honoured without contradicting another.

2. **A brand hex is not a token, and this is the fact the whole increment turns on.** Teal — the one hue the design system already lets a *user* choose — required **six tokens per theme** (fill, hover, active, foreground, tint, focus ring) and carries **seventeen measured pairings** in §4.1. So "validate the tenant's colour" describes nothing that can be done: there is nothing to validate until eleven other colours exist, and it is those eleven that decide whether the result is legible.

3. **Why the curated option was rejected despite being far cheaper.** Extending the closed set to tenant scope is an M-sized increment with no new accessibility risk and a two-line documentation change. It was declined on product grounds: `docs/PRD.md` G3 promises *"custom domain/branding available"* as a Phase-3 exit criterion against Fillout-class competitors, and a tenant who cannot enter their own brand colour does not have branding — they have a second theme. The cost of the honest version is roughly three times the curated one and is recorded as accepted.

4. **The measurement that shaped the engine, taken before any code was written.** Reproducing §4.1's Teal table from the shipped tokens confirmed the grounds and the formula (7.48 / 9.40 / 12.10 / 7.48 / 6.77 / 15.90 light; 6.68 / 4.82 / 9.40 / 5.16 / 6.36 dark, all to the printed precision). The same measurement pass produced the finding in §D6 — that a constraint which *looks* obviously required is one both ratified accents already fail.

---

## Decision

**A tenant's brand colour is one `#RRGGBB` from which the platform GENERATES a twelve-token ramp, holding hue, capping chroma and re-deriving lightness per role against a fixed ground until every §4.1 pairing is satisfied. The derived ramp is stored. The engine never refuses a colour; where a colour cannot be honoured it is adjusted, and the adjustment is disclosed to the tenant with its measured ratios.**

### The eight sub-decisions

**§D1 — The curated set is NARROWED, not overturned.** §2.9's rule still governs **user personalization**: the `AccentToken` enum stays closed at Blueprint and Teal, because a *user* choosing an accent for themselves gains nothing from arbitrary colour and the closed set is what keeps every combination pre-verified. What changes is that the rule no longer extends to **tenant branding**, which serves a different audience (respondents, not members) and answers a different promise (PRD G3, not PRD Feature #9). Two layers, two rules, stated once here so a future reader does not "restore consistency" by collapsing them.

**§D2 — The tenant controls hue and chroma. Lightness is discarded.** Lightness *is* contrast: preserving the tenant's L would preserve the one property that decides whether white button text is legible. Two consequences follow and both must be disclosed by the admin UI (H23a2) rather than discovered: `#FF0000` and `#8B0000` produce near-identical ramps, and every achromatic input — `#000000`, `#FFFFFF`, `#808080` — produces the same grey ramp. Three corpus vectors pin the second.

**§D3 — Snap, do not refuse (the "B-snap" fork).** Where the requested chroma cannot survive at the lightness a role requires, the gamut mapper gives up **chroma**, holding lightness and hue. Giving up lightness instead would silently invalidate the contrast the search just solved for; giving up hue would discard the only thing the tenant actually chose. This is what makes the product able to say "here is your colour, adjusted, and here is by how much" instead of "your brand colour is not allowed" — a sentence no paying customer should receive.

**§D4 — Targets are RATIOS, not ramp steps, and they are the midpoint of the two ratified accents.** The shipped accents disagree about steps: in dark mode Blueprint darkens on hover (6.14 → 9.14 → 13.00) while Teal lightens then darkens (6.68 → 4.82 → 9.40, itself recorded as exceptions-log #6 because it is odd). They agree about ratios. Targeting ratios yields one rule that works for every hue and is monotone in both themes — hover and active always move toward *more* contrast with the white text they carry. **The evidence that these targets encode the system rather than an author's taste: fed Teal's own hue, the engine independently re-derives `--mds-accent-teal-600` (`#1B5E5E`) and `--mds-accent-teal-50` (`#E6F2F2`) byte-identically, and reproduces §4.1 rows 1–3 to two decimals.** That is asserted by a test, not claimed here.

**§D5 — Deny by default, and be honest that it is a guard rather than a gate.** A generated ramp is measured against all seventeen pairings and refused if any fails. But the construction makes failure unreachable: contrast against a fixed ground is monotone in lightness, and the search sweeps the whole lightness range, so a compliant answer exists for every hue (exercised at 5° steps around the wheel by a test, not asserted). `BrandRampException::pairingFailed` therefore indicates an **engine defect** — a mistyped target, a ground token that moved underneath the generator, a role added without a pairing — and is a 500 by design, on the H25 precedent: mapping an integrity guard to a friendly error normalises exactly the condition the guard exists to surface.

**§D6 — Fill-versus-canvas is deliberately NOT a constraint, and the reason is measured.** It looks like it belongs — a button ought to be distinguishable from the page it sits on, and WCAG 1.4.11 is the obvious authority. Measuring the shipped system first showed **both ratified accents fail it in dark mode**: Blueprint 2.61, Teal 2.40, against a 3:1 reading. Adding it would have made the engine stricter than the design system it serves and rejected the product's own colours. §4.1 does not list it; neither does the engine. Recorded because the omission looks like an oversight and is not.

**§D7 — The ramp repaints six custom properties and no others.** `--mds-color-action-primary-{bg,bg-hover,bg-active,fg,tint}` and `--mds-color-focus-ring` — exactly the six a user accent repaints. Never a neutral, success, warning, danger or chart token. The chart prohibition is inherited verbatim from ADR-0011 §D11 and for its stated reason: a data series encodes meaning, and two colleagues reading one screenshot must see the same series in the same colour. Enforced by a test, not by this paragraph.

**§D8 — The derived ramp is STORED, and the reason is not performance.** Three of the five consuming surfaces cannot resolve a CSS custom property at all: mail clients strip `<style>` and ignore `var()`, and dompdf (the H17 PDF renderer) supports neither custom properties reliably nor `oklch()`. Those surfaces need literal hexes at render time, so the ramp must exist as data regardless — and once it does, re-deriving it on read is duplication with a drift hazard attached. `BrandRamp::$engineVersion` is what keeps that honest: a later change to a target or a ground would silently repaint every live tenant if ramps were re-derived on read, and becomes an explicit, auditable re-derivation because they are not.

### Dual-engine parity — why this is a corpus problem

The engine has a **TypeScript twin** (`packages/design-system/src/theme/brand-ramp.ts`) because the admin picker previews a ramp per keystroke, and a round trip per keystroke is not a preview. PHP stays authoritative — four of the five consuming surfaces are server-rendered. A disagreement between the two would be worse than having no preview: the tenant approves one ramp and receives a different one.

This is the H6a/H6b piping-formatter problem in a new costume and takes the same treatment: `tests/fixtures/brand-ramp.json` is generated by the PHP engine and asserted hex-for-hex by both `tests/Unit/Branding/BrandRampParityTest.php` and `brand-ramp.test.ts`. Three implementation choices exist **only** to serve that parity, and each is a deviation from what one would otherwise write:

1. **`x ** (1/3)`, never a dedicated cube root.** JavaScript's `Math.cbrt` is the better function — correctly rounded where `pow` is not — and that is exactly why it is wrong here: PHP has no cbrt, so using it would have the two engines computing one quantity by two routes agreeing only to within a few ulp. **Matching the weaker implementation is what makes the pair exact.**
2. **Fixed trip counts — a 1001-point lightness scan and a 32-step gamut bisection.** Neither loop terminates on a tolerance, because a convergence test can take a different number of iterations on two platforms and settle on opposite sides of an 8-bit rounding boundary.
3. **Clamp, then round.** `Math.round` is half-up; PHP's `round()` is half-away-from-zero. They agree only on non-negative values, so the clamp to [0,1] must precede the round. Reversed, the disagreement appears on roughly one channel in a thousand — rare enough to survive a hand-check and fail in production.

The fixture lives in `tests/fixtures/`, deliberately **not** `tests/golden/`: it carries no `grammar_version` key and no manifest entry, so it moves neither the 296-site count nor the 114-vector total. That is the H21a amendment-A6 precedent already followed by `condition-serializer.json` and `step-projection.json`.

---

## Consequences

**What this buys.** A tenant enters their brand hex once and every surface a respondent or a member sees carries it, with all seventeen §4.1 pairings measured before anything is stored. The product can answer PRD G3 honestly. `tenants.primary_color` — specified in the data dictionary since §115 was written and never built — finally means what it says.

**The cost, stated plainly and not minimised: the accessibility guarantee changes character.** §2.9's actual promise is *structural* — no reachable combination can be inaccessible, because every reachable combination was enumerated and pre-verified by a human. After this ADR the promise is *procedural* — every stored ramp was verified by the engine at write time. **That is precisely the risk §2.9 was written to close**, and it is worth naming rather than papering over. Three things mitigate it and none of them restores the original property:

- the engine is deny-by-default over all seventeen pairings, and re-measured from the stored hexes by a second assertion in each language;
- a stored ramp is immutable — nothing re-derives on read, so a ramp that was compliant when written stays the ramp that renders;
- the engine reproduces the design system's own hand-verified accent byte-identically, which is the strongest available evidence that its judgment matches the human one it replaces.

**What is now load-bearing that was not.** The H-map's *"user personalization wins over tenant branding"* rule stops being vacuous the moment tenant branding reaches the admin app (H23a3). ~~It must be enforced by CSS specificity rather than source order — `:root` for the tenant layer at (0,1,0), losing to `:root[data-accent='…']` at (0,2,0).~~ **CORRECTED BY H23a3 (2026-08-05): it is enforced ON THE SERVER, and the CSS route was rejected on inspection.** The tenant ramp needs three blocks — `:root`, `[data-theme-mode='dark']` and a `prefers-color-scheme` twin, at (0,1,0), (0,2,0) and (0,3,0) — so the user's teal rule at (0,2,0) would have **tied** with the tenant's dark block, leaving source order to decide, which is precisely the G11 bug this paragraph names. `app.blade.php` instead emits the tenant ramp **only when the member has expressed no accent opinion**, so personalization never enters a specificity contest at all. The prediction is struck through rather than deleted because the reasoning that produced it is the reasoning a future reader is most likely to repeat.

**A defect this creates downstream, recorded now so H23a3 does not rediscover it.** §2.9 makes "the product default is the ABSENCE of the attribute" an invariant across all four personalization axes. Once a tenant ramp occupies `:root`, absence stops meaning *product default* and starts meaning *inherit my organisation's brand* — so a member who wants the product blue back has no way to say so. ~~H23a3 closes it by emitting an explicit `data-accent="blueprint"` when, and only when, the tenant has a ramp.~~ **CORRECTED BY H23a3: an explicit `data-accent="blueprint"` would have needed a `:root[data-accent='blueprint']` restoring rule at (0,2,0), which outranks the tenant's `:root` block at (0,1,0) in EVERY case — the tenant's colour would never have applied at all.** What ships instead widens the STORED domain, not the attribute: `user_ui_preferences.accent_token` moves from `{NULL,'teal'}` to `{NULL,'blueprint','teal'}`, **NULL now means "no opinion" and nothing else**, and `<html>` still emits `data-accent` only for Teal — so §2.9's absence-as-default convention survives at the attribute level unchanged.

**Not addressed here.** Per-form branding overrides (`forms.theme`, built and unread since Increment F — data-dictionary §218); custom typefaces; ~~logo placement~~ (**decided by H23a4 — see below**); full white-label, including per-tenant `From` addresses, which would need per-domain DKIM/SPF. The `branding` entitlement stays at Starter+ per ADR-0008 §D7 and is not revisited.

---

## Addendum — the two surfaces §D8 was written for (H23a4, 2026-08-05)

§D8 argues the ramp must be STORED because mail and dompdf cannot resolve a custom property. Until H23a4
that was a prediction about consumers that did not exist. Both now exist, and building them settled three
questions the ADR had left open.

**1. Logo placement — mail yes, PDF no.** The logo reaches email as a **hosted absolute URL** on the
tenant's app host (`GET /branding/logo`, in the unauthenticated subdomain group), and does **not** reach
the PDF at all. Two facts forced the split:

- The only logo-serving route H23a2 left behind is `GET /attachments/{id}`, behind `auth` +
  `can:view,attachment`. A mail client has no session, so that is a 302 to login — a broken image in every
  branded email. Hence a new route, and hence it is **unsigned**: an email is read days later and
  forwarded, so any expiry guarantees the image breaks. The asset is public by nature (it is the logo
  every respondent of every branded form sees) and the row is already `is_pii = false`. Safety comes from
  the route resolving `tenants.logo_attachment_id` and nothing else — it is not a public read primitive
  over `attachments` — plus the `isActive()` gate, the scan-status gate, `nosniff`, and the fact that
  H23a2's content-sniffed allowlist means SVG cannot reach storage in the first place.
- dompdf needs `ext-gd` for PNG and WebP (JPEG is the only GD-free path), and GD is absent from the app
  container **and from all four CI jobs**. A logo would have rendered on a developer's machine and thrown
  in the pipeline. The PDF's existing no-external-references contract is left intact and branding there is
  colour only.

**2. Light theme only, on both surfaces.** The stored ramp carries two themes; these surfaces take one.
`CssToInlineStyles` deletes `@media` from the theme before inlining; a rule moved into a `<style>` tag
would lose to the inline declarations the inliner has just written onto every element; the framework's
mail layout hard-codes `color-scheme: light`; and the dark ramp's contrast was measured against
Meridian's own dark grounds (`#123350` / `#0C2337`), not against whatever ground a client invents when it
inverts a message. Shipping it would assert a guarantee the engine never made. Paper, for its part, is
white.

**3. The palette is resolved at DISPATCH, never in `toMail()`, and this is the sharpest edge in the
increment.** A queued `Notification` is delivered by the framework's `SendQueuedNotifications`, **not** by
`TenantAwareJob`, so on the worker `TenantContext::currentTenantId()` is null — and
`TenantBrandingService::isActive()` reads its entitlement half from exactly that static, not from its
`$tenant` argument. Branding read inside `toMail()` therefore fails closed and sends **every** tenant an
unbranded email, with a green suite. `sharedRamp()` is worse: it resolves a container binding no worker
makes and returns null unconditionally. So `App\Support\Branding\BrandPalette` resolves the palette where
the tenant is known and it rides the notification payload as a scalar array — the same discipline every
`TenantUrl`-built link already follows. `BrandPalette::forTenant()` additionally **refuses to answer**
when its argument is not the ambient tenant, which turns that latent cross-tenant entitlement read into
defined, fail-closed behaviour rather than a silent wrong answer.

The two Fortify emails (verification, password reset) are deliberately the only unbranded ones: those
routes carry no tenancy middleware, so no tenant is resolved — and a user may belong to several, so
"whose brand" has no correct answer either. They still render through the Meridian template.

**As built — the guest runtime (H23b), the fifth and last consuming surface.** Three things are worth recording because none of them is predicted above.

- **This surface CAN resolve `var()`**, so §D8's storage argument gains no new evidence here — it also loses none: the ramp is still read from storage rather than re-derived, because a ramp that was compliant when written must stay the ramp that renders. The revisit trigger below therefore does *not* fire; it asks for a **sixth** surface.
- **There is no precedence rule on this surface, and that is the §D1 two-layers rule paying off rather than an omission.** The admin root withholds the tenant ramp when a member has expressed an accent opinion; a respondent has no preferences for the brand to lose to, so the guest shell emits it whenever branding is active and continues to emit no `data-accent` / `data-font-size` / `data-dyslexia-font` at all. The emission itself is a single shared Blade partial for both surfaces — including the `[data-theme-mode='dark']` block, which is inert on the guest shell but kept anyway: the system-dark selector has to stay at (0,3,0) to beat `theme-overrides.css`'s own, and a guest-specific variant would be a second copy of that specificity reasoning waiting to drift.
- **Two surfaces outside CSS take the ramp's light fill**: the shell's `<meta name="theme-color">` and the per-form manifest's `theme_color`. Their unbranded default was corrected from `--mds-accent-teal-600` to `--mds-primary-600` in the same increment — the guest shell has never emitted `data-accent`, so the browser chrome and the installed app's splash screen had been tinted a colour appearing nowhere in the form. `background_color` stays `--mds-neutral-50`: §D7 keeps the tenant layer off neutrals, and these two sit adjacent in one payload, which is exactly where that rule is easiest to break.

**The offline cost this creates, and how it is paid.** A brand that rides cached HTML can go stale on a device that is offline. The invalidation is a **refresh, never a purge** — deleting the shell cache would trade a fieldworker's offline access to a primed form for a colour, which is a bad trade in any brand's favour. The mechanism (a fingerprint on the mount node, a Dexie `app_state` key, a deferred retry when offline, `?b=` on the manifest link) is specified in `offline-first-sync-design.md` §4.1 rather than duplicated here.

---

## When to Revisit

- **A sixth consuming surface appears that can resolve `var()`.** §D8's storage argument is grounded in mail and dompdf being unable to; it does not become wrong, but it becomes worth restating.
- **`ext-gd` is added to the app image for some other reason.** The PDF's colour-only branding is a consequence of its absence, not a preference — see the Addendum. Adding GD would also make `UploadedFile::fake()->image()` usable, which several tests currently work around with real PNG bytes.
- **A tenant asks to send from their own domain.** That is the full white-label line above; it needs per-domain DKIM/SPF and would change what the mail footer's "Sent via" attribution is for.
- **A target or ground token changes.** That is a `BrandRampGenerator::VERSION` bump plus a deliberate re-derivation pass over stored ramps — never a silent repaint. The version exists to make that moment visible.
- **WCAG 3 / APCA becomes the standard the product tests against.** The whole engine is built on WCAG 2.x relative luminance, which APCA replaces rather than refines; §4.1 and this ADR would move together.
- **The first evidence that a generated ramp reads worse than a curated one in real use.** The structural→procedural trade in Consequences is the thing to re-examine, and a real screenshot beats every argument in this document.
