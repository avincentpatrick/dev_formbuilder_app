<script setup lang="ts">
/**
 * The body of "Form scope" (Increment G10b2), split out in M129 so the form hub's Settings tab mounts the same
 * component the forms list's dialog does — `D63`'s "no second implementation to drift", the way `SharePanel`
 * was split from `ShareModal` in M117.
 *
 * Assigning a form to a node is a grant-equivalent act rather than a metadata edit: it hands every holder of a
 * grant on that node — and on any ancestor whose grant includes descendants — access to the form AND its whole
 * submission history. So both mounts are shown only to holders of `scopes.manage`, and the server enforces it
 * independently by stacking `can:viewAny,ScopeNode` on top of `can:update,form`.
 *
 * The picker renders BREADCRUMB paths ("Luzon / NCR / Manila") rather than indenting labels with &nbsp; —
 * a screen reader reads padding characters aloud, and a native <select> cannot express a tree anyway.
 *
 * ⚠️ `preserveState: true`, EXPLICITLY. On the Settings tab a save returns to the same page; re-keying it
 * would land the reader back on the first settings section, away from the one they just saved.
 */
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsSelect } from '@meridian/design-system';
import type { ScopeOption } from '@/components/forms/types';

const props = defineProps<{
    open: boolean;
    formId: string;
    currentNodeId: string | null;
    scopes: ScopeOption[];
}>();

const emit = defineEmits<{ saved: [] }>();

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
            preserveState: true,
            onSuccess: () => emit('saved'),
        });
}
</script>

<template>
    <div class="scope-panel">
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

        <div class="scope-panel__actions">
            <MdsButton variant="primary" :loading="form.processing" @click="submit">Save scope</MdsButton>
        </div>
    </div>
</template>

<style scoped>
.scope-panel__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--mds-space-2);
    margin-top: var(--mds-space-4);
}
</style>
