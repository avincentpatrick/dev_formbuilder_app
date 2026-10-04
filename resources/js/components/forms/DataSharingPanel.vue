<script setup lang="ts">
/**
 * The Data sharing settings section (M133, `R-5da4a30f` — Connect project v1's SOURCE key): whether other forms of
 * this workspace may take their choices from this form's answers, and from which questions —
 * `PATCH /forms/{form}/data-sharing`, the only writer of `forms.data_sharing_enabled` and `data_sharing_field_keys`.
 *
 * The server sends this section only to someone who can read this form's responses (`D87`) and lists only the
 * questions that may be shared (`ShareableQuestions`: published, one short answer, outside a repeat group, and
 * never marked personal or sensitive — `D86`). The other key of the consent is the linking form's owner, checked
 * where the link is made; this section never sees it.
 *
 * ⚠️ SWITCHING ON ASKS FIRST, INLINE, AND NOT IN A SECOND DIALOG. A shared answer can be seen by anyone who opens a
 * form that uses it — through its public link included — so the switch stays off until the author confirms in
 * words. The confirmation is a group inside this section rather than an `MdsModal`, because in the builder this
 * section is already inside the settings dialog and no settings section stacks a dialog on it.
 *
 * ⚠️ `field_keys` IS NULL FOR "EVERY QUESTION" AND NEVER AN EMPTY LIST — the request and the column both refuse
 * `[]` — so "Only the questions I choose" with nothing ticked is stopped here, before it is sent.
 */
