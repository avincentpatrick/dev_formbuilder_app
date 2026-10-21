<script setup lang="ts">
/**
 * The two object-valued grid controls (Increment G4b), shared by manual-encode + the public SPA + the builder
 * preview via {@link FieldInput.vue}:
 *  - `likert-matrix` — one native radio group per row over the shared column scale; value `{row: colValue}`.
 *  - `matrix` — one control per (row × column) cell, by its column's type (M183, `R-5033e87e`): a select over the
 *    column's choices, a tick box per choice for a multiple-choice column, a text, number or date box; value
 *    `{row: {col: cell}}`, each cell as a question of its column's type stores it.
 *
 * M183 (`R-1c1d7266`, `R-f03cf999`): ONE `<table>` — `th scope="col"` across the top, `th scope="row"` down the side —
 * drawn as a table wherever the GRID ITSELF has 30em of room, and as a card per row below that. A container query,
 * not a viewport one: the form column is at most 45rem (the guest page's 44rem), halved beside a scanned paper and
 * narrower again in the builder's preview pane, so "a table from 48em" on the viewport would draw one where none fits,
 * and on the grid would never draw one. `layout: stacked` keeps the cards at every width. A Likert matrix is
 * KoboToolbox's Rating grid — the scale's words across the top, the statements a third of the width, one centred
 * radio per cell, rows banded — and as cards each statement's scale is the joined line ({@link LikertLine}'s look).
 * Every control is named by its row and its column (`aria-labelledby` over the two header cells, kept in the DOM by
 * both drawings), so it reads "Cleanliness Good" whichever is on screen; the words under a radio or over a box in
 * the cards are `aria-hidden` copies of the column's header. Radios stay native, as they always were in this file:
 * the joined line needs the bare control. A wide grid scrolls inside its own box, never the page. Each copy of the
 * grid names its radio groups for itself (`useId`, the `R-de624bb1` rule), never after the question's key.
 */
import { MdsCheckbox, MdsNumberInput, MdsSelect, MdsTextInput } from '@meridian/design-system';
import { computed, useId } from 'vue';

import type { AnswerValue, EncodeField, MatrixColumn, MatrixOption, RequiredMarker } from './FieldInput.vue';

const props = defineProps<{
    field: EncodeField;
    kind: 'matrix' | 'likert-matrix';
    modelValue: AnswerValue;
    error?: string;
    requiredMarker?: RequiredMarker;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: AnswerValue] }>();

const marker = computed<RequiredMarker>(() => props.requiredMarker ?? (props.field.required ? 'required' : 'none'));
const showRequiredMarker = computed<boolean>(() => marker.value === 'required');
const showOptionalMarker = computed<boolean>(() => marker.value === 'optional');

const rows = computed(() => props.field.matrix?.rows ?? []);
const columns = computed<MatrixColumn[]>(() => props.field.matrix?.columns ?? []);
const cells = computed(() => props.field.matrix?.cells ?? []);
const stacked = computed<boolean>(() => props.field.matrix?.layout === 'stacked');

// ── Ids: one set per rendered copy ─────────────────────────────────────────────────────────────────────────────
const uid = useId();
const hintId = `${uid}-hint`;
const errorId = `${uid}-error`;
const rowId = (i: number): string => `${uid}-row-${i}`;
const colId = (j: number): string => `${uid}-col-${j}`;
const nameFor = (i: number, j: number): string => `${rowId(i)} ${colId(j)}`;
const describedby = computed<string | undefined>(() => {
    const ids = [props.field.hint ? hintId : null, props.error ? errorId : null].filter((id): id is string => id !== null);
    return ids.length > 0 ? ids.join(' ') : undefined;
});
const invalid = computed<boolean>(() => Boolean(props.error));
const controlDescribedby = computed<string | undefined>(() => (props.error ? errorId : undefined));

