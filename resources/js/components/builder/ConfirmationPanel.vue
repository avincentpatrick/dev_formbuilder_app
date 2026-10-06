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
 *
 * ── AFTER THE THANK-YOU (M130, `R-db169c29`, `D76`) ──────────────────────────────────────────────────
 * Below the message: where a respondent goes next — stay, another form of this workspace, or a web address —
 * saved by the same button into the same audit row. "Reset to default" sends the message alone and so never
 * clears a destination. The list of forms comes from the server: only forms this author may open, each with
 * `live`, the predicate the submit response resolves with, so the warning here is exactly the case in which a
 * respondent would stay instead.
 */
import { computed, useId, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsRadio, MdsSelect, MdsTextarea, MdsTextInput } from '@meridian/design-system';
import type { RedirectKind, RedirectTargetOption } from '@/components/forms/types';

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
        redirect_kind: RedirectKind;
        redirect_form_id: string | null;
        redirect_url: string | null;
        redirect_targets: RedirectTargetOption[];
        /** M138 (`R-df7f4b62`, `D91`): how long the thank-you screen waits before the move. */
        redirect_delay_seconds: number;
        default_locale: string;
        supported_locales: string[];
    };
}>();

const form = useForm<{
    confirmation_message: string;
    confirmation_message_translations: Record<string, string>;
    redirect_kind: RedirectKind;
    redirect_form_id: string;
    redirect_url: string;
    redirect_delay_seconds: string;
}>({
    confirmation_message: '',
    confirmation_message_translations: {},
    redirect_kind: 'none',
    redirect_form_id: '',
    redirect_url: '',
    redirect_delay_seconds: '20',
});

const REDIRECT_KINDS: ReadonlyArray<{ value: RedirectKind; label: string }> = [
    { value: 'none', label: 'Stay on the thank-you screen' },
    { value: 'form', label: 'Go to another form' },
    { value: 'url', label: 'Go to a web address' },
];
// M138 (`R-df7f4b62`, `D91` amending `D76`): the four delays the respondent's page is built for. 20 is `D76`'s figure —
// the time WCAG 2.2.1 gives a person to stop a move — so a shorter one says what it costs.
const DELAY_OPTIONS = [
    { value: '5', label: '5 seconds' },
    { value: '10', label: '10 seconds' },
    { value: '20', label: '20 seconds (recommended)' },
    { value: '30', label: '30 seconds' },
];
const delayHelp = computed(() =>
    Number(form.redirect_delay_seconds) < 20
        ? 'Shorter than 20 seconds gives respondents less time than the accessibility guideline (WCAG 2.2.1) asks for to read the thank-you and choose to stay.'
        : 'Respondents can also go on at once, or choose to stay.',
);
const kindName = useId();
const kindHelpId = useId();

/**
 * A SAVED destination this author cannot open — set by someone who could. It is shown as kept rather than by
 * name, and a save that leaves it alone does not send it: the request would refuse the id on this author's
 * behalf, and a message edit must not be blocked by a destination the author never touched.
 */
