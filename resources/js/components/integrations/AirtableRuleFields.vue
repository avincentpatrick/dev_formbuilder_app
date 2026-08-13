<script setup lang="ts">
/**
 * The Airtable half of the delivery-rule form (H16c) — base, table, and the field map.
 *
 * A SIBLING of {@link SheetsRuleFields}, not a branch inside it, for the reason that file records: the two
 * destinations share nothing but the word "where", and H15a's four request classes already showed what
 * happens when one provider's shape is treated as the shared one. What they DO share is
 * `mapping-model.ts`, which is used here unchanged — it never mentions a provider.
 *
 * ── PICKING IS THE ONLY PATH, AND THAT IS THE WHOLE DIFFERENCE FROM SHEETS ─────────────────────────────────
 * `SheetsRuleFields` leads with "create a sheet for me" because `drive.file` cannot enumerate: a pasted id is
 * unreachable for most tenants, so creating the file was the only way to make the destination work by
 * construction. Airtable's `schema.bases:read` enumerates properly, so the base and the table are CHOSEN from
 * lists we can actually read — and `schema.bases:write` was refused (ADR-0009 §D8), so there is no create
 * control at all. Two selects instead of a mode switch.
 *
 * ── NO FINGERPRINT IS COMPUTED HERE ───────────────────────────────────────────────────────────────────────
 * See `mapping-model.ts`: the server re-derives it from the field row through `ColumnMapping::author()`. A
 * TypeScript reimplementation of `ColumnFingerprint`'s normalisation would be a second thing to keep in step
 * with a digest the delivery path compares byte for byte.
 */
import { computed, ref, watch } from 'vue';
import { MdsFormField, MdsIcon, MdsSelect, MdsSpinner } from '@meridian/design-system';
import { fetchChannels, fetchMappableColumns, inspectDestination } from './integrationsClient';
import {
    buildRows,
    columnLabel,
    fieldOptionsFor,
    mappingProblem,
    mappingSummary,
    toPayload,
    UNBOUND_LABEL,
    type MappingRow,
} from './mapping-model';
import type { Channel, MappableColumn, Option, RuleRow, TabularDestination } from './types';

const props = defineProps<{
    connectionId: string | null;
    /** The scope currently chosen in the parent form — '' means all forms. */
    formId: string;
    formTitle: string | null;
    rule?: RuleRow | null;
    /** The parent's dotted server errors, so `config.spreadsheet_id` renders against the right field. */
    errors: Record<string, string | undefined>;
}>();

const emit = defineEmits<{
    change: [
        value: {
            spreadsheet_id: string;
            spreadsheet_title: string;
            sheet_id: string;
            sheet_name: string;
            columns: ReturnType<typeof toPayload>;
        } | null,
    ];
}>();

const bases = ref<Channel[]>([]);
const baseId = ref('');
const destination = ref<TabularDestination | null>(null);
const rows = ref<MappingRow[]>([]);
const catalog = ref<MappableColumn[]>([]);
const scoped = ref(false);
const busy = ref(false);
const problem = ref<string | null>(null);

const destinationError = computed<string | undefined>(() => props.errors['config.spreadsheet_id']);
const tableError = computed<string | undefined>(() => props.errors['config.sheet_id']);
const mappingError = computed<string | undefined>(
    () => props.errors['config.mapping'] ?? props.errors['config.mapping.columns'],
);

/**
 * A base we cannot write to is OFFERED, not hidden — the server marks it `available: false`. The Slack picker
 * makes the same choice for a channel the app has not been invited to: hiding it answers "why isn't my base
 * here?" with silence, when the honest answer is "you have read-only access" and is fixable in Airtable.
 */
const baseOptions = computed<Option[]>(() =>
    bases.value.map((base) => ({
        value: base.id,
        label: base.available ? base.label : `${base.label} (read-only)`,
    })),
);

