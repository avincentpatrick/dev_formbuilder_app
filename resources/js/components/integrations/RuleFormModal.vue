<script setup lang="ts">
/**
 * Create / edit a connector delivery rule (H15b). One modal serves both flows: `rule` null = create
 * (POST /integrations/connections/{id}/rules), non-null = edit (PATCH /integrations/rules/{id}). Uses Inertia
 * `useForm` so per-field 422s surface inline via `MdsFormField :error`, mirroring WebhookFormModal.
 *
 * THE CHANNEL PICKER IS LAZY AND FAILURE-TOLERANT. Channels come from a JSON sidecar that calls the provider,
 * fetched on first open rather than with the page — a tenant who never opens this modal should never cause an
 * outbound call. When the fetch fails the field DOWNGRADES to a plain channel-id input instead of disappearing:
 * a Slack outage must not stop someone renaming a rule or changing which events it carries, and the id is what
 * the server stores either way.
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import {
    MdsButton,
    MdsCheckbox,
    MdsFormField,
    MdsModal,
    MdsSelect,
    MdsSpinner,
    MdsTextInput,
} from '@meridian/design-system';
import { fetchChannels } from './integrationsClient';
import { CHANNEL_PLACEHOLDER, channelWarning, toChannelOptions } from './channel-options';
import SheetsRuleFields from './SheetsRuleFields.vue';
import type { Channel, Option, RuleRow } from './types';

const props = defineProps<{
    open: boolean;
    connectionId: string | null;
    forms: Option[];
    eventTypes: Option[];
    rule?: RuleRow | null;
    /** The grant's provider key (H16b). Absent behaves as Slack, which is the pre-H16b shape. */
    provider?: string | null;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const form = useForm<{
    name: string;
    event_types: string[];
    form_id: string;
    channel_id: string;
    channel_name: string;
}>({
    name: '',
    event_types: [],
    form_id: '',
    channel_id: '',
    channel_name: '',
});

const channels = ref<Channel[]>([]);
const channelsLoading = ref(false);
const channelsError = ref<string | null>(null);
const channelsTruncated = ref(false);
/** Set once the fetch has been attempted, so re-opening the modal doesn't re-hit the provider every time. */
const channelsLoaded = ref(false);

// The tenant-wide (null form_id) choice leads the scope select; a real form scopes the rule to it.
const formScopeOptions = computed<Option[]>(() => [
    { value: '', label: 'All forms (tenant-wide)' },
    ...props.forms,
]);

// The form is FLAT but the request body is nested (`config.channel_id`), so the server's error key is dotted
// and cannot be expressed in useForm's key-of-data error type. Read it through one narrow cast here rather
// than reshaping the form state around a wire detail.
const channelError = computed<string | undefined>(
    () => (form.errors as Record<string, string | undefined>)['config.channel_id'],
);

/** H16b — a tabular provider swaps the channel picker for the sheet destination + column map. */
const isTabular = computed<boolean>(() => props.provider === 'google_sheets');

type SheetsDraft = {
    spreadsheet_id: string;
    spreadsheet_title: string;
    sheet_name: string;
    columns: { header: string; field_key: string | null }[];
};

const sheetsDraft = ref<SheetsDraft | null>(null);

/**
 * ⚠️ THE EVENT LIST IS NARROWED, NOT JUST STYLED — and the server enforces the same rule in `after()`.
 * Only `submission.*` events carry a submission id, and a spreadsheet row IS a submission's answers, so a
 * `form.published` rule on a sheet is undeliverable by construction. Offering the checkbox and rejecting the
 * save would be worse than either: `SubscriptionConfigRules::eventTypeGuard()` exists so a rule that cannot
 * work never reaches the ledger, and this exists so the tenant never ticks the box in the first place.
 */
const availableEvents = computed<Option[]>(() =>
    isTabular.value ? props.eventTypes.filter((opt) => opt.value.startsWith('submission.')) : props.eventTypes,
);

const selectedFormTitle = computed<string | null>(
    () => props.forms.find((f) => f.value === form.form_id)?.label ?? null,
);

/** Dotted server errors, passed down so the sheet fields can render them against the right control. */
const dottedErrors = computed<Record<string, string | undefined>>(
    () => form.errors as Record<string, string | undefined>,
);

const channelOptions = computed<Option[]>(() => toChannelOptions(channels.value, form.channel_id));
const warning = computed<string | null>(() => channelWarning(channels.value, form.channel_id));
/** No usable list — fall back to a raw id field rather than an empty select nobody can satisfy. */
const manualChannel = computed<boolean>(() => channelsLoaded.value && !channelsLoading.value && channels.value.length === 0);

