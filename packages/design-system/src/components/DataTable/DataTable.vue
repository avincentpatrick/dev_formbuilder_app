<script setup lang="ts" generic="Row extends Record<string, unknown>">
/**
 * The shared data table (DSR §3.3) — the single most-used composite (members roster, admin lists, and
 * later the submissions inbox / webhook log / audit log). One component covers them all: a page
 * configures WHICH columns/actions appear, never HOW the table lays out (header/cell padding, row
 * height, hover, the loading skeleton, and the mobile card-per-row collapse are all owned here — §3.3
 * governing rule). Consumes semantic tokens only. Generic over the row shape, so `#cell-<key>` /
 * `#row-actions` slots receive a fully-typed `row` (no casts needed by consumers).
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

    // At <=480px the component drops to a card-per-row layout with `overflow-x: visible`, so the element
    // is not a scroll container at all even if the content is wider.
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

                <!-- Empty: page supplies the message via #empty (compose MdsEmptyState). -->
                <tr v-else-if="sortedRows.length === 0">
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
</template>

<style scoped>
/* `position: relative` is load-bearing, not decorative. This wrapper is the table's horizontal scroll
   container, but `.mds-table__sr` (and, at the mobile breakpoint, the visually-hidden `thead`) are
   `position: absolute`. Without a positioned ancestor here their containing block resolves OUTSIDE the
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

/* JR2: 20px of vertical padding rather than 12px, giving a 61px row against the direction's
   `--m-row-h: 60px`. This is the increment's one real density change and it is the part a user feels:
   it costs roughly one row per screen on the inbox, and buys the air that makes a list of forms read
   as a set of objects rather than a spreadsheet. The mobile block below puts it back to 12px — inside
   a card-per-row each cell is a key/value line, and 20px there makes a very tall card at 375px. */
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

.mds-table__row:hover .mds-table__td {
    background-color: var(--mds-color-bg-canvas);
}

.mds-table__actions {
    white-space: nowrap;
}

.mds-table__empty {
    padding: 0;
}

/* Mobile (§6): the table collapses to a card-per-row — header row hidden, each cell becomes a labelled
   key/value pair (label from the column header). Pages don't write this; the component owns it. */
@media (max-width: 480px) {
    .mds-table__scroll {
        overflow-x: visible;
    }
    .mds-table thead {
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
    .mds-table__row {
        display: block;
        margin-bottom: var(--mds-space-3);
        border: 1px solid var(--mds-color-border-default);
        /* JR2: `lg`, not `xl`, and not the `md` it was. Below 480px each row IS a card, so leaving it
           at the control tier put 12px corners next to every 20px `MdsCard` on the same screen. It
           does not go all the way to `xl` either: a full-bleed card at 375px with 20px corners is
           heavier than the direction's own phone mock, which draws its panels at 16-18px. This is the
           compact-surface tier from DSR §2.6 — the same one `MdsToast` and the card skeleton read. */
        border-radius: var(--mds-radius-lg);
        background-color: var(--mds-color-bg-surface);
    }
    .mds-table__td {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: var(--mds-space-4);
        /* Back to 12px from the 20px the desktop row now carries: here a cell is one key/value line
           inside a card, not a table row, and 20px stacks into a very tall card at 375px. */
        padding: var(--mds-space-3) var(--mds-space-4);
        text-align: end;
        border-bottom: 1px solid var(--mds-color-border-default);
    }
    .mds-table__row .mds-table__td:last-child {
        border-bottom: 0;
    }
    .mds-table__td::before {
        content: attr(data-label);
        font-size: var(--mds-type-label-font-size);
        font-weight: var(--mds-font-weight-semibold);
        color: var(--mds-color-text-secondary);
        text-align: start;
    }
    .mds-table__actions {
        justify-content: flex-end;
    }
    .mds-table__actions::before {
        content: '';
    }
}
</style>
