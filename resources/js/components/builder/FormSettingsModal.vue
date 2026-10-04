<script setup lang="ts">
/**
 * The builder's one "Form settings" surface (Increment M117, decision `D63`).
 *
 * Since M129 this is a thin dialog around {@link FormSettingsSections}, the rail and the sections themselves,
 * which the form hub's Settings tab mounts too — `D63` answered "both entry points", and one component is what
 * keeps them from showing a section two ways. The rail's rationale — why it is a local `role="group"` of
 * `aria-pressed` buttons and never a tablist, and why sections mount on first visit and then stay — moved
 * with it, and is in that file's header.
 *
 * ⚠️ `MdsModal` STAYS THIS COMPONENT'S SINGLE ROOT. `FormSettingsModal.test.ts` passes `teleport: false` as a
 * fall-through attribute, which only reaches the dialog while the dialog is the root.
 */
import { MdsButton, MdsModal } from '@meridian/design-system';
import FormSettingsSections from '@/components/forms/FormSettingsSections.vue';
import type { AutomationsProps, DataSharingProps, FormSettingsForm, OcrScanningProps, ReferenceFileRow, ShareProps } from '@/components/forms/types';

const props = defineProps<{
    open: boolean;
    formId: string;
    form: FormSettingsForm;
    timezones: string[];
    share: ShareProps | null;
    /** Plan gate for the save-and-resume section — the same `feature()` the route enforces server-side. */
    saveResumeAvailable: boolean;
    /** The Scanning section (M129), or null/absent where the workspace cannot scan — decided by the server. */
    ocrScanning?: OcrScanningProps | null;
    /** The Reference files section (M132): the draft's files. */
    referenceFiles?: ReferenceFileRow[] | null;
    /** The Automations section (M132). */
    automations?: AutomationsProps | null;
    /** The Data sharing section (M133), or null/absent for a reader who cannot read the responses. */
    dataSharing?: DataSharingProps | null;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
</script>

<template>
    <MdsModal
        :open="props.open"
        title="Form settings"
        initial-focus=".form-settings__rail-button"
        @close="emit('update:open', false)"
    >
        <FormSettingsSections
            :open="props.open"
            :form-id="props.formId"
            :form="props.form"
            :timezones="props.timezones"
            :share="props.share"
            :save-resume-available="props.saveResumeAvailable"
            :ocr-scanning="props.ocrScanning"
            :reference-files="props.referenceFiles"
            :automations="props.automations"
            :data-sharing="props.dataSharing"
        />

        <template #actions>
            <MdsButton variant="tertiary" @click="emit('update:open', false)">Close</MdsButton>
        </template>
    </MdsModal>
</template>
