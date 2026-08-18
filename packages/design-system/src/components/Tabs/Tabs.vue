<script setup lang="ts">
/**
 * The in-page tablist (Increment J4c, DSR §3.4).
 *
 * Switches between views of ONE resource whose panels are already in the document. Its entire contract is
 * that **no navigation happens** — activating an item reveals a panel and leaves the reader exactly where
 * they were.
 *
 * ⚠️ NO HTML TAG IS SPELLED WITH ANGLE BRACKETS ANYWHERE IN THIS COMMENT, AND THAT IS DELIBERATE. The
 * vue3-vite preset preserves comments for docgen, so the SFC parser tokenises comment bodies that Vitest,
 * vue-tsc and the app's own Vite build all skip — and the error names a line past the end of the file. It
 * cost J1a a Storybook build and J4b a red CI run. Name the element or the role; never quote it.
 *
 * ── THE THREE COMPONENTS THIS IS NOT, AND EACH DISTINCTION IS A CORRECTNESS ONE ─────────────────────────
 * `MdsTabNav` is a navigation landmark of LINKS with aria-current: each item is a URL with its own route
 * and its own gate, so tab roles there would announce "tab, 2 of 5" and then replace the document. Read its
 * docblock before assuming the two can merge.
 *
 * `MdsSegmentedControl` is a radiogroup. It is the right answer when the thing being switched is not a
 * panel this component owns — most importantly the form builder's compact pane switcher, where the panes
 * are the control's SIBLINGS rather than its tabpanel children, so there is no aria-controls relationship
 * to express and nothing for a tablist's contract to be about.
 *
 * ⛔ **THAT LAST ONE IS A STANDING PROHIBITION, NOT A PREFERENCE: THIS COMPONENT MUST NEVER BE RETROFITTED
 * ONTO THE BUILDER'S PANE SWITCHER OR ITS STRUCTURE-VERSUS-LOGIC TOGGLE.** DSR §3.4 carries the measurement.
 * Thirteen end-to-end locators walk the tab role on the builder — four of them LOOPS that click every match
 * — and `ConfigPanel` already owns the page's one tablist. A second one joins it in all thirteen: the loops
 * would click pane-switch tabs in the middle of a scan, destroying the pane the scan was about to measure,
 * and every settle locator would resolve to whichever strip came first in the DOM.
 *
 * ── ARIA-CONTROLS IS ON THE SELECTED TAB ONLY, AND THAT IS THE SHARP EDGE OF THIS COMPONENT ─────────────
 * Only the selected panel is in the document — that is what "already in the document" means for a set of
 * panels rendered one at a time. So an aria-controls on every tab would point at ids that do not exist for
 * every unselected one. The palette states the same rule for aria-activedescendant and states why: a
 * relationship pointing at an absent id is WORSE than an absent relationship, because a screen reader
 * announces nothing and the user has no way to tell. ⚠️ axe downgrades a dangling aria-controls to
 * *incomplete* rather than a violation, so the merge gate would stay green over the lazy version.
 *
 * ── ACTIVATION IS AUTOMATIC ─────────────────────────────────────────────────────────────────────────────
 * An arrow key moves focus AND selects. The APG sanctions this where revealing a panel is cheap, and these
 * panels are local state that is already mounted. Manual activation would be the right call only if
 * selecting a tab cost a round trip — at which point the thing being switched is probably a page, and the
 * component is probably `MdsTabNav`.
 *
 * ── WHEN NOTHING MATCHES, THE FIRST TAB IS STILL THE TAB STOP — AND MdsTabNav DOES THE OPPOSITE ─────────
 * A `modelValue` naming no item selects nothing: no tab gets aria-selected, and the panel gets no label, so
 * the component never invents a selection the consumer did not ask for. But the roving tabindex cannot
 * follow it there. Exactly one tab must be reachable by Tab at all times, and "no item matched" would
 * otherwise take the whole strip out of the tab sequence — a control nobody can reach by keyboard, which is
 * a worse failure than a strip that marks nothing. `MdsTabNav` resolves the same case to -1 and is right to,
 * because its items are links and stay focusable regardless.
 */
