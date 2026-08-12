<script setup lang="ts" generic="Row extends Record<string, unknown>">
/**
 * The shared data table (DSR §3.3) — the single most-used composite (members roster, admin lists, and
 * later the submissions inbox / webhook log / audit log). One component covers them all: a page
 * configures WHICH columns/actions appear, never HOW the table lays out (header/cell padding, row
 * height, hover, the loading skeleton, and the card-per-row collapse are all owned here — §3.3 governing
 * rule). Consumes semantic tokens only. Generic over the row shape, so `#cell-<key>` / `#row-actions`
 * slots receive a fully-typed `row` (no casts needed by consumers).
 *
 * Slots: `#cell-<key>="{ row, value }"` for custom cells (badges, links), `#row-actions="{ row }"` for
 * a trailing per-row action cell, and `#empty` for the zero-rows state (compose `MdsEmptyState`).
 *
 * Loading renders a structure-preserving skeleton (not a spinner) so the layout doesn't jump (§3.9);
 * the table advertises `aria-busy` while loading. Sortable columns toggle an internal client-side sort
 * with `aria-sort` on the header (§4.3).
 */
import { computed, onBeforeUnmount, onMounted, ref, useSlots, watch } from 'vue';
import Icon from '../Icon/Icon.vue';
import Skeleton from '../Skeleton/Skeleton.vue';

export interface DataTableColumn {
    key: string;
    header: string;
    align?: 'start' | 'end';
    sortable?: boolean;
}

const props = withDefaults(
    defineProps<{
        columns: DataTableColumn[];
        rows: Row[];
        rowKey?: string;
        caption: string;
        loading?: boolean;
        skeletonRows?: number;
    }>(),
    { rowKey: 'id', loading: false, skeletonRows: 5 },
);

const slots = useSlots();
const hasRowActions = computed(() => Boolean(slots['row-actions']));
const columnCount = computed(() => props.columns.length + (hasRowActions.value ? 1 : 0));

/*
 * WHICH tables are allowed to collapse to cards (JR4) — and why the gate is the COLUMN COUNT rather than
 * a second width.
 *
 * ⚠️ NO SINGLE WIDTH CAN SEPARATE THE TWO CASES, WHICH IS THE ENTIRE REASON A GATE EXISTS. The app's
 * two-column tables are a chart's paired data equivalent and live in ~295–307px cards at EVERY viewport
 * (`Dashboard.vue`, `AnalyticsChartsCard.vue`, `QuestionResultCard.vue` — one per card in a
 * `minmax(min(100%, 320px), 1fr)` grid). A full-width page on a 375px phone is a ~343px container. Those
 * two ranges OVERLAP, so a threshold wide enough to rescue a five-column table on a tablet would put
 * every chart table in the product into card mode at 1600px as firmly as at 375px. The count has to do
 * the separating.
 *
 * THREE is the floor because a two-column table is already a key/value list — collapsing it buys nothing
 * and doubles the height of every chart card. Measured at the narrowest box either kind ever gets, the
 * widest 2-column table in the app is the platform user list, whose email column is one unbreakable
 * token: ~254px of min-content at the standard type scale, ~309px at `extra_large`. It fits.
 *
 * ⚠️ AND ONE COMBINATION WHERE IT DOES NOT, STATED RATHER THAN GLOSSED: that same list at 375px with
 * `extra_large` AND the dyslexia face wants ~345px against a ~328px box, so it becomes a ~17px scroll
 * region where it used to be cards. That state is legal and reachable — `measure()` below gives it
 * `tabindex="0"` and `role="group"`, which is exactly what that machinery is for — and the alternative
 * costs every chart table at every width. If it ever bites, the fix is a second bucket, NOT the viewport
 * query this replaced.
 *
 * Deliberately not a prop (§3.3 governing rule: a page configures WHICH columns appear, never HOW the
 * table lays out). The count already answers it for all 22 call sites, and an escape hatch would have to
 * be viewport-aware to avoid regressing phones — i.e. exactly the logic being removed here.
 */
const stackable = computed(() => columnCount.value >= 3);

