<script setup lang="ts">
// Create / rename a saved report (Increment H24b2).
//
// The client NEVER builds a `definition`. It posts a name plus the same flat filter params the page
// round-trips through the query string, and SaveReportViewRequest turns them into
// `AnalyticsQuery::toArray()` server-side — so the VO stays the single author of the persisted shape and
// the browser cannot write a schema version nothing will read back.
//
// On a RENAME the filter params are deliberately omitted. SaveReportViewRequest::definitionOrNull()
// returns null when no declaration key was submitted, which is what stops a rename from quietly moving a
// saved report's date range to the last thirty days.
//
// The unique-name collision (saved_report_views_tenant_user_name_unique → SQLSTATE 23505) arrives as a
// ValidationException, which on this web surface is a redirect with a field error — caught by the
// MdsFormField below rather than surfacing as a 500.
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsModal, MdsTextInput } from '@meridian/design-system';
import { toQueryParams } from './query-params';
import type { AppliedQuery, SavedView } from './types';

const props = defineProps<{
    open: boolean;
    /** null = create from the applied declaration; a view = rename it. */
    view: SavedView | null;
    applied: AppliedQuery;
}>();

const emit = defineEmits<{ 'update:open': [boolean] }>();

const form = useForm({ name: '' });

watch(
    () => [props.open, props.view] as const,
    ([open, view]) => {
        if (!open) return;

        form.name = view?.name ?? '';
        form.clearErrors();
    },
    { immediate: true },
);

function submit(): void {
    if (props.view !== null) {
        // Name only. `transform` is reset first because it persists on the form object between submits,
        // and a rename that inherited the create payload would carry the filters it must not send.
        form.transform((data) => ({ name: data.name })).patch(`/analytics/views/${props.view.id}`, {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
        });

        return;
    }

    form.transform((data) => ({ name: data.name, ...toQueryParams(props.applied) })).post('/analytics/views', {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <MdsModal
        :open="open"
        :title="view === null ? 'Save this report' : 'Rename saved report'"
        @close="emit('update:open', false)"
        @update:open="(v: boolean) => emit('update:open', v)"
    >
        <form @submit.prevent="submit">
            <MdsFormField
                label="Name"
                input-id="save-view-name"
                :error="form.errors.name"
                :help="
                    view === null
                        ? 'The current filters are saved with it.'
                        : 'Only the name changes — the saved filters stay as they were.'
                "
                required
            >
                <MdsTextInput
                    id="save-view-name"
                    v-model="form.name"
                    :invalid="Boolean(form.errors.name)"
                    placeholder="Field team — last 30 days"
                />
            </MdsFormField>

            <div class="analytics__modal-actions">
                <MdsButton variant="tertiary" type="button" @click="emit('update:open', false)">
                    Cancel
                </MdsButton>
                <MdsButton
                    variant="primary"
                    type="submit"
                    :loading="form.processing"
                    :disabled="form.name.trim() === ''"
                >
                    {{ view === null ? 'Save report' : 'Rename' }}
                </MdsButton>
            </div>
        </form>
    </MdsModal>
</template>

<style scoped>
.analytics__modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--mds-space-2);
    margin-top: var(--mds-space-4);
}
</style>
