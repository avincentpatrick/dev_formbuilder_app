<script setup lang="ts">
/**
 * **It works** and **Still a problem** — a tester's verdict on a fix (M179, `R-129c44c3`, `D151`).
 *
 * The buttons come from the row's `actions`, which the SERVER computes from the member graph and the policy, so
 * this component never decides who may do what: no action, no button. **It works** posts at once. **Still a
 * problem** opens a short form first, because the comment is the point — what is still wrong is what the team
 * reads next — so an empty one is refused here, before anything is sent (the server refuses it too). A picture
 * is optional, and like every feedback screenshot it reaches the support team only.
 *
 * A move the server refuses — the report changed meanwhile, someone already confirmed it — comes back as the
 * `feedback` error and is shown here, where the tester pressed.
 */
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { MdsButton, MdsFormField, MdsTextarea } from '@meridian/design-system';
import type { FeedbackAction } from '@/components/feedback/types';

const props = defineProps<{
    reportId: string;
    actions: FeedbackAction[];
}>();

const confirmForm = useForm<Record<string, never>>({});
const reopenForm = useForm<{ body: string; screenshot: File | null }>({ body: '', screenshot: null });
const reopening = ref(false);
const localError = ref<string | null>(null);

/** A move the server refused (the report changed meanwhile), sent back under `feedback` — not a field of either form. */
const refusal = computed(
    () =>
        (confirmForm.errors as Record<string, string | undefined>).feedback ??
        (reopenForm.errors as Record<string, string | undefined>).feedback ??
        null,
);

function has(verb: FeedbackAction['verb']): FeedbackAction | undefined {
    return props.actions.find((action) => action.verb === verb);
}

function confirm(): void {
    confirmForm.post(`/feedback/${props.reportId}/confirm`, { preserveScroll: true });
}

function onPicked(event: Event): void {
    const input = event.target as HTMLInputElement;
    reopenForm.screenshot = input.files?.[0] ?? null;
}

function sendReopen(): void {
    if (reopenForm.body.trim() === '') {
        localError.value = 'Say what is still a problem, so the team knows what to look at.';

        return;
    }

    localError.value = null;
    reopenForm.post(`/feedback/${props.reportId}/reopen`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            reopenForm.reset();
            reopening.value = false;
        },
    });
}
</script>

<template>
    <div v-if="actions.length" class="fbv" data-feedback-verdict>
        <p class="fbv__ask">Did the fix work for you?</p>

        <div v-if="!reopening" class="fbv__buttons">
            <MdsButton
                v-if="true"
                variant="primary"
                size="sm"
                :loading="confirmForm.processing"
                data-verdict="confirm"
                @click="confirm"
            >
                {{ has('confirm')?.label }}
            </MdsButton>
            <MdsButton v-if="has('reopen')" variant="secondary" size="sm" data-verdict="reopen" @click="reopening = true">
                {{ has('reopen')?.label }}
            </MdsButton>
        </div>

        <form v-else class="fbv__form" novalidate @submit.prevent="sendReopen">
            <MdsFormField
                label="What is still a problem?"
                :input-id="`fbv-body-${reportId}`"
                required
                :error="localError ?? reopenForm.errors.body ?? undefined"
            >
                <MdsTextarea :id="`fbv-body-${reportId}`" v-model="reopenForm.body" :rows="3" maxlength="2000" autosize />
            </MdsFormField>

            <label class="fbv__file">
                <span class="fbv__file-label">Attach an image (optional)</span>
                <input type="file" accept="image/png,image/jpeg,image/webp" @change="onPicked" />
            </label>

            <div class="fbv__buttons">
                <MdsButton type="submit" variant="primary" size="sm" :loading="reopenForm.processing">Send</MdsButton>
                <MdsButton variant="tertiary" size="sm" @click="reopening = false">Cancel</MdsButton>
            </div>
        </form>

        <p v-if="refusal" class="fbv__error" role="alert">{{ refusal }}</p>
    </div>
</template>

<style scoped>
.fbv {
    margin-top: var(--mds-space-3);
    padding-top: var(--mds-space-3);
    border-top: 1px solid var(--mds-color-border-default);
}

.fbv__ask {
    margin: 0 0 var(--mds-space-2);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.fbv__buttons {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
}

.fbv__form {
    display: grid;
    gap: var(--mds-space-3);
}

.fbv__file {
    display: grid;
    gap: var(--mds-space-1);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.fbv__file-label {
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.fbv__error {
    margin: var(--mds-space-2) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-danger-text);
}
</style>