const sortKey = ref<string | null>(null);
const sortDir = ref<'asc' | 'desc'>('asc');

function toggleSort(key: string) {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = key;
        sortDir.value = 'asc';
    }
}

function ariaSort(key: string): 'ascending' | 'descending' | 'none' {
    if (sortKey.value !== key) return 'none';
    return sortDir.value === 'asc' ? 'ascending' : 'descending';
}

/*
 * The stacked layout hides the header row, and the sort toggles live inside it (see the `thead` rule at
 * the foot of this file). Thirteen `sortable` columns across seven pages would simply lose their control
 * below the collapse threshold — and "sixty forms sorted by response count" is the whole reason the
 * dense view exists — so the same toggles are rendered a second time as a chip row above the cards.
 * Exactly one of the two is ever in the accessibility tree: both sides use `display: none`.
 */
const sortableColumns = computed(() => props.columns.filter((col) => col.sortable));

const sortedRows = computed<Row[]>(() => {
    if (!sortKey.value) return props.rows;
    const key = sortKey.value;
    const dir = sortDir.value === 'asc' ? 1 : -1;
    return [...props.rows].sort((a, b) => {
        const av = a[key];
        const bv = b[key];
        if (av == null) return 1;
        if (bv == null) return -1;
        if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * dir;
        return String(av).localeCompare(String(bv)) * dir;
    });
});

function keyFor(row: Row, index: number): string {
    const k = row[props.rowKey];
    return k == null ? String(index) : String(k);
}

/*
 * A horizontally scrolling region must be reachable by keyboard, or someone who cannot use a pointer
 * simply cannot read the columns that are off-screen (WCAG 2.1.1; axe `scrollable-region-focusable`).
 * `.mds-table__scroll` is `overflow-x: auto`, so ANY table wider than its container is one — this bit
 * consumers on the first page whose columns did not fit at tablet width, and it is a latent defect in
 * this component rather than that page's problem (the same reasoning as the sticky-cell fix below).
 * JR4 acted on the other half of that sentence: the tablet band is no longer a scroll region at all,
 * because the collapse below is keyed on the container instead of the viewport.
 *
 * Measured rather than assumed, so a table that fits adds no tab stop to the page: focusability is the
 * remedy for content you would otherwise be unable to reach, not a decoration. It re-measures on
 * resize (a viewport change or the personalization type scale can push a fitting table over) and
 * whenever the rows change. Guarded for SSR, where there is no element and no ResizeObserver.
 *
 * `role="group"`, deliberately NOT `region`: a page may legitimately render several tables that share a
 * caption (Integrations draws one per connected workspace), and `region` would mint duplicate landmarks
 * with the same name — an axe `landmark-unique` failure. `group` names the focus stop without that.
 */
const scroller = ref<HTMLElement | null>(null);
const scrollable = ref(false);

function measure(): void {
    const el = scroller.value;

    if (!el) {
        scrollable.value = false;

        return;
    }

    // Below the collapse threshold (56em of CONTAINER — see the block at the foot of this file) a
    // stackable table drops to a card-per-row layout with `overflow-x: visible`, so the element is not a
    // scroll container at all even if the content is wider.
    const overflowX = getComputedStyle(el).overflowX;
    const scrolls = overflowX === 'auto' || overflowX === 'scroll';

    scrollable.value = scrolls && el.scrollWidth > el.clientWidth + 1;
}

let observer: ResizeObserver | null = null;

onMounted(() => {
    measure();

    if (typeof ResizeObserver === 'undefined' || !scroller.value) return;

    observer = new ResizeObserver(() => measure());
    observer.observe(scroller.value);

    // The table itself, not just its container: a column can grow without the wrapper changing size.
    const table = scroller.value.querySelector('table');
    if (table) observer.observe(table);
});

onBeforeUnmount(() => {
    observer?.disconnect();
    observer = null;
});

watch(() => [props.rows, props.columns, props.loading], () => queueMicrotask(measure), { deep: false });
</script>

