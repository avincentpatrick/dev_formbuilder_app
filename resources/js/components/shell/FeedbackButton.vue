<script setup lang="ts">
/**
 * "Send Feedback" — the shell's one global non-nav entry point (Feature #11). C2 ships the trigger
 * + a styled placeholder popover; the full submit flow + the reusable Modal component land in C3
 * (there's no feedback backend yet). Popover a11y via useDismissable.
 */
import { ref } from 'vue';
import { MdsIcon, MdsFormField, MdsButton } from '@meridian/design-system';
import { useDismissable } from '@/composables/useDismissable';

const open = ref(false);
const text = ref('');
const rootEl = ref<HTMLElement | null>(null);
const triggerEl = ref<HTMLButtonElement | null>(null);
useDismissable({ isOpen: open, rootEl, triggerEl });
</script>

<template>
    <div ref="rootEl" class="fb">
        <button
            ref="triggerEl"
            type="button"
            class="fb__trigger"
            :aria-expanded="open"
            aria-haspopup="dialog"
            aria-label="Send feedback"
            @click="open = !open"
        >
            <MdsIcon name="feedback" size="md" aria-hidden="true" />
            <span class="fb__label">Feedback</span>
        </button>

        <div v-if="open" class="fb__popover" role="dialog" aria-label="Send feedback">
            <p class="fb__title">Send feedback</p>
            <MdsFormField
                label="What’s on your mind?"
                help="Screenshots and delivery arrive in a later update."
                v-slot="{ id, describedby }"
            >
                <textarea
                    :id="id"
                    v-model="text"
                    class="fb__textarea"
                    rows="4"
                    :aria-describedby="describedby"
                />
            </MdsFormField>
            <div class="fb__actions">
                <MdsButton variant="primary" size="sm" disabled>Send</MdsButton>
                <span class="fb__soon">Coming soon</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.fb {
    position: relative;
}

.fb__trigger {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-2);
    min-height: 40px;
    padding: 0 var(--mds-space-3);
    border: none;
    border-radius: var(--mds-radius-md);
    background-color: transparent;
    color: var(--mds-color-text-secondary);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    font-weight: var(--mds-font-weight-medium);
    cursor: pointer;
}

.fb__trigger:hover {
    background-color: var(--mds-color-bg-sunken);
}

.fb__trigger:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.fb__popover {
    position: absolute;
    top: calc(100% + var(--mds-space-1));
    right: 0;
    z-index: 30;
    width: 320px;
    max-width: calc(100vw - var(--mds-space-6));
    padding: var(--mds-space-4);
    background-color: var(--mds-color-bg-surface-raised);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    box-shadow: var(--mds-shadow-3);
}

.fb__title {
    margin: 0 0 var(--mds-space-3);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.fb__textarea {
    width: 100%;
    padding: var(--mds-space-2) var(--mds-space-3);
    border: 1px solid var(--mds-color-input-border);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-input-bg);
    color: var(--mds-color-text-body);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    resize: vertical;
}

.fb__textarea:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
    border-color: var(--mds-color-action-primary-bg);
}

.fb__actions {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
    margin-top: var(--mds-space-3);
}

.fb__soon {
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

@media (max-width: 480px) {
    .fb__label {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
        white-space: nowrap;
    }
}
</style>
