<script setup lang="ts">
/**
 * Per-form "Save and finish later" opt-in (Increment H10) — PATCH /forms/{form}/save-resume, gated
 * `feature:save_and_resume` at the route on top of `can:update,form`, so a form owner can only enable a
 * feature the tenant plan actually includes.
 *
 * ⚠️ MOVED OUT OF `Pages/forms/Builder.vue` IN M117, AND IT WAS THE ODD ONE OUT. Of the seven per-form
 * settings this was the only one with no dialog at all: a bare `MdsCheckbox` wedged between nine toolbar
 * buttons, with no label beyond its own and nowhere to say what it does. It is now a section of
 * {@link FormSettingsModal} with room for the sentence it always needed. The route and
 * {@see \App\Http\Requests\Forms\UpdateSaveResumeRequest} did not move — that request's docblock is the
 * precedent the other two refusals cite, and it stays true.
 *
 * ⚠️ THE OPTIMISTIC TOGGLE AND ITS REVERT ARE CARRIED OVER VERBATIM, DELIBERATELY. A checkbox that waits
 * for a round trip reads as broken, and the revert matters more here than it looks: the route's feature gate
 * can refuse after the plan changes under a session that still has the control on screen.
 */
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { MdsCheckbox } from '@meridian/design-system';

const props = defineProps<{
    /** Whether the SETTINGS MODAL is open — see `ConfirmationPanel`'s prop note for why, not the section. */
    open: boolean;
    formId: string;
    enabled: boolean;
}>();

const saveResume = ref(props.enabled);

// Re-seed on open like every other section. This one also guards against a stale optimistic value: if a
// write was refused and reverted, reopening the modal shows what the server actually holds.
watch(
    () => props.open,
    (open) => {
        if (!open) return;
        saveResume.value = props.enabled;
    },
    { immediate: true },
);

function onToggle(value: boolean): void {
    saveResume.value = value;
    router.patch(
        `/forms/${props.formId}/save-resume`,
        { save_and_resume: value },
        {
            preserveScroll: true,
            preserveState: true,
            // Revert the optimistic toggle if the write is rejected (e.g. the plan no longer includes it).
            onError: () => {
                saveResume.value = !value;
            },
        },
    );
}
</script>

<template>
    <div class="save-resume">
        <p class="save-resume__prose">
            Let a respondent save an unfinished response and come back to it later from a private link. The
            link expires after the number of days set in workspace settings.
        </p>

        <MdsCheckbox
            :model-value="saveResume"
            label="Allow respondents to save and finish later"
            @update:model-value="onToggle"
        />

        <p class="save-resume__note">
            This saves as soon as you change it — there is nothing to confirm.
        </p>
    </div>
</template>

<style scoped>
.save-resume__prose {
    margin: 0 0 var(--mds-space-4);
    color: var(--mds-color-text-body);
}

.save-resume__note {
    margin: var(--mds-space-4) 0 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}
</style>
