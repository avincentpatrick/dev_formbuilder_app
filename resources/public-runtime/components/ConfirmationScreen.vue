<script setup lang="ts">
/**
 * The post-submit confirmation (UX §9): a full-screen thank-you with the submission's handle and an option to
 * submit another response. Focus moves to the heading on arrival so a screen reader is told the flow has
 * concluded (§10.2).
 *
 * ⚠️ TWO DIFFERENT CODES, AND THE DISTINCTION IS THE WHOLE POINT (Increment J2e).
 *
 *   · `reference` — the SERVER-issued handle, present when the response actually reached the server. This is
 *     the string a respondent should write down: the tenant can paste it into their inbox and find this row.
 *   · `queueTag` — a device-local label for a response still sitting in the outbox. There is no server row
 *     yet, so there is no reference yet; calling it one would hand the respondent a code that finds nothing.
 *
 * Exactly one is ever non-null. Before J2e both screens said "Reference:" and the offline one was derived
 * from the client uuid and stored nowhere — which is the defect this split removes.
 */
import { onMounted, ref } from 'vue';
import { MdsButton } from '@meridian/design-system';

defineProps<{ reference: string | null; queueTag: string | null; message: string }>();
defineEmits<{ restart: [] }>();

const heading = ref<HTMLElement | null>(null);
onMounted(() => heading.value?.focus());
</script>

<template>
    <div class="confirmation">
        <div class="confirmation__card">
            <svg class="confirmation__icon" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
                <circle cx="24" cy="24" r="21" fill="none" stroke="currentColor" stroke-width="2" />
                <path
                    d="M15 24l6 6 12-12"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </svg>
            <h1 ref="heading" tabindex="-1" class="confirmation__title">{{ message }}</h1>
            <p v-if="reference !== null" class="confirmation__ref">
                Reference: <strong>{{ reference }}</strong>
            </p>
            <template v-else-if="queueTag !== null">
                <p class="confirmation__ref">Queue tag: <strong>{{ queueTag }}</strong></p>
                <p class="confirmation__ref-note">
                    This is a temporary label for this device. Your reference is issued once this response is
                    sent.
                </p>
            </template>
            <MdsButton variant="secondary" @click="$emit('restart')">Submit another response</MdsButton>
        </div>
    </div>
</template>

<style scoped>
.confirmation__ref-note {
    /* J2e — the sentence that stops a queue tag being mistaken for a reference. Quieter than the code above
       it, but NOT `--mds-color-text-muted`: it is the only thing on screen saying the response has not been
       delivered yet, which is the truth that matters most on this screen. */
    margin: calc(-1 * var(--mds-space-2)) 0 var(--mds-space-4);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.confirmation {
    /* I10d — `flex: 1`, not `min-height: 100vh`. The sync surface now sits ABOVE this in App.vue's
       flex column, so claiming the whole viewport here would make the document taller than the screen and
       put a scrollbar on every page. `assertClean` checks HORIZONTAL overflow only, so this would have
       shipped as a visible regression with a green gate. */
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--mds-space-6);
    background-color: var(--mds-color-bg-canvas);
}

.confirmation__card {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: var(--mds-space-4);
    max-width: 32rem;
    padding: var(--mds-space-8);
    background-color: var(--mds-color-bg-surface);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-lg, var(--mds-radius-md));
    box-shadow: var(--mds-shadow-1);
}

.confirmation__icon {
    width: 56px;
    height: 56px;
    color: var(--mds-color-action-primary-fg);
}

.confirmation__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-2-font-size);
    line-height: var(--mds-type-heading-2-line-height);
    font-weight: var(--mds-type-heading-2-font-weight);
    color: var(--mds-color-text-heading);
}

.confirmation__title:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 4px;
}

.confirmation__ref {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    color: var(--mds-color-text-secondary);
}

.confirmation__ref strong {
    font-family: var(--mds-font-family-mono, monospace);
    color: var(--mds-color-text-body);
}
</style>
