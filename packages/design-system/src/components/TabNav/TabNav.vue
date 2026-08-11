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
 * colour moves from secondary to heading — both verified on `bg-surface` in both themes, which is why this
 * does not reach for `action-primary-fg` (H21d1: a foreground that is fine in light can fail in dark, and
 * the accent ramps are re-pointed per theme and per brand).
 */
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
}>();
</script>

<template>
    <nav class="mds-tabnav" :aria-label="ariaLabel">
        <ul class="mds-tabnav__list">
            <li v-for="item in items" :key="item.key" class="mds-tabnav__item">
                <a
                    class="mds-tabnav__link"
                    :class="{ 'is-current': item.key === current }"
                    :href="item.href"
                    :aria-current="item.key === current ? 'page' : undefined"
                >
                    <Icon v-if="item.icon" :name="item.icon" size="sm" />
                    <span class="mds-tabnav__label">{{ item.label }}</span>
                    <Badge
                        v-if="item.badge !== undefined"
                        variant="neutral"
                        :label="String(item.badge)"
                    />
                </a>
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

.mds-tabnav__link.is-current {
    color: var(--mds-color-text-heading);
    font-weight: var(--mds-font-weight-semibold);
    border-bottom-color: var(--mds-color-action-primary-bg);
}

/* Inset, like MdsDataTable's scroll region and for the same reason: the strip spans the full content width,
   so an outset ring on the first or last item would be clipped by the scroll container. */
.mds-tabnav__link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: -2px;
    border-radius: var(--mds-radius-sm);
}
</style>
