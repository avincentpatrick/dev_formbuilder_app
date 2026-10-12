<script setup lang="ts">
/**
 * The Scanning settings section (M129 — single-form OCR groundwork 2): whether this form accepts scans of its
 * printed paper — `PATCH /forms/{form}/ocr-scanning`, the only writer of `forms.allow_ocr_single`.
 *
 * The route gates it `can:update,form`, then the workspace's `ocr_single` module BEFORE the plan; the server
 * sends this section's props only where that route would admit the save, so the section is never a dead end.
 *
 * ⚠️ THE OPTIMISTIC TOGGLE AND ITS REVERT ARE `SaveResumePanel`'s, DELIBERATELY. A checkbox that waits for a
 * round trip reads as broken, and the revert matters for the same reason there: a gate can refuse after the
 * workspace changes under a session that still has the control on screen.
 *
 * ⚠️ SWITCHING ON IS ALLOWED FOR A FORM PAPER CANNOT CARRY YET. The setting is the author's intent; whether the
 * CURRENT version can be read is checked by every upload, and the section says why it would refuse in the
 * upload's own words — so an author fixing the form does not have to come back and switch this on.
 *
 * ── PAPER COPIES FROM THE PUBLIC LINK (M165, `R-7173c02c`, `D106`) ───────────────────────────────────────
 * Below the switch, while scanning is on: whether anyone with the public link may send a filled paper copy, and
 * the two limits the user chose — one device's window (10 minutes by default, never longer) and the most copies a
 * day. These three save TOGETHER with a button, unlike the switch above: a limit half-typed into a number box must
 * not be sent on every keystroke. The windows, the cap's ceiling and the per-window count are the server's, so this
 * section keeps no list of its own.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { MdsButton, MdsCheckbox, MdsFormField, MdsNumberInput, MdsSelect } from '@meridian/design-system';
import type { OcrScanningProps } from '@/components/forms/types';

const props = defineProps<{
    /** Whether the SETTINGS are open — the modal's state, or always true on the hub page. See `ConfirmationPanel`. */
    open: boolean;
    formId: string;
    scanning: OcrScanningProps;
}>();

const enabled = ref(props.scanning.enabled);

const guestEnabled = ref(props.scanning.guest.enabled);
const guestWindow = ref(String(props.scanning.guest.window_minutes));
const guestCap = ref<number | null>(props.scanning.guest.daily_cap);
const guestErrors = ref<Record<string, string>>({});
const guestSaving = ref(false);

const windowOptions = computed(() =>
    props.scanning.guest.windows.map((minutes) => ({
        value: String(minutes),
        label: minutes === 1 ? '1 minute' : `${minutes} minutes`,
    })),
);
const windowHelp = computed(() => {
    const count = props.scanning.guest.uploads_per_window;
    return `One phone or computer can send ${count} paper ${count === 1 ? 'copy' : 'copies'} in this time, then waits.`;
});

// Re-seed on open like every other section; after a refused write this also shows what the server holds.
watch(
    () => props.open,
    (open) => {
        if (!open) return;
        enabled.value = props.scanning.enabled;
        guestEnabled.value = props.scanning.guest.enabled;
        guestWindow.value = String(props.scanning.guest.window_minutes);
        guestCap.value = props.scanning.guest.daily_cap;
        guestErrors.value = {};
    },
    { immediate: true },
);

function onToggle(value: boolean): void {
    enabled.value = value;
    router.patch(
        `/forms/${props.formId}/ocr-scanning`,
        { allow_ocr_single: value },
        {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                enabled.value = !value;
            },
        },
    );
}

function saveGuest(): void {
    guestSaving.value = true;
    guestErrors.value = {};
    router.patch(
        `/forms/${props.formId}/ocr-scanning`,
        {
            allow_ocr_single: enabled.value,
            allow_guest_ocr: guestEnabled.value,
            guest_ocr_window_minutes: Number(guestWindow.value),
            guest_ocr_daily_cap: guestCap.value,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors: Record<string, string>) => {
                guestErrors.value = errors;
            },
            onFinish: () => {
                guestSaving.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="scanning">
        <p class="scanning__prose">
            Staff can photograph or scan filled-in paper copies of this form. Each scan is read automatically, and
            a person checks every answer before it is saved as a response.
        </p>

        <MdsCheckbox :model-value="enabled" label="Accept scans of paper copies" @update:model-value="onToggle" />

        <p v-if="!scanning.eligible && scanning.reason" class="scanning__reason" data-scanning-reason>
            {{ scanning.reason }}
        </p>

        <p class="scanning__note">This saves as soon as you change it — there is nothing to confirm.</p>

        <form v-if="enabled" class="scanning__guest" data-scanning-guest @submit.prevent="saveGuest">
            <h3 class="scanning__heading">Paper copies from the public link</h3>
            <p class="scanning__prose">
                People with this form's public link can also send photos or a scan of a paper copy they filled in.
                It waits in Recent scans, marked “From the public link”, and becomes a response only when someone
                here checks and saves it. Each upload passes an automatic spam check.
            </p>

            <MdsCheckbox v-model="guestEnabled" label="Let the public link take filled paper copies" />

            <p v-if="guestEnabled && !scanning.guest.link_live" class="scanning__reason" data-scanning-link-off>
                The public link is not open yet, so nobody can send a copy. Open it in the Share settings.
            </p>

            <MdsFormField
                v-slot="{ id, describedby, invalid }"
                label="Time window for one device"
                :help="windowHelp"
                :error="guestErrors.guest_ocr_window_minutes"
            >
                <MdsSelect
                    :id="id"
                    v-model="guestWindow"
                    :options="windowOptions"
                    :describedby="describedby"
                    :invalid="invalid"
                />
            </MdsFormField>

            <MdsFormField
                v-slot="{ id, describedby, invalid }"
                label="Paper copies a day"
                :help="`The most copies the public link takes in any 24 hours, up to ${scanning.guest.daily_cap_max}.`"
                :error="guestErrors.guest_ocr_daily_cap"
            >
                <MdsNumberInput
                    :id="id"
                    v-model="guestCap"
                    :min="1"
                    :max="scanning.guest.daily_cap_max"
                    :describedby="describedby"
                    :invalid="invalid"
                />
            </MdsFormField>

            <div class="scanning__actions">
                <MdsButton type="submit" variant="secondary" :loading="guestSaving">Save paper-copy settings</MdsButton>
            </div>
        </form>
    </div>
</template>

<style scoped>
.scanning__prose {
    margin: 0 0 var(--mds-space-4);
    color: var(--mds-color-text-body);
}

/* A fact about the form, not an error in this control: the secondary text colour, with a warning edge so the
   reason is noticed beside a checkbox that may read "on". */
.scanning__reason {
    margin: var(--mds-space-4) 0 0;
    padding-left: var(--mds-space-3);
    border-left: 4px solid var(--mds-color-status-warning-fg);
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-sm-font-size);
}

.scanning__guest {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    margin-top: var(--mds-space-5);
    padding-top: var(--mds-space-5);
    border-top: 1px solid var(--mds-color-border-default);
}

.scanning__guest .scanning__prose,
.scanning__guest .scanning__reason {
    margin: 0;
}

.scanning__heading {
    margin: 0;
    font-size: var(--mds-type-body-md-font-size);
    font-weight: var(--mds-type-label-font-weight);
}

.scanning__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
}

.scanning__note {
    margin: var(--mds-space-4) 0 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}
</style>
