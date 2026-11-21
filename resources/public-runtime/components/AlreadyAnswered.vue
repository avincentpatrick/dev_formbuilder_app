<script setup lang="ts">
/**
 * "You've already answered this form" — M248 (`D232`, `R-e07273d1`, `R-aec84b42`).
 *
 * Shown by `App.vue` INSTEAD of the form when this device remembers sending a response (`lib/answered.ts`) and the form
 * allows one response: `once`, or `amend` with "Change my answers" while the form still takes changes. Guests do not
 * sign in, so this is the device's memory, not the person's — another phone starts afresh (`D232` answer 2).
 *
 * The answers stay off the screen until the respondent asks for them: "Change my answers" emits, and `App.vue` reads the
 * response through its amend token. Focus moves to the heading on arrival, as on the thank-you screen.
 */
import { computed, onMounted, ref } from 'vue';
import { MdsButton } from '@meridian/design-system';
import { formatInstantInZone } from '../lib/schedule';

const props = defineProps<{
    title: string;
    reference: string | null;
    /** When this device sent it (ISO). */
    sentAt: string;
    /** Whether "Change my answers" is offered: `amend` mode, a token in hand, and the form still taking changes. */
    canChange: boolean;
    /** Why changes are not offered in `amend` mode, when they are not — or null. */
    closedNote: string | null;
    /** When the form stops taking changes (ISO), or null. */
    until: string | null;
    timeZone: string;
    locale: string;
}>();
const emit = defineEmits<{ change: [] }>();

const heading = ref<HTMLElement | null>(null);
const busy = ref(false);
const sentWhen = computed(() => formatInstantInZone(props.sentAt, props.timeZone, props.locale));
const untilWhen = computed(() => (props.until === null ? null : formatInstantInZone(props.until, props.timeZone, props.locale)));

function onChange(): void {
    busy.value = true;
    emit('change');
}

onMounted(() => heading.value?.focus());
</script>

<template>
    <div class="answered" data-already-answered>
        <div class="answered__card">
            <p class="answered__form">{{ title }}</p>
            <h1 ref="heading" tabindex="-1" class="answered__title">You've already answered this form</h1>
            <p class="answered__text">You sent your response from this device on {{ sentWhen }}.</p>
            <p v-if="reference !== null" class="answered__ref">
                Reference: <strong>{{ reference }}</strong>
            </p>
            <template v-if="canChange">
                <p class="answered__text">
                    You can change your answers<template v-if="untilWhen !== null"> until {{ untilWhen }}</template><template v-else> while the form is open</template>.
                </p>
                <MdsButton variant="primary" :disabled="busy" data-change-answers @click="onChange">Change my answers</MdsButton>
            </template>
            <p v-else-if="closedNote !== null" class="answered__text">{{ closedNote }}</p>
        </div>
    </div>
</template>

<style scoped>
/* The thank-you screen's frame (`ConfirmationScreen.vue`): a centred card on the canvas, `flex: 1` inside App's column. */
.answered {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--mds-space-6);
    background-color: var(--mds-color-bg-canvas);
}

.answered__card {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: var(--mds-space-4);
    max-width: 32rem;
    padding: var(--mds-space-8);
    background-color: var(--mds-color-bg-surface);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-xl, var(--mds-radius-lg));
    box-shadow: var(--mds-shadow-1);
}

.answered__form {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
    overflow-wrap: anywhere;
}

.answered__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-2-font-size);
    line-height: var(--mds-type-heading-2-line-height);
    font-weight: var(--mds-type-heading-2-font-weight);
    color: var(--mds-color-text-heading);
}

.answered__title:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 4px;
}

.answered__text {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

.answered__ref {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    color: var(--mds-color-text-secondary);
}

.answered__ref strong {
    font-family: var(--mds-font-family-mono, monospace);
    color: var(--mds-color-text-body);
}
</style>
