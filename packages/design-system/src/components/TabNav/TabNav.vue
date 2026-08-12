<script setup lang="ts">
/**
 * The per-resource navigation strip (Increment J2a, DSR §3.4).
 *
 * One resource, several pages: a form's Overview / Submissions / Builder / Analytics all live at their own
 * URL, under their own gate, and this strip is how a reader moves between them without going back to the
 * list first. It is the "tabs" layer of §3.4's governing rule — sidebar for primary sections, this for
 * views-of-one-resource, breadcrumbs for path context — and the three are never substituted for each other.
 *
 * ⚠️ NO HTML TAG IS SPELLED WITH ANGLE BRACKETS ANYWHERE IN THIS COMMENT, AND THAT IS DELIBERATE. J1a lost a
 * Storybook build to a bare `header` tag literal inside a docblock: the vue3-vite preset preserves comments
 * for docgen, so the SFC parser tokenises comment bodies that Vitest, vue-tsc and the app's own Vite build
 * all skip — and the error names a line past the end of the file. Name the element or the class instead.
 *
 * ── ⚠️ THIS IS NOT THE ARIA TABS PATTERN, AND THE DIFFERENCE IS A CORRECTNESS ONE ─────────────────────────
 * DSR §3.4 also specifies a `Tabs` component: role tablist / tab / tabpanel with a roving tabindex, per the
 * ARIA Authoring Practices. That pattern is for switching between panels that are **already in the
 * document** — its whole contract is "no navigation happens". These items are links that load a new page.
 *
 * Dressing links in tab roles is not a harmless relabelling. A screen reader announces "tab, 2 of 5",
 * implying activation will reveal a panel and leave the user where they are; instead the document is
 * replaced, focus resets to the top, and the announced position is describing a page that no longer exists.
 * The roving tabindex compounds it: it removes every non-active item from the tab sequence, so a keyboard
 * user can no longer Tab to the other destinations at all — they have to discover that arrow keys now move
 * between what look like ordinary links. And `aria-selected` would claim a selection state the browser's own
 * history, not this component, actually owns.
 *
 * So: a navigation landmark containing plain links, with `aria-current="page"` on the active one. That is
 * what the APG itself prescribes for navigation that merely *looks* like tabs, and it is why this component
 * is `TabNav` rather than `Tabs`. Building the in-page tablist to §3.4's spec remains J4's, under the name
 * `Tabs`; the two are different components and the DSR now says so.
 *
 * ── The scroll region is deliberately NOT focusable, unlike MdsDataTable's ──────────────────────────────
 * `.mds-tabnav__list` is `overflow-x: auto`, so a strip too wide for its container scrolls — and
 * `MdsDataTable` goes to real trouble (a ResizeObserver, a measured `scrollable` flag, `role="group"`) to
 * make its equivalent region a focus stop. Copying that here would be wrong. axe's
 * `scrollable-region-focusable` fires only when a scrollable region has **no focusable descendants**; every
 * item here is a link, so the content off-screen is already reachable by Tab and the browser scrolls it into
 * view on focus. Adding a tabindex would mint a redundant tab stop in front of the very links it exists to
 * reach. The table needs it because its cells are usually plain text; this does not.
 *
 * ── Colour is never the only channel ───────────────────────────────────────────────────────────────────
 * The active item carries three signals: the 2px underline, a heavier weight, and `aria-current`. The label
 * colour moves from secondary to heading — both verified on `bg-surface` in both themes.
 *
 * ⚠️ THE UNDERLINE IS `action-primary-fg`, AND THE OBVIOUS-LOOKING `action-primary-bg` IS A REAL WCAG 1.4.11
 * FAILURE. This component shipped with `-bg` first — DSR §3.4 specifies "`--mds-primary-600` 2px", and `-bg`
 * is that token's semantic name in light. But `-bg` is a FILL, and the only contrast the system guarantees
 * for it is against the text printed ON it: {@see BRAND_RAMP_PAIRINGS} pairs `bg` solely with `on_primary`.
 * Against the surface behind it there is no guarantee at all. The underline is a non-text UI component and
 * owes 3:1.
 *
 * ⚠️ **JR1 MOVED THE EVIDENCE UNDER THIS NOTE WITHOUT MOVING THE CONCLUSION, AND THE DIFFERENCE MATTERS.**
 * The original measurement was `-bg` = `primary-500` `#2E6789` on `bg-surface` `#123350` = **2.12:1**, an
 * outright failure. Under the Vivid ramp the same pairing is `primary-600` `#0E6FE8` on `#1a2130` =
 * **3.42:1**, which passes — so the default accent no longer demonstrates the bug. The teal accent still
 * does (`accent-teal-500` on `#1a2130` = **2.41:1**), and more importantly the ARGUMENT never depended on
 * either number: `bg` is a fill, {@see BRAND_RAMP_PAIRINGS} pairs it solely with `on_primary`, and a token
 * that happens to clear 3:1 against today's surface for today's palette is a coincidence a re-skin can
 * revoke — as this one nearly did, in the other direction. Do not "simplify" this back to `-bg` on the
 * strength of the 3.42.
 *
 * `action-primary-fg` is the token that carries exactly the guarantee needed, for every tenant brand rather
 * than just the two shipped accents: the ramp pairs `fg` against BOTH `surface` and `canvas`, in BOTH
 * themes, at `TEXT_MIN` (4.5:1) — comfortably over 3:1, by construction and not by spot measurement.
 * Measured anyway (JR1 values): 7.01:1 light, 8.29:1 dark, 7.48:1 light-teal, 6.39:1 dark-teal.
 * `ConfigPanel.vue` already uses this token for the same job.
 * **axe never checks border contrast, so no gate would have caught this.**
 *
 * ── The badge IS part of the link's accessible name, deliberately, and MdsBarChart does the opposite ────
 * "Submissions 128" is what this link is: a navigation item whose count tells the reader how much is behind
 * it before they spend a page load. A bar chart's value is the DATUM rather than a property of the
 * destination, and there are a dozen of them, so `MdsBarChart` keeps the value outside its anchor. The rule
 * the two share: a count belongs in the name when it describes the DESTINATION, and outside it when it is
 * the data being plotted.
 *
 * ── `linkComponent` — how the strip does an SPA visit without this package knowing Inertia exists (J2b) ──
 * Each item renders as a bare anchor by default, which in an Inertia application means a **full document
 * load** on every tab click: the persistent AppLayout the router goes out of its way to keep mounted is torn
 * down and rebuilt, the shell flashes, and every long-lived piece of client state (the command palette, the
 * notification poll, the resolved theme) re-initialises. On the strip that IS a form's primary navigation
 * that is the difference between an application and a set of documents.
 *
 * This package imports nothing from Inertia and must not start — Storybook renders these components with no
 * router present, and a router import would make every story a mock-setup exercise. So the consumer passes
 * its own link element instead: `linkComponent` accepts a component (Inertia's Link) or a tag name, and the
 * item renders through a dynamic component with the same href, class and `aria-current` it always had. A
 * passed component receives `href` as a prop; the default, the string `'a'`, receives it as an attribute,
 * and the rendered markup is identical either way.
 *
 * ⚠️ THE DEFAULT MUST STAY `'a'`. It is what keeps every existing story, every existing call site and the
 * whole of `TabNav.test.ts` correct without a single edit — those files passing unedited is the evidence this
 * prop added a capability rather than changed a behaviour. Anything that makes the Inertia path the default
 * silently couples this package to an application framework.
 */
