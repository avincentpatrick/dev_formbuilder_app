<script setup lang="ts">
/**
 * The Automations settings section (M132, `R-b7bc5149`): "when a response is submitted, do this" — send an email (a
 * notice and a link, `D82`) or send the answers to a web address (`D83`). They run on the queue after the response is
 * stored (`D62` = A), so the section says first that they never hold a respondent up.
 *
 * ── WHAT EACH READER MAY DO IS THE SERVER'S ANSWER ──────────────────────────────────────────────────────────
 * `can_webhook` (the permission AND the plan, asked as the route asks) decides whether a web address is offered at
 * all; each row's `manageable` decides whether it can be changed — a web-address automation is `webhooks.manage`'s.
 * A row this reader cannot change still shows what it does and how its runs went, and says who can change it.
 *
 * ── A NEW SECRET IS SHOWN ONCE ──────────────────────────────────────────────────────────────────────────────
 * Inline in the section, not in a second dialog: in the builder these settings already ARE a dialog. It stays until
 * the author says they have copied it, and is gone when the section is reopened.
 *
 * ⛔ The word "step" appears nowhere here: an automation runs an ACTION (`docs/workflow-branching-design.md` §2).
 */
import { computed, ref, watch } from 'vue';
import { MdsButton, MdsFormField, MdsSegmentedControl, MdsSwitch, MdsTextarea, MdsTextInput } from '@meridian/design-system';
import { createAutomation, deleteAutomation, parseRecipients, runReason, testAutomation, updateAutomation } from '@/components/forms/automations';
import type { AutomationAction, AutomationRow, AutomationsProps } from '@/components/forms/types';

const props = defineProps<{
    /** Whether the SETTINGS are open — the modal's state, or always true on the hub page. See `ConfirmationPanel`. */
    open: boolean;
    formId: string;
    automations: AutomationsProps;
}>();

const items = ref<AutomationRow[]>([...props.automations.items]);
const status = ref('');
const actionError = ref<string | null>(null);
const secret = ref<{ name: string; value: string } | null>(null);
const copied = ref(false);

// The add form.
const name = ref('');
const action = ref<AutomationAction>('email');
const recipientsText = ref('');
const url = ref('');
const formErrors = ref<Record<string, string>>({});
const saving = ref(false);

// The one row being edited, if any.
const editing = ref<string | null>(null);
const editName = ref('');
const editRecipients = ref('');
const editUrl = ref('');
const editError = ref<string | null>(null);

const atLimit = computed(() => items.value.length >= props.automations.max);
const actionOptions = computed(() => [
    { value: 'email', label: 'Send an email' },
    ...(props.automations.can_webhook ? [{ value: 'webhook', label: 'Send the answers to a web address' }] : []),
]);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        items.value = [...props.automations.items];
        status.value = '';
        actionError.value = null;
        secret.value = null;
        editing.value = null;
        formErrors.value = {};
    },
    { immediate: true },
);

function summary(row: AutomationRow): string {
    if (row.action === 'email') {
        const people = row.recipients ?? [];
        return `Emails ${people.join(', ')} with a link to each new response.`;
    }
    return `Sends the answers to ${row.url ?? row.host ?? 'a web address'}.`;
}

function runLine(run: AutomationRow['runs'][number]): string {
    const when = run.at === null ? '' : new Date(run.at).toLocaleString();
    const reason = runReason(run.error_code, run.response_status);
    return [run.label, when, reason].filter((part) => part !== null && part !== '').join(' · ');
}

/** A refusal's field errors arrive as the thrown message; field-level errors are mapped where the server names them. */
function fail(thrown: unknown, fallback: string): string {
    return thrown instanceof Error ? thrown.message : fallback;
}

async function add(): Promise<void> {
    if (saving.value) return;
    saving.value = true;
    formErrors.value = {};
    actionError.value = null;
    try {
        const { row, secret: minted } = await createAutomation(props.formId, {
            name: name.value,
            action: action.value,
            ...(action.value === 'email' ? { recipients: parseRecipients(recipientsText.value) } : { url: url.value }),
        });
        items.value = [...items.value, row];
        if (minted !== null) {
            secret.value = { name: row.name, value: minted };
            copied.value = false;
        }
        status.value = `Added ${row.name}.`;
        name.value = '';
        recipientsText.value = '';
        url.value = '';
    } catch (thrown) {
        formErrors.value = { form: fail(thrown, 'The automation was not saved.') };
    } finally {
        saving.value = false;
    }
}

