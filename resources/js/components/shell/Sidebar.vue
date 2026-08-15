<script setup lang="ts">
/**
 * Primary sidebar navigation. Items are real Inertia <Link>s (standard navigation — a list of links, not
 * a roving menu widget); the active item shows three non-colour signifiers (left accent bar + bold + tint).
 * Responsive: full (>1024) → icon-only rail with tooltips (≤1024) → off-canvas drawer (≤480) toggled from
 * the top nav.
 *
 * ⚠️ THE RAIL'S TOOLTIP IS A REAL COMPONENT NOW, NOT A `title` (J4b). The native attribute never appeared
 * on keyboard focus at all, which is half of what DSR §3.4 asks for — and it cannot be dismissed, cannot be
 * hovered, and cannot be styled. It is gone from both branches rather than left alongside `MdsTooltip`,
 * because two tooltips on one element render one under the other.
 *
 * ⚠️ THE TOOLTIP MUST STAY TELEPORTED HERE, WHICH IS WHY IT IS A PACKAGE COMPONENT AND NOT A LOCAL DIV.
 * `.sidebar` is `overflow-y: auto`, and per CSS Overflow 3 that drags `overflow-x` to `auto` too — so an
 * in-flow bubble in a 64px rail is clipped and silently adds an internal scrollbar. Nothing in CI could
 * see that: the shell clips its own horizontal axis, so the document-level overflow assertion reads flat.
 *
 * ⚠️ AND THE DRAWER TAKES THE PAGE, WITHOUT BECOMING A DIALOG. This root wraps the primary navigation
 * landmark at ALL THREE breakpoints; stamping `role="dialog"` on it below 480px would make the landmark's
 * identity depend on the viewport, which is exactly what DSR §6 promises it never does. `useInertBackground`
 * marks everything outside inert instead — which is a stronger guarantee than a Tab trap, because `inert`
 * also covers the virtual cursor and browser-chrome round trips that a keydown handler never sees.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { MdsIcon, MdsTooltip, useInertBackground } from '@meridian/design-system';
import { navGroups, type NavItem } from './nav-model';

const props = defineProps<{ drawerOpen: boolean }>();
const emit = defineEmits<{ close: [] }>();

const page = usePage();
const wrap = ref<HTMLElement | null>(null);

// Hide permission-gated items (e.g. Members) from users who lack the ability, AND plan-gated items
// (e.g. Webhooks = Starter+) from tenants whose plan lacks the feature. Both gates resolve fail-closed
// off-tenant (auth.can.* false, entitlements null), so central/guest chrome never shows a tenant-only
// destination — and a plan-gated item never appears where its `feature:` route guard would only bounce.
function isVisible(item: NavItem): boolean {
    return (!item.gate || page.props.auth.can[item.gate])
        && (!item.feature || page.props.entitlements?.features?.[item.feature] === true);
}

/**
 * ⚠️ A GROUP WHOSE ITEMS ARE ALL GATED AWAY RENDERS NOTHING — no label, no empty list, no separator. This
 * is not defensive: the filter above is aggressive enough that the floor is TWO items (Dashboard and
 * Settings are the only ungated ones, so off-tenant chrome shows just those), a viewer on any plan below
 * Business sees three, and an Owner on Free loses the whole Connections run because all three of its items
 * are plan-gated. An unconditional heading would print a category over nothing in every one of those cases.
 */
const visibleGroups = computed(() =>
    navGroups
        .map((group) => ({ ...group, items: group.items.filter(isVisible) }))
        .filter((group) => group.items.length > 0),
);

function labelId(key: string): string {
    return `nav-group-${key}`;
}

function isActive(href: string): boolean {
    const path = page.url.split('?')[0];
    return path === href || path.startsWith(`${href}/`);
}

// ── The drawer's own breakpoint ──────────────────────────────────────────────────────────────────────
// §6: components carry their own responsive behaviour and pages never add breakpoint logic. The layout
// owns only the boolean.
const MOBILE_QUERY = '(max-width: 480px)';
/**
 * The rail band, and it is a BAND rather than a maximum — 481px to 1024px, the only widths at which an
 * item's label is hidden.
 *
 * ⚠️ THE TOOLTIP IS OFF EVERYWHERE ELSE, AND LEAVING IT ON WAS A REAL DEFECT RATHER THAN A TIDINESS
 * QUESTION. Above 1024px the label sits in the item, so a bubble repeating it is pure noise. Below 480px
 * the drawer restores the label AND there is no hover to summon a tooltip with — but focus still fires, and
 * `useInertBackground` moves focus into the drawer programmatically the moment it opens. So on mobile the
 * drawer would open, silently show a tooltip nobody asked for, and then EAT THE FIRST ESCAPE: the tooltip's
 * capture-phase handler dismisses itself and stops the key before the drawer's own handler ever sees it.
 * The user presses Escape on a drawer and nothing appears to happen. Found by the drawer's own test.
 */
