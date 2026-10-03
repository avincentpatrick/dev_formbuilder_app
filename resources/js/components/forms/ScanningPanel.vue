<script setup lang="ts">
/**
 * The Scanning settings section (M129 — single-form OCR groundwork 2): whether this form accepts scans of its
 * printed paper — `PATCH /forms/{form}/ocr-scanning`, the only writer of `forms.allow_ocr_single`.
 *
 * The route gates it `can:update,form`, then the workspace's `ocr_single` module BEFORE the plan; the server
 * sends this section's props only where that route would admit the save, so the section is never a dead end.
 *
 * ⚠️ THE OPTIMISTIC TOGGLE AND ITS REVERT ARE `SaveResumePanel`'s, DELIBERATELY. A checkbox that waits for a
 * round trip reads as broken, and the revert matters for the same reason there: a gate can refuse after the
 * workspace changes under a session that still has the control on screen.
 *
 * ⚠️ SWITCHING ON IS ALLOWED FOR A FORM PAPER CANNOT CARRY YET. The setting is the author's intent; whether the
 * CURRENT version can be read is checked by every upload, and the section says why it would refuse in the
 * upload's own words — so an author fixing the form does not have to come back and switch this on.
 */
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { MdsCheckbox } from '@meridian/design-system';
import type { OcrScanningProps } from '@/components/forms/types';

const props = defineProps<{
    /** Whether the SETTINGS are open — the modal's state, or always true on the hub page. See `ConfirmationPanel`. */
    open: boolean;
    formId: string;
    scanning: OcrScanningProps;
}>();

const enabled = ref(props.scanning.enabled);

// Re-seed on open like every other section; after a refused write this also shows what the server holds.
watch(
    () => props.open,
    (open) => {
        if (!open) return;
        enabled.value = props.scanning.enabled;
    },
    { immediate: true },
);

function onToggle(value: boolean): void {
    enabled.value = value;
    router.patch(
        `/forms/${props.formId}/ocr-scanning`,
        { allow_ocr_single: value },
        {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                enabled.value = !value;
            },
        },
    );
}
</script>

<template>
    <div class="scanning">
        <p class="scanning__prose">
            Staff can photograph or scan filled-in paper copies of this form. Each scan is read automatically, and
            a person checks every answer before it is saved as a response.
        </p>

        <MdsCheckbox :model-value="enabled" label="Accept scans of paper copies" @update:model-value="onToggle" />

        <p v-if="!scanning.eligible && scanning.reason" class="scanning__reason" data-scanning-reason>
            {{ scanning.reason }}
        </p>

        <p class="scanning__note">This saves as soon as you change it — there is nothing to confirm.</p>
    </div>
</template>

<style scoped>
.scanning__prose {
    margin: 0 0 var(--mds-space-4);
    color: var(--mds-color-text-body);
}

/* A fact about the form, not an error in this control: the secondary text colour, with a warning edge so the
   reason is noticed beside a checkbox that may read "on". */
.scanning__reason {
    margin: var(--mds-space-4) 0 0;
    padding-left: var(--mds-space-3);
    border-left: 4px solid var(--mds-color-status-warning-fg);
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-sm-font-size);
}

.scanning__note {
    margin: var(--mds-space-4) 0 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}
</style>