async function toggle(row: AutomationRow, enabled: boolean): Promise<void> {
    actionError.value = null;
    try {
        const updated = await updateAutomation(props.formId, row.id, { enabled });
        items.value = items.value.map((item) => (item.id === updated.id ? updated : item));
        status.value = `${updated.name} is ${updated.enabled ? 'on' : 'off'}.`;
    } catch (thrown) {
        actionError.value = fail(thrown, 'The change was not saved.');
    }
}

function startEdit(row: AutomationRow): void {
    editing.value = row.id;
    editName.value = row.name;
    editRecipients.value = (row.recipients ?? []).join(', ');
    editUrl.value = row.url ?? '';
    editError.value = null;
}

async function saveEdit(row: AutomationRow): Promise<void> {
    editError.value = null;
    try {
        const updated = await updateAutomation(props.formId, row.id, {
            name: editName.value,
            ...(row.action === 'email' ? { recipients: parseRecipients(editRecipients.value) } : { url: editUrl.value }),
        });
        items.value = items.value.map((item) => (item.id === updated.id ? updated : item));
        editing.value = null;
        status.value = `Saved ${updated.name}.`;
    } catch (thrown) {
        editError.value = fail(thrown, 'The change was not saved.');
    }
}

async function remove(row: AutomationRow): Promise<void> {
    actionError.value = null;
    try {
        await deleteAutomation(props.formId, row.id);
        items.value = items.value.filter((item) => item.id !== row.id);
        status.value = `Deleted ${row.name}.`;
    } catch (thrown) {
        actionError.value = fail(thrown, 'The automation was not deleted.');
    }
}

async function test(row: AutomationRow): Promise<void> {
    actionError.value = null;
    try {
        const result = await testAutomation(props.formId, row.id);
        status.value = `${row.name}: ${result.message}`;
    } catch (thrown) {
        actionError.value = fail(thrown, 'The test was not sent.');
    }
}