import { computed, ref, useId } from 'vue';

export interface TabItem {
    /** Matched against `modelValue` to decide the selected tab. Never rendered. */
    key: string;
    label: string;
}

const props = defineProps<{
    items: TabItem[];
    /** The `key` of the selected tab. */
    modelValue: string;
    /**
     * Names the tablist. Required, and it should name what is being switched ("Configuration sections")
     * rather than say "Tabs" — a page may hold more than one, and they are told apart by accessible name.
     *
     * ⚠️ PASS IT AS `ariaLabel`: the kebab spelling FAILS THE TYPE CHECK, and only the type check.
     * `vue-tsc` rejects it — it did on this component's first call site — because `aria-label` is also a
     * native attribute, so it type-checks as a fall-through and this prop reads as missing.
     *
     * ⚠️ AN EARLIER VERSION OF THIS NOTE WENT FURTHER AND WAS WRONG, WHICH THE MUTATION PASS IS WHAT
     * DISPROVED. It claimed the kebab spelling leaves the tablist unnamed at runtime. It does not: Vue
     * CAMELIZES a hyphenated prop key, so `aria-label` resolves to this prop and the name lands exactly
     * where it should. Mutating the real call site to the kebab spelling left the suite green, which is the
     * correct answer — and a docblock describing a hazard that does not exist is how the next author comes
     * to distrust the ones that do. The prop keeps this name because `MdsSegmentedControl` and `MdsTabNav`
     * already use it.
     */
    ariaLabel: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [key: string] }>();

const idPrefix = useId();
const panelId = `${idPrefix}-panel`;

const root = ref<HTMLElement | null>(null);

function tabId(index: number): string {
    return `${idPrefix}-tab-${index}`;
}

/**
 * The selected item resolved ONCE, by index — `MdsTabNav`'s rule and its reason: comparing per row looks
 * equivalent and is not, because two items sharing a key would render two selected tabs and Vue emits no
 * duplicate-key warning on initial mount, so nothing anywhere would report it.
 */
const selectedIndex = computed(() => props.items.findIndex((item) => item.key === props.modelValue));

/** Which tab holds the single tabindex of 0. See the docblock for why this is not `selectedIndex`. */
const stopIndex = computed(() => (selectedIndex.value === -1 ? 0 : selectedIndex.value));

/**
 * Focus is moved by querying this component's own root for the data attribute, not through an array
 * template ref. Vue does not guarantee a v-for ref array is ordered like its source, and `items` here is a
 * computed list that gains and loses members as the consumer's selection changes — an index into an array
 * whose order is undefined is the kind of thing that works until it silently does not.
 */
function focusTab(index: number): void {
    root.value?.querySelector<HTMLButtonElement>(`[data-mds-tab-index="${index}"]`)?.focus();
}

function select(index: number): void {
    const item = props.items[index];

    if (item !== undefined) emit('update:modelValue', item.key);
}

function onKeydown(event: KeyboardEvent, index: number): void {
    const count = props.items.length;
    let next: number;

    switch (event.key) {
        case 'ArrowRight':
            next = (index + 1) % count;
            break;
        case 'ArrowLeft':
            next = (index - 1 + count) % count;
            break;
        case 'Home':
            next = 0;
            break;
        case 'End':
            next = count - 1;
            break;
        default:
            return;
    }

    // Only after a key we actually handle — an unconditional preventDefault would swallow Tab, Enter and
    // every printable character the browser is meant to act on.
    event.preventDefault();
    select(next);
    focusTab(next);
}
</script>