const savedUnseen = computed(
    () =>
        props.form.redirect_kind === 'form' &&
        props.form.redirect_form_id !== null &&
        !props.form.redirect_targets.some((target) => target.id === props.form.redirect_form_id),
);
const keptUnseen = computed(
    () => savedUnseen.value && form.redirect_kind === 'form' && form.redirect_form_id === props.form.redirect_form_id,
);
const targetOptions = computed(() => [
    ...(savedUnseen.value ? [{ value: props.form.redirect_form_id ?? '', label: 'A form you cannot open (kept as it is)' }] : []),
    ...props.form.redirect_targets.map((target) => ({
        value: target.id,
        label: target.live ? target.title : `${target.title} (not open to respondents)`,
    })),
]);
const chosenTarget = computed(() => props.form.redirect_targets.find((target) => target.id === form.redirect_form_id) ?? null);
const targetHelp = computed(() =>
    chosenTarget.value !== null && !chosenTarget.value.live
        ? 'This form is not open to respondents yet — it needs a public link, guest access and a published version. Until then respondents stay on the thank-you screen, and publishing this form is refused.'
        : keptUnseen.value
          ? 'Respondents go to a form you cannot open. Choose another to change it.'
          : 'Only forms you can open are listed.',
);

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
        form.redirect_kind = props.form.redirect_kind;
        form.redirect_form_id = props.form.redirect_form_id ?? '';
        form.redirect_url = props.form.redirect_url ?? '';
        form.redirect_delay_seconds = String(props.form.redirect_delay_seconds ?? 20);
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
            // The message alone: the request leaves the destination as it is when it is not mentioned (M130).
            if (clear) {
                return { confirmation_message: null, confirmation_message_translations: null };
            }

            const variants = Object.fromEntries(
                Object.entries(data.confirmation_message_translations).filter(([, value]) => value.trim() !== ''),
            );

            const message = {
                confirmation_message: data.confirmation_message.trim() === '' ? null : data.confirmation_message,
                confirmation_message_translations: Object.keys(variants).length > 0 ? variants : null,
            };

            // An untouched destination the author cannot open travels as no destination at all: left as it is.
            if (keptUnseen.value) {
                return message;
            }

            return {
                ...message,
                redirect_kind: data.redirect_kind,
                ...(data.redirect_kind === 'form' ? { redirect_form_id: data.redirect_form_id } : {}),
                ...(data.redirect_kind === 'url' ? { redirect_url: data.redirect_url } : {}),
                ...(data.redirect_kind !== 'none' ? { redirect_delay_seconds: Number(data.redirect_delay_seconds) } : {}),
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

        <fieldset class="confirmation__next" :aria-describedby="kindHelpId" data-redirect-settings>
            <legend class="confirmation__legend">After the thank-you screen</legend>
            <p :id="kindHelpId" class="confirmation__help">
                Respondents see the thank-you first, then go on after the wait you choose unless they choose to stay. A
                response saved on a device while offline never moves, and an embedded form only offers the link.
            </p>
            <div class="confirmation__kinds">
                <MdsRadio
                    v-for="kind in REDIRECT_KINDS"
                    :key="kind.value"
                    v-model="form.redirect_kind"
                    :value="kind.value"
                    :label="kind.label"
                    :name="kindName"
                />
            </div>

            <MdsFormField
                v-if="form.redirect_kind === 'form'"
                v-slot="{ id, describedby, invalid }"
                label="Form"
                :help="targetHelp"
                :error="form.errors.redirect_form_id"
            >
                <MdsSelect
                    :id="id"
                    v-model="form.redirect_form_id"
                    :options="targetOptions"
                    placeholder="Choose a form"
                    :describedby="describedby"
                    :invalid="invalid"
                />
            </MdsFormField>
            <MdsFormField
                v-else-if="form.redirect_kind === 'url'"
                v-slot="{ id, describedby, invalid }"
                label="Web address"
                help="A full address that starts with https://."
                :error="form.errors.redirect_url"
            >
                <MdsTextInput
                    :id="id"
                    v-model="form.redirect_url"
                    type="url"
                    placeholder="https://"
                    :describedby="describedby"
                    :invalid="invalid"
                />
            </MdsFormField>
            <MdsFormField
                v-if="form.redirect_kind !== 'none' && !keptUnseen"
                v-slot="{ id, describedby, invalid }"
                label="Wait before moving on"
                :help="delayHelp"
                :error="form.errors.redirect_delay_seconds"
            >
                <MdsSelect
                    :id="id"
                    v-model="form.redirect_delay_seconds"
                    :options="DELAY_OPTIONS"
                    :describedby="describedby"
                    :invalid="invalid"
                    data-redirect-delay
                />
            </MdsFormField>
        </fieldset>

        <div class="settings-panel__actions">
            <MdsButton variant="tertiary" :disabled="form.processing" @click="submit(true)">
                Reset message to default
            </MdsButton>
            <MdsButton variant="primary" icon-left="check" :loading="form.processing" @click="submit(false)">
                Save thank-you screen
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
/* M130 (`D76`) — the destination, a group of its own under the message. */
.confirmation__next {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    min-width: 0;
    margin: var(--mds-space-6) 0 0;
    padding: 0;
    border: 0;
}

.confirmation__legend {
    padding: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    line-height: var(--mds-type-label-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.confirmation__help {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.confirmation__kinds {
    display: flex;
    flex-direction: column;
}

.settings-panel__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--mds-space-2);
    margin-top: var(--mds-space-6);
}
</style>