async function loadChannels(force = false): Promise<void> {
    if (!props.connectionId) return;
    // A tabular provider has no channel_lister by design (drive.file cannot enumerate), so the sidecar would
    // answer with the "no destination list" message every time this modal opened. Not calling is both
    // cheaper and the difference between a picker that degrades and one that reports a failure that is not one.
    if (isTabular.value) return;
    if (channelsLoaded.value && !force) return;

    channelsLoading.value = true;
    channelsError.value = null;

    const payload = await fetchChannels(props.connectionId);

    channels.value = payload?.channels ?? [];
    channelsTruncated.value = payload?.truncated ?? false;
    channelsError.value =
        payload === null ? 'We couldn’t load channels. Enter a channel id, or try again.' : payload.error;
    channelsLoading.value = false;
    channelsLoaded.value = true;
}

// (Re)seed on open from the rule being edited, or blank for a create — Inertia's redirect refreshes the page
// props after each save, so this always reflects the latest persisted state.
watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.name = props.rule?.name ?? '';
        form.event_types = [...(props.rule?.event_types ?? [])];
        form.form_id = props.rule?.form_id ?? '';
        form.channel_id = props.rule?.channel_id ?? '';
        form.channel_name = props.rule?.channel_name ?? '';
        form.clearErrors();
        void loadChannels();
    },
    { immediate: true },
);

// A different grant means a different workspace, so the cached channel list is meaningless.
watch(
    () => props.connectionId,
    () => {
        channels.value = [];
        channelsLoaded.value = false;
        channelsTruncated.value = false;
        channelsError.value = null;
    },
);

function toggleEvent(value: string, checked: boolean): void {
    form.event_types = checked
        ? [...form.event_types, value]
        : form.event_types.filter((v) => v !== value);
}

function close(): void {
    emit('update:open', false);
}