<template>
    <div class="mds-table__frame" :class="{ 'mds-table__frame--stackable': stackable }">
        <!-- ⚠️ THE SAME TOGGLES AS THE HEADER ROW, AND EXACTLY ONE SET IS EVER REACHABLE. Above the
             collapse threshold this bar is `display: none` and the `thead` carries the sort; below it the
             `thead` is `display: none` and this does. `display: none` on both sides rather than a
             visually-hidden clip is the point — a clipped duplicate would be a second control with the
             same name and an invisible focus stop, which is the very defect the stacked `thead` rule
             exists to remove. Outside `.mds-table__scroll` deliberately: it is a control for the table,
             not a part of it, and must not sit inside a region that can scroll away sideways. -->
        <div
            v-if="sortableColumns.length > 0"
            class="mds-table__sortbar"
            role="group"
            :aria-label="`Sort ${caption}`"
        >
            <span class="mds-table__sortbar-label">Sort</span>
            <button
                v-for="col in sortableColumns"
                :key="col.key"
                type="button"
                class="mds-table__sort mds-table__sortchip"
                :aria-pressed="sortKey === col.key"
                @click="toggleSort(col.key)"
            >
                {{ col.header }}
                <!-- The direction belongs in the NAME, not only in the glyph: `aria-sort` is a property of
                     a `<th>` and there is no `<th>` here, so without this a screen reader announces
                     "Submitted, pressed" and the user cannot tell which way it went. -->
                <span v-if="sortKey === col.key" class="mds-table__sr">{{
                    sortDir === 'asc' ? ', ascending' : ', descending'
                }}</span>
                <Icon
                    v-if="sortKey === col.key"
                    name="chevron-down"
                    size="sm"
                    class="mds-table__sort-icon"
                    :class="{ 'mds-table__sort-icon--asc': sortDir === 'asc' }"
                />
            </button>
        </div>

        <div
            ref="scroller"
            class="mds-table__scroll"
            :tabindex="scrollable ? 0 : undefined"
            :role="scrollable ? 'group' : undefined"
            :aria-label="scrollable ? caption : undefined"
        >
            <table class="mds-table" :aria-busy="loading || undefined">
                <caption class="mds-table__caption">{{ caption }}</caption>
                <thead>
                    <tr>
                        <th
                            v-for="col in columns"
                            :key="col.key"
                            scope="col"
                            class="mds-table__th"
                            :class="{ 'mds-table__cell--end': col.align === 'end' }"
                            :aria-sort="col.sortable ? ariaSort(col.key) : undefined"
                        >
                            <button
                                v-if="col.sortable"
                                type="button"
                                class="mds-table__sort"
                                @click="toggleSort(col.key)"
                            >
                                {{ col.header }}
                                <Icon
                                    v-if="sortKey === col.key"
                                    name="chevron-down"
                                    size="sm"
                                    class="mds-table__sort-icon"
                                    :class="{ 'mds-table__sort-icon--asc': sortDir === 'asc' }"
                                />
                            </button>
                            <template v-else>{{ col.header }}</template>
                        </th>
                        <th v-if="hasRowActions" scope="col" class="mds-table__th mds-table__cell--end">
                            <span class="mds-table__sr">Actions</span>
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <!-- Loading: structure-preserving skeleton rows (§3.9). -->
                    <template v-if="loading">
                        <tr v-for="n in skeletonRows" :key="`sk-${n}`" class="mds-table__row">
                            <td
                                v-for="col in columns"
                                :key="col.key"
                                class="mds-table__td"
                                :data-label="col.header"
                            >
                                <Skeleton variant="text" width="70%" />
                            </td>
                            <td v-if="hasRowActions" class="mds-table__td mds-table__cell--end">
                                <Skeleton variant="text" width="48px" />
                            </td>
                        </tr>
                    </template>

                    <!-- Empty: page supplies the message via #empty (compose MdsEmptyState). The class is
                         carried explicitly rather than reached with `:has()` — the stacked layout makes the
                         row group a grid, where a `colspan` means nothing and this cell must be told to
                         span every track. -->
                    <tr v-else-if="sortedRows.length === 0" class="mds-table__empty-row">
                        <td :colspan="columnCount" class="mds-table__empty">
                            <slot name="empty" />
                        </td>
                    </tr>

                    <!-- Data. -->
                    <template v-else>
                        <tr
                            v-for="(row, index) in sortedRows"
                            :key="keyFor(row, index)"
                            class="mds-table__row"
                        >
                            <td
                                v-for="col in columns"
                                :key="col.key"
                                class="mds-table__td"
                                :class="{ 'mds-table__cell--end': col.align === 'end' }"
                                :data-label="col.header"
                            >
                                <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                                    {{ row[col.key] }}
                                </slot>
                            </td>
                            <td
                                v-if="hasRowActions"
                                class="mds-table__td mds-table__cell--end mds-table__actions"
                            >
                                <slot name="row-actions" :row="row" />
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</template>