function isPlainObject(value: unknown): value is Record<string, unknown> {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

// likert_matrix `{row: colValue}`
const likertValue = computed<Record<string, string>>(() =>
    isPlainObject(props.modelValue) ? (props.modelValue as Record<string, string>) : {},
);

function setRow(rowValue: string, colValue: string): void {
    emit('update:modelValue', { ...likertValue.value, [rowValue]: colValue });
}

// matrix `{row: {col: cell}}` — a cell is a string, a number, or a list (a multiple-choice column).
type Cell = string | number | string[];
const matrixValue = computed<Record<string, Record<string, Cell>>>(() =>
    isPlainObject(props.modelValue) ? (props.modelValue as Record<string, Record<string, Cell>>) : {},
);

/** A column's type as the grid draws it: none (a Likert scale point, or an older payload) is a single choice. */
function typeOf(column: MatrixColumn): string {
    return column.type ?? 'single_select';
}

/** A column's choices: its own as resolved by the server and the guest runtime, else the shared cells. */
function choicesOf(column: MatrixColumn): MatrixOption[] {
    return column.options !== undefined && column.options.length > 0 ? column.options : cells.value;
}

function cellOf(rowValue: string, colValue: string): Cell | undefined {
    const row = matrixValue.value[rowValue];
    return isPlainObject(row) ? (row[colValue] as Cell | undefined) : undefined;
}

function cellText(rowValue: string, colValue: string): string {
    const cell = cellOf(rowValue, colValue);
    return typeof cell === 'string' ? cell : typeof cell === 'number' ? String(cell) : '';
}

function cellNumber(rowValue: string, colValue: string): number | null {
    const cell = cellOf(rowValue, colValue);
    if (typeof cell === 'number') return cell;
    return typeof cell === 'string' && cell.trim() !== '' && Number.isFinite(Number(cell)) ? Number(cell) : null;
}

function cellList(rowValue: string, colValue: string): string[] {
    const cell = cellOf(rowValue, colValue);
    return Array.isArray(cell) ? cell.filter((v): v is string => typeof v === 'string') : [];
}

function setCell(rowValue: string, colValue: string, value: Cell | null): void {
    // Rebuild fresh (clone rows) so the emit is a new reference and cleared rows/cells collapse — matching
    // the engine's config-order prune (an empty row is dropped, an empty grid becomes {}).
    const next: Record<string, Record<string, Cell>> = {};
    for (const [rowKey, rowVal] of Object.entries(matrixValue.value)) {
        if (isPlainObject(rowVal)) {
            next[rowKey] = { ...(rowVal as Record<string, Cell>) };
        }
    }
    const row = { ...(next[rowValue] ?? {}) };
    if (value === null || value === '' || (Array.isArray(value) && value.length === 0)) {
        delete row[colValue];
    } else {
        row[colValue] = value;
    }
    if (Object.keys(row).length === 0) {
        delete next[rowValue];
    } else {
        next[rowValue] = row;
    }
    emit('update:modelValue', next as AnswerValue);
}

function toggleChoice(rowValue: string, colValue: string, choice: string, checked: boolean): void {
    const current = cellList(rowValue, colValue);
    setCell(rowValue, colValue, checked ? [...current, choice] : current.filter((v) => v !== choice));
}

function selectOptions(column: MatrixColumn): MatrixOption[] {
    return [{ value: '', label: 'Select…' }, ...choicesOf(column)];
}
</script>

<template>
    <fieldset
        class="mds-grid"
        :class="{ 'mds-grid--stacked': stacked, 'mds-grid--likert': kind === 'likert-matrix' }"
        :aria-describedby="describedby"
        data-matrix-grid
    >
        <legend class="mds-grid__legend">
            {{ field.label
            }}<span v-if="showRequiredMarker" class="mds-grid__required"> (required)</span
            ><span v-else-if="showOptionalMarker" class="mds-grid__required"> (optional)</span>
        </legend>
        <p v-if="field.hint" :id="hintId" class="mds-grid__help">{{ field.hint }}</p>

        <div class="mds-grid__frame">
            <div class="mds-grid__scroll">
                <table class="mds-grid__table" :style="{ '--mds-grid-columns': String(Math.max(columns.length, 1)) }">
                    <thead class="mds-grid__head">
                        <tr>
                            <td class="mds-grid__corner"></td>
                            <th v-for="(col, j) in columns" :id="colId(j)" :key="col.value" scope="col" class="mds-grid__col">{{ col.label }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in rows" :key="row.value" class="mds-grid__row">
                            <th :id="rowId(i)" scope="row" class="mds-grid__row-label">{{ row.label }}</th>
                            <td v-for="(col, j) in columns" :key="col.value" class="mds-grid__cell">
                                <!-- likert_matrix: one radio per point; the row's points are one native group. -->
                                <label v-if="kind === 'likert-matrix'" class="mds-grid__point">
                                    <input
                                        type="radio"
                                        class="mds-grid__radio"
                                        :name="rowId(i)"
                                        :value="col.value"
                                        :checked="likertValue[row.value] === col.value"
                                        :aria-labelledby="nameFor(i, j)"
                                        :aria-invalid="invalid || undefined"
                                        :aria-describedby="controlDescribedby"
                                        @change="setRow(row.value, col.value)"
                                    />
                                    <span class="mds-grid__caption" aria-hidden="true">{{ col.label }}</span>
                                </label>

                                <!-- matrix: a control by the column's type, its header repeated over it as a card. -->
                                <template v-else>
                                    <span class="mds-grid__caption" aria-hidden="true">{{ col.label }}</span>
                                    <div
                                        v-if="typeOf(col) === 'multi_select'"
                                        role="group"
                                        class="mds-grid__ticks"
                                        :aria-labelledby="nameFor(i, j)"
                                    >
                                        <MdsCheckbox
                                            v-for="choice in choicesOf(col)"
                                            :key="choice.value"
                                            :label="choice.label"
                                            :model-value="cellList(row.value, col.value).includes(choice.value)"
                                            :invalid="invalid"
                                            :describedby="controlDescribedby"
                                            @update:model-value="toggleChoice(row.value, col.value, choice.value, $event)"
                                        />
                                    </div>
                                    <MdsNumberInput
                                        v-else-if="typeOf(col) === 'integer' || typeOf(col) === 'decimal'"
                                        :model-value="cellNumber(row.value, col.value)"
                                        :step="typeOf(col) === 'integer' ? 1 : undefined"
                                        :aria-labelledby="nameFor(i, j)"
                                        :invalid="invalid"
                                        :describedby="controlDescribedby"
                                        @update:model-value="setCell(row.value, col.value, $event)"
                                    />
                                    <MdsTextInput
                                        v-else-if="typeOf(col) === 'short_text' || typeOf(col) === 'date'"
                                        :type="typeOf(col) === 'date' ? 'date' : 'text'"
                                        :model-value="cellText(row.value, col.value)"
                                        :aria-labelledby="nameFor(i, j)"
                                        :invalid="invalid"
                                        :describedby="controlDescribedby"
                                        @update:model-value="setCell(row.value, col.value, $event)"
                                    />
                                    <MdsSelect
                                        v-else
                                        :model-value="cellText(row.value, col.value)"
                                        :options="selectOptions(col)"
                                        :aria-labelledby="nameFor(i, j)"
                                        :invalid="invalid"
                                        :describedby="controlDescribedby"
                                        @update:model-value="setCell(row.value, col.value, $event)"
                                    />
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div :id="errorId" class="mds-grid__error" aria-live="polite">
            <template v-if="error">
                <svg class="mds-grid__error-icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                    <path
                        fill="currentColor"
                        d="M8 1a7 7 0 100 14A7 7 0 008 1zm-.9 3.6h1.8l-.2 5h-1.4l-.2-5zM8 12.4a1 1 0 110-2 1 1 0 010 2z"
                    />
                </svg>
                <span>{{ error }}</span>
            </template>
        </div>
    </fieldset>
</template>

<style scoped>
.mds-grid {
    /* The radio's size, which the joined line is drawn through the middle of. */
    --mds-grid-radio: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    margin: 0;
    padding: 0;
    border: 0;
    min-width: 0;
}

.mds-grid__legend {
    padding: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    line-height: var(--mds-type-label-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.mds-grid__required {
    font-weight: var(--mds-font-weight-regular);
    color: var(--mds-color-text-secondary);
}

.mds-grid__help {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
}

/* The grid measures ITSELF (see the docblock): the table below switches on this box's width, not the window's. */
.mds-grid__frame {
    container: mds-grid / inline-size;
    min-width: 0;
}

/* ── Cards: the default, and every width under `layout: stacked` ───────────────────────────────────────────── */
.mds-grid__table,
.mds-grid__table > tbody {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    width: 100%;
    border-collapse: collapse;
}

/* The column headers stay in the DOM — every control's name reads them — but only the table shows them. */
.mds-grid__head {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
}

.mds-grid__row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-3);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
    min-width: 0;
}

.mds-grid__row-label {
    flex: 1 0 100%;
    padding: 0;
    text-align: start;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.mds-grid__cell {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    flex: 1 1 10rem;
    padding: 0;
    min-width: 0;
}

.mds-grid__caption {
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
    overflow-wrap: anywhere;
}

.mds-grid__ticks {
    display: flex;
    flex-wrap: wrap;
    gap: 0 var(--mds-space-3);
    min-width: 0;
}

/* A Likert statement as a card: its scale is the joined line — the points in equal columns, the radios on one
   3px line drawn cell by cell through their middles (starting and ending at the end radios), each word centred
   under its radio. The line is decoration: each radio is its own control, with its own ring. */
.mds-grid--likert .mds-grid__row {
    display: grid;
    grid-template-columns: repeat(var(--mds-grid-columns), minmax(0, 1fr));
    gap: var(--mds-space-2) 0;
}

.mds-grid--likert .mds-grid__row-label {
    grid-column: 1 / -1;
}

.mds-grid--likert .mds-grid__cell {
    position: relative;
    align-items: center;
    text-align: center;
}

.mds-grid--likert .mds-grid__cell::before {
    content: '';
    position: absolute;
    top: calc(var(--mds-grid-radio) / 2 - 1.5px);
    inset-inline: 0;
    height: 3px;
    background-color: var(--mds-color-border-strong);
}

.mds-grid--likert .mds-grid__cell:nth-child(2)::before {
    inset-inline-start: 50%;
}

.mds-grid--likert .mds-grid__cell:last-child::before {
    inset-inline-end: 50%;
}

.mds-grid__point {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--mds-space-1);
    min-width: 0;
    cursor: pointer;
}

.mds-grid__radio {
    position: relative;
    z-index: 1;
    width: var(--mds-grid-radio);
    height: var(--mds-grid-radio);
    margin: 0;
    accent-color: currentColor;
}

/* ── The table: wherever the grid has 30em of its own, unless the author chose cards ───────────────────────── */
@container mds-grid (min-width: 30em) {
    .mds-grid:not(.mds-grid--stacked) .mds-grid__scroll {
        overflow-x: auto;
    }

    .mds-grid:not(.mds-grid--stacked) .mds-grid__table {
        display: table;
        width: 100%;
    }

    .mds-grid:not(.mds-grid--stacked) .mds-grid__table > tbody {
        display: table-row-group;
    }

    .mds-grid:not(.mds-grid--stacked) .mds-grid__head {
        position: static;
        display: table-header-group;
        width: auto;
        height: auto;
        overflow: visible;
        clip-path: none;
        white-space: normal;
    }

    .mds-grid:not(.mds-grid--stacked) .mds-grid__row {
        display: table-row;
        border: 0;
        border-radius: 0;
        background-color: transparent;
    }

    /* Rows banded, so the eye keeps its line across. */
    .mds-grid:not(.mds-grid--stacked) .mds-grid__row:nth-child(even) {
        background-color: var(--mds-color-bg-sunken);
    }

    .mds-grid:not(.mds-grid--stacked) .mds-grid__row-label,
    .mds-grid:not(.mds-grid--stacked) .mds-grid__col,
    .mds-grid:not(.mds-grid--stacked) .mds-grid__corner,
    .mds-grid:not(.mds-grid--stacked) .mds-grid__cell {
        display: table-cell;
        padding: var(--mds-space-2) var(--mds-space-3);
        vertical-align: middle;
        border-bottom: 1px solid var(--mds-color-border-default);
    }

    .mds-grid:not(.mds-grid--stacked) .mds-grid__col {
        text-align: center;
        font-family: var(--mds-font-family-body);
        font-size: var(--mds-type-caption-font-size);
        line-height: var(--mds-type-caption-line-height);
        font-weight: var(--mds-font-weight-semibold);
        color: var(--mds-color-text-secondary);
        overflow-wrap: anywhere;
    }

    /* The header row says what each column is, so the copies over each control go. */
    .mds-grid:not(.mds-grid--stacked) .mds-grid__caption {
        display: none;
    }

    /* A typed cell keeps room enough to type in; a grid wider than its box scrolls in the box above. */
    .mds-grid:not(.mds-grid--stacked):not(.mds-grid--likert) .mds-grid__cell {
        min-width: 8rem;
    }

    /* Kobo's Rating grid: the statements a third of the width, the scale's columns equal, one centred radio each. */
    .mds-grid--likert:not(.mds-grid--stacked) .mds-grid__table {
        table-layout: fixed;
    }

    .mds-grid--likert:not(.mds-grid--stacked) .mds-grid__corner,
    .mds-grid--likert:not(.mds-grid--stacked) .mds-grid__row-label {
        width: 35%;
    }

    .mds-grid--likert:not(.mds-grid--stacked) .mds-grid__cell {
        text-align: center;
    }

    .mds-grid--likert:not(.mds-grid--stacked) .mds-grid__cell::before {
        display: none;
    }
}

.mds-grid__error {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-1);
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-danger-text);
}
.mds-grid__error:empty {
    display: none;
}

.mds-grid__error-icon {
    flex-shrink: 0;
    width: 1em;
    height: 1em;
    margin-top: 0.15em;
}
</style>