import { computed, type Component } from 'vue';
import Badge from '../Badge/Badge.vue';
import Icon from '../Icon/Icon.vue';
import type { IconName } from '../Icon/icons';

export interface TabNavItem {
    /** Matched against `current` to decide the active item. Never rendered. */
    key: string;
    label: string;
    href: string;
    icon?: IconName;
    /**
     * An optional count or short status beside the label (e.g. a submission total). Pre-formatted by the
     * caller — this component does no number formatting, the same rule `MdsStatTile` follows.
     */
    badge?: string | number;
}

const props = withDefaults(
    defineProps<{
        items: TabNavItem[];
        /** The `key` of the item representing the page currently open. */
        current: string;
        /**
         * Names the landmark. Required, and it should name the RESOURCE ("Clinic Intake") rather than say
         * "Tabs": a page can hold several navigation landmarks, and axe's `landmark-unique` distinguishes them
         * by accessible name.
         */
        ariaLabel: string;
        /**
         * What each item renders as. Defaults to a plain anchor; an Inertia application passes its own Link
         * so a tab switch is a client visit rather than a document load. See the docblock — the default is
         * load-bearing and must not change.
         */
        linkComponent?: Component | string;
    }>(),
    { linkComponent: 'a' },
);

/**
 * The active item resolved ONCE, by index.
 *
 * ⚠️ Comparing `item.key === current` per row looks equivalent and is not: two items sharing a key render
 * TWO `aria-current="page"` elements, which is an ARIA error, and Vue emits no duplicate-key warning on
 * initial mount so nothing anywhere reports it. `-1` when nothing matches is the correct answer and stays
 * one — a strip whose `current` names no item marks nothing rather than defaulting to the first.
 */