<template>
    <!-- Nothing renders for an empty set: a tabpanel with no tab to name it has no accessible name, and a
         tablist with no tabs is not a control. The consumer owns its own empty state — the same rule
         MdsTabNav follows. -->
    <div v-if="items.length > 0" ref="root" class="mds-tabs">
        <!-- The rule under the strip is on THIS element, and the scrolling is on its child. They must not
             be the same element — see the stylesheet, where the measurement is recorded. MdsTabNav has the
             same two-element split for the same reason. -->
        <div class="mds-tabs__bar">
        <div class="mds-tabs__list" role="tablist" :aria-label="ariaLabel">
            <button
                v-for="(item, index) in items"
                :id="tabId(index)"
                :key="item.key"
                type="button"
                role="tab"
                class="mds-tabs__tab"
                :class="{ 'is-selected': index === selectedIndex }"
                :data-mds-tab-index="index"
                :aria-selected="index === selectedIndex"
                :aria-controls="index === selectedIndex ? panelId : undefined"
                :tabindex="index === stopIndex ? 0 : -1"
                @click="select(index)"
                @keydown="onKeydown($event, index)"
            >
                {{ item.label }}
            </button>
        </div>
        </div>

        <!-- No tabindex. The APG puts a panel in the tab sequence only when it holds nothing focusable;
             one that does would mint a redundant stop in front of its own controls. -->
        <div
            :id="panelId"
            class="mds-tabs__panel"
            role="tabpanel"
            :aria-labelledby="selectedIndex === -1 ? undefined : tabId(selectedIndex)"
        >
            <slot />
        </div>
    </div>
</template>

<style scoped>
.mds-tabs {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    /* A flex item's automatic minimum size is its content width, so without this the strip pushes its
       container wider instead of scrolling — the same one-line fix the nav's centre region carries. */
    min-width: 0;
}

/*
 * ⚠️ THE RULE AND THE SCROLLING ARE ON TWO DIFFERENT ELEMENTS, AND THE FIRST VERSION PUT THEM ON ONE — WHICH
 * THE ADVERSARIAL PASS MEASURED AS A 1px VERTICAL SCROLL CONTAINER, THE FIFTH INSTANCE OF A CLASS THIS
 * REPOSITORY HAS ALREADY PAID FOR FOUR TIMES.
 *
 * The mechanism, because it is not obvious from either declaration alone. `overflow-x: auto` with
 * `overflow-y` unset COERCES the other axis to `auto` (CSS Overflow 3) — the same rule that forces
 * MdsTooltip to teleport out of the sidebar rail. Give a flex ITEM in that box a negative bottom margin (to
 * pull its 2px underline over the container's own 1px rule) and the container's height shrinks by one pixel
 * while the item's border box does not: `scrollHeight` 35 against `clientHeight` 34, measured in the
 * running builder at both 1440 and 375.
 *
 * ⚠️ THIS PARAGRAPH DELIBERATELY DESCRIBES THAT DECLARATION INSTEAD OF SPELLING IT. The first draft wrote
 * it out and the test below — which scans this stylesheet for exactly that shape — failed against its own
 * explanation. Sixth occurrence in this project of *name the thing, never quote it*, and the second to
 * booby-trap the very note explaining it.
 *
 * ⚠️ NOTHING WE RUN COULD HAVE CAUGHT IT. happy-dom lays nothing out. The end-to-end overflow assertion
 * reads the DOCUMENT's scroll box, which `.app-shell { overflow-x: clip }` pins flat. And axe's
 * `scrollable-region-focusable` fires only on a scroll region with NO focusable descendants — every child
 * here is a button, so axe is correctly silent about a region that should not have been scrollable at all.
 *
 * `MdsTabNav` never had this: its rule is on the outer landmark and its scrolling is on a separate child,
 * with no negative margin anywhere. This component now has the same two-element split, for the same reason.
 * The 2px underline sits directly above the 1px rule rather than over it, which is what that component
 * already looks like.
 */
.mds-tabs__bar {
    border-bottom: 1px solid var(--mds-color-border-default);
}

