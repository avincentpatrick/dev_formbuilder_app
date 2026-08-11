<script setup lang="ts">
/**
 * Path context for a nested page (Increment J2a, DSR §3.4).
 *
 * §3.4's rule: used on any screen nested more than one level below a primary sidebar section — Forms →
 * *[Form Name]* → Submissions → *[Response]*. Every crumb is a real link except the last, which is plain
 * text carrying `aria-current="page"`. It mounts through `PageHeader`'s `breadcrumbs` slot.
 *
 * That slot has TWO consumers already — `Pages/forms/Analytics.vue` and `Pages/submissions/Encode.vue` —
 * each rendering a single hand-rolled back link inside it. (An earlier draft of this docblock said "zero",
 * which was wrong and materially so: those two need only their link swapped, while the four below sit
 * outside `PageHeader` entirely and need the slot adopting first.)
 *
 * ⚠️ NO HTML TAG IS SPELLED WITH ANGLE BRACKETS ANYWHERE IN THIS COMMENT, AND THAT IS DELIBERATE — see
 * `FilterBar.vue`'s docblock for the Storybook build failure that rule exists to prevent.
 *
 * ── Why this is a component and not six more scoped-CSS back links ─────────────────────────────────────
 * Six pages hand-roll a single back link with its own class and its own rule set: the form analytics page
 * and the encode screen (both inside the slot), plus the builder, the submission detail, the webhook detail
 * and the connector rule detail (all four outside `PageHeader`). The exceptions log's own threshold —
 * "three-plus undocumented deviations for the same need signal a missing shared component" — was crossed
 * twice over before this existed.
 *
 * ── The last crumb is NOT a link, and that is the substantive contract ─────────────────────────────────
 * A self-link is a WCAG 2.4.4 nuisance (a destination that goes nowhere) and, more practically, it makes
 * the trail's tail indistinguishable from its body for anyone navigating by links. `aria-current="page"` is
 * what tells a screen-reader user where in the trail they are; a trailing link with no `aria-current` reads
 * as one more place to go. The component decides this from the item's POSITION rather than from an
 * `href`-is-absent test, so a caller who passes an href for every crumb still gets a correct trail.
 *
 * ── The separator is decorative and must never be read out ─────────────────────────────────────────────
 * It is `aria-hidden` and generated in CSS-free markup (a span, so it can carry the attribute — a `::after`
 * would be announced by some screen readers as content). Left as a literal chevron glyph rather than an
 * `MdsIcon`: at caption size an SVG stroke renders heavier than the surrounding text and the trail stops
 * reading as one line.
 */
export interface BreadcrumbItem {
    label: string;
    /**
     * Omitted on any crumb that should render as text. The LAST item is always rendered as text whether or
     * not it carries one — see the docblock.
     */
    href?: string;
}

defineProps<{
    items: BreadcrumbItem[];
    /**
     * Names the landmark. Defaults to "Breadcrumb", the conventional name; override only when a page
     * renders two trails, because axe's `landmark-unique` distinguishes navigation landmarks by name.
     */
    ariaLabel?: string;
}>();

function isLast(index: number, length: number): boolean {
    return index === length - 1;
}
</script>

<template>
    <nav v-if="items.length > 0" class="mds-breadcrumb" :aria-label="ariaLabel ?? 'Breadcrumb'">
        <!-- `role="list"` survives `list-style: none`, which Safari/VoiceOver otherwise strips list
             semantics for. Load-bearing here: the separators are `aria-hidden` precisely BECAUSE the list
             structure is what conveys the relationship, so losing that structure would leave a screen
             reader with an unpunctuated run of words. -->
        <ol class="mds-breadcrumb__list" role="list">
            <li v-for="(item, index) in items" :key="`${index}-${item.label}`" class="mds-breadcrumb__item">
                <a
                    v-if="item.href && !isLast(index, items.length)"
                    class="mds-breadcrumb__link"
                    :href="item.href"
                >{{ item.label }}</a>
                <span
                    v-else
                    class="mds-breadcrumb__current"
                    :aria-current="isLast(index, items.length) ? 'page' : undefined"
                >{{ item.label }}</span>

                <span v-if="!isLast(index, items.length)" class="mds-breadcrumb__sep" aria-hidden="true">
                    /
                </span>
            </li>
        </ol>
    </nav>
</template>

<style scoped>
.mds-breadcrumb__list {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-1);
    margin: 0;
    padding: 0;
    list-style: none;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
}

/*
 * Wraps rather than scrolls, unlike MdsTabNav. A trail is read once and is rarely more than four crumbs, so
 * a second line at 375px is cheaper than a scroll container the reader has to discover — and `flex-wrap`
 * keeps the whole trail out of the document's horizontal scroll width, which `assertClean` fails on.
 */
.mds-breadcrumb__item {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-1);
    min-width: 0;
}

.mds-breadcrumb__link {
    color: var(--mds-color-text-secondary);
    text-decoration: none;
}

.mds-breadcrumb__link:hover {
    color: var(--mds-color-text-body);
    text-decoration: underline;
}

.mds-breadcrumb__link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
}

.mds-breadcrumb__current {
    color: var(--mds-color-text-body);
    font-weight: var(--mds-font-weight-medium);
    overflow-wrap: anywhere;
}

.mds-breadcrumb__sep {
    color: var(--mds-color-text-secondary);
}
</style>
