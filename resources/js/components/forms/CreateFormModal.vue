<script setup lang="ts">
/**
 * "New form" — the blank-canvas half of onboarding §2's two choices (extracted in Increment J5c).
 *
 * ── EXTRACTED, NOT INVENTED ─────────────────────────────────────────────────────────────────────────────
 * Every line below was `forms/Index.vue`'s create block, moved with its behaviour intact. The extraction
 * exists because J5c added a SECOND consumer: the dashboard's first-run moment offers *start from blank*
 * beside *start from a template*, and a second copy of "make a form" is how two spellings of the same
 * action drift — the `useInertBackground` / `MdsCombobox` / `ShellAbilities` precedent, three times over.
 * The evidence that this is an extraction rather than a change is that `forms/index.test.ts` passes
 * byte-unedited.
 *
 * ── THE RESET MOVED INSIDE, WHICH IS THE ONE THING THAT IS NOT A STRAIGHT MOVE ──────────────────────────
 * The page used to call `reset()` + `clearErrors()` in its own `openCreate()`. With two call sites that
 * becomes a thing each of them must remember, and the second one to forget it would show the first one's
 * abandoned title. A watch on the open flag makes it structural instead. It is not a behaviour change:
 * a `pre`-flush watcher runs before the DOM updates, so the fields are blank on the frame the dialog
 * appears, exactly as before.
 *
 * ⚠️ **AND IT MUST NOT RESET ON EVERY RENDER — ONLY ON THE OPENING EDGE.** Errors arrive from a failed
 * submit while the dialog stays open, so a reset that fired on any change would wipe the messages the user
 * needs in the same tick they arrive.
 *
 * ── `initialFocus`, AND WHY THIS DIALOG OPTS OUT OF THE DEFAULT ─────────────────────────────────────────
 * `MdsModal` focuses `focusable()[0]`, which is its own Close button — right for the destructive
 * confirmations that were its first consumers, wrong for a dialog whose POINT is an input. Its own docblock
 * says so. This is the first-run moment for a brand-new workspace: opening it focused on Close is offering
 * somebody the exit before the entrance. The selector is resolved inside the panel, and `MdsTextInput`'s
 * root IS the input (Vue's default `inheritAttrs`), so the attribute lands on the real control rather than
 * on a wrapper — the silent no-op J4c2 paid for.
 */
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import {
    MdsButton,
    MdsFormField,
    MdsModal,
    MdsTextInput,
    MdsTextarea,
} from '@meridian/design-system';

const open = defineModel<boolean>('open', { required: true });

const form = useForm({ title: '', description: '' });

watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
    }
});

function submit(): void {
    // `POST /forms` redirects into the builder (J5c — see FormController::store), so on success this
    // component is unmounted by the navigation and the close below is the belt to that braces: it is what
    // keeps the dialog honest if the destination ever changes back.
    form.post('/forms', {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <MdsModal v-model:open="open" title="New form" initial-focus="[data-create-form-title]">
        <form class="create-form" @submit.prevent="submit">
            <MdsFormField label="Title" required :error="form.errors.title" v-slot="{ id, describedby, invalid }">
                <MdsTextInput
                    :id="id"
                    v-model="form.title"
                    :describedby="describedby"
                    :invalid="invalid"
                    placeholder="Household survey"
                    data-create-form-title
                />
            </MdsFormField>
            <MdsFormField label="Description" :error="form.errors.description" v-slot="{ id, describedby, invalid }">
                <MdsTextarea
                    :id="id"
                    v-model="form.description"
                    :describedby="describedby"
                    :invalid="invalid"
                    placeholder="What is this form for?"
                />
            </MdsFormField>
        </form>
        <template #actions>
            <MdsButton variant="tertiary" @click="open = false">Cancel</MdsButton>
            <MdsButton variant="primary" icon-left="plus" :loading="form.processing" @click="submit">
                Create form
            </MdsButton>
        </template>
    </MdsModal>
</template>

<style scoped>
/* `forms/Index.vue`'s `.forms__form`, moved here because a page's scoped style cannot reach a child
   component's markup — so leaving the class behind would have shipped an unspaced stack of fields. That
   page keeps its own copy: the Rename dialog still uses it. */
.create-form {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}
</style>
