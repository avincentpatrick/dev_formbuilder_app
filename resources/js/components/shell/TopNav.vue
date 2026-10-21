<script setup lang="ts">
/**
 * Top navigation bar (DSR §3.4): wordmark + mobile hamburger (left); global search (centre); theme
 * quick-toggle, feedback, notifications, account menu (right). 64px tall; gains a shadow when the content
 * region scrolls. (The tenant switcher is still unbuilt.)
 *
 * ── THE CENTRE REGION IS DELIBERATELY JAVASCRIPT-FREE (Increment J1b) ───────────────────────────────────
 * A real `form method="GET" action="/search"` above the mobile breakpoint, and a plain link to `/search`
 * at or below it. No overlay, no key handler, no XHR. Three reasons:
 *   1. It works with JavaScript disabled and it is a real, linkable destination.
 *   2. It absorbs this file's whole e2e blast radius on a STATIC element. Every one of the 15 pages in
 *      `responsive-axe.spec.ts` renders this bar at three viewports in two themes, so a red run here is
 *      layout — full stop — with no focus trap or chord in the diff to confuse the bisect. J1d layers the
 *      palette on top afterwards, and its axe risk is a new spec rather than 15 existing pages.
 *   3. It keeps exactly ONE combobox in the product (J1d's palette). An anchored suggest listbox here
 *      would be a second, and would hit the clipping trap described below from the other direction.
 *
 * ⚠️ `.app-shell` IS `overflow-x: clip`, SO THE STANDARD `scrollWidth` OVERFLOW ASSERTION IS BLIND HERE.
 * A mis-sized centre region is CLIPPED AND INVISIBLE rather than caught — the scan stays green over an
 * unreadable nav. `tests/e2e/search-nav.spec.ts` asserts bounding-box containment at BOTH edges plus
 * non-overlap against the wordmark and the right-hand controls, at 375px under the extra_large type scale
 * and the dyslexia face. Note the notification-panel precedent it is modelled on checks only `box.x >= 0`;
 * that is half the assertion, and copying it verbatim would leave the right edge unguarded.
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { MdsIcon, MdsTextInput } from '@meridian/design-system';
import ThemeQuickToggle from './ThemeQuickToggle.vue';
import NotificationBell from './NotificationBell.vue';
import FeedbackButton from './FeedbackButton.vue';
import AccountMenu from './AccountMenu.vue';

defineProps<{ scrolled: boolean; drawerOpen: boolean }>();
const emit = defineEmits<{ 'toggle-drawer': [] }>();

const page = usePage();

/**
 * The query the search RESULTS page is currently showing — and nothing else's.
 *
 * ⛔ READ FROM `usePage()`, NEVER FROM `window.location`, BECAUSE THIS BAR IS NEVER REMOUNTED. `app.ts`
 * assigns `AppLayout` at module level, so Inertia patches one layout instance for the life of the tab. A
 * computed over `window.location.search` therefore has NO reactive dependency: it evaluates once and holds
 * that value through every client-side visit underneath it. The docblock this replaces claimed a browser
 * Back "arrives as a fresh render" — it does not. Inertia intercepts popstate and swaps the page in place,
 * with no document load, so Back was broken too.
 *
 * ⛔ AND IT IS GATED ON THE COMPONENT, WHICH IS THE HALF THE BACKLOG ROW DID NOT HAVE. `q` is NOT this
 * feature's private parameter — it is the shared filter key on six OTHER list pages (forms, members,
 * webhooks, audit log, feedback, the submissions inbox), every one of which commits it through a
 * client-side `router.get(..., { preserveState: true })`. Reading it unconditionally would put the audit
 * ledger's filter term into a box labelled "Search this workspace", where pressing Enter posts it to
 * `/search` and silently converts "filter this list" into "search everything". Gating on the rendered
 * component also fixes the pre-existing full-page-load version of that bleed rather than widening it.
 *
 * The gate is a second benefit that is easy to miss: because the value only changes when the search page's
 * own `q` changes, an unrelated visit cannot clobber text the user is part-way through typing here.
 *
 * `page.url` is path + search + hash with no origin, so the hash is stripped before parsing and no base is
 * needed. `URLSearchParams` decodes `%20` (the palette's `encodeURIComponent`) and `+` (a native form GET)
 * alike, and a url with no query string yields ''.
 */
const activeQuery = computed(() => {
    if (page.component !== 'search/Index') return '';

    // Split on '?' and re-join the tail: a literal '?' inside a value is legal, and taking [1] alone
    // would truncate the query at it.
    const [, ...query] = page.url.split('#')[0].split('?');

    return new URLSearchParams(query.join('?')).get('q') ?? '';
});
</script>