<style scoped>
/* JR4 — THE TABLE IS ITS OWN QUERY CONTAINER, and it is this element rather than `.mds-table__scroll`
   for a reason that is not stylistic: a container query never matches the container element ITSELF, and
   the collapse has to set `overflow-x: visible` on the scroll wrapper. Declare `container-type` there
   and that one declaration becomes unreachable. Declared in the component rather than asked of the page
   (§6: components carry their own responsive behaviour, pages never add breakpoint logic), so no
   consumer can forget to establish one — the same choice `FormCard.vue:209` makes for the same reason.

   ⚠️ `inline-size` CONTAINMENT MEANS THE FRAME'S WIDTH COMES FROM ITS CONTAINING BLOCK AND NEVER FROM
   ITS CONTENTS. All 22 call sites mount the table as a block in normal flow — bare on the page, inside
   an `MdsCard`, or inside a `<section>` — where that is already true. A consumer that put one inside a
   shrink-to-fit box (an `inline-flex` parent, a grid track sized `auto`) would collapse it: that is the
   one placement this component now forbids.

   ⚠️ AND THE `font-size` IS NOT DECORATION — IT PINS WHAT `em` MEANS BELOW. A container query's
   font-relative units resolve against the CONTAINER's own computed font size, so the threshold would
   shift silently for any consumer whose ancestors set a different one. `app.css` already puts this exact
   value on `body` and nothing between body and a table changes it, so this is a no-op today and a
   guarantee afterwards. It reaches no painted text: every cell sets its own size (`__td` body-md, `__th`
   caption) and slot content inherits from those. */
.mds-table__frame {
    container-type: inline-size;
    font-size: var(--mds-type-body-lg-font-size);
}

/* `position: relative` is load-bearing, not decorative. This wrapper is the table's horizontal scroll
   container, but `.mds-table__caption` and `.mds-table__sr` are `position: absolute`. Without a
   positioned ancestor here their containing block resolves OUTSIDE the
   wrapper, so `overflow-x: auto` does not clip them — a 1px visually-hidden span sitting past the last
   column then extends the DOCUMENT's scrollable width, and the whole page scrolls horizontally even
   though the table itself is correctly contained.

   Found in G11 by the personalization E2E: at 834px the combination of the extra_large type scale and
   the wider dyslexia-friendly face pushed the "Actions" span past the viewport edge and the page gained
   50px of real horizontal scroll. It is a latent bug in this component, not a personalization one —
   personalization only made the table wide enough to reach it — so it is fixed here rather than worked
   around at the page level. Note that no `overflow-x` on any ancestor can fix it: an absolutely
   positioned element is clipped by its containing block, not by an arbitrary scrolling ancestor. */
.mds-table__scroll {
    position: relative;
    width: 100%;
    overflow-x: auto;
}

/* The scroll region only becomes focusable when it actually scrolls (see `measure()`), and a focus stop
   the user cannot see is worse than none — WCAG 2.4.7. `outline-offset` is negative here, unlike the
   button's: the region spans the full content width, so an outset ring would be clipped by the page. */
.mds-table__scroll:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: -2px;
}

.mds-table {
    width: 100%;
    border-collapse: collapse;
    font-family: var(--mds-font-family-body);
    color: var(--mds-color-text-body);
}

