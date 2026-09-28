<script setup lang="ts">
/**
 * The Share dialog — a thin `MdsModal` around {@link SharePanel}, which holds every line of the surface.
 *
 * ⛔ WHY A WRAPPER RATHER THAN A CONVERSION (M117). `D63` folds the builder's nine ungrouped toolbar buttons
 * into one "Form settings" modal, and Schedule and Confirmation could simply BECOME sections because each
 * had a single call site. This one has two: the builder and the form hub (`Pages/forms/Show.vue:482`). The
 * backlog row that prescribed the fold counted it among five builder dialogs and did not mention the hub
 * mount, so converting it in place would have silently removed the hub's Share button — the kind of miss
 * that reads as "the fold is done" and is not.
 *
 * So the split is by RESPONSIBILITY, not by expedience: `SharePanel` is the surface, this file is one way in
 * to it, and the settings modal is the other. There is no second implementation, which is the property `D63`
 * turns on — two entry points cannot drift when both mount the same component against the same route.
 *
 * ⚠️ EVERY PROP IS PASSED STRAIGHT THROUGH, INCLUDING `open`. The panel re-seeds its Inertia form whenever
 * `open` goes true, which is how it reflects what the controller's `back()` redirect just refreshed. A
 * wrapper that swallowed that prop would leave the panel showing the values from the first time it mounted.
 */
import { MdsButton, MdsModal } from '@meridian/design-system';
import SharePanel from '@/components/forms/SharePanel.vue';
import type { ShareProps } from '@/components/forms/types';

const props = defineProps<{
    open: boolean;
    formId: string;
    formTitle: string;
    share: ShareProps;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
</script>

<template>
    <MdsModal :open="props.open" title="Share form" @close="emit('update:open', false)">
        <SharePanel
            :open="props.open"
            :form-id="props.formId"
            :form-title="props.formTitle"
            :share="props.share"
        />
        <template #actions>
            <MdsButton variant="tertiary" @click="emit('update:open', false)">Close</MdsButton>
        </template>
    </MdsModal>
</template>
