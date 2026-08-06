<script setup lang="ts">
/**
 * Settings → Maintenance (Increment I5, PRD Feature #10) — pause the PUBLIC form runtime and say why.
 *
 * ⚠️ THE FLAG AND THE MESSAGE ARE ONE DECISION, SO THEY ARE WRITTEN TOGETHER. `UpdateMaintenanceSettingsRequest`
 * makes both `required` (the deliberate opposite of the Access card's `sometimes`), because switching
 * maintenance on while leaving the notice at whatever was typed months ago is a worse outcome than either
 * field being wrong on its own. That is also why this card has a SAVE BUTTON while Access and Modules
 * autosave: turning off every respondent's access to every form mid-keystroke is not a change to make on
 * `@change`, and the message needs to be finished before it ships.
 *
 * The scope note in the hint is not decoration. "Maintenance mode" reads like it takes the whole product
 * offline; here it stops respondents and leaves the admin app fully usable — which is the property that
 * lets whoever switched it on switch it off again.
 *
 * ⚠️ THE `.settings-*` RULES ARE RE-DECLARED AT THE BOTTOM — scoped CSS reaches a child SFC's root node only.
 */
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsCard, MdsFormField, MdsIcon, MdsSwitch, MdsTextarea } from '@meridian/design-system';

const props = defineProps<{ maintenance: { enabled: boolean; message: string | null } }>();

const form = useForm({
    maintenance_mode: props.maintenance.enabled,
    maintenance_message: props.maintenance.message ?? '',
});

const isPaused = computed(() => props.maintenance.enabled);

function save(): void {
    form.patch('/settings/maintenance', { preserveScroll: true });
}
</script>

<template>
    <MdsCard class="settings-card">
        <template #header>
            <div class="settings-card__head">
                <MdsIcon name="alert" size="sm" aria-hidden="true" />
                <h2 class="settings-card__title">Maintenance</h2>
            </div>
        </template>

        <!-- role="status", not an alert: this is a standing state the admin already knows about (they are
             looking at the switch that caused it), not an event that has just interrupted them. -->
        <p v-if="isPaused" class="maintenance__notice" role="status">
            <MdsIcon name="alert" size="sm" aria-hidden="true" />
            <span>Your public forms are paused. Respondents see your message instead of the form.</span>
        </p>

        <form class="settings-form" @submit.prevent="save">
            <div class="settings-row">
                <div class="settings-row__text">
                    <p class="settings-row__label">Pause public forms</p>
                    <p class="settings-row__hint">
                        Stops new responses on every public form and shows your message instead. Meridian
                        itself keeps working for your team, so you can turn this back off.
                    </p>
                </div>
                <MdsSwitch v-model="form.maintenance_mode" label="Pause public forms" />
            </div>

            <MdsFormField
                label="Message for respondents"
                help="Shown on the paused form. Leave it empty to use our standard wording."
                :error="form.errors.maintenance_message"
                v-slot="{ id, describedby, invalid }"
            >
                <MdsTextarea
                    :id="id"
                    v-model="form.maintenance_message"
                    :rows="3"
                    :describedby="describedby"
                    :invalid="invalid"
                    placeholder="We’re updating this form and will be back shortly."
                />
            </MdsFormField>

            <div class="settings-form__foot">
                <MdsButton variant="primary" type="submit" :loading="form.processing">Save</MdsButton>
                <span v-if="form.recentlySuccessful" class="settings-form__saved" role="status">Saved</span>
            </div>
        </form>
    </MdsCard>
</template>

<style scoped>
.maintenance__notice {
    display: flex;
    gap: var(--mds-space-2);
    align-items: flex-start;
    margin: 0 0 var(--mds-space-4);
    padding: var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    background: var(--mds-color-status-warning-bg);
    color: var(--mds-color-status-warning-fg);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

/* Re-declared, not inherited — see the header note about scoped CSS and child SFCs. */
.settings-card {
    max-width: 640px;
}

.settings-card__head {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    color: var(--mds-color-text-secondary);
}

.settings-card__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.settings-form {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.settings-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-4);
}

.settings-row__text {
    min-width: 12rem;
    flex: 1;
}

.settings-row__label {
    margin: 0 0 var(--mds-space-1);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.settings-row__hint {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.settings-form__foot {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
}

.settings-form__saved {
    color: var(--mds-color-status-success-fg);
    font-size: var(--mds-type-body-sm-font-size);
}
</style>
