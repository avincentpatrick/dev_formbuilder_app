<script setup lang="ts">
/**
 * The two object-valued grid controls (Increment G4b), shared by manual-encode + the public SPA + repeat
 * instances via {@link FieldInput.vue}:
 *  - `likert-matrix` — one native radio group per row over the shared column scale; value `{row: colValue}`.
 *  - `matrix` — one native single-select per (row × column) cell over the shared cell pool; value
 *    `{row: {col: cellValue}}`.
 *
 * Accessibility + reflow (WCAG 1.4.10, the merge-blocking axe requirement): NO `<table>` — each row is its
 * own nested `<fieldset>`/`<legend>` group and every control carries a visible column label, so the whole
 * grid reflows by `flex-wrap` at narrow viewports with zero horizontal scroll. Native radios (shared name
 * per row) form an implicit radio group; native selects are wrapped in a `<label>`. The field-level error is
 * announced through an `aria-live` region, matching the group-control anatomy of FieldInput.
 */
import { computed } from 'vue';

import type { AnswerValue, EncodeField, RequiredMarker } from './FieldInput.vue';

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
const columns = computed(() => props.field.matrix?.columns ?? []);
const cells = computed(() => props.field.matrix?.cells ?? []);

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

// matrix `{row: {col: cellValue}}`
const matrixValue = computed<Record<string, Record<string, string>>>(() =>
    isPlainObject(props.modelValue) ? (props.modelValue as Record<string, Record<string, string>>) : {},
);

function cellValue(rowValue: string, colValue: string): string {
    const row = matrixValue.value[rowValue];
    return isPlainObject(row) && typeof row[colValue] === 'string' ? (row[colValue] as string) : '';
}

function setCell(rowValue: string, colValue: string, value: string): void {
    // Rebuild fresh (clone rows) so the emit is a new reference and cleared rows/cells collapse — matching
    // the engine's config-order prune (an empty row is dropped, an empty grid becomes {}).
    const next: Record<string, Record<string, string>> = {};
    for (const [rowKey, rowVal] of Object.entries(matrixValue.value)) {
        if (isPlainObject(rowVal)) {
            next[rowKey] = { ...(rowVal as Record<string, string>) };
        }
    }
    const row = { ...(next[rowValue] ?? {}) };
    if (value === '') {
        delete row[colValue];
    } else {
        row[colValue] = value;
    }
    if (Object.keys(row).length === 0) {
        delete next[rowValue];
    } else {
        next[rowValue] = row;
    }
    emit('update:modelValue', next);
}
</script>

<template>
    <fieldset class="mds-grid">
        <legend class="mds-grid__legend">
            {{ field.label
            }}<span v-if="showRequiredMarker" class="mds-grid__required"> (required)</span
            ><span v-else-if="showOptionalMarker" class="mds-grid__required"> (optional)</span>
        </legend>
        <p v-if="field.hint" class="mds-grid__help">{{ field.hint }}</p>

        <!-- likert_matrix: one radio group per row over the shared column scale -->
        <template v-if="kind === 'likert-matrix'">
            <fieldset v-for="row in rows" :key="row.value" class="mds-grid__row">
                <legend class="mds-grid__row-label">{{ row.label }}</legend>
                <div class="mds-grid__scale">
                    <label
                        v-for="col in columns"
                        :key="col.value"
                        class="mds-grid__chip"
                        :class="{ 'mds-grid__chip--active': likertValue[row.value] === col.value }"
                    >
                        <input
                            type="radio"
                            class="mds-grid__radio"
                            :name="`lm-${field.key}-${row.value}`"
                            :value="col.value"
                            :checked="likertValue[row.value] === col.value"
                            @change="setRow(row.value, col.value)"
                        />
                        <span class="mds-grid__chip-label">{{ col.label }}</span>
                    </label>
                </div>
            </fieldset>
        </template>

        <!-- matrix: one single-select per (row × column) cell over the shared cell pool -->
        <template v-else>
            <fieldset v-for="row in rows" :key="row.value" class="mds-grid__row">
                <legend class="mds-grid__row-label">{{ row.label }}</legend>
                <div class="mds-grid__cells">
                    <label v-for="col in columns" :key="col.value" class="mds-grid__cell">
                        <span class="mds-grid__cell-label">{{ col.label }}</span>
                        <select
                            class="mds-grid__select"
                            :value="cellValue(row.value, col.value)"
                            @change="setCell(row.value, col.value, ($event.target as HTMLSelectElement).value)"
                        >
                            <option value="">Select…</option>
                            <option v-for="cell in cells" :key="cell.value" :value="cell.value">{{ cell.label }}</option>
                        </select>
                    </label>
                </div>
            </fieldset>
        </template>

        <div class="mds-grid__error" aria-live="polite">
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

/* One row = a nested labelled group. Reflows for free (no table); a card at every width. */
.mds-grid__row {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin: 0;
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
    min-width: 0;
}

.mds-grid__row-label {
    padding: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

/* likert scale — a wrapping row of radio chips (never overflows the page at narrow viewports). */
.mds-grid__scale {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
    min-width: 0;
}

.mds-grid__chip {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-1);
    padding: var(--mds-space-1) var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-body);
    cursor: pointer;
}

.mds-grid__chip--active {
    border-color: var(--mds-color-border-strong);
    background-color: var(--mds-color-bg-sunken);
    font-weight: var(--mds-font-weight-medium);
}

.mds-grid__radio {
    margin: 0;
    accent-color: currentColor;
}

/* matrix — wrapping labelled selects, one per column. */
.mds-grid__cells {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-3);
    min-width: 0;
}

.mds-grid__cell {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    min-width: 0;
    flex: 1 1 10rem;
}

.mds-grid__cell-label {
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
}

.mds-grid__select {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    padding: var(--mds-space-2) var(--mds-space-3);
    border: 1px solid var(--mds-color-input-border, var(--mds-color-border-default));
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

.mds-grid__select:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring, var(--mds-color-border-strong));
    outline-offset: 2px;
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