<template>
    <header class="topnav" :class="{ 'is-scrolled': scrolled }">
        <div class="topnav__left">
            <!--
                ⚠️ WHILE THE DRAWER IS OPEN THIS BUTTON IS ITSELF INERT, AND THAT IS STATED RATHER THAN
                DISCOVERED. The drawer takes the page (J4b), so everything outside it — this bar included —
                is marked inert; the control advertising `aria-expanded="true"` therefore cannot be used to
                collapse what it opened. Escape and the scrim are the close paths, exactly as MdsModal
                treats its own opener. `data-mds-inert-exempt` is NOT a fix here: the exemption only works
                on an element the inert walk actually visits, i.e. an off-path sibling, and this button is
                nested inside the header that gets marked — so the attribute would do nothing, silently.
            -->
            <button
                type="button"
                class="topnav__hamburger"
                :aria-label="drawerOpen ? 'Close navigation' : 'Open navigation'"
                :aria-expanded="drawerOpen"
                aria-controls="app-drawer"
                @click="emit('toggle-drawer')"
            >
                <MdsIcon name="menu" size="md" aria-hidden="true" />
            </button>
            <span class="topnav__wordmark">Meridian</span>
        </div>

        <!--
            DSR §3.4's centre region, specified since the design system was written and deferred by C3.
            Two states, both real navigation. The `role="search"` landmark is on the form so assistive tech
            can jump to it; the label is visually hidden because the magnifier plus the placeholder carry
            the meaning visually, and DSR §4.3 forbids a placeholder as the only label.
        -->
        <div class="topnav__centre">
            <form class="topnav__search" role="search" method="GET" action="/search">
                <label class="topnav__search-label" for="topnav-search">Search this workspace</label>
                <MdsIcon name="search" size="sm" aria-hidden="true" class="topnav__search-icon" />
                <MdsTextInput
                    id="topnav-search"
                    name="q"
                    type="search"
                    :model-value="activeQuery"
                    placeholder="Search"
                    autocomplete="off"
                />
                <!--
                    The ⌘K discoverability hint (J1d). A keyboard shortcut nobody can find is a feature
                    that does not exist, and this is the only surface that advertises it.

                    ⚠️ `aria-hidden`, and NOT a button. It is decoration: the chord is a document-level
                    capture listener, so this element does nothing when clicked and must not be announced
                    as though it could. It is also positioned INSIDE the existing form rather than added as
                    a new flex child, so it cannot change the nav's flex distribution — the geometry
                    `tests/e2e/search-nav.spec.ts` guards, which `assertClean` is structurally blind to.
                -->
                <kbd class="topnav__search-hint" aria-hidden="true">⌘K</kbd>
            </form>
            <a class="topnav__search-compact" href="/search" aria-label="Search this workspace">
                <MdsIcon name="search" size="md" aria-hidden="true" />
            </a>
        </div>

        <div class="topnav__right">
            <ThemeQuickToggle />
            <FeedbackButton />
            <NotificationBell />
            <AccountMenu />
        </div>
    </header>
</template>

<style scoped>
.topnav {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-4);
    /* `--mds-space-16` IS 64px and DSR §3.4 names it as the source of this height. It was hard-coded here,
       and `NotificationBell.vue` grew a comment asserting no such token existed — corrected in J1b, in the
       same commit, because a note that outlives its subject is the liability exceptions-log #6 records. */
    height: var(--mds-space-16);
    flex-shrink: 0;
    padding: 0 var(--mds-space-4);
    background-color: var(--mds-color-bg-surface);
    border-bottom: 1px solid var(--mds-color-border-default);
    transition: box-shadow var(--mds-duration-base) var(--mds-ease-standard);
}

.topnav.is-scrolled {
    box-shadow: var(--mds-shadow-1);
}

/* min-width: 0 on both groups so they can shrink below their content instead of pushing the bar
   wider than the viewport. Without it a flex item's automatic minimum size is its content size, and
   the §2.9 text-size scale grows the wordmark ~25% at extra_large — at 375px that leaves only ~31px
   of headroom, i.e. one long tenant name from overflowing. */
.topnav__left {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    min-width: 0;
}

/* The third flex child. `min-width: 0` for the reason recorded above `.topnav__left` and for a second one
   that group does not have: this child is `flex: 1 1 auto`, so without it its automatic minimum size is the
   input's content width and the BAR gets pushed wider than the viewport instead of the field shrinking.
   `max-width` stops it becoming a 900px runway on a 1440 desktop. */
