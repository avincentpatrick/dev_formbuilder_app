<script setup lang="ts">
// The analytics filter rail (Increment H24b2).
//
// ── Why the multi-valued filters are checkbox groups and a chip picker ──────────────────────────────────
// MdsSelect is a native single `<select>` — it has no `multiple`, and adding a combobox primitive to the
// design system for four filters would put the hardest ARIA pattern there is under a merge-blocking axe
// job inside an already-large increment. Native checkboxes inside a <fieldset>/<legend> get grouping
// semantics, keyboard behaviour and screen-reader announcement for free; the form picker is an "Add a
// form" select that appends removable chips, which also degrades sanely at 100 selections.
//
// Every control emits `apply` with a PATCH rather than mutating a shared object: the page owns the
// declaration, re-seeds it from what the SERVER applied, and this component never guesses.
import { computed, ref } from 'vue';
import {
    MdsBadge,
    MdsButton,
    MdsCheckbox,
    MdsFormField,
    MdsIconButton,
    MdsSelect,
    MdsTextInput,
} from '@meridian/design-system';
import type { AppliedQuery, Axis, FilterOptions, Granularity, Selection } from './types';

const props = defineProps<{
    applied: AppliedQuery;
    options: FilterOptions;
    busy: boolean;
}>();

const emit = defineEmits<{ apply: [Partial<AppliedQuery>] }>();

const pendingForm = ref('');

const selectedForms = computed(() =>
    props.applied.form_ids.map((id) => ({
        id,
        // A form removed since the link was shared still needs a removable chip, or the user cannot
        // clear a filter they can see is applied.
        label: props.options.forms.find((option) => option.value === id)?.label ?? 'Unknown form',
    })),
);

const availableForms = computed(() =>
    props.options.forms.filter((option) => !props.applied.form_ids.includes(option.value)),
);

const topNOptions = computed(() =>
    Array.from({ length: props.options.limits.max_top_n }, (_, i) => ({
        value: String(i + 1),
        label: String(i + 1),
    })),
);

const timezoneOptions = computed(() => props.options.timezones.map((tz) => ({ value: tz, label: tz })));

// Indentation is a nbsp prefix rather than CSS, because a native <option> cannot be styled per-item and
// the hierarchy is genuinely part of the label a screen reader should hear.
const scopeNodeOptions = computed(() =>
    props.options.scope_nodes.map((node) => ({
        value: node.value,
        label: `${'  '.repeat(node.depth)}${node.label}${node.is_active ? '' : ' (inactive)'}`,
    })),
);

function toggle(list: string[], value: string, on: boolean): string[] {
    return on ? [...list, value] : list.filter((item) => item !== value);
}

function addForm(id: string): void {
    if (id === '') return;

    pendingForm.value = '';
    emit('apply', { form_ids: [...props.applied.form_ids, id] });
}

function removeForm(id: string): void {
    emit('apply', { form_ids: props.applied.form_ids.filter((item) => item !== id) });
}

function reset(): void {
    emit('apply', {
        statuses: [],
        sources: [],
        locales: [],
        form_ids: [],
        scope_node_id: null,
        selection: 'all',
    });
}
</script>