function submit(): void {
    const shaped = form.transform((data) => ({
        name: data.name,
        event_types: data.event_types,
        form_id: data.form_id === '' ? null : data.form_id,
        config: isTabular.value
            ? {
                  spreadsheet_id: sheetsDraft.value?.spreadsheet_id ?? '',
                  spreadsheet_title: sheetsDraft.value?.spreadsheet_title ?? null,
                  sheet_name: sheetsDraft.value?.sheet_name ?? null,
                  // No `fingerprint` — the server derives it from these headers through
                  // `ColumnMapping::author()`. Sending one from here would be a second implementation of
                  // `ColumnFingerprint`'s normalisation, kept in step by nothing.
                  mapping: sheetsDraft.value ? { columns: sheetsDraft.value.columns } : null,
              }
            : {
                  channel_id: data.channel_id,
                  // The label the tenant picked, so the list can read "#general" without re-querying the
                  // provider. Null when it was typed by hand — there is no name to know.
                  channel_name:
                      channels.value.find((c) => c.id === data.channel_id)?.label.replace(/^#/, '') ?? null,
              },
    }));

    const options = { preserveScroll: true, onSuccess: () => close() };

    if (props.rule) {
        shaped.patch(`/integrations/rules/${props.rule.id}`, options);
    } else if (props.connectionId) {
        shaped.post(`/integrations/connections/${props.connectionId}/rules`, options);
    }
}
</script>

<template>
    <MdsModal :open="open" :title="rule ? 'Edit delivery rule' : 'Add delivery rule'" @close="close">
        <form class="rule-form" @submit.prevent="submit">
            <MdsFormField v-slot="{ id, describedby, invalid }" label="Name" :error="form.errors.name">
                <MdsTextInput
                    :id="id"
                    v-model="form.name"
                    placeholder="e.g. New submissions to #ops"
                    :describedby="describedby"
                    :invalid="invalid"
                />
            </MdsFormField>

            <!-- A checkbox GROUP → fieldset/legend (not FormField, which owns a single label→control). -->
            <fieldset class="rule-form__group">
                <legend class="rule-form__legend">Events</legend>
                <p class="rule-form__help">
                    {{
                        isTabular
                            ? 'Which submissions get a row. Only submission events carry answers, so only those can be written to a spreadsheet.'
                            : 'Which events get posted to the channel.'
                    }}
                </p>
                <div class="rule-form__events">
                    <MdsCheckbox
                        v-for="opt in availableEvents"
                        :key="opt.value"
                        :model-value="form.event_types.includes(opt.value)"
                        :label="opt.label"
                        @update:model-value="toggleEvent(opt.value, $event)"
                    />
                </div>
                <p v-if="form.errors.event_types" class="rule-form__group-error" role="alert">
                    {{ form.errors.event_types }}
                </p>
            </fieldset>

            <SheetsRuleFields
                v-if="isTabular"
                :connection-id="connectionId"
                :form-id="form.form_id"
                :form-title="selectedFormTitle"
                :rule="rule"
                :errors="dottedErrors"
                @change="sheetsDraft = $event"
            />

            <MdsFormField
                v-else
                v-slot="{ id, describedby, invalid }"
                label="Channel"
                :help="manualChannel ? 'Paste the channel id from Slack (Channel details → the ID at the bottom).' : 'Only public channels are listed.'"
                :error="channelError"
            >
                <div class="rule-form__channel">
                    <MdsTextInput
                        v-if="manualChannel"
                        :id="id"
                        v-model="form.channel_id"
                        placeholder="C0123456789"
                        :describedby="describedby"
                        :invalid="invalid"
                    />
                    <MdsSelect
                        v-else
                        :id="id"
                        v-model="form.channel_id"
                        :options="channelOptions"
                        :placeholder="CHANNEL_PLACEHOLDER"
                        :disabled="channelsLoading"
                        :describedby="describedby"
                        :invalid="invalid"
                    />
                    <!-- `loading`, not `disabled`, while a fetch is in flight: MdsButton keeps a loading
                         button focusable (aria-disabled rather than the native attribute), whereas natively
                         disabling it on click would blow away the focus of the very keyboard user who
                         pressed it. -->
                    <MdsButton
                        type="button"
                        variant="tertiary"
                        size="sm"
                        icon-left="redo"
                        :loading="channelsLoading"
                        :disabled="!connectionId"
                        @click="loadChannels(true)"
                    >
                        Refresh
                    </MdsButton>
                </div>
            </MdsFormField>

            <!-- Not rendered for a tabular provider: SheetsRuleFields owns its own always-present live
                 region, and two competing `aria-live` regions on one form is worse than one — this one would
                 be permanently empty, which is exactly the "region with nothing to say" the H15b note warns
                 about, doubled. -->
            <p v-if="!isTabular" class="rule-form__status" role="status" aria-live="polite">
                <template v-if="channelsLoading">
                    <MdsSpinner size="sm" label="Loading channels" />
                    Loading channels…
                </template>
                <template v-else-if="channelsError">{{ channelsError }}</template>
                <template v-else-if="warning">{{ warning }}</template>
                <template v-else-if="channelsTruncated">
                    Showing the first {{ channels.length }} channels. Refine in Slack or paste an id if yours is missing.
                </template>
            </p>

            <MdsFormField
                v-slot="{ id, describedby, invalid }"
                label="Scope"
                help="Post events from every form, or just one."
                :error="form.errors.form_id"
            >
                <MdsSelect
                    :id="id"
                    v-model="form.form_id"
                    :options="formScopeOptions"
                    :describedby="describedby"
                    :invalid="invalid"
                />
            </MdsFormField>
        </form>
        <template #actions>
            <MdsButton variant="tertiary" :disabled="form.processing" @click="close">Cancel</MdsButton>
            <MdsButton variant="primary" icon-left="check" :loading="form.processing" @click="submit">
                {{ rule ? 'Save changes' : 'Create rule' }}
            </MdsButton>
        </template>
    </MdsModal>
</template>

<style scoped>
.rule-form {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.rule-form__group {
    margin: 0;
    padding: 0;
    border: 0;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

.rule-form__legend {
    padding: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    line-height: var(--mds-type-label-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.rule-form__help {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
}

.rule-form__events {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
}

.rule-form__group-error {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-danger-text);
}

/* Wraps rather than overflowing: the select + Refresh button is a two-control row inside a modal that is
   itself narrow at 375px. `min-width: 0` lets the select shrink instead of forcing a horizontal scroller. */
.rule-form__channel {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
}

.rule-form__channel > :first-child {
    flex: 1 1 12rem;
    min-width: 0;
}

/* Always rendered (never v-if'd away) so the live region exists in the DOM before it has anything to say —
   an aria-live region inserted at the same moment as its text is not reliably announced. */
.rule-form__status {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    margin: calc(var(--mds-space-4) * -1) 0 0;
    min-height: 1.25rem;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
}
</style>
