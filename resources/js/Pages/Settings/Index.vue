<script setup lang="ts">
/**
 * Settings — Profile, Appearance, and Security (2FA + password). Profile/password/2FA drive Fortify's
 * own account endpoints; Appearance persists theme_mode via the shared theme composable. Rendered inside
 * the persistent AppLayout. Feature #10 sections (Access/Modules/Maintenance) land in Phase 1.
 */
import { usePage } from '@inertiajs/vue3';
import { useForm } from '@inertiajs/vue3';
import {
    MdsButton,
    MdsCard,
    MdsFormField,
    MdsIcon,
    MdsPasswordInput,
    MdsSegmentedControl,
    MdsTextInput,
    type IconName,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import TwoFactorSetup from '@/components/settings/TwoFactorSetup.vue';
import type { ThemeMode } from '@/types/inertia';
import { useThemePreference } from '@/composables/useTheme';

defineProps<{ twoFactor: { enabled: boolean; confirmed: boolean } }>();

const page = usePage();

// Appearance
const { mode, setMode } = useThemePreference();
const themeOptions: { value: ThemeMode; label: string; icon: IconName }[] = [
    { value: 'light', label: 'Light', icon: 'sun' },
    { value: 'dark', label: 'Dark', icon: 'moon' },
    { value: 'system', label: 'Match System', icon: 'monitor' },
];

// Profile (Fortify: PUT /user/profile-information)
const profile = useForm({
    name: page.props.auth.user?.name ?? '',
    email: page.props.auth.user?.email ?? '',
});
function saveProfile(): void {
    profile.put('/user/profile-information', { preserveScroll: true });
}

// Password (Fortify: PUT /user/password)
const password = useForm({ current_password: '', password: '', password_confirmation: '' });
function savePassword(): void {
    password.put('/user/password', {
        preserveScroll: true,
        onSuccess: () => password.reset(),
        onError: () => password.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <div class="settings">
        <PageHeader title="Settings" icon="settings" />

        <!-- Profile -->
        <MdsCard class="settings-card">
            <template #header>
                <div class="settings-card__head">
                    <MdsIcon name="user" size="sm" aria-hidden="true" />
                    <h2 class="settings-card__title">Profile</h2>
                </div>
            </template>
            <form class="settings-form" @submit.prevent="saveProfile">
                <MdsFormField label="Name" required :error="profile.errors.name" v-slot="{ id, describedby, invalid }">
                    <MdsTextInput
                        :id="id"
                        v-model="profile.name"
                        name="name"
                        autocomplete="name"
                        :describedby="describedby"
                        :invalid="invalid"
                    />
                </MdsFormField>
                <MdsFormField label="Email" required :error="profile.errors.email" v-slot="{ id, describedby, invalid }">
                    <MdsTextInput
                        :id="id"
                        v-model="profile.email"
                        type="email"
                        name="email"
                        autocomplete="email"
                        :describedby="describedby"
                        :invalid="invalid"
                    />
                </MdsFormField>
                <div class="settings-form__foot">
                    <MdsButton variant="primary" type="submit" :loading="profile.processing">Save</MdsButton>
                    <span v-if="profile.recentlySuccessful" class="settings-form__saved" role="status">Saved</span>
                </div>
            </form>
        </MdsCard>

        <!-- Appearance -->
        <MdsCard class="settings-card">
            <template #header>
                <div class="settings-card__head">
                    <MdsIcon name="sun" size="sm" aria-hidden="true" />
                    <h2 class="settings-card__title">Appearance</h2>
                </div>
            </template>
            <div class="settings-row">
                <div class="settings-row__text">
                    <p class="settings-row__label">Theme</p>
                    <p class="settings-row__hint">
                        Choose how Meridian looks. "Match System" follows your device setting.
                    </p>
                </div>
                <MdsSegmentedControl
                    :model-value="mode"
                    :options="themeOptions"
                    ariaLabel="Theme"
                    @update:model-value="(v: string) => setMode(v as ThemeMode)"
                />
            </div>
        </MdsCard>

        <!-- Security -->
        <MdsCard class="settings-card">
            <template #header>
                <div class="settings-card__head">
                    <MdsIcon name="shield" size="sm" aria-hidden="true" />
                    <h2 class="settings-card__title">Security</h2>
                </div>
            </template>

            <section class="settings-sub">
                <h3 class="settings-sub__title">Password</h3>
                <form class="settings-form" @submit.prevent="savePassword">
                    <MdsFormField
                        label="Current password"
                        required
                        :error="password.errors.current_password"
                        v-slot="{ id, describedby, invalid }"
                    >
                        <MdsPasswordInput
                            :id="id"
                            v-model="password.current_password"
                            name="current_password"
                            autocomplete="current-password"
                            :describedby="describedby"
                            :invalid="invalid"
                        />
                    </MdsFormField>
                    <MdsFormField
                        label="New password"
                        required
                        :error="password.errors.password"
                        v-slot="{ id, describedby, invalid }"
                    >
                        <MdsPasswordInput
                            :id="id"
                            v-model="password.password"
                            name="password"
                            autocomplete="new-password"
                            :describedby="describedby"
                            :invalid="invalid"
                        />
                    </MdsFormField>
                    <MdsFormField label="Confirm new password" required v-slot="{ id, describedby, invalid }">
                        <MdsPasswordInput
                            :id="id"
                            v-model="password.password_confirmation"
                            name="password_confirmation"
                            autocomplete="new-password"
                            :describedby="describedby"
                            :invalid="invalid"
                        />
                    </MdsFormField>
                    <div class="settings-form__foot">
                        <MdsButton variant="primary" type="submit" :loading="password.processing">
                            Update password
                        </MdsButton>
                        <span v-if="password.recentlySuccessful" class="settings-form__saved" role="status">
                            Updated
                        </span>
                    </div>
                </form>
            </section>

            <hr class="settings-divider" />

            <section class="settings-sub">
                <h3 class="settings-sub__title">Two-factor authentication</h3>
                <TwoFactorSetup :enabled="twoFactor.enabled" :confirmed="twoFactor.confirmed" />
            </section>
        </MdsCard>
    </div>
</template>

<style scoped>
.settings {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

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
    max-width: 380px;
}

.settings-form__foot {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
}

.settings-form__saved {
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-status-success-fg);
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
    color: var(--mds-color-text-secondary);
}

.settings-sub__title {
    margin: 0 0 var(--mds-space-3);
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    font-weight: var(--mds-type-heading-4-font-weight);
    color: var(--mds-color-text-heading);
}

.settings-divider {
    margin: var(--mds-space-6) 0;
    border: 0;
    border-top: 1px solid var(--mds-color-border-default);
}
</style>