const RAIL_QUERY = '(min-width: 481px) and (max-width: 1024px)';
const isMobile = ref(false);
const isRail = ref(false);
let mobileMql: MediaQueryList | null = null;
let railMql: MediaQueryList | null = null;

function onMobileChange(event: MediaQueryListEvent): void {
    isMobile.value = event.matches;

    // ⚠️ CLOSING ON THE WAY UP IS NOT TIDINESS. Above 480px `.is-open` styles nothing, so a live
    // `drawerOpen` is invisible but real — and it would leave `<main>` marked inert at desktop, where there
    // is no drawer and no scrim to explain why the page has stopped responding. Releasing also clears the
    // inline z-index the stack wrote.
    if (!event.matches) emit('close');
}

function onRailChange(event: MediaQueryListEvent): void {
    isRail.value = event.matches;
}

onMounted(() => {
    mobileMql = window.matchMedia(MOBILE_QUERY);
    isMobile.value = mobileMql.matches;
    mobileMql.addEventListener('change', onMobileChange);

    railMql = window.matchMedia(RAIL_QUERY);
    isRail.value = railMql.matches;
    railMql.addEventListener('change', onRailChange);
});

onBeforeUnmount(() => {
    mobileMql?.removeEventListener('change', onMobileChange);
    railMql?.removeEventListener('change', onRailChange);
});

// The seam is viewport-agnostic, so this is the gate: above 480px the drawer is not a drawer, and taking
// the page there would inert a shell that has no overlay covering it.
const takesPage = computed(() => props.drawerOpen && isMobile.value);

useInertBackground({ active: takesPage, root: wrap, initialFocus: '.sidebar__item' });

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && props.drawerOpen) emit('close');
}
</script>

<template>
    <div
        id="app-drawer"
        ref="wrap"
        class="sidebar-wrap"
        :class="{ 'is-open': drawerOpen }"
        tabindex="-1"
        @keydown="onKeydown"
    >
        <div class="sidebar-scrim" @click="emit('close')" />
        <nav class="sidebar" aria-label="Primary">
            <div v-for="group in visibleGroups" :key="group.key" class="sidebar__group">
                <!--
                    ⚠️ A <div>, AND THE ELEMENT TYPE IS PINNED BY THREE SEPARATE THINGS.
                    (1) It sits OUTSIDE the <ul>: axe's `list` rule is WCAG-tagged and therefore inside the
                        e2e scan's tag set, so a heading between a list and its items would redden roughly
                        forty cases at once — the sidebar is scanned on every whole-page assertClean.
                    (2) NOT a <span>: `Sidebar.test.ts`'s label helper selects `nav a, nav span` and asserts
                        four item names are ABSENT for gated-away users. A span here joins that text.
                    (3) NOT an <h2>: this nav renders ahead of every page's own <h1>, so headings here would
                        lead each page's outline with shell chrome. `aria-labelledby` on the list announces
                        the group when the reader enters it, which is where the information is wanted, and
                        costs no outline at all.
                -->
                <div v-if="group.label" :id="labelId(group.key)" class="sidebar__group-label">
                    {{ group.label }}
                </div>
                <!--
                    `role="list"` survives `list-style: none`, which Safari/VoiceOver otherwise strips list
                    semantics for — the same note MdsBreadcrumb, MdsTabNav and this directory's own
                    NotificationBell already carry. Load-bearing rather than tidy now that grouping exists,
                    since a stripped list would take the group's name with it.
                -->
                <ul
                    class="sidebar__list"
                    role="list"
                    :aria-labelledby="group.label ? labelId(group.key) : undefined"
                >
                    <li v-for="item in group.items" :key="item.key">
                        <MdsTooltip :text="item.label" placement="right" block :disabled="!isRail">
                            <template #default="{ trigger }">
                                <Link
                                    v-bind="trigger"
                                    :href="item.href"
                                    class="sidebar__item"
                                    :class="{ 'is-active': isActive(item.href) }"
                                    :aria-current="isActive(item.href) ? 'page' : undefined"
                                    @click="emit('close')"
                                >
                                    <MdsIcon :name="item.icon" size="md" />
                                    <span class="sidebar__label">{{ item.label }}</span>
                                </Link>
                            </template>
                        </MdsTooltip>
                    </li>
                </ul>
            </div>
        </nav>
    </div>
</template>

<style scoped>
.sidebar-wrap {
    flex-shrink: 0;
}

.sidebar-scrim {
    display: none;
}

.sidebar {
    width: 240px;
    height: 100%;
    padding: var(--mds-space-4) var(--mds-space-3);
    background-color: var(--mds-color-bg-surface);
    border-right: 1px solid var(--mds-color-border-default);
    overflow-y: auto;
}