.mds-table__caption {
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

.mds-table__sr {
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

.mds-table__th {
    padding: var(--mds-space-3) var(--mds-space-4);
    text-align: start;
    vertical-align: middle;
    border-bottom: 1px solid var(--mds-color-border-default);
    /* ⚠️ JR2 KEPT `bg-surface` HERE, DIVERGING FROM THE MOCKUP, AND THE REASON IS MEASURED.
       The direction paints its header band `--m-surface-2` `#EEF3FE`, which is byte-identical to our
       `--mds-color-bg-sunken` in light — so the swap looks free. It is not: in dark, `bg-sunken`
       re-points to `neutral-50` `#0f131c`, which IS the dark canvas. The mockup can assume that,
       because every table it draws sits inside a card; this app's busiest list does not
       (`forms/Index.vue:265` mounts the table bare on the canvas), so a sunken header there would be
       the same colour as the page behind it and the whole band would vanish in dark.
       The Vivid header therefore reads through TYPE rather than fill: caps, tracked, bold, small. */
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    font-weight: var(--mds-font-weight-bold);
    letter-spacing: var(--mds-tracking-wide);
    text-transform: uppercase;
    background-color: var(--mds-color-bg-surface);
    color: var(--mds-color-text-secondary);
    white-space: nowrap;
}

/* An end-aligned column is a numeric column in practice — that is what right alignment is FOR in a
   table — so it gets the lining figures the charts and the stat tiles already use. Without them the
   digits in a right-aligned count column do not share a column position and the edge fringes. */
.mds-table__cell--end {
    text-align: end;
    font-variant-numeric: tabular-nums;
}

/* ⚠️ `text-transform` and `letter-spacing` are inherited EXPLICITLY, and `font: inherit` does not
   cover them. The `font` shorthand resets the font-* longhands only; the UA stylesheet for <button>
   separately sets `text-transform: none` and `letter-spacing: normal`, and those beat inheritance.
   Found by looking at the real page after JR2 made the header uppercase and tracked: `Status` and
   `Version` obeyed while `Form` and `Updated` — the two SORTABLE columns, whose text lives inside this
   button — did not, so one header row carried two different type treatments. Nothing could have caught
   it but a browser: happy-dom computes no styles, and axe does not care about case. */
.mds-table__sort {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-1);
    padding: 0;
    border: 0;
    background: transparent;
    font: inherit;
    text-transform: inherit;
    letter-spacing: inherit;
    color: inherit;
    cursor: pointer;
}
.mds-table__sort:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}
.mds-table__sort-icon {
    transition: transform var(--mds-duration-fast) var(--mds-ease-standard);
}
.mds-table__sort-icon--asc {
    transform: rotate(180deg);
}

/* The stacked-mode sort control (JR4). `display: none` is its DEFAULT state and the container block at
   the foot of this file is the only thing that reveals it — so above the threshold it costs nothing, not
   even a tab stop, and the header row remains the single sort affordance. */
.mds-table__sortbar {
    display: none;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
    margin-bottom: var(--mds-space-3);
}
.mds-table__sortbar-label {
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-secondary);
}
/* Composed on `.mds-table__sort` in the template, so the chevron rotation and the focus ring are
   declared once and this rule adds only the chip's own shape. It renders sentence-case rather than the
   header's caps precisely because `__sort` inherits `text-transform`/`letter-spacing` and this parent is
   not the uppercase `__th` — the JR2 inheritance finding paying off in the other direction. */
.mds-table__sortchip {
    min-height: 32px;
    padding: 0 var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-full);
    font-size: var(--mds-type-label-font-size);
    color: var(--mds-color-text-secondary);
}
.mds-table__sortchip[aria-pressed='true'] {
    border-color: var(--mds-color-action-primary-bg);
    background-color: color-mix(in srgb, var(--mds-color-action-primary-bg) 10%, transparent);
    color: var(--mds-color-text-heading);
}