<template>
    <section class="analytics__filters" aria-labelledby="analytics-filters-heading">
        <h2 id="analytics-filters-heading" class="analytics__filters-title">Filters</h2>

        <div class="analytics__filters-grid">
            <MdsFormField label="From" input-id="af-from">
                <MdsTextInput
                    id="af-from"
                    type="date"
                    :model-value="applied.from"
                    :disabled="busy"
                    @update:model-value="(v: string) => emit('apply', { from: v })"
                />
            </MdsFormField>

            <MdsFormField label="To" input-id="af-to">
                <MdsTextInput
                    id="af-to"
                    type="date"
                    :model-value="applied.to"
                    :disabled="busy"
                    @update:model-value="(v: string) => emit('apply', { to: v })"
                />
            </MdsFormField>

            <MdsFormField
                label="Time zone"
                input-id="af-tz"
                help="Buckets are cut in this zone, so a saved report reproduces the same days anywhere."
            >
                <MdsSelect
                    id="af-tz"
                    :model-value="applied.timezone"
                    :options="timezoneOptions"
                    :disabled="busy"
                    @update:model-value="(v: string) => emit('apply', { timezone: v })"
                />
            </MdsFormField>

            <MdsFormField label="Group time by" input-id="af-granularity">
                <MdsSelect
                    id="af-granularity"
                    :model-value="applied.granularity"
                    :options="options.granularities"
                    :disabled="busy"
                    @update:model-value="(v: string) => emit('apply', { granularity: v as Granularity })"
                />
            </MdsFormField>

            <MdsFormField label="Break down by" input-id="af-axis">
                <MdsSelect
                    id="af-axis"
                    :model-value="applied.axis"
                    :options="options.axes"
                    :disabled="busy"
                    @update:model-value="(v: string) => emit('apply', { axis: v as Axis })"
                />
            </MdsFormField>

            <MdsFormField label="Categories shown" input-id="af-topn" help="The rest are folded into “Other”.">
                <MdsSelect
                    id="af-topn"
                    :model-value="String(applied.top_n)"
                    :options="topNOptions"
                    :disabled="busy"
                    @update:model-value="(v: string) => emit('apply', { top_n: Number(v) })"
                />
            </MdsFormField>

            <MdsFormField label="Which forms" input-id="af-selection">
                <MdsSelect
                    id="af-selection"
                    :model-value="applied.selection"
                    :options="options.selections"
                    :disabled="busy"
                    @update:model-value="(v: string) => emit('apply', { selection: v as Selection })"
                />
            </MdsFormField>

            <MdsFormField
                v-if="applied.selection === 'scope_node'"
                label="Area"
                input-id="af-node"
                help="Includes everything beneath it. A deactivated branch is excluded."
            >
                <MdsSelect
                    id="af-node"
                    :model-value="applied.scope_node_id ?? ''"
                    :options="scopeNodeOptions"
                    placeholder="Choose an area"
                    :disabled="busy"
                    @update:model-value="(v: string) => emit('apply', { scope_node_id: v === '' ? null : v })"
                />
            </MdsFormField>
        </div>

        <div v-if="applied.selection === 'forms'" class="analytics__forms">
            <MdsFormField
                label="Forms in this report"
                input-id="af-form-add"
                :help="`Up to ${options.limits.max_form_ids}.`"
            >
                <MdsSelect
                    id="af-form-add"
                    :model-value="pendingForm"
                    :options="availableForms"
                    placeholder="Add a form"
                    :disabled="busy || applied.form_ids.length >= options.limits.max_form_ids"
                    @update:model-value="addForm"
                />
            </MdsFormField>

            <ul v-if="selectedForms.length > 0" class="analytics__chips">
                <li v-for="form in selectedForms" :key="form.id" class="analytics__chip">
                    <MdsBadge :label="form.label" />
                    <MdsIconButton
                        icon="close"
                        size="sm"
                        :label="`Remove ${form.label}`"
                        :disabled="busy"
                        @click="removeForm(form.id)"
                    />
                </li>
            </ul>
            <p v-else class="analytics__hint">
                No forms picked yet — the report stays empty until you add at least one.
            </p>
        </div>

        <div class="analytics__checkgroups">
            <fieldset class="analytics__checkgroup">
                <legend class="analytics__legend">Status</legend>
                <MdsCheckbox
                    v-for="status in options.statuses"
                    :key="status.value"
                    :label="status.label"
                    :model-value="applied.statuses.includes(status.value)"
                    :disabled="busy"
                    @update:model-value="
                        (on: boolean) => emit('apply', { statuses: toggle(applied.statuses, status.value, on) })
                    "
                />
            </fieldset>

            <fieldset class="analytics__checkgroup">
                <legend class="analytics__legend">Channel</legend>
                <MdsCheckbox
                    v-for="source in options.sources"
                    :key="source.value"
                    :label="source.label"
                    :model-value="applied.sources.includes(source.value)"
                    :disabled="busy"
                    @update:model-value="
                        (on: boolean) => emit('apply', { sources: toggle(applied.sources, source.value, on) })
                    "
                />
            </fieldset>

            <fieldset v-if="options.locales.length > 1" class="analytics__checkgroup">
                <legend class="analytics__legend">Language</legend>
                <MdsCheckbox
                    v-for="locale in options.locales"
                    :key="locale.value"
                    :label="locale.label"
                    :model-value="applied.locales.includes(locale.value)"
                    :disabled="busy"
                    @update:model-value="
                        (on: boolean) => emit('apply', { locales: toggle(applied.locales, locale.value, on) })
                    "
                />
            </fieldset>
        </div>

        <div class="analytics__filters-actions">
            <MdsButton variant="tertiary" icon-left="undo" :disabled="busy" @click="reset">
                Clear filters
            </MdsButton>
        </div>
    </section>
</template>

<style scoped>
.analytics__filters {
    margin-bottom: var(--mds-space-6);
    padding: var(--mds-space-4);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-lg);
    background: var(--mds-color-bg-surface);
}

.analytics__filters-title {
    margin: 0 0 var(--mds-space-3);
    font-size: var(--mds-type-body-lg-font-size);
    font-weight: var(--mds-type-heading-4-font-weight);
    color: var(--mds-color-text-heading);
}

.analytics__filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 200px), 1fr));
    gap: var(--mds-space-3);
}

.analytics__forms {
    margin-top: var(--mds-space-3);
}

.analytics__chips {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
    margin: var(--mds-space-2) 0 0;
    padding: 0;
    list-style: none;
}

.analytics__chip {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-1);
}

.analytics__hint {
    margin: var(--mds-space-2) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.analytics__checkgroups {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr));
    gap: var(--mds-space-3);
    margin-top: var(--mds-space-4);
}

.analytics__checkgroup {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin: 0;
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
}

.analytics__legend {
    padding: 0 var(--mds-space-1);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-type-heading-4-font-weight);
    color: var(--mds-color-text-heading);
}

.analytics__filters-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: var(--mds-space-3);
}
</style>