/*
 * `overflow-x: auto` with no breakpoint: the item count and their widths both depend on the consumer and on
 * the §2.9 type scale, so a media query would have to know both.
 *
 * The scroll region is deliberately NOT focusable, unlike MdsDataTable's. axe's
 * `scrollable-region-focusable` fires only on a scrollable region with NO focusable descendants; every child
 * here is a button, so off-screen content is already reachable and the browser scrolls it into view on
 * focus. A tabindex would mint a redundant stop in front of the very controls it would exist to reach. The
 * table needs one because its cells are usually plain text; this does not. `MdsTabNav` records the same
 * reasoning for the same shape.
 */
.mds-tabs__list {
    display: flex;
    gap: var(--mds-space-1);
    max-width: 100%;
    overflow-x: auto;
    scrollbar-width: thin;
}

.mds-tabs__tab {
    flex: 0 0 auto;
    padding: var(--mds-space-2) var(--mds-space-3);
    border: 0;
    /* Drawn as a transparent border on every tab, so selecting one does not shift its neighbours by 2px.
       ⚠️ NO NEGATIVE MARGIN — see the bar rule above. A tab is a flex item of a scroll container, and a
       negative bottom margin there buys a 1px overlap at the cost of 1px of real vertical scroll. */
    border-bottom: 2px solid transparent;
    background: transparent;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-secondary);
    white-space: nowrap;
    cursor: pointer;
    transition:
        color var(--mds-duration-fast) var(--mds-ease-standard),
        border-color var(--mds-duration-fast) var(--mds-ease-standard);
}

.mds-tabs__tab:hover {
    color: var(--mds-color-text-body);
    border-bottom-color: var(--mds-color-border-strong);
}

/*
 * ⚠️ THE UNDERLINE IS `action-primary-fg`, AND THE OBVIOUS-LOOKING `action-primary-bg` IS A REAL WCAG
 * 1.4.11 FAILURE — the third time this repository has paid for it. DSR §3.4 specifies "--mds-primary-600
 * 2px", whose semantic name in light is the `-bg` fill; J2a measured that on MdsTabNav, J4a measured it on
 * the personalization accent bar at 2.54:1 dark, and `ConfigPanel.vue` was still carrying it on this very
 * strip when J4c arrived. A fill's only guaranteed contrast is against the text printed ON it —
 * BRAND_RAMP_PAIRINGS pairs `bg` solely with `on_primary` — while an underline is a non-text UI component
 * and owes 3:1 against the surface BEHIND it. `-fg` is paired against both surface and canvas, in both
 * themes, for every tenant brand, at the 4.5:1 text minimum. axe checks no border contrast, so no gate we
 * run would catch a regression here.
 *
 * ⚠️ ON THIS STRIP IT WAS A LIVE FAILURE, NOT HYGIENE — measured in the running builder across all four
 * shipped combinations, old → new: blueprint light 4.71 → 7.01, blueprint dark 3.42 → 8.29, teal light
 * 7.48 → 7.48 (both tokens resolve to one colour there), and teal dark 2.41 → 6.39. That 2.41 is BELOW the
 * 3:1 threshold, on a §2.9 accent any user can pick for themselves. J4a's two cases went opposite ways on
 * this same question, which is why the answer has to be measured rather than reasoned about.
 *
 * Three non-colour channels, matching MdsTabNav exactly: the underline, the heavier weight, and
 * aria-selected. The label colour moving from secondary to heading is the fourth and is not relied on.
 */
.mds-tabs__tab.is-selected {
    color: var(--mds-color-text-heading);
    font-weight: var(--mds-font-weight-semibold);
    border-bottom-color: var(--mds-color-action-primary-fg);
}

/* Inset, like MdsTabNav's and MdsDataTable's scroll regions and for the same reason: the strip is itself
   the scroll container, so an outset ring on the first or last tab would be clipped by it. */
.mds-tabs__tab:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: -2px;
    border-radius: var(--mds-radius-sm);
}

/* §3.2's governing layout rule is a vertical field stack at this gap, which is what a tab panel almost
   always holds. A consumer needing a different arrangement wraps its own slot content. */
.mds-tabs__panel {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    min-width: 0;
}
</style>
