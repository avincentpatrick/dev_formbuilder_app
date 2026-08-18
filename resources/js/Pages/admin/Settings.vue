<script setup lang="ts">
/**
 * Super-admin platform settings (Increment I5, PRD Feature #10) — the two toggles that belong to no tenant.
 *
 * ⚠️ ONE SAVE BUTTON, NO AUTOSAVE, unlike the tenant Access/Modules cards. The blast radius here is every
 * tenant on the platform: switching maintenance on mid-keystroke, before the notice is finished, would show
 * respondents and admins alike a half-written sentence. `UpdatePlatformSettingsRequest` makes all three
 * fields `required` for the same reason.
 *
 * The warning below is not decoration either — the operator needs to know, at the moment they flip it, that
 * this console stays reachable. That property is enforced by `EnforcePlatformMaintenance`'s `admin/*` path
 * exemption, and it is the difference between a maintenance window and a lockout.
 *
 * Self-lays-out with AdminLayout: central admin pages are excluded from app.ts's resolver.
 */
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsCard, MdsFormField, MdsIcon, MdsSwitch, MdsTextarea } from '@meridian/design-system';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps<{
    settings: { signup_open: boolean; maintenance_enabled: boolean; maintenance_message: string };
    about: { version: string; commit: string | null; built_at: string | null; environment: string };
}>();

const form = useForm({
    signup_open: props.settings.signup_open,
    maintenance_enabled: props.settings.maintenance_enabled,
    maintenance_message: props.settings.maintenance_message,
});

function save(): void {
    form.patch('/admin/settings', { preserveScroll: true });
}
</script>

<template>
    <AdminLayout title="Platform" icon="settings">
        <form class="platform" @submit.prevent="save">
            <MdsCard class="platform__card">
                <template #header>
                    <div class="platform__head">
                        <MdsIcon name="user-plus" size="sm" aria-hidden="true" />
                        <h2 class="platform__title">Signup</h2>
                    </div>
                </template>

                <div class="platform__row">
                    <div class="platform__text">
                        <p class="platform__label">Open signup</p>
                        <p class="platform__hint">
                            When this is off, nobody can create a new account anywhere on the platform.
                            Existing members sign in as usual, and workspace invitations still work.
                        </p>
                    </div>
                    <MdsSwitch v-model="form.signup_open" label="Open signup" />
                </div>
            </MdsCard>

            <MdsCard class="platform__card">
                <template #header>
                    <div class="platform__head">
                        <MdsIcon name="alert" size="sm" aria-hidden="true" />
                        <h2 class="platform__title">Maintenance</h2>
                    </div>
                </template>

                <p v-if="settings.maintenance_enabled" class="platform__notice" role="status">
                    <MdsIcon name="alert" size="sm" aria-hidden="true" />
                    <span>Meridian is in maintenance for every tenant. This console stays reachable.</span>
                </p>

                <div class="platform__row">
                    <div class="platform__text">
                        <p class="platform__label">Platform maintenance</p>
                        <p class="platform__hint">
                            Blocks the whole product — every tenant’s app and every public form — and shows
                            your message instead. This admin console and the sign-in pages stay reachable, so
                            you can turn it back off.
                        </p>
                    </div>
                    <MdsSwitch v-model="form.maintenance_enabled" label="Platform maintenance" />
                </div>

                <MdsFormField
                    label="Message"
                    help="Shown to everyone while maintenance is on. Leave it empty to use our standard wording."
                    :error="form.errors.maintenance_message"
                    v-slot="{ id, describedby, invalid }"
                >
                    <MdsTextarea
                        :id="id"
                        v-model="form.maintenance_message"
                        :rows="3"
                        :describedby="describedby"
                        :invalid="invalid"
                        placeholder="Meridian is down for scheduled maintenance until 03:00 UTC."
                    />
                </MdsFormField>
            </MdsCard>

            <div class="platform__foot">
                <MdsButton variant="primary" type="submit" :loading="form.processing">Save</MdsButton>
                <span v-if="form.recentlySuccessful" class="platform__saved" role="status">Saved</span>
            </div>

            <MdsCard class="platform__card">
                <template #header>
                    <div class="platform__head">
                        <MdsIcon name="info" size="sm" aria-hidden="true" />
                        <h2 class="platform__title">About</h2>
                    </div>
                </template>

                <dl class="platform__about">
                    <div class="platform__row">
                        <dt class="platform__label">Version</dt>
                        <dd class="platform__value">{{ about.version }}</dd>
                    </div>
                    <div v-if="about.commit" class="platform__row">
                        <dt class="platform__label">Revision</dt>
                        <dd class="platform__value platform__value--mono">{{ about.commit }}</dd>
                    </div>
                    <div class="platform__row">
                        <dt class="platform__label">Environment</dt>
                        <dd class="platform__value">{{ about.environment }}</dd>
                    </div>
                </dl>
            </MdsCard>
        </form>
    </AdminLayout>
</template>

<style scoped>
.platform {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.platform__card {
    max-width: 640px;
}

.platform__head {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    color: var(--mds-color-text-secondary);
}

.platform__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.platform__notice {
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

.platform__row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-2) var(--mds-space-4);
    margin-block-end: var(--mds-space-4);
}

.platform__row:last-child {
    margin-block-end: 0;
}

.platform__text {
    min-width: 12rem;
    flex: 1;
}

.platform__label {
    margin: 0 0 var(--mds-space-1);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.platform__hint {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.platform__value {
    margin: 0;
    color: var(--mds-color-text-secondary);
}

.platform__value--mono {
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
}

.platform__about {
    margin: 0;
}

.platform__foot {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
}

.platform__saved {
    color: var(--mds-color-status-success-fg);
    font-size: var(--mds-type-body-sm-font-size);
}
</style>
