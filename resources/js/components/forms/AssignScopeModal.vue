<script setup lang="ts">
/**
 * Assign a form to a scope node (Increment G10b2).
 *
 * Shown only to holders of `scopes.manage` (the page gates it on auth.can.manageScopes), because assigning
 * a form to a node is a grant-equivalent act rather than a metadata edit: it hands every holder of a grant
 * on that node — and on any ancestor whose grant includes descendants — access to the form AND its whole
 * submission history. The server enforces this independently by stacking `can:viewAny,ScopeNode` on top of
 * `can:update,form`; this gate only keeps the control from appearing where it would 403.
 *
 * The picker renders BREADCRUMB paths ("Luzon / NCR / Manila") rather than indenting labels with &nbsp; —
 * a screen reader reads padding characters aloud, and a native <select> cannot express a tree anyway.
 */
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsModal, MdsSelect } from '@meridian/design-system';

type ScopeOption = { id: string; name: string; parent_id: string | null; is_active: boolean };

const props = defineProps<{
    open: boolean;
    formId: string;
    currentNodeId: string | null;
    scopes: ScopeOption[];
}>();

const emit = defineEmits<{ 'update:open': [boolean] }>();

// '' is the "no scope" sentinel — MdsSelect models a plain string, and submit() transforms it back to the
// null the API expects (un-assigning is a meaningful value, which is why the request rule is `present`).
const form = useForm({ scope_node_id: '' });

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.reset();
        form.clearErrors();
        form.scope_node_id = props.currentNodeId ?? '';
    },
    { immediate: true },
);

const options = computed(() => {
    const byId = new Map(props.scopes.map((s) => [s.id, s]));

    const trail = (node: ScopeOption): string => {
        const parts: string[] = [];
        let cursor: ScopeOption | undefined = node;
        while (cursor) {
            parts.unshift(cursor.name);
            cursor = cursor.parent_id ? byId.get(cursor.parent_id) : undefined;
        }
        return parts.join(' / ');
    };

    return [
        { value: '', label: 'No scope' },
        // Only ACTIVE nodes: FormService::assignScope refuses a deactivated one, since the resolver discards
        // inactive paths and parking a form there would be a disguised un-assign.
        ...props.scopes.filter((s) => s.is_active).map((s) => ({ value: s.id, label: trail(s) })),
    ];
});

function submit(): void {
    form
        .transform((data) => ({ scope_node_id: data.scope_node_id === '' ? null : data.scope_node_id }))
        .patch(`/forms/${props.formId}/scope`, {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
        });
}
</script>

<template>
    <MdsModal :open="open" title="Form scope" @close="emit('update:open', false)">
        <MdsFormField
            v-slot="{ id, describedby, invalid }"
            label="Scope"
            help="Anyone granted access on this scope — or on a branch above it — can reach this form and its submissions."
            :error="form.errors.scope_node_id"
        >
            <MdsSelect
                :id="id"
                v-model="form.scope_node_id"
                :options="options"
                :describedby="describedby"
                :invalid="invalid"
            />
        </MdsFormField>

        <template #actions>
            <MdsButton variant="tertiary" @click="emit('update:open', false)">Cancel</MdsButton>
            <MdsButton variant="primary" :loading="form.processing" @click="submit">Save scope</MdsButton>
        </template>
    </MdsModal>
</template>