const tableOptions = computed<Option[]>(
    () => destination.value?.tabs.map((tab) => ({ value: tab, label: tab })) ?? [],
);

const localProblem = computed<string | null>(() => (destination.value ? mappingProblem(rows.value) : null));
const summary = computed<string>(() => (destination.value ? mappingSummary(rows.value) : ''));

/**
 * The status line's text. Always rendered (never `v-if`'d away) so the live region exists in the DOM before it
 * has anything to say — an `aria-live` region inserted at the same moment as its text is not reliably
 * announced. The H15b pattern, unchanged.
 */
const status = computed<string>(() => {
    if (busy.value) return '';
    if (problem.value) return problem.value;
    if (localProblem.value) return localProblem.value;
    if (destination.value) return summary.value;
    return '';
});

function reset(): void {
    destination.value = null;
    rows.value = [];
    problem.value = null;
}

/** Push the current draft up, or null while it is not yet saveable. */
function publish(): void {
    if (!destination.value || !destination.value.sheet_id || localProblem.value) {
        emit('change', null);
        return;
    }

    emit('change', {
        spreadsheet_id: destination.value.spreadsheet_id,
        spreadsheet_title: destination.value.title,
        sheet_id: destination.value.sheet_id,
        sheet_name: destination.value.sheet_name,
        columns: toPayload(rows.value),
    });
}

async function loadBases(): Promise<void> {
    if (!props.connectionId) return;
    busy.value = true;
    const payload = await fetchChannels(props.connectionId);
    bases.value = payload?.channels ?? [];
    problem.value = payload?.error ?? null;
    busy.value = false;
}

async function loadCatalog(): Promise<void> {
    if (!props.connectionId) return;
    const payload = await fetchMappableColumns(props.connectionId, props.formId === '' ? null : props.formId);
    catalog.value = payload?.columns ?? [];
    scoped.value = payload?.scoped ?? false;
}

/**
 * Read a base's tables. `table` re-inspects rather than reusing the previous payload, because a different
 * table has a different field row and carrying the old one would bind every column to a name that is not there.
 */
async function inspect(base: string, table?: string | null): Promise<void> {
    if (!props.connectionId || base === '') return;
    busy.value = true;
    problem.value = null;

    const payload = await inspectDestination(props.connectionId, base, table ?? null);

    destination.value = payload.destination;
    problem.value = payload.error;
    rows.value = payload.destination
        ? buildRows(payload.destination.header_row, table ? undefined : props.rule?.mapping)
        : [];
    busy.value = false;
    publish();
}

async function chooseBase(id: string): Promise<void> {
    baseId.value = id;
    reset();
    await inspect(id);
}

function bind(row: MappingRow, value: string): void {
    rows.value = rows.value.map((r) => (r.index === row.index ? { ...r, fieldKey: value === '' ? null : value } : r));
    publish();
}

// Seed on the grant or the rule changing: a different rule is a different destination, and a rule already
// pointing at a table is RE-INSPECTED rather than read from storage, so the editor shows the table's CURRENT
// field names — which, when a rule has drifted, is precisely the thing that differs from what was stored.
watch(
    [() => props.connectionId, () => props.rule?.id],
    async () => {
        reset();
        baseId.value = '';
        await Promise.all([loadBases(), loadCatalog()]);

        if (props.rule?.spreadsheet_id && props.connectionId) {
            baseId.value = props.rule.spreadsheet_id;
            await inspect(props.rule.spreadsheet_id, props.rule.sheet_name);
        }
    },
    { immediate: true },
);

/**
 * A scope change reloads the catalog but KEEPS the destination.
 *
 * ⚠️ The Sheets version of this watcher records why: resetting here once threw away a spreadsheet the tenant
 * had just had us create. Nothing is created for Airtable, so there is no irreversible side effect to lose —
 * but the other half of that reasoning still holds exactly. Only the BINDINGS can be invalidated by a scope
 * change, so only the bindings are pruned: a key the new catalog does not offer would otherwise stay selected,
 * pass validation, and write blanks forever because `ColumnMapping::project()` has no value for it.
 */
