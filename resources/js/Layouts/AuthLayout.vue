<script setup lang="ts">
/**
 * The pre-auth guest layout (sign in, register, password reset, 2FA challenge, verify email, invitation
 * accept). A documented member of the Public/Guest Runtime Shell family — NOT a third top-level shell
 * (design-system-reference.md §3.0; see docs/ux/exceptions-log.md #1 and #14). Tokens only.
 *
 * ── TWO VARIANTS, AND THE DEFAULT IS THE ONE SEVEN OF THE NINE CONSUMERS WANT ──────────────────────────
 * `card` (default) is the slim ~400px card this layout has always been. `split` adds a value panel beside
 * it and is used by the two FRONT DOORS ONLY — Login and Register — on the user's decision of record
 * (2026-08-13). The seven utility pages keep the card deliberately: a one-field "check your email" page
 * inside a two-column marketing frame reads as a mistake, not as a brand.
 *
 * ⚠️ THE PANEL CARRIES NO LINK AND NO BUTTON, AND THAT IS A HARD CONSTRAINT RATHER THAN A DESIGN CHOICE.
 * `tests/e2e/global-setup.ts` signs in TWICE before any spec in the suite runs, locating the submit
 * control with `getByRole('button', { name: 'Sign in' })` — NOT exact. A second control whose accessible
 * name merely contains "Sign in" is a strict-mode violation in global setup, which is not a handful of
 * failures but all ~506 specs with none executed. The heading below is safe because it is a heading.
 *
 * ⚠️ AND THE PANEL'S HEADLINE IS A `<p>`, NOT AN `<h1>`. `responsive-axe.spec.ts` settles on
 * `getByRole('heading', { level: 1 })`, and `.auth__title` is already the page's one h1. A second would
 * make that locator ambiguous and change what axe's heading-order rules see.
 */
import { Head } from '@inertiajs/vue3';
import { MdsIcon } from '@meridian/design-system';

withDefaults(defineProps<{ title: string; variant?: 'card' | 'split' }>(), { variant: 'card' });

/**
 * Compressed from `Pages/Welcome.vue`'s `capabilities`, deliberately — the landing page and the front
 * door should not describe the product in two different ways, and a reader who arrives here from `/`
 * should recognise what they just read. Three, not Welcome's four: the panel is ~26rem wide and a fourth
 * row pushes the set below the fold at 900px tall.
 */
const pitch = [
    {
        icon: 'forms',
        title: 'Forms that think',
        body: 'Conditional logic, calculations and repeat groups, with a live logic view in plain language.',
    },
    {
        icon: 'globe',
        title: 'Collect anywhere',
        body: 'Public links and QR codes. Fill offline on a phone; everything syncs on reconnect.',
    },
    {
        icon: 'submissions',
        title: 'Review before you trust',
        body: 'Approve, return and archive, with a full audit trail of who changed what.',
    },
] as const;
</script>

<template>
    <Head :title="title" />
    <div class="auth" :class="{ 'auth--split': variant === 'split' }">
        <div class="auth__frame">
            <aside v-if="variant === 'split'" class="auth__pitch" aria-label="About this product">
                <p class="auth__pitch-title">Forms, field data, and a review trail you can defend.</p>

                <ul class="auth__pitch-list">
                    <li v-for="point in pitch" :key="point.title" class="auth__pitch-item">
                        <MdsIcon :name="point.icon" size="sm" class="auth__pitch-icon" aria-hidden="true" />
                        <span>
                            <strong class="auth__pitch-lead">{{ point.title }}</strong>
                            {{ point.body }}
                        </span>
                    </li>
                </ul>
            </aside>

            <main class="auth__card">
                <p class="auth__brand">Meridian</p>
                <h1 class="auth__title">{{ title }}</h1>
                <div class="auth__body">
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>

<style scoped>
.auth {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    min-height: 100dvh;
    padding: var(--mds-space-6);
    background-color: var(--mds-color-bg-canvas);
}

/* ⚠️ NO `overflow-x: clip` HERE, AND THAT IS DELIBERATE — IT WOULD BLIND THE ONLY GATE THAT CAN SEE THIS
   LAYOUT. `AppLayout` clips (its own comment explains why), and exceptions-log #12 and #13 both record
   the cost: `assertClean`'s `documentElement.scrollWidth` check is pinned flat on every authenticated
   page, so it can no longer fail there. The auth pages are the one place in the product where that
   assertion still measures something real, and J3b is adding four more scans that depend on it. A clip
   here would hide exactly the regressions those scans exist to catch. The layout must genuinely not
   overflow instead — which is what the panel's `display: none` below the threshold buys. */

/* ── The split variant's container ─────────────────────────────────────────────────────────────────────
   `container-type: inline-size` rather than a media query, following JR5 and exceptions-log #13.
   ⚠️ THE `font-size` PIN IS WHAT MAKES THE `em` THRESHOLD MEAN ANYTHING. A media query's `em` resolves
   against the ROOT font size, which `[data-font-size]` never moves — it re-points the `--mds-type-*`
   tokens instead — so `@media (min-width: 54em)` would be correct at exactly one type scale and wrong at
   the other two. A container query's `em` resolves against the CONTAINER's own font size, so pinning it
   here makes the threshold scale with the text it is protecting: 864px at standard, and proportionally
   later at `large` and `extra_large`, where the same layout needs more room.
   The pin is a genuine no-op today — `resources/css/app.css` already sets exactly this on `body` — which
   is the point: it is a guarantee against a future change to that rule, not a restyle. */
.auth--split {
    container-type: inline-size;
    font-size: var(--mds-type-body-lg-font-size);
}

.auth__frame {
    display: flex;
    justify-content: center;
    width: 100%;
}

