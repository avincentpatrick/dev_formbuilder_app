<script setup lang="ts">
/**
 * One-time signing-secret reveal (Increment H14). Shown after a create or rotate, driven by the one-shot
 * `flash.newSecret` prop — the plaintext secret is returned by the server exactly once and never again
 * (thereafter only its masked suffix is available). The receiver uses it to verify the HMAC-SHA256 signature
 * on every delivery. Copy-once affordance mirrors the guest runtime's SaveForLater resume-link pattern.
 */
import { ref, watch } from 'vue';
import { MdsButton, MdsFormField, MdsModal, MdsTextInput } from '@meridian/design-system';

const props = defineProps<{ open: boolean; secret: string; name: string }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const copied = ref(false);

// Reset the copied flag each time the modal opens for a fresh secret.
watch(
    () => props.open,
    (open) => {
        if (open) copied.value = false;
    },
);

async function onCopy(): Promise<void> {
    try {
        await navigator.clipboard?.writeText(props.secret);
        copied.value = true;
    } catch {
        copied.value = false;
    }
}

function close(): void {
    emit('update:open', false);
}
</script>

<template>
    <MdsModal :open="open" title="Signing secret" @close="close">
        <div class="secret">
            <p class="secret__lead">
                This is the signing secret for <strong>{{ name }}</strong>. Store it now — for your security it
                won’t be shown again. Use it to verify the <code>X-Webhook-Signature</code> header on each delivery.
            </p>
            <MdsFormField label="Signing secret">
                <template #default="{ id, describedby }">
                    <MdsTextInput
                        :id="id"
                        :model-value="secret"
                        :describedby="describedby"
                        readonly
                        @focus="($event.target as HTMLInputElement).select()"
                    />
                </template>
            </MdsFormField>
            <div class="secret__row">
                <MdsButton type="button" variant="secondary" icon-left="copy" @click="onCopy">
                    {{ copied ? 'Copied' : 'Copy secret' }}
                </MdsButton>
            </div>
        </div>
        <template #actions>
            <MdsButton type="button" variant="primary" @click="close">Done</MdsButton>
        </template>
    </MdsModal>
</template>

<style scoped>
.secret {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.secret__lead {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

.secret__lead code {
    font-family: var(--mds-font-family-mono);
    font-size: 0.9em;
}

.secret__row {
    display: flex;
    justify-content: flex-start;
}
</style>
