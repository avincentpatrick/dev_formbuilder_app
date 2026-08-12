<script setup lang="ts">
/**
 * The Google Sheets half of the delivery-rule form (H16b) — destination, tab, and the column map.
 *
 * Mounted by {@link RuleFormModal} in place of the channel picker when the grant's provider is tabular. A
 * sibling rather than a branch inside that file: the two destinations share nothing but the word "where", and
 * H15a's four request classes already showed what happens when one provider's shape is treated as the shared
 * one.
 *
 * ── CREATE IS THE DEFAULT PATH, AND THAT IS THE WHOLE POINT OF THE INCREMENT ───────────────────────────────
 * Under `drive.file` we can reach only files we created or the tenant explicitly handed us, so "paste an id"
 * fails for most tenants — and fails at DELIVERY time, where it reads as a broken integration rather than a
 * setup mistake. Creating the spreadsheet makes the destination reachable by construction, and it means the
 * header row is ours, so the first drift the tenant ever sees is a real edit of theirs.
 *
 * ── NO FINGERPRINT IS COMPUTED HERE ────────────────────────────────────────────────────────────────────────
 * See `mapping-model.ts`: the server re-derives it from the header row through `ColumnMapping::author()`. A
 * TypeScript reimplementation of `ColumnFingerprint`'s normalisation would be a second thing to keep in step
 * with a digest the delivery path compares byte for byte.
 */
import { computed, ref, watch } from 'vue';
import {
    MdsButton,
    MdsFormField,
    MdsIcon,
    MdsSegmentedControl,
    MdsSelect,
    MdsSpinner,
    MdsTextInput,
} from '@meridian/design-system';
import { createSheet, fetchMappableColumns, inspectSheet } from './integrationsClient';
import {
    buildRows,
    columnLabel,
    fieldOptionsFor,
    mappingProblem,
    mappingSummary,
    suggestedTitle,
    toPayload,
    UNBOUND_LABEL,
    type MappingRow,
} from './mapping-model';
import type { MappableColumn, Option, RuleRow, SheetDestination } from './types';

const props = defineProps<{
    connectionId: string | null;
    /** The scope currently chosen in the parent form — '' means all forms. */
    formId: string;
    formTitle: string | null;
    rule?: RuleRow | null;
    /** The parent's dotted server errors, so `config.spreadsheet_id` can render against the right field. */
    errors: Record<string, string | undefined>;
}>();

const emit = defineEmits<{
    change: [value: { spreadsheet_id: string; spreadsheet_title: string; sheet_name: string; columns: ReturnType<typeof toPayload> } | null];
}>();

type Mode = 'create' | 'existing';

const mode = ref<Mode>('create');
const reference = ref('');
const title = ref('');
const destination = ref<SheetDestination | null>(null);
const rows = ref<MappingRow[]>([]);
const catalog = ref<MappableColumn[]>([]);
const scoped = ref(false);
const busy = ref(false);
const problem = ref<string | null>(null);

const modeOptions: Option[] = [
    { value: 'create', label: 'Create a sheet for me' },
    { value: 'existing', label: 'Use one I already have' },
];

const destinationError = computed<string | undefined>(() => props.errors['config.spreadsheet_id']);
const mappingError = computed<string | undefined>(
    () => props.errors['config.mapping'] ?? props.errors['config.mapping.columns'],
);

const tabOptions = computed<Option[]>(
    () => destination.value?.tabs.map((tab) => ({ value: tab, label: tab })) ?? [],
);

const localProblem = computed<string | null>(() => (destination.value ? mappingProblem(rows.value) : null));
const summary = computed<string>(() => (destination.value ? mappingSummary(rows.value) : ''));

