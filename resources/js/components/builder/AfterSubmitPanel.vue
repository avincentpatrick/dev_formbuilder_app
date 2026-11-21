<script setup lang="ts">
/**
 * What a form offers a respondent after Submit — M248 (`D232`, NHETE recommendation 6a) — PATCH
 * /forms/{form}/after-submit, gated `can:update,form` alone (no plan feature covers it).
 *
 * Three choices, in the user's words: another response (the default, what every form did before), "Change my answers"
 * until the form closes, or one response. Respondents do not sign in, so the last two hold for one device — the panel
 * says so, because an author choosing "one response" for a survey of named people should know it is not a guarantee.
 *
 * Radios in a fieldset, as `ConfirmationPanel`'s "After the thank-you screen" choice is, rather than
 * `MdsSegmentedControl`: three long labels do not fit its single unwrapping line. Saves on change, with the optimistic
 * write and revert {@link PageModePanel} and `SaveResumePanel` use; reopening the modal re-seeds from the server.
 */
import { ref, useId, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { MdsRadio } from '@meridian/design-system';
import type { AfterSubmit } from '@/components/forms/types';

const CHOICES: { value: AfterSubmit; label: string; detail: string }[] = [
    {
        value: 'another',
        label: 'Let them submit another response',
        detail: 'The thank-you page offers "Submit another response".',
    },
    {
        value: 'amend',
        label: 'Let them change their answers until the form closes',
        detail: 'Reopening the form on the same device offers "Change my answers", and the thank-you page gives a link that works on any device. Anyone with that link can see and change the answers. Changes stop once the form closes, or once you start reviewing the response.',
    },
    {
        value: 'once',
        label: 'One response only',
        detail: 'Reopening the form on the same device says it was already answered.',
    },
];

const props = defineProps<{
    /** Whether the SETTINGS MODAL is open — see `ConfirmationPanel`'s prop note for why, not the section. */
    open: boolean;
    formId: string;
    afterSubmit: AfterSubmit;
}>();

const choice = ref<AfterSubmit>(props.afterSubmit);
const groupName = `after-submit-${useId()}`;
const helpId = `after-submit-help-${useId()}`;

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        choice.value = props.afterSubmit;
    },
    { immediate: true },
);

function onChange(value: string): void {
    const previous = choice.value;
    choice.value = value as AfterSubmit;

    router.patch(
        `/forms/${props.formId}/after-submit`,
        { after_submit: value },
        {
            preserveScroll: true,
            preserveState: true,
            // Revert the optimistic choice if the write is rejected — e.g. editing rights lost mid-session.
            onError: () => {
                choice.value = previous;
            },
        },
    );
}
</script>

<template>
    <fieldset class="after-submit" :aria-describedby="helpId" data-after-submit-settings>
        <legend class="after-submit__legend">After a respondent submits</legend>
        <p :id="helpId" class="after-submit__help">
            Respondents do not sign in, so "change their answers" and "one response" hold for one device: someone on
            another phone or browser can still send a new response. This saves as soon as you change it.
        </p>
        <div class="after-submit__choices">
            <div v-for="item in CHOICES" :key="item.value" class="after-submit__choice">
                <MdsRadio
                    :model-value="choice"
                    :value="item.value"
                    :label="item.label"
                    :name="groupName"
                    @update:model-value="onChange"
                />
                <p class="after-submit__detail">{{ item.detail }}</p>
            </div>
        </div>
    </fieldset>
</template>

<style scoped>
.after-submit {
    margin: 0;
    padding: 0;
    border: 0;
    min-width: 0;
}

.after-submit__legend {
    padding: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    line-height: var(--mds-type-label-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.after-submit__help {
    margin: var(--mds-space-2) 0 var(--mds-space-4);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.after-submit__choices {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.after-submit__detail {
    margin: var(--mds-space-1) 0 0;
    padding-left: var(--mds-space-6);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}
</style>