/* JR2: 20px of vertical padding rather than 12px — the increment's one real density change, and the
   part a user feels. ⚠️ It gives a 61px row only when the tallest thing in the row is one line of
   body text; a status badge is 24px, an `sm` icon button 28px. Measured on the seeded app, `/forms`
   rows are **68.5px**, so the real range is 61 text-only / 65–73 in practice against the direction's
   60px. (An earlier version of this comment asserted "61px" flatly, computed from the padding token
   without measuring what the cells actually contain.) The mobile block below puts it back to 12px —
   inside a card-per-row each cell is a key/value line, and 20px there makes a very tall card. */
.mds-table__td {
    padding: var(--mds-space-5) var(--mds-space-4);
    vertical-align: middle;
    border-bottom: 1px solid var(--mds-color-border-default);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    /* The hover COLOUR is unchanged; only the instant swap is. At 61px a row is a big enough object
       that an untransitioned repaint reads as a flicker when the pointer crosses the table.
       Declared on the cell, not on the `:hover` rule — a transition that lives only in the hover
       state animates the way in and snaps the way out. */
    transition: background-color var(--mds-duration-fast) var(--mds-ease-standard);
}

/* ⚠️ THE HOVER USED TO BE `bg-canvas`, AND ON MOST OF THIS APP'S TABLES THAT PAINTED NOTHING.
   Neither `.mds-table` nor `.mds-table__td` sets a background, so a row's ground is whatever is
   behind the table — and the tables on `/forms`, `/submissions`, `/members`, `/webhooks`,
   `/integrations` and `/feedback` are mounted bare on the page, whose background IS `bg-canvas`
   (`app.css` on body, `AppLayout.vue` on `.app-shell__content`). Measured: `#F5F7FC` on `#F5F7FC` is
   **1.000:1** in light and `#0f131c` on `#0f131c` is **1.000:1** in dark. Not subtle — absent. It
   only ever worked for the minority of tables sitting inside an `MdsCard`.
   JR2 found this the embarrassing way: it added a transition to smooth a colour change that does not
   happen, and wrote a comment asserting the hover worked. The same premise that keeps `bg-surface` on
   the header — most tables are bare on the canvas — proves the hover was a no-op, and the increment
   read that premise twice without joining the two.
   An 8% wash of the accent is ground-independent, which no opaque token in this system can be: there
   is no colour that differs from BOTH `bg-canvas` and `bg-surface` in BOTH themes. Measured against
   each of the four possible grounds: 1.110 / 1.117 light, 1.066 / 1.076 dark — in the same band as
   the in-card hover that already worked (1.072 light). Degradation without `color-mix` is
   `transparent`, i.e. exactly the no-op it replaces, so nothing regresses on an old engine. */
.mds-table__row:hover .mds-table__td {
    background-color: color-mix(in srgb, var(--mds-color-action-primary-bg) 8%, transparent);
}

.mds-table__actions {
    white-space: nowrap;
}

.mds-table__empty {
    padding: 0;
}