watch(
    () => props.formId,
    async () => {
        await loadCatalog();
        const offered = new Set(catalog.value.map((column) => column.key));
        rows.value = rows.value.map((row) =>
            row.fieldKey && !offered.has(row.fieldKey) ? { ...row, fieldKey: null } : row,
        );
        publish();
    },
);
</script>

<template>
    <div class="airtable-fields">
        <MdsFormField
            v-slot="{ id, describedby, invalid }"
            label="Base"
            help="We can see the bases shared with the Airtable account you connected."
            :error="destinationError"
        >
            <MdsSelect
                :id="id"
                :model-value="baseId"
                :options="baseOptions"
                :disabled="busy || !connectionId"
                placeholder="Choose a base"
                :describedby="describedby"
                :invalid="invalid"
                @update:model-value="chooseBase"
            />
        </MdsFormField>

        <MdsFormField
            v-if="destination"
            v-slot="{ id, describedby, invalid }"
            label="Table"
            :error="tableError"
        >
            <MdsSelect
                :id="id"
                :model-value="destination.sheet_name"
                :options="tableOptions"
                :disabled="busy"
                :describedby="describedby"
                :invalid="invalid"
                @update:model-value="inspect(baseId, $event)"
            />
        </MdsFormField>

        <p v-if="destination" class="airtable-fields__found">
            <MdsIcon name="check" size="sm" aria-hidden="true" />
            <a
                :href="destination.url"
                target="_blank"
                rel="noopener noreferrer"
                class="airtable-fields__link"
            >
                {{ destination.title }}
            </a>
        </p>

        <fieldset v-if="destination" class="airtable-fields__group">
            <legend class="airtable-fields__legend">Which answer fills which field?</legend>
            <p v-if="!scoped" class="airtable-fields__help">
                This rule isn’t scoped to one form, so only submission details can be mapped — pick a form
                above to map its answers.
            </p>
            <div class="airtable-fields__map">
                <MdsFormField
                    v-for="row in rows"
                    :key="row.index"
                    v-slot="{ id, describedby }"
                    :label="columnLabel(row)"
                >
                    <MdsSelect
                        :id="id"
                        :model-value="row.fieldKey ?? ''"
                        :groups="fieldOptionsFor(row, catalog, rows).groups"
                        :placeholder="UNBOUND_LABEL"
                        :describedby="describedby"
                        @update:model-value="bind(row, $event)"
                    />
                </MdsFormField>
            </div>
            <p v-if="mappingError" class="airtable-fields__group-error" role="alert">{{ mappingError }}</p>
        </fieldset>

        <p class="airtable-fields__status" role="status" aria-live="polite">
            <template v-if="busy">
                <MdsSpinner size="sm" label="Talking to Airtable" />
                Talking to Airtable…
            </template>
            <template v-else>{{ status }}</template>
        </p>
    </div>
</template>

<style scoped>
.airtable-fields {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.airtable-fields__group {
    border: 0;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.airtable-fields__legend {
    padding: 0;
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.airtable-fields__help,
.airtable-fields__status {
    margin: 0;
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
    min-height: 1.5rem;
}

.airtable-fields__found {
    margin: 0;
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

/* `-fg`, never `-bg` — there is no global `a` reset, so an unclassed link renders #0000EE (the J2a finding). */
.airtable-fields__link {
    color: var(--mds-color-action-primary-fg);
    max-width: min(28rem, 100%);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* The map can be long, so it scrolls inside the modal rather than pushing the actions off-screen. */
.airtable-fields__map {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    max-height: 22rem;
    overflow-y: auto;
    padding-right: var(--mds-space-1);
}

.airtable-fields__group-error {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-status-danger-fg);
}
</style>