/**
 * The status line's text. Always rendered (never `v-if`'d away) so the live region exists in the DOM before
 * it has anything to say — an `aria-live` region inserted at the same moment as its text is not reliably
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
    if (!destination.value || localProblem.value) {
        emit('change', null);

        return;
    }

    emit('change', {
        spreadsheet_id: destination.value.spreadsheet_id,
        spreadsheet_title: destination.value.title,
        sheet_name: destination.value.sheet_name,
        columns: toPayload(rows.value),
    });
}

async function loadCatalog(): Promise<void> {
    if (!props.connectionId) return;

    const payload = await fetchMappableColumns(props.connectionId, props.formId === '' ? null : props.formId);

    catalog.value = payload?.columns ?? [];
    scoped.value = payload?.scoped ?? false;
}

async function connectExisting(): Promise<void> {
    if (!props.connectionId || reference.value.trim() === '') return;

    busy.value = true;
    problem.value = null;

    const payload = await inspectSheet(props.connectionId, reference.value);

    destination.value = payload.destination;
    problem.value = payload.error;
    rows.value = payload.destination ? buildRows(payload.destination.header_row, props.rule?.mapping) : [];
    busy.value = false;
    publish();
}

async function changeTab(tab: string): Promise<void> {
    if (!props.connectionId || !destination.value) return;

    busy.value = true;
    problem.value = null;

    // Re-inspected rather than assumed: a different tab has a different header row, and reusing the previous
    // one would bind every column to a heading that is not there.
    const payload = await inspectSheet(props.connectionId, destination.value.spreadsheet_id, tab);

    if (payload.destination) {
        destination.value = payload.destination;
        rows.value = buildRows(payload.destination.header_row);
    }

    problem.value = payload.error;
    busy.value = false;
    publish();
}

async function create(): Promise<void> {
    if (!props.connectionId) return;

    busy.value = true;
    problem.value = null;

    // The header row IS the catalog, in order, so a created sheet arrives fully mapped and the tenant can
    // unbind what they do not want rather than bind fourteen things by hand.
    const headers = catalog.value.map((column) => column.label);
    const payload = await createSheet(props.connectionId, title.value.trim() || suggestedTitle(props.formTitle), headers);

    destination.value = payload.destination;
    problem.value = payload.error;

    if (payload.destination) {
        rows.value = payload.destination.header_row.map((header, index) => ({
            index,
            header,
            fieldKey: catalog.value[index]?.key ?? null,
        }));
    }

    busy.value = false;
    publish();
}

function bind(row: MappingRow, value: string): void {
    rows.value = rows.value.map((r) => (r.index === row.index ? { ...r, fieldKey: value === '' ? null : value } : r));
    publish();
}

// Seed on the grant or the rule changing: a different rule is a different destination, and a rule already
// pointing at a sheet is RE-INSPECTED rather than read from storage, so the editor shows the sheet's CURRENT
// headings — which, when a rule has drifted, is precisely the thing that differs from what was stored.
watch(
    [() => props.connectionId, () => props.rule?.id],
    async () => {
        reset();
        title.value = suggestedTitle(props.formTitle);
        await loadCatalog();

        if (props.rule?.spreadsheet_id && props.connectionId) {
            mode.value = 'existing';
            reference.value = props.rule.spreadsheet_id;
            await connectExisting();
        }
    },
    { immediate: true },
);

/**
 * A scope change reloads the catalog but KEEPS the destination.
 *
 * ⚠️ The first version reset everything here, which threw away a spreadsheet the tenant had just had us
 * create in their Drive — an irreversible side effect discarded by an unrelated dropdown, leaving an orphan
 * file behind. Only the BINDINGS can be invalidated by a scope change, so only the bindings are pruned: a key
 * the new catalog does not offer would otherwise stay selected, pass validation, and write blanks forever
 * because `ColumnMapping::project()` has no value for it.
 */
