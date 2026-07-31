<script setup lang="ts">
/**
 * The confirmation-message editor (Increment H6a, `docs/piping-output-encoding-design.md` §6.2) — sets or
 * clears the thank-you copy a respondent sees after submitting, over PATCH /forms/{form}/confirmation
 * (ungated, `can:update,form`). Mirrors {@link ScheduleModal} exactly: a modal over an Inertia `useForm`
 * PATCH, so the request's per-field 422s surface inline and a successful save flashes the controller's
 * toast and refreshes the builder props.
 *
 * This is the first author-editable text in the product that may carry `${key}` piping holes, and it shows
 * the RAW template — an author needs to see `${child_name}`, not a value there is no submission to supply.
 * (No builder preview surface exists; rendering a filled example is not this increment's job.)
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
import { MdsButton, MdsFormField, MdsModal, MdsTextarea } from '@meridian/design-system';

const props = defineProps<{
    open: boolean;
    formId: string;
    form: {
        confirmation_message: string | null;
        confirmation_message_translations: Record<string, string>;
        default_locale: string;
        supported_locales: string[];
    };
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

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

function close(): void {
    emit('update:open', false);
}

/**
 * PATCH the message. A blank base message maps to null, restoring the runtime default; `clear` nulls both
 * columns. Blank variants are dropped rather than stored as empty strings, so locale resolution's
 * never-blank fallback (which treats '' as missing) has nothing to trip over.
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
            onSuccess: () => close(),
        });
}
</script>

<template>
    <MdsModal :open="open" title="Confirmation message" @close="close">
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

        <template #actions>
            <MdsButton variant="tertiary" :disabled="form.processing" @click="submit(true)">
                Reset to default
            </MdsButton>
            <MdsButton variant="tertiary" @click="close">Cancel</MdsButton>
            <MdsButton variant="primary" icon-left="check" :loading="form.processing" @click="submit(false)">
                Save message
            </MdsButton>
        </template>
    </MdsModal>
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
</style>