.auth__card {
    /* `min()` rather than a bare `width: 100%`: in the split frame this is a flex item, and a percentage
       basis against a flex container is how a card comes to overrun its track at the transition width. */
    width: min(100%, 400px);
    padding: var(--mds-space-6);
    background-color: var(--mds-color-bg-surface);
    border: 1px solid var(--mds-color-border-default);
    /* JR2: the page-level card tier (DSR §2.6). This card is the entire visual weight of the sign-in
       screen and it is the FIRST surface a new user sees, so it is the last one that should still be
       speaking the old language. */
    border-radius: var(--mds-radius-xl);
    box-shadow: var(--mds-shadow-2);
}

.auth__brand {
    margin: 0 0 var(--mds-space-2);
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    font-weight: var(--mds-font-weight-bold);
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--mds-color-action-primary-fg);
}

.auth__title {
    margin: 0 0 var(--mds-space-6);
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-2-font-size);
    line-height: var(--mds-type-heading-2-line-height);
    font-weight: var(--mds-font-weight-semibold);
    text-transform: uppercase;
    letter-spacing: 0.02em;
    color: var(--mds-color-text-heading);
}

/* Spaces the top-level blocks a page drops into the slot (form, links, notices).
   Flex `gap` reaches slotted children directly — no :slotted() needed. */
.auth__body {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

/* ── The value panel ───────────────────────────────────────────────────────────────────────────────────
   Hidden by default and revealed by the container query, never the reverse: below the threshold this
   layout must be byte-for-byte the card it has always been, and `display: none` also takes the panel out
   of the accessibility tree so a phone reader is not read three marketing lines before the form. */
.auth__pitch {
    display: none;
}

.auth__pitch-title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    /* The marketing display role. DSR §2.9 scopes it to "onboarding/marketing hero text only — never
       inside the authenticated app shell", which this is not; exceptions-log #8 previously recorded
       Welcome.vue as its only consumer and is amended by #14 to say so accurately. */
    font-size: var(--mds-type-display-font-size);
    line-height: var(--mds-type-display-line-height);
    font-weight: var(--mds-type-display-font-weight);
    letter-spacing: var(--mds-type-display-letter-spacing);
    color: var(--mds-color-text-heading);
    /* A 44px display face at `extra_large` is ~55px, and the panel is a fixed track — an unbreakable
       string is not a risk in authored English, but the guard costs nothing and the Domains page paid
       for learning that `min-width: 0` shrinks a BOX and does nothing to a WORD. */
    overflow-wrap: anywhere;
}

.auth__pitch-list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    margin: 0;
    padding: 0;
    list-style: none;
}

.auth__pitch-item {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-3);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-secondary);
}

.auth__pitch-icon {
    flex-shrink: 0;
    margin-top: 0.2em;
    color: var(--mds-color-action-primary-fg);
}

.auth__pitch-lead {
    display: block;
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-body);
}

@container (min-width: 54em) {
    /* ⚠️ 54em IS A CONTAINER WIDTH, NOT A VIEWPORT WIDTH, AND THE TWO DIFFER BY EXACTLY THIS LAYOUT'S
       PADDING. A container query measures the CONTENT box, so `.auth`'s 2 × `--mds-space-6` (48px) is not
       in it: 54em = 864px of container is reached at a **912px viewport**. Measured, not computed —
       viewport 911 is still the card and 912 is the split, with the container reading 863 and 864.

       The threshold is derived from what has to fit rather than from a device: 400px card + a
       `--mds-space-8` gap + a 26rem panel = 848px, so 864 clears it with the slack a shrinking flex item
       needs. Keying it to the content is what makes it survive the type scale — at `large` and
       `extra_large` the same 54em resolves to a larger pixel width, because the `font-size` pinned on the
       container above is a `--mds-type-*` token.

       ⚠️ THE FIRST DRAFT OF THIS COMMENT SAID "864px", MEANING THE VIEWPORT, AND WAS WRONG BY THOSE 48px.
       JR3 paid for the identical mistake on `MdsDataTable` — a 260px threshold written in the belief it
       would never fire, firing on every 300px card — and its note is explicit: reason about a container
       threshold in content-box terms or it is off by exactly the padding. It is recorded twice now. */
    .auth__frame {
        gap: var(--mds-space-8);
        align-items: center;
    }

    .auth__pitch {
        display: flex;
        flex: 0 1 26rem;
        flex-direction: column;
        gap: var(--mds-space-6);
    }
}
</style>

<!-- ⚠️ GUEST-SHELL HELPERS, OWNED HERE AND DELIBERATELY NOT SCOPED. Three classes, consumed by all nine
     pages that mount this layout, so they belong to the auth shell rather than to any one page.

     `:slotted()` is the obvious way to scope them and it does NOT work here: it applies to slot-content
     ROOT nodes only, and `auth/VerifyEmail.vue` nests a `.auth-note` INSIDE a `.auth-form`. Writing the
     descendant chains that would cover every nesting a page might invent is more fragile than the leak,
     and the leak is bounded — this file is chunk-loaded by those nine pages and by nothing else.

     Two former members of this block are gone, replaced by real components rather than restyled:
     `.auth-remember` → `MdsCheckbox` (its only consumer was Login's raw checkbox) and
     `.auth-alert`/`.auth-alert--error` → `MdsBanner`. That is what "migrate them, don't just restyle the
     shell" meant; what remains are three layout helpers with no component equivalent. -->
<style>
.auth-form {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.auth-note {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
}

.auth-links {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-4);
    font-size: var(--mds-type-body-md-font-size);
}

.auth-links a {
    color: var(--mds-color-action-primary-fg);
    font-weight: var(--mds-font-weight-medium);
    text-decoration: none;
}

.auth-links a:hover {
    text-decoration: underline;
}
</style>
