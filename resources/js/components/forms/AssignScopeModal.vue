<script setup lang="ts">
/**
 * Assign a form to a scope node, from the forms list (Increment G10b2).
 *
 * Shown only to holders of `scopes.manage` (the page gates it on auth.can.manageScopes), because assigning
 * a form to a node is a grant-equivalent act rather than a metadata edit — the full argument is at
 * {@link ScopePanel}, the body this dialog wraps. Since M129 the form hub's Settings tab mounts that same body,
 * so there is one picker and one save, in two places.
 */
import { MdsButton, MdsModal } from '@meridian/design-system';
import ScopePanel from '@/components/forms/ScopePanel.vue';
import type { ScopeOption } from '@/components/forms/types';

const props = defineProps<{
    open: boolean;
    formId: string;
    currentNodeId: string | null;
    scopes: ScopeOption[];
}>();

const emit = defineEmits<{ 'update:open': [boolean] }>();
</script>

<template>
    <MdsModal :open="open" title="Form scope" @close="emit('update:open', false)">
        <ScopePanel
            :open="props.open"
            :form-id="props.formId"
            :current-node-id="props.currentNodeId"
            :scopes="props.scopes"
            @saved="emit('update:open', false)"
        />

        <template #actions>
            <MdsButton variant="tertiary" @click="emit('update:open', false)">Cancel</MdsButton>
        </template>
    </MdsModal>
</template>
