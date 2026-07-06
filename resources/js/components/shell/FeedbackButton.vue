<script setup lang="ts">
/**
 * "Send Feedback" — the shell's one global non-nav entry point (Feature #11). The trigger opens the
 * shared Modal; submitting posts to the tenant feedback endpoint (route + browser info + remarks). On
 * success the modal closes and the controller's flash surfaces a Toast. Screenshot capture is deferred
 * (the shared attachments table is Phase 1).
 */
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsIcon, MdsModal } from '@meridian/design-system';

const open = ref(false);
const form = useForm({
    route: '',
    remarks: '',
    browser_info: { userAgent: '', viewport: '' },
});

function openPanel(): void {
    form.reset();
    form.clearErrors();
    form.route = window.location.pathname;
    form.browser_info = {
        userAgent: window.navigator.userAgent,
        viewport: `${window.innerWidth}x${window.innerHeight}`,
    };
    open.value = true;
}

function submit(): void {
    form.post('/feedback', {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <div class="fb">
        <button
            type="button"
            class="fb__trigger"
            aria-haspopup="dialog"
            aria-label="Send feedback"
            @click="openPanel"
        >
            <MdsIcon name="feedback" size="md" aria-hidden="true" />
            <span class="fb__label">Feedback</span>
        </button>

        <MdsModal v-model:open="open" title="Send feedback">
            <form class="fb__form" @submit.prevent="submit">
                <MdsFormField
                    label="What's on your mind?"
                    required
                    help="Tell us what's working, what isn't, or what you'd like to see."
                    :error="form.errors.remarks"
                    v-slot="{ id, describedby, invalid }"
                >
                    <textarea
                        :id="id"
                        v-model="form.remarks"
                        class="fb__textarea"
                        :class="{ 'fb__textarea--invalid': invalid }"
                        rows="5"
                        :aria-describedby="describedby"
                        :aria-invalid="invalid || undefined"
                    />
                </MdsFormField>
            </form>
            <template #actions>
                <MdsButton variant="tertiary" @click="open = false">Cancel</MdsButton>
                <MdsButton variant="primary" :loading="form.processing" @click="submit">Send feedback</MdsButton>
            </template>
        </MdsModal>
    </div>
</template>

<style scoped>
.fb {
    display: inline-flex;
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

.fb__form {
    display: flex;
    flex-direction: column;
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

.fb__textarea--invalid {
    border-color: var(--mds-color-action-danger-bg);
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