const currentIndex = computed(() => props.items.findIndex((item) => item.key === props.current));
</script>

<template>
    <nav v-if="items.length > 0" class="mds-tabnav" :aria-label="ariaLabel">
        <!-- `role="list"` survives `list-style: none`, which Safari/VoiceOver otherwise strips list
             semantics for — the same fix and the same reason as `NotificationBell.vue`'s panel list. -->
        <ul class="mds-tabnav__list" role="list">
            <li v-for="(item, index) in items" :key="item.key" class="mds-tabnav__item">
                <component
                    :is="linkComponent"
                    class="mds-tabnav__link"
                    :class="{ 'is-current': index === currentIndex }"
                    :href="item.href"
                    :aria-current="index === currentIndex ? 'page' : undefined"
                >
                    <Icon v-if="item.icon" :name="item.icon" size="sm" />
                    <span class="mds-tabnav__label">{{ item.label }}</span>
                    <Badge
                        v-if="item.badge !== undefined"
                        variant="neutral"
                        :label="String(item.badge)"
                    />
                </component>
            </li>
        </ul>
    </nav>
</template>

<style scoped>
.mds-tabnav {
    border-bottom: 1px solid var(--mds-color-border-default);
    margin-bottom: var(--mds-space-5);
}

/*
 * `overflow-x: auto` with no breakpoint: the strip carries between two and six items and their widths depend
 * on the type scale, so a media query would have to know both. The links are the focus stops (see the
 * docblock), and `max-width: 100%` keeps the overflow inside this element rather than on the document —
 * `assertClean` fails a page that scrolls horizontally, and a nav that widened the body would fail it here
 * for every page that adopts the strip.
 */
.mds-tabnav__list {
    display: flex;
    gap: var(--mds-space-1);
    max-width: 100%;
    margin: 0;
    padding: 0;
    overflow-x: auto;
    list-style: none;
    scrollbar-width: thin;
}

.mds-tabnav__item {
    flex: 0 0 auto;
}

.mds-tabnav__link {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-2);
    padding: var(--mds-space-3) var(--mds-space-4);
    /* The underline is drawn as a transparent border on every item, so the active one does not shift its
       neighbours by 2px when it changes. */
    border-bottom: 2px solid transparent;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-secondary);
    text-decoration: none;
    white-space: nowrap;
    transition:
        color var(--mds-duration-fast) var(--mds-ease-standard),
        border-color var(--mds-duration-fast) var(--mds-ease-standard);
}

.mds-tabnav__link:hover {
    color: var(--mds-color-text-body);
    border-bottom-color: var(--mds-color-border-strong);
}

/* `-fg`, NEVER `-bg` — see the docblock. `-bg` is a fill guaranteed only against the text printed on it,
   and measures 2.12:1 against the dark surface; `-fg` is guaranteed against surface AND canvas in both
   themes for every tenant brand by BRAND_RAMP_PAIRINGS. axe does not check border contrast. */
.mds-tabnav__link.is-current {
    color: var(--mds-color-text-heading);
    font-weight: var(--mds-font-weight-semibold);
    border-bottom-color: var(--mds-color-action-primary-fg);
}

/* Inset, like MdsDataTable's scroll region and for the same reason: the strip spans the full content width,
   so an outset ring on the first or last item would be clipped by the scroll container. */
.mds-tabnav__link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: -2px;
    border-radius: var(--mds-radius-sm);
}
</style>
