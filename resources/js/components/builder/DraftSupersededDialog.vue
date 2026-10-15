<script setup lang="ts">
/**
 * M172 (`R-81756e95`) — the form was published since this builder page loaded: from the hub, the forms list, another tab,
 * the API, or before the browser's Back button brought this page back. Every id the page holds belongs to the version
 * that publish froze, so nothing more can be saved from it — the testers met that as "The key has already been taken".
 *
 * There is one way on, so there is one button: reload into the new draft. The edits this page kept but the server never
 * received are put back there by key (`edit-journal.ts`, the store's `restoreJournal()`). Closing the dialog reloads too —
 * staying on a page that can save nothing is not a choice worth offering.
 */
import { MdsButton, MdsModal } from '@meridian/design-system';

defineProps<{ open: boolean; versionNumber: number | null }>();
const emit = defineEmits<{ reload: [] }>();
</script>

<template>
    <MdsModal :open="open" title="This form was published" close-label="Reload" @close="emit('reload')">
        <div class="superseded">
            <p class="superseded__lead">This form was published since this page loaded. Reload to keep editing.</p>
            <p class="superseded__note">
                Changes this page had not saved yet are kept, and put back after the reload<template v-if="versionNumber !== null">
                    into draft version {{ versionNumber }}</template>.
            </p>
        </div>
        <template #actions>
            <MdsButton variant="primary" @click="emit('reload')">Reload</MdsButton>
        </template>
    </MdsModal>
</template>

<style scoped>
.superseded {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.superseded__lead {
    margin: 0;
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
}

.superseded__note {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-md-line-height);
}
</style>
