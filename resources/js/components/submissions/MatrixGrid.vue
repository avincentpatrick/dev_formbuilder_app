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
 *
 * M185 (`R-7418e6bd`): in the builder's Preview — and only there, because only the Preview provides it — a rename button
 * beside every row and column header and "+ Add row" / "+ Add column" under the grid ({@link GridAuthoringKey}). The
 * header WORDS carry the ids the controls are named by, in a span of their own, so a button beside them never joins a
 * control's name; and in the cards the header row, otherwise hidden, is drawn as a strip of the columns' names so each
 * can be renamed there. A grid whose rows come from a list or a form says so instead of offering to add or rename them.
 *
 * M246 (`grid-question-table`): the grid's own rules, as the engine decided them (`relevance`): a row or column not asked
 * is not drawn, and a cell not asked reads "Not needed" in place of its control. A long-text column is a text area. A
 * row's details — note content — sit under its label; such a row's header is a `td` with the row-header role, because a
 * `th` may not hold a heading. Each cell carries its own error (`cellErrors`, keyed `row.col`, or `row` on a Likert
 * grid) and tells the host when it is left (`cellTouched`), so an error shows on the cell the person just answered, not
 * on every cell of a 35-row table at once; the whole-grid line then counts them. The builder's Preview shows every row
 * and column, so a hidden one can still be renamed there.
 */
import { MdsButton, MdsCheckbox, MdsIconButton, MdsNumberInput, MdsSelect, MdsTextarea, MdsTextInput } from '@meridian/design-system';
import { computed, inject, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';

import type { AnswerValue, EncodeField, GridRelevanceView, MatrixColumn, MatrixOption, RequiredMarker } from './FieldInput.vue';
import { GridAuthoringKey, type GridEdit } from './grid-authoring';
import NoteContent from './NoteContent.vue';
import type { ContentBlock } from './note-content';

const props = defineProps<{
    field: EncodeField;
    kind: 'matrix' | 'likert-matrix';
    modelValue: AnswerValue;
    error?: string;
    requiredMarker?: RequiredMarker;
    relevance?: GridRelevanceView | null;
    cellErrors?: Record<string, string>;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: AnswerValue]; cellTouched: [path: string] }>();

const marker = computed<RequiredMarker>(() => props.requiredMarker ?? (props.field.required ? 'required' : 'none'));
const showRequiredMarker = computed<boolean>(() => marker.value === 'required');
const showOptionalMarker = computed<boolean>(() => marker.value === 'optional');

const rows = computed(() => props.field.matrix?.rows ?? []);
const columns = computed<MatrixColumn[]>(() => props.field.matrix?.columns ?? []);
const cells = computed(() => props.field.matrix?.cells ?? []);
const stacked = computed<boolean>(() => props.field.matrix?.layout === 'stacked');

// ── The builder Preview's editing (M185), when it provides one; no other surface does ──────────────────────────────────
const authoring = inject(GridAuthoringKey, null);
const rowsFrom = computed<string | null>(() => (authoring ? authoring.rowsFrom(props.field.key) : null));
const rowsEditable = computed<boolean>(() => authoring !== null && rowsFrom.value === null);
const columnNoun = computed<string>(() => (props.kind === 'likert-matrix' ? 'scale point' : 'column'));

function edit(part: GridEdit['part'], value: string): GridEdit {
    return { key: props.field.key, part, value };
}

function isEditing(part: GridEdit['part'], value: string): boolean {
    const open = authoring?.editing.value ?? null;

    return open !== null && open.key === props.field.key && open.part === part && open.value === value;
}

