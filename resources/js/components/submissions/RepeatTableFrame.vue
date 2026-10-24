<script setup lang="ts">
/**
 * A repeatable section drawn as a table (M185, `R-0a7275ce`, `D129` — Fillout's Table): every question a column, every
 * entry a row, a row header "<Section> n" down the side and the entry's Remove at its end.
 *
 * Shared by the guest form and the builder's Preview (`public-runtime/components/RepeatGroup.vue`) and the encode page
 * (`Pages/submissions/Encode.vue`). It owns the table, its headers and its two drawings, and nothing else: each surface fills
 * a cell with its own field slot (the `cell` slot) and a row's end with its own Remove (`actions`), so a cell keeps the
 * control, the error, the condition and the jump anchor that surface's fields always had. Whether a section may be drawn
 * so is `repeat-table.ts`'s to say, before this is mounted.
 *
 * ONE DOM, TWO DRAWINGS — `MatrixGrid.vue`'s rule: a table wherever the section has 30em of its own, a card per entry
 * below that. In the cards every question shows its own label, as the cards always did; in the table the column headers
 * say it, so each cell's label and help are hidden from sight and kept for a screen reader, which reads the row and the
 * column too. A row carries `data-repeat-instance` and its header `data-instance-heading`, the hooks the surfaces' focus
 * moves already look for.
 */
export interface RepeatTableColumn {
    key: string;
    label: string;
    /** "(required)" or "(optional)" after the header, as a question's own label carries it. */
    marker?: 'required' | 'optional' | 'none';
}

defineProps<{
    /** The section's title: each row is "<label> n". */
    label: string;
    columns: RepeatTableColumn[];
    /** One stable key per entry, in order. */
    rows: string[];
}>();

defineSlots<{
    cell(props: { index: number; column: RepeatTableColumn }): unknown;
    actions(props: { index: number }): unknown;
}>();
</script>

<template>
    <div class="repeat-table" data-repeat-table>
        <div class="repeat-table__scroll">
            <table class="repeat-table__table">
                <thead class="repeat-table__head">
                    <tr>
                        <td class="repeat-table__corner"></td>
                        <th v-for="column in columns" :key="column.key" scope="col" class="repeat-table__col">
                            {{ column.label
                            }}<span v-if="column.marker === 'required'" class="repeat-table__marker"> (required)</span
                            ><span v-else-if="column.marker === 'optional'" class="repeat-table__marker"> (optional)</span>
                        </th>
                        <td class="repeat-table__corner"></td>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in rows" :key="row" class="repeat-table__row" data-repeat-instance :data-instance-index="index">
                        <th scope="row" class="repeat-table__row-label">
                            <span tabindex="-1" data-instance-heading>{{ label }} {{ index + 1 }}</span>
                        </th>
                        <td v-for="column in columns" :key="column.key" class="repeat-table__cell" :data-repeat-cell="column.key">
                            <slot name="cell" :index="index" :column="column" />
                        </td>
                        <td class="repeat-table__actions">
                            <slot name="actions" :index="index" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<style scoped>
/* The section measures ITSELF, as a grid does: the table switches on this box's width, not the window's. */
.repeat-table {
    container: repeat-table / inline-size;
    min-width: 0;
}

/* ── Cards: a card per entry, below 30em ─────────────────────────────────────────────────────────────────── */
.repeat-table__table,
.repeat-table__table > tbody {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    width: 100%;
    border-collapse: collapse;
}

.repeat-table__head {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
}

.repeat-table__row {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    padding: var(--mds-space-4);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
    min-width: 0;
}

.repeat-table__row-label {
    padding: 0;
    text-align: start;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    line-height: var(--mds-type-label-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.repeat-table__row-label > span:focus {
    outline: none;
}

.repeat-table__row-label > span:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.repeat-table__cell,
.repeat-table__actions {
    display: block;
    padding: 0;
    min-width: 0;
}

.repeat-table__actions {
    display: flex;
    justify-content: flex-end;
}

/* ── The table: wherever the section has 30em of its own ──────────────────────────────────────────────────── */
@container repeat-table (min-width: 30em) {
    .repeat-table__scroll {
        overflow-x: auto;
    }

    .repeat-table__table {
        display: table;
        width: 100%;
    }

    .repeat-table__table > tbody {
        display: table-row-group;
    }

    .repeat-table__head {
        position: static;
        display: table-header-group;
        width: auto;
        height: auto;
        overflow: visible;
        clip-path: none;
        white-space: normal;
    }

    .repeat-table__row {
        display: table-row;
        border: 0;
        border-radius: 0;
        background-color: transparent;
    }

    .repeat-table__row:nth-child(even) {
        background-color: var(--mds-color-bg-sunken);
    }

    .repeat-table__row-label,
    .repeat-table__col,
    .repeat-table__corner,
    .repeat-table__cell,
    .repeat-table__actions {
        display: table-cell;
        padding: var(--mds-space-2) var(--mds-space-3);
        vertical-align: top;
        border-bottom: 1px solid var(--mds-color-border-default);
    }

    .repeat-table__col {
        text-align: start;
        font-family: var(--mds-font-family-body);
        font-size: var(--mds-type-caption-font-size);
        line-height: var(--mds-type-caption-line-height);
        font-weight: var(--mds-font-weight-semibold);
        color: var(--mds-color-text-secondary);
        overflow-wrap: anywhere;
    }

    .repeat-table__marker {
        font-weight: var(--mds-font-weight-regular);
    }

    .repeat-table__row-label {
        white-space: nowrap;
    }

    /* A cell keeps room enough to answer in; a table wider than its box scrolls in the box above. */
    .repeat-table__cell {
        min-width: 9rem;
    }

    /* The column header says what each cell asks, so a cell's own label and help are for a screen reader only. */
    .repeat-table__cell :deep(.mds-field__label),
    .repeat-table__cell :deep(.mds-field__help),
    .repeat-table__cell :deep(.encode-field__legend),
    .repeat-table__cell :deep(.encode-field__help) {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip-path: inset(50%);
        white-space: nowrap;
    }
}
</style>
