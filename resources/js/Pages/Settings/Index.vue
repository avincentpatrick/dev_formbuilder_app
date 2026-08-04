<script setup lang="ts">
/**
 * Settings — Profile, Appearance, and Security (2FA + password). Profile/password/2FA drive Fortify's
 * own account endpoints; Appearance persists the four personalization axes via the shared composable.
 * Rendered inside the persistent AppLayout. Feature #10 sections (Access/Modules/Maintenance) land in
 * Phase 1.
 *
 * This is the canonical home for personalization (PRD Feature #9, design-system-reference.md §2.9).
 * The top-nav quick toggle is a deliberately narrow additional surface for theme mode only
 * (exceptions-log #3) — the other three axes live here and nowhere else.
 */
import { router, usePage } from '@inertiajs/vue3';
import { useForm } from '@inertiajs/vue3';
import {
    MdsButton,
    MdsCard,
    MdsCheckbox,
    MdsFormField,
    MdsIcon,
    MdsNumberInput,
    MdsPasswordInput,
    MdsSegmentedControl,
    MdsTextInput,
    type IconName,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import TwoFactorSetup from '@/components/settings/TwoFactorSetup.vue';
import type { AccentToken, FontSizeScale, ThemeMode } from '@/types/inertia';
import { useAppearancePreference } from '@/composables/useTheme';

const props = defineProps<{
    twoFactor: { enabled: boolean; confirmed: boolean };
    // Increment H10 — tenant-level draft settings. `can_manage` is Owner/Admin (tenant.settings.manage); the
    // card is hidden otherwise. `is_default` means the effective value is the 30-day fallback (column unset).
    draftSettings: { draft_ttl_days: number; is_default: boolean; can_manage: boolean };
    // Increment H22b — a LINK to /domains, shown only once the tenant actually holds a custom domain. This
    // is ADR-0012 §D9's escape hatch: the sidebar item requires the `custom_domain` plan feature, so a
    // tenant downgraded off Business would otherwise lose every path to a hostname that is still resolving.
    customDomains: { count: number };
}>();

const page = usePage();

// Appearance — all four §2.9 axes. Each control PATCHes only its own field.
const { mode, setMode, accent, setAccent, fontSize, setFontSize, dyslexiaFont, setDyslexiaFont } =
    useAppearancePreference();

const themeOptions: { value: ThemeMode; label: string; icon: IconName }[] = [
    { value: 'light', label: 'Light', icon: 'sun' },
    { value: 'dark', label: 'Dark', icon: 'moon' },
    { value: 'system', label: 'Match System', icon: 'monitor' },
];

// Label-only, deliberately: a colour swatch would be a colour-ONLY signifier (WCAG 1.4.1), and the
// control already previews the accent for free — the selected chip is painted with
// --mds-color-action-primary-bg, so choosing Teal turns the chip you just clicked teal.
const accentOptions: { value: AccentToken; label: string }[] = [
    { value: 'blueprint', label: 'Blueprint' },
    { value: 'teal', label: 'Teal' },
];

const fontSizeOptions: { value: FontSizeScale; label: string }[] = [
    { value: 'standard', label: 'Standard' },
    { value: 'large', label: 'Large' },
    { value: 'extra_large', label: 'Extra large' },
];

// Profile (Fortify: PUT /user/profile-information)
const profile = useForm({
    name: page.props.auth.user?.name ?? '',
    email: page.props.auth.user?.email ?? '',
});
function saveProfile(): void {
    profile.put('/user/profile-information', { preserveScroll: true });
}

// Drafts — tenant-level "Save and finish later" expiry (Increment H10). Owner/Admin only; PATCHes the one
// tenant-scoped column. The change applies to drafts started afterwards (stamp-once — see the row hint).
const draftForm = useForm({ draft_ttl_days: props.draftSettings.draft_ttl_days });
function saveDraftSettings(): void {
    draftForm.patch('/settings/drafts', { preserveScroll: true });
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

            <div class="settings-row">
                <div class="settings-row__text">
                    <p class="settings-row__label">Accent colour</p>
                    <p class="settings-row__hint">
                        Recolours primary buttons, links, and focus rings. Status colours are unaffected.
                    </p>
                </div>
                <MdsSegmentedControl
                    :model-value="accent"
                    :options="accentOptions"
                    ariaLabel="Accent colour"
                    @update:model-value="(v: string) => setAccent(v as AccentToken)"
                />
            </div>

            <div class="settings-row">
                <div class="settings-row__text">
                    <p class="settings-row__label">Text size</p>
                    <p class="settings-row__hint">
                        Scales all text across Meridian. Public forms your respondents see are not
                        affected.
                    </p>
                </div>
                <MdsSegmentedControl
                    :model-value="fontSize"
                    :options="fontSizeOptions"
                    ariaLabel="Text size"
                    @update:model-value="(v: string) => setFontSize(v as FontSizeScale)"
                />
            </div>

            <div class="settings-row">
                <div class="settings-row__text">
                    <p class="settings-row__label">Dyslexia-friendly font</p>
                    <p class="settings-row__hint">
                        Switches body text to OpenDyslexic. Headings and code stay as they are.
                    </p>
                </div>
                <MdsCheckbox
                    :model-value="dyslexiaFont"
                    label="Use a dyslexia-friendly font"
                    @update:model-value="setDyslexiaFont"
                />
            </div>
        </MdsCard>

        <!-- Drafts (Increment H10) — Owner/Admin only tenant-level setting -->
        <MdsCard v-if="draftSettings.can_manage" class="settings-card">
            <template #header>
                <div class="settings-card__head">
                    <MdsIcon name="clock" size="sm" aria-hidden="true" />
                    <h2 class="settings-card__title">Drafts</h2>
                </div>
            </template>
            <form class="settings-form" @submit.prevent="saveDraftSettings">
                <MdsFormField
                    label="Save &amp; resume link expiry (days)"
                    help="How long a respondent's “Save and finish later” link stays valid. Applies to drafts started after this change."
                    :error="draftForm.errors.draft_ttl_days"
                    v-slot="{ id, describedby, invalid }"
                >
                    <MdsNumberInput
                        :id="id"
                        v-model="draftForm.draft_ttl_days"
                        :min="1"
                        :max="365"
                        :describedby="describedby"
                        :invalid="invalid"
                    />
                </MdsFormField>
                <div class="settings-form__foot">
                    <MdsButton variant="primary" type="submit" :loading="draftForm.processing">Save</MdsButton>
                    <span v-if="draftForm.recentlySuccessful" class="settings-form__saved" role="status">Saved</span>
                </div>
            </form>
        </MdsCard>

        <!-- Custom domains (Increment H22b) — a signpost, not a control. Only rendered once the tenant
             holds a domain, so it never advertises a Business feature to a tenant that cannot buy it. -->
        <MdsCard v-if="customDomains.count > 0" class="settings-card">
            <template #header>
                <div class="settings-card__head">
                    <MdsIcon name="globe" size="sm" aria-hidden="true" />
                    <h2 class="settings-card__title">Custom domains</h2>
                </div>
            </template>
            <div class="settings-row">
                <div class="settings-row__text">
                    <p class="settings-row__label">
                        {{ customDomains.count }} domain{{ customDomains.count === 1 ? '' : 's' }}
                    </p>
                    <p class="settings-row__hint">
                        Serve your public forms on your own hostname, with the DNS records and setup status.
                    </p>
                </div>
                <MdsButton variant="secondary" icon-left="globe" @click="router.visit('/domains')">
                    Manage domains
                </MdsButton>
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

/* The Appearance card carries four rows since G11 — separate them so each preference reads as its
   own decision rather than one dense block. */
.settings-row + .settings-row {
    margin-top: var(--mds-space-5);
    padding-top: var(--mds-space-5);
    border-top: 1px solid var(--mds-color-border-default);
}

/* Let a control shrink/wrap rather than force a horizontal scroller inside the card: under the §2.9
   text-size scale the three-segment "Text size" control grows ~25%, and at 375px that is enough to
   exceed the card's content box. */
.settings-row > :not(.settings-row__text) {
    min-width: 0;
    max-width: 100%;
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