async function copySecret(): Promise<void> {
    if (secret.value === null) return;
    try {
        await navigator.clipboard.writeText(secret.value.value);
        copied.value = true;
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <div class="automations">
        <p class="automations__prose">
            When a response is submitted, an automation can email people or send the answers to a web address. They run
            in the background once the response is saved, so they never hold a respondent up.
        </p>

        <div v-if="secret !== null" class="automations__secret" data-automation-secret>
            <p class="automations__secret-title">Signing secret for {{ secret.name }}</p>
            <p class="automations__secret-help">
                Copy it now: it will not be shown again. The receiving system uses it to check that each request came
                from this form.
            </p>
            <code class="automations__secret-value">{{ secret.value }}</code>
            <div class="automations__row-actions">
                <MdsButton size="sm" variant="secondary" icon-left="copy" @click="copySecret">{{ copied ? 'Copied' : 'Copy' }}</MdsButton>
                <MdsButton size="sm" variant="tertiary" @click="secret = null">I have copied it</MdsButton>
            </div>
        </div>

        <ul v-if="items.length > 0" class="automations__list" aria-label="Automations">
            <li v-for="row in items" :key="row.id" class="automations__item" :data-automation="row.id">
                <template v-if="editing === row.id">
                    <MdsFormField v-slot="{ id }" label="Name">
                        <MdsTextInput :id="id" v-model="editName" maxlength="80" />
                    </MdsFormField>
                    <MdsFormField v-if="row.action === 'email'" v-slot="{ id }" label="Email addresses" help="Up to five, separated by commas.">
                        <MdsTextarea :id="id" v-model="editRecipients" :rows="2" />
                    </MdsFormField>
                    <MdsFormField v-else v-slot="{ id }" label="Web address">
                        <MdsTextInput :id="id" v-model="editUrl" type="url" />
                    </MdsFormField>
                    <p v-if="editError" class="automations__error" role="alert">{{ editError }}</p>
                    <div class="automations__row-actions">
                        <MdsButton size="sm" variant="primary" @click="saveEdit(row)">Save</MdsButton>
                        <MdsButton size="sm" variant="tertiary" @click="editing = null">Cancel</MdsButton>
                    </div>
                </template>
                <template v-else>
                    <div class="automations__head">
                        <span :id="`automation-name-${row.id}`" class="automations__name">{{ row.name }}</span>
                        <MdsSwitch
                            v-if="row.manageable"
                            :model-value="row.enabled"
                            :label="row.enabled ? 'On' : 'Off'"
                            :describedby="`automation-name-${row.id}`"
                            @update:model-value="(value: boolean) => toggle(row, value)"
                        />
                        <span v-else class="automations__meta">{{ row.enabled ? 'On' : 'Off' }}</span>
                    </div>
                    <p class="automations__summary">{{ summary(row) }}</p>
                    <p v-if="!row.manageable" class="automations__meta">
                        Only Owners and Admins can change an automation that sends answers to a web address.
                    </p>
                    <div v-if="row.manageable" class="automations__row-actions">
                        <MdsButton size="sm" variant="tertiary" icon-left="edit" @click="startEdit(row)">Edit</MdsButton>
                        <MdsButton v-if="row.action === 'webhook'" size="sm" variant="tertiary" icon-left="share" @click="test(row)">
                            Send a test
                        </MdsButton>
                        <MdsButton size="sm" variant="tertiary" icon-left="trash" @click="remove(row)">Delete</MdsButton>
                    </div>
                    <p class="automations__runs-label">Recent runs</p>
                    <ul v-if="row.runs.length > 0" class="automations__runs">
                        <li v-for="run in row.runs" :key="run.id" :data-run-status="run.status">{{ runLine(run) }}</li>
                    </ul>
                    <p v-else class="automations__meta">No runs yet.</p>
                </template>
            </li>
        </ul>
        <p v-else class="automations__meta">No automations yet.</p>

        <p v-if="actionError" class="automations__error" role="alert">{{ actionError }}</p>

        <div v-if="!atLimit" class="automations__add">
            <p class="automations__add-title">Add an automation</p>
            <MdsFormField v-slot="{ id }" label="Name">
                <MdsTextInput :id="id" v-model="name" maxlength="80" />
            </MdsFormField>
            <div class="automations__choice">
                <MdsSegmentedControl
                    v-model="action"
                    :options="actionOptions"
                    ariaLabel="What happens when a response is submitted"
                />
            </div>
            <MdsFormField
                v-if="action === 'email'"
                v-slot="{ id }"
                label="Email addresses"
                help="Up to five, separated by commas. The email says a response arrived and links to it; it never contains the answers."
            >
                <MdsTextarea :id="id" v-model="recipientsText" :rows="2" />
            </MdsFormField>
            <MdsFormField
                v-else
                v-slot="{ id }"
                label="Web address"
                help="The answers are sent here, signed with a secret you will see once."
            >
                <MdsTextInput :id="id" v-model="url" type="url" placeholder="https://" />
            </MdsFormField>
            <p v-if="formErrors.form" class="automations__error" role="alert">{{ formErrors.form }}</p>
            <MdsButton variant="primary" :loading="saving" @click="add">Add automation</MdsButton>
        </div>
        <p v-else class="automations__meta">A form can have at most {{ automations.max }} automations.</p>

        <p class="automations__status" role="status">{{ status }}</p>
    </div>
</template>

<style scoped>
.automations {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.automations__prose {
    margin: 0;
    color: var(--mds-color-text-body);
}

.automations__list,
.automations__runs {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

.automations__item,
.automations__secret,
.automations__add {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-sm);
}

.automations__secret {
    border-left: 4px solid var(--mds-color-status-warning-fg);
}

.automations__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-2);
}

.automations__name,
.automations__secret-title,
.automations__add-title {
    margin: 0;
    color: var(--mds-color-text-heading);
    font-weight: var(--mds-font-weight-semibold);
    overflow-wrap: anywhere;
}

.automations__summary,
.automations__secret-help {
    margin: 0;
    color: var(--mds-color-text-body);
    overflow-wrap: anywhere;
}

.automations__secret-value {
    padding: var(--mds-space-2);
    background-color: var(--mds-color-bg-sunken);
    border-radius: var(--mds-radius-sm);
    font-family: var(--mds-font-family-mono, monospace);
    overflow-wrap: anywhere;
}

.automations__runs-label,
.automations__meta,
.automations__runs li,
.automations__status {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.automations__runs-label {
    font-weight: var(--mds-font-weight-medium);
}

.automations__error {
    margin: 0;
    color: var(--mds-color-status-danger-fg);
    font-size: var(--mds-type-body-sm-font-size);
}

.automations__row-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-1);
}

/* `D28`'s host guard: the segmented control is inline-flex with no wrap of its own (see `PageModePanel`). */
.automations__choice :deep(.mds-segmented) {
    flex-wrap: wrap;
}
</style>