/* ── The card-per-row collapse (§6) ────────────────────────────────────────────────────────────────────
   The table collapses to a card-per-row — header row hidden, each cell a labelled key/value pair (label
   from the column header). Pages don't write this; the component owns it.

   ⚠️ THIS WAS `@media (max-width: 480px)`, AND THE VIEWPORT WAS THE WRONG THING TO ASK. A table does not
   care how wide the WINDOW is, it cares how much room it was handed, and on this shell the two disagree
   badly: the sidebar is 240px above 1024px and 64px at or below it (`Sidebar.vue:81,160`), so a tenant
   page's content box is 896px at a 1024px viewport and 721px at 1025px — NARROWER on the wider screen. A
   max-width media query therefore fires its densest layout in the box with the least room.
   `FormCard.vue:303` learned this in JR3; this is the same lesson arriving at the component that needed
   it most.

   WHAT THE VIEWPORT RULE COST, MEASURED: across 481–1024px — every tablet, every small laptop — a dense
   table stayed a table and became a sideways-scrolling strip. At the 834px project the tenant content box
   is 706px and the console's is 802px, against `/submissions` (6 columns), `/members` (4 plus the widest
   action cell in the app), `/audit-log`, `/webhooks`, `/feedback`, `/integrations` and `/admin/feedback`
   (5 each). This component's own docblock already called that "a latent defect in this component rather
   than that page's problem". This is that fix, in the component, as the sentence promised.

   ⚠️ THE THRESHOLD IS IN `em` AND IS DERIVED FROM TWO MEASURED EDGES RATHER THAN PREFERRED. `em` because
   `[data-font-size]` (§2.9) re-points the type tokens and the frame above pins its own font size to
   `body-lg`, so the query resolves against 16/18/20px and scales with the text it is protecting; a `px`
   value is correct at exactly ONE type scale, which is the defect JR3 shipped and had to fix.

   The number is 56em = 896 / 1008 / 1120px, and 896 is not a preference: it is THE WIDEST CONTENT BOX
   THAT CAN EXIST WHILE THE SIDEBAR IS STILL A 64px RAIL (viewport 1024 − 64 rail − 64 padding). Choosing
   exactly that makes the switch CONTINUOUS across the sidebar swap, which no smaller value can do:

       viewport   375   834   1024        1025          1200   1201   1440   1600(wide)
       box        343   706   896         721           896    897    1136   1296
       result     card  card  card        card          card   TABLE  table  table
                              └── the swap costs 176px and the layout does not flinch ──┘

   A 48em threshold, say, would leave 897–1024px a sideways strip AND turn a table back into cards as the
   window widened 1024→1025. 56em is the smallest value with no such flip-flop. The console has no
   sidebar, so it switches on its own terms: cards at or below 944px, table above.

   ⚠️ AT `extra_large` THE DESKTOP BOX CLEARS THE THRESHOLD BY 16px (1136 against 1120) — tight, and
   correct: a 1366px laptop at that scale gets cards, because bigger text really does have less room.
   Do not "fix" that by pinning the threshold in px.

   The threshold cannot be a token: `@container` conditions cannot read `var()` — the same dead end
   `tokens/breakpoint.json` sits in, with three defined breakpoints and zero references anywhere.

   ⚠️ AND ONE THING THIS BLOCK CANNOT BE GATED BY: `.app-shell { overflow-x: clip }` means an overrun in
   here is CLIPPED rather than scrolled, so `documentElement.scrollWidth` — what the e2e overflow
   assertion measures — cannot see a mistake in it. `tests/e2e/list-layout.spec.ts` measures the scroll
   wrapper's own box instead. */
