<script setup lang="ts">
/**
 * The confirmation-message editor (Increment H6a, `docs/piping-output-encoding-design.md` §6.2) — sets or
 * clears the thank-you copy a respondent sees after submitting, over PATCH /forms/{form}/confirmation
 * (ungated, `can:update,form`).
 *
 * ⚠️ WAS `ConfirmationModal.vue` UNTIL M117, AND THE ROUTE DID NOT MOVE WITH IT. `D63` folds the builder's
 * nine ungrouped toolbar buttons into one "Form settings" modal, so this surface is now a SECTION of
 * {@link FormSettingsModal} rather than a dialog of its own. Every reason the fold was refused three times
 * over in the FormRequest docblocks still holds and is still honoured: this section keeps its own route and
 * its own {@see \App\Http\Requests\Forms\UpdateConfirmationMessageRequest}. What changed is only where an
 * author finds it. The file had exactly ONE call site (`Pages/forms/Builder.vue`), which is why it was
 * converted in place rather than split into a panel plus a wrapper the way `ShareModal` had to be.
 *
 * This is the first author-editable text in the product that may carry `${key}` piping holes, and it shows
 * the RAW template — an author needs to see `${child_name}`, not a value there is no submission to supply.
 *
 * Validation is split deliberately. The request rule checks GRAMMAR only, so a malformed hole like
 * `${1abc}` fails here and inline; whether `${child_name}` actually names a pipeable field is resolved at
 * PUBLISH, against the version being published — this column lives on `forms`, not on a version, so there
 * is no version to resolve against at edit time. A hole that dangles between edits renders as the empty
 * string and never throws; the next publish refuses it and names this column.
 *
 * One message box per supported locale, driven by `form.supported_locales`. Blank leaves the runtime's
 * built-in default standing, which is what makes the whole column additive for every existing form.
 */
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsTextarea } from '@meridian/design-system';

const props = defineProps<{
    /**
     * Whether the SETTINGS MODAL is open — not whether this section is the visible one. Re-seeding on the
     * modal's open rather than on the section becoming visible is deliberate: switching sections and coming
     * back must not silently discard what the author typed, and `FormSettingsModal` keeps every visited
     * section mounted for exactly that reason.
     */
    open: boolean;
    formId: string;
    form: {
        confirmation_message: string | null;
        confirmation_message_translations: Record<string, string>;
        default_locale: string;
        supported_locales: string[];
    };
}>();

const form = useForm<{
    confirmation_message: string;
    confirmation_message_translations: Record<string, string>;
}>({
    confirmation_message: '',
    confirmation_message_translations: {},
});

/** Every supported locale except the default, which the base message already covers. */
const variantLocales = computed(() =>
    props.form.supported_locales.filter((locale) => locale !== props.form.default_locale),
);

// (Re)seed from the current props whenever the modal opens — Inertia refreshes the builder props after each
// save (the controller's back() redirect), so this always reflects the latest saved copy.
watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.confirmation_message = props.form.confirmation_message ?? '';
        form.confirmation_message_translations = Object.fromEntries(
            variantLocales.value.map((locale) => [locale, props.form.confirmation_message_translations[locale] ?? '']),
        );
        form.clearErrors();
    },
    { immediate: true },
);

/**
 * PATCH the message. A blank base message maps to null, restoring the runtime default; `clear` nulls both
 * columns. Blank variants are dropped rather than stored as empty strings, so locale resolution's
 * never-blank fallback (which treats '' as missing) has nothing to trip over.
 *
 * ⚠️ IT NO LONGER CLOSES ON SUCCESS, AND THAT IS THE POINT OF THE SETTINGS SURFACE. As a dialog this
 * dismissed itself on save; as one section of five, dismissing the whole modal would throw the author out
 * of the thing they opened to make several changes in. The controller's `back()` toast is the confirmation,
 * exactly as it is on the forms list.
 */
function submit(clear: boolean): void {
    form
        .transform((data) => {
            if (clear) {
                return { confirmation_message: null, confirmation_message_translations: null };
            }

            const variants = Object.fromEntries(
                Object.entries(data.confirmation_message_translations).filter(([, value]) => value.trim() !== ''),
            );

            return {
                confirmation_message: data.confirmation_message.trim() === '' ? null : data.confirmation_message,
                confirmation_message_translations: Object.keys(variants).length > 0 ? variants : null,
            };
        })
        .patch(`/forms/${props.formId}/confirmation`, {
            preserveScroll: true,
            preserveState: true,
        });
}
</script>

<template>
    <div class="confirmation">
        <p class="confirmation__prose">
            The thank-you message a respondent sees after submitting. Leave it blank to use the built-in
            default. Reference an earlier answer with <code class="confirmation__code">${'{'}key{'}'}</code> —
            for example <code class="confirmation__code">Thanks, ${'{'}full_name{'}'}!</code>. A reference is
            checked when you publish.
        </p>

        <MdsFormField
            v-slot="{ id, describedby, invalid }"
            :label="`Message (${form.confirmation_message === '' ? 'default' : props.form.default_locale})`"
            help="Blank uses the built-in default."
            :error="form.errors.confirmation_message"
        >
            <MdsTextarea
                :id="id"
                v-model="form.confirmation_message"
                :rows="3"
                placeholder="Thanks — your response has been recorded."
                :describedby="describedby"
                :invalid="invalid"
            />
        </MdsFormField>

        <MdsFormField
            v-for="locale in variantLocales"
            :key="locale"
            v-slot="{ id, describedby, invalid }"
            :label="`Message (${locale})`"
            help="Blank falls back to the message above."
            :error="form.errors[`confirmation_message_translations.${locale}`]"
        >
            <MdsTextarea
                :id="id"
                v-model="form.confirmation_message_translations[locale]"
                :rows="3"
                :describedby="describedby"
                :invalid="invalid"
            />
        </MdsFormField>

        <div class="settings-panel__actions">
            <MdsButton variant="tertiary" :disabled="form.processing" @click="submit(true)">
                Reset to default
            </MdsButton>
            <MdsButton variant="primary" icon-left="check" :loading="form.processing" @click="submit(false)">
                Save message
            </MdsButton>
        </div>
    </div>
</template>

<style scoped>
.confirmation__prose {
    margin: 0 0 var(--mds-space-4);
    color: var(--mds-color-text-body);
}

.confirmation__code {
    padding: 0 var(--mds-space-1);
    border-radius: var(--mds-radius-sm);
    background: var(--mds-color-bg-sunken);
    font-family: var(--mds-font-family-mono);
    font-size: 0.9em;
}

/* ⚠️ RE-DECLARED, NOT INHERITED — the same note `AccessCard.vue` carries. `<style scoped>` reaches a child
   SFC's ROOT node only, so the settings modal cannot style this footer for its sections. */
.settings-panel__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--mds-space-2);
    margin-top: var(--mds-space-6);
}
</style>