watch(
    () => props.formId,
    async () => {
        title.value = suggestedTitle(props.formTitle);
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
    <div class="sheets-fields">
        <!-- No wrapping fieldset/legend: MdsSegmentedControl renders its OWN fieldset and prints `ariaLabel`
             as the legend, so one here would nest a fieldset inside a fieldset and give the group two
             legends — an accessible name that reads as both at once. Caught by vue-tsc, which is the only
             gate in the stack that could have: happy-dom computes no styles and axe never sees an app page. -->
        <MdsSegmentedControl
            v-model="mode"
            :options="modeOptions"
            ariaLabel="Where should the rows go?"
            @update:model-value="reset()"
        />

        <template v-if="mode === 'create'">
            <MdsFormField
                v-slot="{ id, describedby, invalid }"
                label="Name the new spreadsheet"
                help="We’ll create it in your Google Drive with a heading row already set up."
                :error="destinationError"
            >
                <div class="sheets-fields__row">
                    <MdsTextInput
                        :id="id"
                        v-model="title"
                        :describedby="describedby"
                        :invalid="invalid"
                        :disabled="destination !== null"
                    />
                    <MdsButton
                        type="button"
                        variant="secondary"
                        icon-left="plus"
                        :loading="busy"
                        :disabled="!connectionId || destination !== null"
                        @click="create"
                    >
                        Create
                    </MdsButton>
                </div>
            </MdsFormField>
        </template>

        <template v-else>
            <MdsFormField
                v-slot="{ id, describedby, invalid }"
                label="Paste the spreadsheet link"
                help="Copy the address from your browser while the sheet is open."
                :error="destinationError"
            >
                <div class="sheets-fields__row">
                    <MdsTextInput
                        :id="id"
                        v-model="reference"
                        placeholder="https://docs.google.com/spreadsheets/d/…"
                        :describedby="describedby"
                        :invalid="invalid"
                    />
                    <MdsButton
                        type="button"
                        variant="secondary"
                        :loading="busy"
                        :disabled="!connectionId || reference.trim() === ''"
                        @click="connectExisting"
                    >
                        Check
                    </MdsButton>
                </div>
            </MdsFormField>
        </template>

        <p v-if="destination" class="sheets-fields__found">
            <MdsIcon name="check" size="sm" aria-hidden="true" />
            <a :href="destination.url" target="_blank" rel="noopener noreferrer" class="sheets-fields__link">
                {{ destination.title }}
            </a>
        </p>

        <MdsFormField
            v-if="destination && destination.tabs.length > 1"
            v-slot="{ id, describedby }"
            label="Tab"
        >
            <MdsSelect
                :id="id"
                :model-value="destination.sheet_name"
                :options="tabOptions"
                :disabled="busy"
                :describedby="describedby"
                @update:model-value="changeTab"
            />
        </MdsFormField>

        <fieldset v-if="destination" class="sheets-fields__group">
            <legend class="sheets-fields__legend">Which field fills which column?</legend>
            <p v-if="!scoped" class="sheets-fields__help">
                This rule isn’t scoped to one form, so only submission details can be mapped — pick a form
                above to map its answers.
            </p>
            <div class="sheets-fields__map">
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
            <p v-if="mappingError" class="sheets-fields__group-error" role="alert">{{ mappingError }}</p>
        </fieldset>

        <p class="sheets-fields__status" role="status" aria-live="polite">
            <template v-if="busy">
                <MdsSpinner size="sm" label="Talking to Google" />
                Talking to Google…
            </template>
            <template v-else>{{ status }}</template>
        </p>
    </div>
</template>

<style scoped>
.sheets-fields {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.sheets-fields__group {
    border: 0;
    margin: 0;
    padding: 0;
}

.sheets-fields__legend {
    padding: 0;
    margin-bottom: var(--mds-space-2);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.sheets-fields__help,
.sheets-fields__status {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.sheets-fields__status {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    min-height: var(--mds-space-5);
}

/* Wrap rather than overflow — the standing 375px rule for any row of controls. */
.sheets-fields__row {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: var(--mds-space-2);
}

.sheets-fields__row > :first-child {
    flex: 1 1 14rem;
}

.sheets-fields__found {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-status-success-fg);
}

/* `-fg`, never `-bg`, for a coloured indicator — the J2a WCAG 1.4.11 finding. */
.sheets-fields__link {
    color: var(--mds-color-action-primary-fg);
    text-decoration: none;
}

.sheets-fields__link:hover {
    text-decoration: underline;
}

.sheets-fields__link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
}

/* The map can be long, so it scrolls inside the modal rather than pushing the actions off-screen. */
.sheets-fields__map {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    max-height: 22rem;
    overflow-y: auto;
    padding-right: var(--mds-space-1);
}

.sheets-fields__group-error {
    margin: var(--mds-space-2) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-status-danger-fg);
}
</style>
