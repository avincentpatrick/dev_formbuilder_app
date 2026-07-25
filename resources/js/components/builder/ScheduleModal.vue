<script setup lang="ts">
/**
 * The scheduled-form config modal (Increment H12b) — sets or clears a form's open/close window + response
 * cap over PATCH /forms/{form}/schedule (ungated, `can:update,form`). Mirrors the builder's XLSForm-import
 * modal flow.
 *
 * The design system has no date primitive, so open/close use native `datetime-local` inputs: the author
 * enters a NAIVE wall-clock and picks an IANA timezone, and the server interprets that wall-clock IN the
 * chosen zone to store the absolute instant (see UpdateFormScheduleRequest). Prefill therefore renders the
 * stored ISO instant back into the form's timezone as a wall-clock string. Uses Inertia `useForm` so the
 * request's per-field 422s (close-before-open ordering, IANA timezone, positive cap) surface inline; a
 * successful save flashes the controller's toast and refreshes the builder props (which re-prefill on the
 * next open).
 */
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsModal, MdsNumberInput, MdsSelect } from '@meridian/design-system';

const props = defineProps<{
    open: boolean;
    formId: string;
    form: {
        opens_at: string | null;
        closes_at: string | null;
        timezone: string;
        max_responses: number | null;
    };
    timezones: string[];
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const form = useForm<{
    opens_at: string;
    closes_at: string;
    timezone: string;
    max_responses: number | null;
}>({
    opens_at: '',
    closes_at: '',
    timezone: props.form.timezone || 'UTC',
    max_responses: props.form.max_responses,
});

const timezoneOptions = props.timezones.map((tz) => ({ value: tz, label: tz }));

/**
 * Render an absolute ISO instant as the `datetime-local` wall-clock (YYYY-MM-DDTHH:mm) in a given IANA zone,
 * so the input shows the same local time the author picked (`hourCycle: 'h23'` keeps midnight as 00, not 24).
 * Empty string for an unset bound.
 */
function isoToLocalInput(iso: string | null, timeZone: string): string {
    if (!iso) return '';
    const at = new Date(iso);
    if (Number.isNaN(at.getTime())) return '';
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(at);
    const get = (type: string): string => parts.find((p) => p.type === type)?.value ?? '';
    return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}

// (Re)seed the fields from the current props whenever the modal opens — Inertia refreshes the builder props
// after each save (the controller's back() redirect), so this always reflects the latest saved schedule.
watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const tz = props.form.timezone || 'UTC';
        form.opens_at = isoToLocalInput(props.form.opens_at, tz);
        form.closes_at = isoToLocalInput(props.form.closes_at, tz);
        form.timezone = tz;
        form.max_responses = props.form.max_responses;
        form.clearErrors();
    },
    { immediate: true },
);

function close(): void {
    emit('update:open', false);
}

/**
 * PATCH the schedule. A blank datetime maps to null (open-ended on that side); `clear` nulls the whole
 * window + cap (timezone is still required by the request, so the current zone is kept). preserveState keeps
 * the canvas store intact while the back() redirect refreshes `props.form.*`.
 */
function submit(clear: boolean): void {
    form
        .transform((data) => ({
            opens_at: clear || data.opens_at === '' ? null : data.opens_at,
            closes_at: clear || data.closes_at === '' ? null : data.closes_at,
            timezone: data.timezone,
            max_responses: clear ? null : data.max_responses,
        }))
        .patch(`/forms/${props.formId}/schedule`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => close(),
        });
}
</script>

<template>
    <MdsModal :open="open" title="Schedule form" @close="close">
        <p class="schedule__prose">
            Set an optional open/close window and a response cap. Open and close times are interpreted in the
            timezone you choose. Leave a field blank for no limit on that side.
        </p>
        <div class="schedule__grid">
            <MdsFormField v-slot="{ id, describedby, invalid }" label="Opens at" :error="form.errors.opens_at">
                <input
                    :id="id"
                    v-model="form.opens_at"
                    type="datetime-local"
                    class="schedule__input"
                    :aria-describedby="describedby"
                    :aria-invalid="invalid || undefined"
                />
            </MdsFormField>
            <MdsFormField v-slot="{ id, describedby, invalid }" label="Closes at" :error="form.errors.closes_at">
                <input
                    :id="id"
                    v-model="form.closes_at"
                    type="datetime-local"
                    class="schedule__input"
                    :aria-describedby="describedby"
                    :aria-invalid="invalid || undefined"
                />
            </MdsFormField>
            <MdsFormField v-slot="{ id, describedby, invalid }" label="Timezone" :error="form.errors.timezone">
                <MdsSelect
                    :id="id"
                    v-model="form.timezone"
                    :options="timezoneOptions"
                    :describedby="describedby"
                    :invalid="invalid"
                />
            </MdsFormField>
            <MdsFormField
                v-slot="{ id, describedby, invalid }"
                label="Max responses"
                help="Leave blank for unlimited."
                :error="form.errors.max_responses"
            >
                <MdsNumberInput
                    :id="id"
                    v-model="form.max_responses"
                    :min="1"
                    placeholder="Unlimited"
                    :describedby="describedby"
                    :invalid="invalid"
                />
            </MdsFormField>
        </div>
        <template #actions>
            <MdsButton variant="tertiary" :disabled="form.processing" @click="submit(true)">
                Clear schedule
            </MdsButton>
            <MdsButton variant="tertiary" @click="close">Cancel</MdsButton>
            <MdsButton variant="primary" icon-left="check" :loading="form.processing" @click="submit(false)">
                Save schedule
            </MdsButton>
        </template>
    </MdsModal>
</template>

<style scoped>
.schedule__prose {
    margin: 0 0 var(--mds-space-4);
    color: var(--mds-color-text-body);
}

.schedule__grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--mds-space-4);
}

@media (max-width: 520px) {
    .schedule__grid {
        grid-template-columns: 1fr;
    }
}

/* The native datetime-local control, matched to the MdsTextInput/MdsNumberInput surface so the modal reads
   as one system (the design system has no date primitive — H12b narrowing). */
.schedule__input {
    width: 100%;
    min-height: 40px;
    padding: 0 var(--mds-space-3);
    border: 1px solid var(--mds-color-input-border);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-input-bg);
    color: var(--mds-color-text-body);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-lg-font-size);
    line-height: var(--mds-type-body-lg-line-height);
}

.schedule__input:hover {
    border-color: var(--mds-color-input-border-hover);
}

.schedule__input:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
    border-color: var(--mds-color-action-primary-bg);
}

.schedule__input[aria-invalid='true'] {
    border-color: var(--mds-color-action-danger-bg);
}
</style>