.topnav__centre {
    display: flex;
    justify-content: center;
    flex: 1 1 auto;
    min-width: 0;
    max-width: 480px;
}

.topnav__search {
    position: relative;
    display: flex;
    align-items: center;
    width: 100%;
    min-width: 0;
}

/* Visually hidden, not `display: none` — the input needs a real accessible name and a placeholder is not
   one (WCAG 3.3.2). */
.topnav__search-label {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}

.topnav__search-icon {
    position: absolute;
    left: var(--mds-space-3);
    color: var(--mds-color-text-secondary);
    pointer-events: none;
}

.topnav__search-hint {
    /*
     * Absolutely positioned so it takes NO part in the flex layout — the nav's width distribution is
     * exactly what it was before this element existed, which is what keeps the J1b geometry spec honest.
     * Padding-right on the input below reserves the space it sits over.
     */
    position: absolute;
    right: var(--mds-space-3);
    top: 50%;
    transform: translateY(-50%);
    pointer-events: none;
    font-family: inherit;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
    background-color: var(--mds-color-bg-sunken);
    border-radius: var(--mds-radius-sm);
    padding: 0 var(--mds-space-1);
}

/*
 * Hidden below the tablet band: at 1024px and under there is not room for a hint beside the query, and
 * below 480px the field is replaced by an icon link anyway. DSR §3.4.1 scopes the hint to desktop.
 */
@media (max-width: 1024px) {
    .topnav__search-hint {
        display: none;
    }
}

.topnav__search :deep(.mds-input) {
    /*
     * Room for the magnifier. The rest of the box model stays the primitive's own.
     *
     * ⚠️ THE SPACE SCALE HAS NO 7 AND NO 9. It runs 0,1,2,3,4,5,6,8,10,12,16,20,24 — this first shipped as
     * `--mds-space-9`, which resolves to nothing, so the padding silently collapsed to the primitive's own
     * and the icon overlapped the caret. `token-references.test.ts` is the gate that catches it; check the
     * scale before reaching for a step that "should" exist.
     *
     * 40px is arithmetic, not taste: the icon is `sm` (16px) at `left: var(--mds-space-3)` (12px), so it
     * ends at 28px, and 40px leaves a 12px gap that mirrors the 12px inset on its other side.
     */
    padding-left: var(--mds-space-10);
    padding-right: var(--mds-space-10);
}

/* The compact state's target is the full 44x44 §4.4 asks for, even though the glyph is 24. */
.topnav__search-compact {
    display: none;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: var(--mds-radius-md);
    color: var(--mds-color-text-secondary);
}

.topnav__search-compact:hover {
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-body);
}

.topnav__search-compact:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.topnav__wordmark {
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-4-font-size);
    font-weight: var(--mds-font-weight-bold);
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--mds-color-action-primary-fg);
    /* Truncate rather than force the bar wide (see .topnav__left). */
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.topnav__right {
    display: flex;
    align-items: center;
    gap: var(--mds-space-1);
    min-width: 0;
}

.topnav__hamburger {
    display: none;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border: none;
    border-radius: var(--mds-radius-md);
    background-color: transparent;
    color: var(--mds-color-text-secondary);
    cursor: pointer;
}

.topnav__hamburger:hover {
    background-color: var(--mds-color-bg-sunken);
}

.topnav__hamburger:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

@media (max-width: 480px) {
    .topnav__hamburger {
        display: inline-flex;
    }

    /* §6's mobile band, and NOT an invented breakpoint: §6 calls the 480/1024 bands "the binding contract"
       and the token set defines only those two maxima. Below it the field becomes an icon link — the bar
       already carries a hamburger, a wordmark and four right-hand controls, and this file's own note above
       `.topnav__left` records only ~31px of headroom at the extra_large type scale BEFORE search existed. */
    .topnav__centre {
        flex: 0 0 auto;
        max-width: none;
    }

    .topnav__search {
        display: none;
    }

    .topnav__search-compact {
        display: inline-flex;
    }

    /* Buys back the width the icon costs. At extra_large with OpenDyslexic's wider advances the display-face
       wordmark is the single widest item in the bar, and `min-width: 0` would otherwise resolve the squeeze
       by ellipsising it to "MERI…" — contained, but not a wordmark. */
    .topnav__wordmark {
        font-size: var(--mds-type-body-lg-font-size);
    }
}
</style>