@container (max-width: 56em) {
    /* The wrapper stops being a scroll container at all. `measure()` reads exactly this property
       (`getComputedStyle(el).overflowX`) to decide whether to claim a tab stop — keep the two in step. */
    .mds-table__frame--stackable .mds-table__scroll {
        overflow-x: visible;
    }

    /* `display: block`, which the ≤480px version never needed: the row group below is a GRID now, and a
       grid child of a `display: table` element is wrapped in an anonymous table cell whose intrinsic
       sizing is the engine's business rather than ours. */
    .mds-table__frame--stackable .mds-table {
        display: block;
    }

    /* ⚠️ `display: none`, NOT THE VISUALLY-HIDDEN CLIP THIS BLOCK USED TO CARRY, AND THE REASON IS THE
       SORT BUTTONS. `clip: rect(0 0 0 0)` hides a control; it does not remove it from the tab order.
       Thirteen columns across seven pages are `sortable`, and each one's toggle lives inside this
       `thead` — so a keyboard user on `/members` at 834px would land on two focus stops with no visible
       ring anywhere on screen (WCAG 2.4.7, and 2.4.11 in 2.2). That was already true below 480px and
       nothing in this repo could see it — axe has no rule for a focusable-but-invisible control — but
       this increment brings the layout to every tablet, and a latent defect that becomes a common one
       gets fixed rather than moved. The column NAME is not lost: `__td::before` prints it beside every
       value, and the sort affordance is replaced rather than dropped (`.mds-table__sortbar`). */
    .mds-table__frame--stackable thead {
        display: none;
    }

    /* A GRID, NOT A COLUMN, and 834px is the width that decides it: the tenant content box there is
       706px, which takes two 320px cards and a 12px gap with 54px to spare — JR3's measured 2-up shape
       on the forms grid, rather than one column of very tall cards down the middle of a tablet. At 343px
       it is 1-up (two tracks would need 652px), which is exactly what the ≤480px version drew.
       ⚠️ `min(100%, 20em)` IS LOAD-BEARING AND CI CANNOT SEE IT GO WRONG (`forms/Index.vue:679`): a bare
       `minmax(20em, 1fr)` sets a track floor wider than the container at 343px — or at any width once
       the type scale grows the em — the grid overruns, `.app-shell` is `overflow-x: clip` so the overrun
       is clipped rather than scrolled, and the e2e assertion measures a document width the clip pins at
       zero. `auto-fill`, not `auto-fit`: with `auto-fit` a one-row table stretches a single card across
       the whole band. The `gap` replaces the row's own `margin-bottom` — keeping both doubles it. */
    .mds-table__frame--stackable tbody {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 20em), 1fr));
        gap: var(--mds-space-3);
    }

    .mds-table__frame--stackable .mds-table__row {
        display: block;
        border: 1px solid var(--mds-color-border-default);
        /* JR2: `lg`, not `xl`, and not the `md` it was. Here each row IS a card, so leaving it at the
           control tier put 12px corners next to every 20px `MdsCard` on the same screen. It does not go
           all the way to `xl` either: a full-bleed card at 375px with 20px corners is heavier than the
           direction's own phone mock, which draws its panels at 16-18px. This is the compact-surface
           tier from DSR §2.6, shared with `MdsToast`. (A draft of this comment also named "the card
           skeleton" — there is no such variant: `MdsSkeleton` is text/block/circle, and `--block` still
           reads `md` because it has no consumer that would make it disagree.) JR4 only widened the range
           this applies over; the choice is unchanged. */
        border-radius: var(--mds-radius-lg);
        background-color: var(--mds-color-bg-surface);
    }

    /* The zero-rows row is a single `colspan` cell, and a colspan means nothing to a grid — without this
       the empty state (an `MdsEmptyState` with a medallion and two lines of copy) is squeezed into one
       20em track with the rest of the row left blank. Reachable in CI today: `responsive-axe` loads
       `/members?q=…` and `/webhooks?q=…` at 834px and asserts only a heading and a clean axe run, so it
       would pass straight over this. */
    .mds-table__frame--stackable .mds-table__empty-row {
        grid-column: 1 / -1;
    }

    .mds-table__frame--stackable .mds-table__td {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: var(--mds-space-4);
        /* Back to 12px from the 20px the desktop row now carries: here a cell is one key/value line
           inside a card, not a table row, and 20px stacks into a very tall card. */
        padding: var(--mds-space-3) var(--mds-space-4);
        text-align: end;
        border-bottom: 1px solid var(--mds-color-border-default);
        /* ⚠️ BOTH HALVES, BECAUSE THEY FIX DIFFERENT THINGS AND THIS IS WHERE TENANT STRINGS LAND.
           `min-width: 0` lets the flex item SHRINK; it does nothing about a single unbreakable WORD, and
           the strings these cells carry are named in three of `responsive-axe`'s own comments — a 64-hex
           domain token, a uuid in an audit diff, a long URL in a feedback report. The JR3 lesson arriving
           where the value is not even ordinary English. */
        min-width: 0;
        overflow-wrap: anywhere;
    }
    .mds-table__frame--stackable .mds-table__row .mds-table__td:last-child {
        border-bottom: 0;
    }
    .mds-table__frame--stackable .mds-table__td::before {
        content: attr(data-label);
        /* The label must not be squeezed to nothing by a long value, and must not stretch either. */
        flex: 0 0 auto;
        font-size: var(--mds-type-label-font-size);
        font-weight: var(--mds-font-weight-semibold);
        color: var(--mds-color-text-secondary);
        text-align: start;
    }
    .mds-table__frame--stackable .mds-table__actions {
        justify-content: flex-end;
    }
    .mds-table__frame--stackable .mds-table__actions::before {
        content: '';
    }

    .mds-table__frame--stackable .mds-table__sortbar {
        display: flex;
    }
}
</style>