.sidebar__group + .sidebar__group {
    margin-top: var(--mds-space-5);
}

.sidebar__group-label {
    padding: 0 var(--mds-space-3) var(--mds-space-1);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    font-weight: var(--mds-font-weight-semibold);
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--mds-color-text-secondary);
}

.sidebar__list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    margin: 0;
    padding: 0;
    list-style: none;
}

.sidebar__item {
    position: relative;
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
    min-height: 40px;
    padding: 0 var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
    text-decoration: none;
}

a.sidebar__item:hover:not(.is-active) {
    background-color: var(--mds-color-bg-sunken);
}

a.sidebar__item:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: -2px;
}

.sidebar__item.is-active {
    background-color: var(--mds-color-action-primary-tint);
    color: var(--mds-color-text-heading);
    font-weight: var(--mds-font-weight-semibold);
}

/* Left-edge accent bar — a non-colour signifier of the active item, paired with the bold weight.
   ⚠️ `-fg`, NOT `-bg`, AND THE DIFFERENCE IS A CONTRAST GUARANTEE RATHER THAN A SHADE. DSR §3.4 states the
   rule for every coloured rule, edge or indicator in the system: the only contrast `-bg` guarantees is
   against the text printed ON it, because the brand ramp pairs it solely with `on_primary`. This bar is
   painted on the active item's tint and is a non-text UI component owing 3:1 against it — a guarantee only
   `-fg` carries, and for every tenant brand rather than the two shipped accents. Same defect J4a fixed on
   the capacity meter; axe checks no border or indicator contrast, so no gate here can see it. */
.sidebar__item.is-active::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 3px;
    height: 60%;
    border-radius: var(--mds-radius-full);
    background-color: var(--mds-color-action-primary-fg);
}

.sidebar__label {
    flex: 1;
}

/* ── Tablet (≤1024): icon-only, labels to AT via the clip idiom and to sight via MdsTooltip ─────────── */
@media (max-width: 1024px) {
    .sidebar {
        width: 64px;
        padding: var(--mds-space-4) var(--mds-space-2);
    }
    .sidebar__item {
        justify-content: center;
        gap: 0;
    }
    .sidebar__label {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
        white-space: nowrap;
    }
    /* ⚠️ `display: none`, NOT the clip idiom, and the reason is JR4's rule rather than convenience: a
       clipped control is still a focus stop the user cannot see. There is no information loss either way —
       §6 and the two glyph-uniqueness notes in nav-model.ts all say the mark is the sole signifier at this
       width — and the group's accessible NAME survives regardless, because `aria-labelledby` traverses a
       hidden referent. A 1px rule carries the boundary visually in the label's place. */
    .sidebar__group-label {
        display: none;
    }
    .sidebar__group + .sidebar__group {
        margin-top: var(--mds-space-2);
        padding-top: var(--mds-space-2);
        border-top: 1px solid var(--mds-color-border-default);
    }
}

/* ── Mobile (≤480): off-canvas drawer with scrim ────────────────────────────────────── */
@media (max-width: 480px) {
    .sidebar-wrap {
        position: fixed;
        inset: 0;
        z-index: var(--mds-z-index-drawer, 40);
        pointer-events: none;
        visibility: hidden;
    }
    .sidebar-wrap.is-open {
        pointer-events: auto;
        visibility: visible;
    }
    .sidebar-scrim {
        display: block;
        position: absolute;
        inset: 0;
        background-color: var(--mds-color-overlay-scrim);
        opacity: 0;
        transition: opacity var(--mds-duration-base) var(--mds-ease-standard);
    }
    .sidebar-wrap.is-open .sidebar-scrim {
        opacity: 1;
    }
    .sidebar {
        position: absolute;
        top: 0;
        left: 0;
        bottom: 0;
        width: 260px;
        padding: var(--mds-space-4) var(--mds-space-3);
        transform: translateX(-100%);
        transition: transform var(--mds-duration-base) var(--mds-ease-decelerate);
        box-shadow: var(--mds-shadow-4);
    }
    .sidebar-wrap.is-open .sidebar {
        transform: translateX(0);
    }
    /* Labels and group headings return inside the open drawer — there is room, and no tooltip fires on a
       touch device to supply them. */
    .sidebar__item {
        justify-content: flex-start;
        gap: var(--mds-space-3);
    }
    .sidebar__label {
        position: static;
        width: auto;
        height: auto;
        margin: 0;
        clip: auto;
    }
    .sidebar__group-label {
        display: block;
    }
    .sidebar__group + .sidebar__group {
        margin-top: var(--mds-space-5);
        padding-top: 0;
        border-top: none;
    }
}
</style>
