<script setup lang="ts">
/**
 * The form's name and description — the first section of {@link FormSettingsModal} (Increment M117, `D63`).
 *
 * ⛔ WHY THIS SECTION EXISTS WHEN THE OTHER FOUR WERE ONLY MOVED. The row behind `D63` counts the settings
 * an author cannot find: five modals, a toolbar checkbox, and TWO FORM-LIST DIALOGS. Title and description
 * were the first of those two — reachable only by leaving the builder, going back to the forms list, and
 * opening a row action on the form you were just editing. A "Form settings" surface that cannot rename the
 * form is not the thing the report asked for.
 *
 * ⚠️ IT IS THE SAME ROUTE AND THE SAME FormRequest, NOT A SECOND WRITER. PATCH /forms/{form} with
 * {@see \App\Http\Requests\Forms\FormMetadataRequest}, which is exactly what the forms-list Rename dialog
 * posts. Nothing here folds two settings into one endpoint — the three docblocks that refuse that are about
 * the OTHER sections' routes and are untouched.
 *
 * ⛔ AND IT MAKES A RECORDED FACT FALSE, WHICH IS CORRECTED IN THE SAME CHANGE. `FormRowActions.vue` stated
 * that the Rename dialog is "the ONLY call site of `PATCH /forms/{form}` in the entire client". This is the
 * second. The note there now names both, because a comment that is quietly wrong is worse than no comment.
 *
 * The form's SCOPE — the other form-list dialog — is deliberately NOT here: it confers capacity rather than
 * describing the form, `routes/tenant.php` records why it is its own route, and it belongs with the
 * administering surface. It lands with the hub Settings tab, which is the deferred half of this row.
 */
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsTextInput, MdsTextarea } from '@meridian/design-system';

const props = defineProps<{
    /** Whether the SETTINGS MODAL is open — see `ConfirmationPanel`'s prop note for why, not the section. */
    open: boolean;
    formId: string;
    form: {
        title: string;
        description: string | null;
    };
}>();

const form = useForm<{ title: string; description: string }>({ title: '', description: '' });

// (Re)seed whenever the modal opens, for the same reason every other section does: the controller's back()
// redirect refreshes the builder props, so this always shows the latest saved values.
watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.title = props.form.title;
        form.description = props.form.description ?? '';
        form.clearErrors();
    },
    { immediate: true },
);

function submit(): void {
    form.patch(`/forms/${props.formId}`, {
        preserveScroll: true,
        // ⚠️ preserveState, which the forms-list Rename dialog does NOT need and this does. That dialog can
        // afford a fresh page; here a state reset would discard the builder's canvas store — the undo stack
        // and any in-progress keyboard reorder — because this modal is mounted inside the builder page.
        preserveState: true,
    });
}
</script>

<template>
    <div class="general">
        <p class="general__prose">
            The form's name and an optional description. The name is what respondents see at the top of the
            form and what this workspace lists it under.
        </p>

        <MdsFormField v-slot="{ id, describedby, invalid }" label="Title" required :error="form.errors.title">
            <MdsTextInput :id="id" v-model="form.title" :describedby="describedby" :invalid="invalid" />
        </MdsFormField>

        <MdsFormField v-slot="{ id, describedby, invalid }" label="Description" :error="form.errors.description">
            <MdsTextarea :id="id" v-model="form.description" :rows="3" :describedby="describedby" :invalid="invalid" />
        </MdsFormField>

        <div class="settings-panel__actions">
            <MdsButton variant="primary" icon-left="check" :loading="form.processing" @click="submit">
                Save details
            </MdsButton>
        </div>
    </div>
</template>

<style scoped>
.general__prose {
    margin: 0 0 var(--mds-space-4);
    color: var(--mds-color-text-body);
}

/* Re-declared, not inherited — see ConfirmationPanel's note. */
.settings-panel__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--mds-space-2);
    margin-top: var(--mds-space-6);
}
</style>