// ── Ids: one set per rendered copy ─────────────────────────────────────────────────────────────────────────────
const uid = useId();
const hintId = `${uid}-hint`;
const errorId = `${uid}-error`;
const rowId = (i: number): string => `${uid}-row-${i}`;
const colId = (j: number): string => `${uid}-col-${j}`;
const nameFor = (i: number, j: number): string => `${rowId(i)} ${colId(j)}`;
const describedby = computed<string | undefined>(() => {
    const ids = [props.field.hint ? hintId : null, props.error || Object.keys(props.cellErrors ?? {}).length > 0 ? errorId : null].filter((id): id is string => id !== null);
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

// ── M246: the grid's own rules, as the engine decided them ──────────────────────────────────────────────────────
// The Preview shows every row and column, asked or not, so the author can still see and rename a hidden one.
const showAll = computed<boolean>(() => authoring !== null || props.relevance === null || props.relevance === undefined);
const visibleRows = computed<MatrixOption[]>(() => (showAll.value ? rows.value : rows.value.filter((row) => props.relevance?.rows[row.value] !== false)));
const visibleColumns = computed<MatrixColumn[]>(() =>
    showAll.value ? columns.value : columns.value.filter((column) => props.relevance?.columns[column.value] !== false),
);
/** Every row hidden: the grid stays a question, but there is nothing in it to answer. */
const nothingToAnswer = computed<boolean>(() => rows.value.length > 0 && visibleRows.value.length === 0);

function cellNeeded(rowValue: string, colValue: string): boolean {
    return props.relevance?.cells[rowValue]?.[colValue] !== false;
}

function rowDetailsOf(rowValue: string): ContentBlock[] | null {
    const details = props.field.matrix?.rowDetails?.[rowValue];

    return Array.isArray(details) && details.length > 0 ? (details as ContentBlock[]) : null;
}

const cellPathOf = (rowValue: string, colValue: string | null): string => (colValue === null ? rowValue : `${rowValue}.${colValue}`);
const cellErrorOf = (rowValue: string, colValue: string | null): string | undefined => props.cellErrors?.[cellPathOf(rowValue, colValue)];
const cellErrorId = (i: number, j: number | null): string => (j === null ? `${uid}-err-${i}` : `${uid}-err-${i}-${j}`);
const cellErrorCount = computed<number>(() => Object.keys(props.cellErrors ?? {}).length);
const errorLine = computed<string | undefined>(() =>
    cellErrorCount.value > 0
        ? cellErrorCount.value === 1
            ? '1 answer in this grid needs your attention.'
            : `${cellErrorCount.value} answers in this grid need your attention.`
        : props.error,
);

/** A control's invalid state and description: its own cell's error first, else the grid's own. */
function cellInvalid(rowValue: string, colValue: string | null): boolean {
    return invalid.value || cellErrorOf(rowValue, colValue) !== undefined;
}
function cellDescribedby(i: number, j: number | null, rowValue: string, colValue: string | null): string | undefined {
    return cellErrorOf(rowValue, colValue) !== undefined ? cellErrorId(i, j) : controlDescribedby.value;
}

function onCellLeft(rowValue: string, colValue: string | null): void {
    emit('cellTouched', cellPathOf(rowValue, colValue));
}

// ── M247 (`grid-wide-sticky-explorer`, `D231`): a big table keeps its first column, and a long one its header row, in view ──
/** More shown rows than this, and on a screen tall enough the table scrolls in a box of its own so its header row stays pinned. */
const LONG_GRID_ROWS = 8;
const long = computed<boolean>(() => visibleRows.value.length > LONG_GRID_ROWS);
const scrollBox = ref<HTMLElement | null>(null);
/** The table is wider or taller than its box: the box is then a named, focusable group, so a keyboard can scroll it (as `MdsDataTable`). */
const overflows = ref(false);
let observer: ResizeObserver | null = null;

/**
 * Measures the box, and keeps a focused cell clear of the pinned header row and first column (WCAG 2.4.11): the box's
 * scroll padding is the header row's height and the first column's width while they are pinned, and nothing in the cards.
 */
function measureScroll(): void {
    const box = scrollBox.value;
    if (box === null) return;
    overflows.value = box.scrollWidth > box.clientWidth + 1 || box.scrollHeight > box.clientHeight + 1;
    const header = box.querySelector<HTMLElement>('.mds-grid__col');
    const label = box.querySelector<HTMLElement>('.mds-grid__row-label');
    const pinned = header !== null && getComputedStyle(header).position === 'sticky';
    box.style.scrollPaddingBlockStart = pinned && header ? `${header.offsetHeight}px` : '';
    box.style.scrollPaddingInlineStart = pinned && label ? `${label.offsetWidth}px` : '';
}

onMounted(() => {
    measureScroll();
    if (typeof ResizeObserver === 'undefined' || scrollBox.value === null) return;
    observer = new ResizeObserver(() => measureScroll());
    observer.observe(scrollBox.value);
    const table = scrollBox.value.querySelector('table');
    if (table !== null) observer.observe(table);
});
watch([visibleRows, visibleColumns], () => queueMicrotask(measureScroll));
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <fieldset
        class="mds-grid"
        :class="{ 'mds-grid--stacked': stacked, 'mds-grid--likert': kind === 'likert-matrix', 'mds-grid--authoring': authoring !== null, 'mds-grid--long': long }"
        :aria-describedby="describedby"
        data-matrix-grid
    >
        <legend class="mds-grid__legend">
            {{ field.label
            }}<span v-if="showRequiredMarker" class="mds-grid__required"> (required)</span
            ><span v-else-if="showOptionalMarker" class="mds-grid__required"> (optional)</span>
        </legend>
        <p v-if="field.hint" :id="hintId" class="mds-grid__help">{{ field.hint }}</p>

        <p v-if="nothingToAnswer && !authoring" class="mds-grid__help" data-grid-nothing>Nothing in this grid applies to you.</p>
        <div v-else class="mds-grid__frame">
            <div ref="scrollBox" class="mds-grid__scroll" :tabindex="overflows ? 0 : undefined" :role="overflows ? 'group' : undefined" :aria-label="overflows ? field.label : undefined" data-grid-scroll>
                <table class="mds-grid__table" :style="{ '--matrix-grid-columns': String(Math.max(columns.length, 1)) }">
                    <thead class="mds-grid__head">
                        <tr>
                            <td class="mds-grid__corner"></td>
                            <th v-for="(col, j) in visibleColumns" :key="col.value" scope="col" class="mds-grid__col">
                                <component
                                    :is="authoring.editor"
                                    v-if="authoring && isEditing('columns', col.value)"
                                    :value="col.label"
                                    :label="`Name of the ${columnNoun}`"
                                    @commit="(value: string, via: 'key' | 'blur') => authoring?.commit(edit('columns', col.value), value, via)"
                                    @cancel="authoring?.cancel()"
                                />
                                <span :id="colId(j)" class="mds-grid__words" :class="{ 'mds-grid__words--editing': isEditing('columns', col.value) }">{{ col.label }}</span>
                                <MdsIconButton
                                    v-if="authoring && !isEditing('columns', col.value)"
                                    icon="edit"
                                    size="sm"
                                    :label="`Rename ${columnNoun} ${col.label}`"
                                    :data-grid-rename-column="col.value"
                                    @click="authoring?.open(edit('columns', col.value))"
                                />
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in visibleRows" :key="row.value" class="mds-grid__row">
                            <component
                                :is="rowDetailsOf(row.value) ? 'td' : 'th'"
                                :scope="rowDetailsOf(row.value) ? undefined : 'row'"
                                :role="rowDetailsOf(row.value) ? 'rowheader' : undefined"
                                class="mds-grid__row-label"
                            >
                                <component
                                    :is="authoring.editor"
                                    v-if="authoring && rowsEditable && isEditing('rows', row.value)"
                                    :value="row.label"
                                    label="Name of the row"
                                    @commit="(value: string, via: 'key' | 'blur') => authoring?.commit(edit('rows', row.value), value, via)"
                                    @cancel="authoring?.cancel()"
                                />
                                <span :id="rowId(i)" class="mds-grid__words" :class="{ 'mds-grid__words--editing': rowsEditable && isEditing('rows', row.value) }">{{ row.label }}</span>
                                <MdsIconButton
                                    v-if="rowsEditable && !isEditing('rows', row.value)"
                                    icon="edit"
                                    size="sm"
                                    :label="`Rename row ${row.label}`"
                                    :data-grid-rename-row="row.value"
                                    @click="authoring?.open(edit('rows', row.value))"
                                />
                                <!-- M246: the row's details, under its label. -->
                                <div v-if="rowDetailsOf(row.value)" class="mds-grid__details" data-grid-row-details>
                                    <NoteContent :blocks="rowDetailsOf(row.value) ?? []" />
                                </div>
                                <p v-if="kind === 'likert-matrix' && cellErrorOf(row.value, null)" :id="cellErrorId(i, null)" class="mds-grid__cell-error" data-grid-cell-error>
                                    {{ cellErrorOf(row.value, null) }}
                                </p>
                            </component>
                            <td
                                v-for="(col, j) in visibleColumns"
                                :key="col.value"
                                class="mds-grid__cell"
                                :class="{ 'mds-grid__cell--not-needed': kind === 'matrix' && !cellNeeded(row.value, col.value) }"
                                @focusout="onCellLeft(row.value, kind === 'likert-matrix' ? null : col.value)"
                            >
                                <!-- likert_matrix: one radio per point; the row's points are one native group. -->
                                <label v-if="kind === 'likert-matrix'" class="mds-grid__point">
                                    <input
                                        type="radio"
                                        class="mds-grid__radio"
                                        :name="rowId(i)"
                                        :value="col.value"
                                        :checked="likertValue[row.value] === col.value"
                                        :aria-labelledby="nameFor(i, j)"
                                        :aria-invalid="cellInvalid(row.value, null) || undefined"
                                        :aria-describedby="cellDescribedby(i, null, row.value, null)"
                                        @change="setRow(row.value, col.value)"
                                    />
                                    <span class="mds-grid__caption" aria-hidden="true">{{ col.label }}</span>
                                </label>

                                <!-- M246: a cell its row or its column's rule does not ask. -->
                                <template v-else-if="!cellNeeded(row.value, col.value)">
                                    <span class="mds-grid__caption" aria-hidden="true">{{ col.label }}</span>
                                    <span class="mds-grid__not-needed" data-grid-not-needed>Not needed</span>
                                </template>

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
                                            :invalid="cellInvalid(row.value, col.value)"
                                            :describedby="cellDescribedby(i, j, row.value, col.value)"
                                            @update:model-value="toggleChoice(row.value, col.value, choice.value, $event)"
                                        />
                                    </div>
                                    <MdsNumberInput
                                        v-else-if="typeOf(col) === 'integer' || typeOf(col) === 'decimal'"
                                        :model-value="cellNumber(row.value, col.value)"
                                        :step="typeOf(col) === 'integer' ? 1 : undefined"
                                        :aria-labelledby="nameFor(i, j)"
                                        :invalid="cellInvalid(row.value, col.value)"
                                        :describedby="cellDescribedby(i, j, row.value, col.value)"
                                        @update:model-value="setCell(row.value, col.value, $event)"
                                    />
                                    <MdsTextInput
                                        v-else-if="typeOf(col) === 'short_text' || typeOf(col) === 'date'"
                                        :type="typeOf(col) === 'date' ? 'date' : 'text'"
                                        :model-value="cellText(row.value, col.value)"
                                        :aria-labelledby="nameFor(i, j)"
                                        :invalid="cellInvalid(row.value, col.value)"
                                        :describedby="cellDescribedby(i, j, row.value, col.value)"
                                        @update:model-value="setCell(row.value, col.value, $event)"
                                    />
                                    <!-- M246: a long-text column — a text area that grows with what is written. -->
                                    <MdsTextarea
                                        v-else-if="typeOf(col) === 'long_text'"
                                        :model-value="cellText(row.value, col.value)"
                                        autosize
                                        :aria-labelledby="nameFor(i, j)"
                                        :invalid="cellInvalid(row.value, col.value)"
                                        :describedby="cellDescribedby(i, j, row.value, col.value)"
                                        @update:model-value="setCell(row.value, col.value, $event)"
                                    />
                                    <MdsSelect
                                        v-else
                                        :model-value="cellText(row.value, col.value)"
                                        :options="selectOptions(col)"
                                        :aria-labelledby="nameFor(i, j)"
                                        :invalid="cellInvalid(row.value, col.value)"
                                        :describedby="cellDescribedby(i, j, row.value, col.value)"
                                        @update:model-value="setCell(row.value, col.value, $event)"
                                    />
                                    <p v-if="cellErrorOf(row.value, col.value)" :id="cellErrorId(i, j)" class="mds-grid__cell-error" data-grid-cell-error>
                                        {{ cellErrorOf(row.value, col.value) }}
                                    </p>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="authoring" class="mds-grid__authoring" data-grid-authoring>
            <MdsButton v-if="rowsEditable" variant="tertiary" size="sm" icon-left="plus" data-grid-add-row @click="authoring.add(field.key, 'rows')">
                Add row
            </MdsButton>
            <p v-else class="mds-grid__help" data-grid-rows-from>{{ rowsFrom }}</p>
            <MdsButton variant="tertiary" size="sm" icon-left="plus" data-grid-add-column @click="authoring.add(field.key, 'columns')">
                Add {{ columnNoun }}
            </MdsButton>
        </div>

        <div :id="errorId" class="mds-grid__error" aria-live="polite">
            <template v-if="errorLine">
                <svg class="mds-grid__error-icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                    <path
                        fill="currentColor"
                        d="M8 1a7 7 0 100 14A7 7 0 008 1zm-.9 3.6h1.8l-.2 5h-1.4l-.2-5zM8 12.4a1 1 0 110-2 1 1 0 010 2z"
                    />
                </svg>
                <span>{{ errorLine }}</span>
            </template>
        </div>
    </fieldset>
</template>

<style scoped>
.mds-grid {
    /* The radio's size, which the joined line is drawn through the middle of. */
    --matrix-grid-radio: 1.25rem;
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
    grid-template-columns: repeat(var(--matrix-grid-columns), minmax(0, 1fr));
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
    top: calc(var(--matrix-grid-radio) / 2 - 1.5px);
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
    width: var(--matrix-grid-radio);
    height: var(--matrix-grid-radio);
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

/* ── The builder Preview's editing (M185, `R-7418e6bd`) — only where the Preview provides it ─────────────────────────── */
/* A header being renamed keeps its words in the DOM, out of sight, so every control is still named by them. */
.mds-grid__words--editing {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
}

.mds-grid--authoring .mds-grid__words {
    margin-inline-end: var(--mds-space-1);
}

/* The cards hide the header row; for the author it is drawn as a strip of the columns' names, each with its rename. */
.mds-grid--authoring .mds-grid__head {
    position: static;
    width: auto;
    height: auto;
    overflow: visible;
    clip-path: none;
    white-space: normal;
}

.mds-grid--authoring .mds-grid__head > tr {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
}

.mds-grid--authoring .mds-grid__corner {
    display: none;
}

.mds-grid--authoring .mds-grid__col {
    display: inline-flex;
    align-items: center;
    padding: var(--mds-space-1) var(--mds-space-2);
    border: 1px dashed var(--mds-color-border-strong);
    border-radius: var(--mds-radius-sm);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-secondary);
    overflow-wrap: anywhere;
}

.mds-grid__authoring {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
}

@container mds-grid (min-width: 30em) {
    .mds-grid--authoring:not(.mds-grid--stacked) .mds-grid__head > tr {
        display: table-row;
    }

    .mds-grid--authoring:not(.mds-grid--stacked) .mds-grid__corner {
        display: table-cell;
    }

    .mds-grid--authoring:not(.mds-grid--stacked) .mds-grid__col {
        display: table-cell;
        border: 0;
        border-bottom: 1px solid var(--mds-color-border-default);
        border-radius: 0;
    }
}

/* M246: a cell its rules do not ask, a cell's own error, and a row's details under its label. */
.mds-grid__not-needed {
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    font-style: italic;
    color: var(--mds-color-text-secondary);
}

.mds-grid__cell--not-needed {
    background-color: var(--mds-color-bg-sunken);
}

.mds-grid__cell-error {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-danger-text);
}

.mds-grid__details {
    margin-top: var(--mds-space-1);
    font-weight: var(--mds-font-weight-regular);
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

/* ── M247 (`grid-wide-sticky-explorer`, `D231`): a table wider than its box keeps its first column in view, and a long one
   its header row too. The layers are local to the box (`isolation`), so they never compete with the page's z-index scale:
   1 a Likert radio, 2 the first column, 3 the header row, 4 their corner. Separate borders, because collapsed ones do not
   travel with a pinned cell; every pinned cell is opaque, in the table's own surface or its band. ── */
@container mds-grid (min-width: 30em) {
    .mds-grid:not(.mds-grid--stacked) .mds-grid__scroll {
        isolation: isolate;
    }

    .mds-grid:not(.mds-grid--stacked) .mds-grid__table {
        border-collapse: separate;
        border-spacing: 0;
        background-color: var(--mds-color-bg-surface);
    }

    .mds-grid:not(.mds-grid--stacked) .mds-grid__row-label {
        position: sticky;
        inset-inline-start: 0;
        z-index: 2;
        background-color: var(--mds-color-bg-surface);
    }

    .mds-grid:not(.mds-grid--stacked) .mds-grid__row:nth-child(even) > .mds-grid__row-label {
        background-color: var(--mds-color-bg-sunken);
    }

    /* A first column of long row details must not leave the cells no room. */
    .mds-grid:not(.mds-grid--stacked):not(.mds-grid--likert) .mds-grid__row-label {
        max-inline-size: 22rem;
    }

    .mds-grid:not(.mds-grid--stacked) .mds-grid__col,
    .mds-grid:not(.mds-grid--stacked) .mds-grid__corner {
        position: sticky;
        top: 0;
        z-index: 3;
        background-color: var(--mds-color-bg-surface);
    }

    .mds-grid:not(.mds-grid--stacked) .mds-grid__corner {
        inset-inline-start: 0;
        z-index: 4;
    }
}

/* A long table on a screen tall enough scrolls in its own box, so its header row stays pinned while its rows go by. */
@media (min-height: 40rem) {
    @container mds-grid (min-width: 30em) {
        .mds-grid--long:not(.mds-grid--stacked) .mds-grid__scroll {
            max-block-size: 75vh;
            max-block-size: 75dvh;
            overflow: auto;
        }
    }
}
</style>