import { computed, ref, useId, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { MdsButton, MdsCheckbox, MdsRadio } from '@meridian/design-system';
import type { DataSharingProps } from '@/components/forms/types';

const props = defineProps<{
    /** Whether the SETTINGS are open — the modal's state, or always true on the hub page. See `ConfirmationPanel`. */
    open: boolean;
    formId: string;
    sharing: DataSharingProps;
}>();

type Scope = 'all' | 'chosen';

const enabled = ref(props.sharing.enabled);
const confirming = ref(false);
const scope = ref<Scope>(props.sharing.field_keys === null ? 'all' : 'chosen');
const chosen = ref<string[]>(props.sharing.field_keys ?? []);
const error = ref<string | null>(null);
const saving = ref(false);

const scopeName = useId();
const confirmId = useId();
const scopeHelpId = useId();

function reseed(): void {
    enabled.value = props.sharing.enabled;
    confirming.value = false;
    scope.value = props.sharing.field_keys === null ? 'all' : 'chosen';
    chosen.value = [...(props.sharing.field_keys ?? [])];
    error.value = null;
}

// Re-seed on open like every other section; after a refused write this also shows what the server holds.
watch(
    () => props.open,
    (open) => {
        if (open) reseed();
    },
    { immediate: true },
);

const canShare = computed(() => props.sharing.published && props.sharing.questions.length > 0);

/** The list as the request reads it: null for every question, never an empty list. */
const fieldKeys = computed<string[] | null>(() => (scope.value === 'all' ? null : chosen.value));

const noneChosen = computed(() => scope.value === 'chosen' && chosen.value.length === 0);

const questionsChanged = computed(() => {
    const stored = props.sharing.field_keys;
    if (scope.value === 'all') return stored !== null;
    if (stored === null) return true;

    return stored.length !== chosen.value.length || stored.some((key) => !chosen.value.includes(key));
});

function send(nextEnabled: boolean, onRefused: () => void): void {
    if (noneChosen.value) {
        error.value = 'Choose at least one question, or share them all.';
        onRefused();

        return;
    }

    saving.value = true;
    error.value = null;
    router.patch(
        `/forms/${props.formId}/data-sharing`,
        { enabled: nextEnabled, acknowledged: nextEnabled, field_keys: fieldKeys.value },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                error.value = errors.enabled ?? errors.field_keys ?? errors.acknowledged ?? 'This could not be saved. Try again.';
                onRefused();
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}

function onToggle(value: boolean): void {
    error.value = null;
    if (value) {
        // Never on without the confirmation below — the checkbox reads as asked-for, not as done.
        confirming.value = true;

        return;
    }

    confirming.value = false;
    enabled.value = false;
    send(false, () => {
        enabled.value = true;
    });
}

function confirmShare(): void {
    confirming.value = false;
    enabled.value = true;
    send(true, () => {
        enabled.value = false;
    });
}

function cancelShare(): void {
    confirming.value = false;
}

function toggleQuestion(key: string, on: boolean): void {
    chosen.value = on ? [...chosen.value.filter((k) => k !== key), key] : chosen.value.filter((k) => k !== key);
}

function saveQuestions(): void {
    send(enabled.value, () => {});
}
</script>

<template>
    <div class="data-sharing">
        <p class="data-sharing__prose">
            Other forms in this workspace can offer answers from this form as their choices — a list of the facilities
            registered here, for example. Questions marked personal or sensitive are never shared.
        </p>

        <p v-if="!sharing.published" class="data-sharing__reason" data-sharing-reason>
            Publish this form before sharing its answers.
        </p>
        <p v-else-if="sharing.questions.length === 0" class="data-sharing__reason" data-sharing-reason>
            This form has no questions whose answers can be shared. A short text, number, single-choice or dropdown
            question outside a repeating section can be, unless it is marked personal or sensitive.
        </p>

        <template v-if="canShare">
            <MdsCheckbox
                :model-value="enabled || confirming"
                label="Let other forms use answers from this form"
                :disabled="saving"
                @update:model-value="onToggle"
            />

            <div
                v-if="confirming"
                class="data-sharing__confirm"
                role="group"
                :aria-labelledby="confirmId"
                data-sharing-confirm
            >
                <p :id="confirmId" class="data-sharing__confirm-text">
                    Anyone who opens a form that uses these answers will see them as choices — including people who
                    fill it in through its public link.
                </p>
                <div class="data-sharing__actions">
                    <MdsButton variant="tertiary" @click="cancelShare">Cancel</MdsButton>
                    <MdsButton variant="primary" @click="confirmShare">Share answers</MdsButton>
                </div>
            </div>

            <fieldset class="data-sharing__questions" :aria-describedby="scopeHelpId" data-sharing-questions>
                <legend class="data-sharing__legend">Which answers</legend>
                <p :id="scopeHelpId" class="data-sharing__help">
                    A form that uses this one picks one of these questions; its answers become the choices.
                </p>
                <div class="data-sharing__scopes">
                    <MdsRadio v-model="scope" value="all" label="Every question listed here" :name="scopeName" />
                    <MdsRadio v-model="scope" value="chosen" label="Only the questions I choose" :name="scopeName" />
                </div>
                <ul v-if="scope === 'all'" class="data-sharing__list">
                    <li v-for="question in sharing.questions" :key="question.key">{{ question.label }}</li>
                </ul>
                <div v-else class="data-sharing__choices">
                    <MdsCheckbox
                        v-for="question in sharing.questions"
                        :key="question.key"
                        :model-value="chosen.includes(question.key)"
                        :label="question.label"
                        @update:model-value="toggleQuestion(question.key, $event)"
                    />
                </div>
                <div class="data-sharing__actions">
                    <MdsButton
                        variant="secondary"
                        :disabled="!questionsChanged || noneChosen"
                        :loading="saving"
                        @click="saveQuestions"
                    >
                        Save questions
                    </MdsButton>
                </div>
            </fieldset>
        </template>

        <p v-if="error" class="data-sharing__error" role="alert">{{ error }}</p>

        <div class="data-sharing__used" data-sharing-used-by>
            <p class="data-sharing__legend">Forms using these answers</p>
            <ul v-if="sharing.used_by.length > 0" class="data-sharing__list">
                <li v-for="form in sharing.used_by" :key="form.id">{{ form.title }}</li>
            </ul>
            <p v-if="sharing.used_by_others > 0" class="data-sharing__help">
                {{ sharing.used_by.length > 0 ? 'And' : '' }}
                {{ sharing.used_by_others }} {{ sharing.used_by_others === 1 ? 'form' : 'forms' }} you cannot open.
            </p>
            <p v-if="sharing.used_by.length === 0 && sharing.used_by_others === 0" class="data-sharing__help">
                No published form uses them yet.
            </p>
        </div>
    </div>
</template>

<style scoped>
.data-sharing__prose {
    margin: 0 0 var(--mds-space-4);
    color: var(--mds-color-text-body);
}

/* A fact about the form, not an error in a control: the Scanning section's reason edge. */
.data-sharing__reason {
    margin: 0 0 var(--mds-space-4);
    padding-left: var(--mds-space-3);
    border-left: 4px solid var(--mds-color-status-warning-fg);
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-sm-font-size);
}

.data-sharing__confirm {
    margin: var(--mds-space-3) 0 0;
    padding: var(--mds-space-3) var(--mds-space-4);
    border-left: 4px solid var(--mds-color-status-warning-fg);
    background: var(--mds-color-bg-sunken);
    border-radius: var(--mds-radius-sm);
}

.data-sharing__confirm-text {
    margin: 0;
    color: var(--mds-color-text-body);
}

.data-sharing__questions {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    min-width: 0;
    margin: var(--mds-space-6) 0 0;
    padding: 0;
    border: 0;
}

.data-sharing__legend {
    margin: 0;
    padding: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    line-height: var(--mds-type-label-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.data-sharing__help {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.data-sharing__scopes,
.data-sharing__choices {
    display: flex;
    flex-direction: column;
}

.data-sharing__list {
    margin: 0;
    padding-left: var(--mds-space-5);
    color: var(--mds-color-text-body);
    overflow-wrap: anywhere;
}

/* ⚠️ RE-DECLARED, NOT INHERITED — `<style scoped>` reaches a child SFC's root only (see `ConfirmationPanel`). */
.data-sharing__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--mds-space-2);
    margin-top: var(--mds-space-3);
}

.data-sharing__error {
    margin: var(--mds-space-4) 0 0;
    color: var(--mds-color-status-danger-fg);
    font-size: var(--mds-type-body-sm-font-size);
}

.data-sharing__used {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin-top: var(--mds-space-6);
}
</style>
