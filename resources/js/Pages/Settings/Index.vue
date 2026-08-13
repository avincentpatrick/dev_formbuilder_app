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
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useForm } from '@inertiajs/vue3';
import {
    MdsButton,
    MdsCard,
    MdsFormField,
    MdsIcon,
    MdsNumberInput,
    MdsPasswordInput,
    MdsSegmentedControl,
    MdsSwitch,
    MdsTextInput,
    type IconName,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import TwoFactorSetup from '@/components/settings/TwoFactorSetup.vue';
import BrandingCard from '@/components/settings/BrandingCard.vue';
import NotificationPreferencesCard from '@/components/settings/NotificationPreferencesCard.vue';
import AccessCard from '@/components/settings/AccessCard.vue';
import MaintenanceCard from '@/components/settings/MaintenanceCard.vue';
import ModulesCard from '@/components/settings/ModulesCard.vue';
import AboutCard from '@/components/settings/AboutCard.vue';
import type { NotificationPreferenceRow } from '@/components/notifications/types';
import type { SsoSettingsCard } from '@/components/sso/types';
import type { AccentToken, FontSizeScale, ThemeMode } from '@/types/inertia';
import { useAppearancePreference } from '@/composables/useTheme';

const props = defineProps<{
    // `needs_password_confirmation` is what stops the enrolment panel rendering a blank QR when the
    // session's password confirmation has lapsed — see TwoFactorSetup.vue's docblock (I8a).
    twoFactor: { enabled: boolean; confirmed: boolean; needs_password_confirmation: boolean };
    // Increment H10 — tenant-level draft settings. `can_manage` is Owner/Admin (tenant.settings.manage); the
    // card is hidden otherwise. `is_default` means the effective value is the 30-day fallback (column unset).
    draftSettings: { draft_ttl_days: number; is_default: boolean; can_manage: boolean };
    // Increment H22b — a LINK to /domains, shown only once the tenant actually holds a custom domain. This
    // is ADR-0012 §D9's escape hatch: the sidebar item requires the `custom_domain` plan feature, so a
    // tenant downgraded off Business would otherwise lose every path to a hostname that is still resolving.
    customDomains: { count: number };
    // Increment H23a2 — tenant branding. A full CONTROL rather than the signpost customDomains is:
    // branding is three inputs and a preview, which does not earn a page of its own. The card owns
    // its own shape; see BrandingCard.vue.
    branding: InstanceType<typeof BrandingCard>['$props']['branding'];
    // Increment I4 — the seven NotificationType cases with this user's RESOLVED channels (§23 is sparse,
    // so absence means default and the server fills the gaps). Typed from the shared contract file rather
    // than through InstanceType like `branding` above: `components/notifications/types.ts` exists so the
    // card's own test fixtures type-check against the same shape the server sends.
    notificationPreferences: NotificationPreferenceRow[];
    // Increment I5 — App Settings (PRD Feature #10). ONE prop from ONE presenter rather than four, because
    // the four panels answer one question ("how is this workspace configured") and share one gate.
    // `can_manage` hides Access/Maintenance/Modules from a non-admin; About is for everyone (a support aid,
    // and the person filing a bug report is rarely an Owner).
    appSettings: {
        can_manage: boolean;
        access: InstanceType<typeof AccessCard>['$props']['access'];
        maintenance: InstanceType<typeof MaintenanceCard>['$props']['maintenance'];
        modules: InstanceType<typeof ModulesCard>['$props']['modules'];
        about: InstanceType<typeof AboutCard>['$props']['about'];
    };
    // P1a (ADR-0016) — a LINK to /settings/sso, and note the condition is NOT `configured` alone the way
    // customDomains above is `count > 0`. SSO has no sidebar entry at all (§D6: `sso_saml` is Enterprise-only
    // and Enterprise is seeded is_active:false, so a feature-gated nav row would be invisible in every
    // environment), which makes this card the ONLY way in — so it must also render for an entitled tenant
    // that has configured nothing yet. The server folds both halves into `visible`.
    sso: SsoSettingsCard;
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
// On a BRANDED tenant a third option appears, and it is first because it is the effective default:
// "Brand" stores NULL, which means "no opinion — use my organisation's colour". Blueprint then means the
// product default EXPLICITLY, which is the only way a member can opt back out of their org's brand
// (design-system-reference.md §395; the escape H23a3 exists to close).
//
// On an unbranded tenant the control is unchanged at two options: with no brand to inherit, "no opinion"
// and "blueprint" render identically, so offering both would be a distinction without a difference.
const isBranded = computed(() => page.props.ui.brand !== null);

const accentOptions = computed<{ value: string; label: string }[]>(() => [
    ...(isBranded.value ? [{ value: 'brand', label: 'Brand' }] : []),
    { value: 'blueprint', label: 'Blueprint' },
    { value: 'teal', label: 'Teal' },
]);

// The control's value is a non-nullable string, so the null state travels as the sentinel 'brand'.
// Mapped in both directions here and nowhere else — the wire and the column both carry a real null.
const accentChoice = computed(() => accent.value ?? (isBranded.value ? 'brand' : 'blueprint'));

function chooseAccent(value: string): void {
    setAccent(value === 'brand' ? null : (value as AccentToken));
}

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
                    :model-value="accentChoice"
                    :options="accentOptions"
                    ariaLabel="Accent colour"
                    @update:model-value="chooseAccent"
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
                <!-- A switch since I5. DSR §3.2 argued for a checkbox here while no switch existed; now
                     that one does, this is the only on/off preference on the page that would have looked
                     different from the eighteen around it. -->
                <MdsSwitch
                    :model-value="dyslexiaFont"
                    label="Use a dyslexia-friendly font"
                    @update:model-value="setDyslexiaFont"
                />
            </div>
        </MdsCard>

        <!-- Notifications (Increment I4, PRD Feature #13b) — placed here, after Appearance and before
             Drafts, because Appearance and Notifications are the two PERSONAL preference cards, while
             Drafts, Branding and Custom domains below are org-wide Owner/Admin settings. -->
        <NotificationPreferencesCard :preferences="notificationPreferences" />

        <!-- App Settings (Increment I5, PRD Feature #10). PRD #10 asks for the area to be "organized by
             section (Access, Maintenance, Modules) rather than as one long unstructured list of switches",
             so the three stay CONTIGUOUS and in that order, and they open the org-wide block: everything
             above is a personal preference, everything from here down is a decision for the whole
             workspace. About is deliberately not among them — see the bottom of the page. -->
        <template v-if="appSettings.can_manage">
            <AccessCard :access="appSettings.access" />
            <MaintenanceCard :maintenance="appSettings.maintenance" />
            <ModulesCard :modules="appSettings.modules" />
        </template>

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

        <!-- Branding (Increment H23a2, ADR-0014). Placed after Drafts and before the custom-domain
             signpost: both are org-wide Owner/Admin settings, and this one is a control. -->
        <BrandingCard :branding="branding" />

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

        <!-- Single sign-on (P1a) — a signpost, not a control, exactly like custom domains above. The
             condition is deliberately WIDER than that card's: `visible` is entitled OR configured, because
             SSO has no sidebar entry to cover the entitled-but-empty case (ADR-0016 §D6). It still never
             advertises an unbuyable plan, since an unentitled tenant with nothing configured sees nothing. -->
        <MdsCard v-if="sso.visible" class="settings-card">
            <template #header>
                <div class="settings-card__head">
                    <MdsIcon name="shield" size="sm" aria-hidden="true" />
                    <h2 class="settings-card__title">Single sign-on</h2>
                </div>
            </template>
            <div class="settings-row">
                <div class="settings-row__text">
                    <p class="settings-row__label">
                        {{ sso.configured ? `SAML 2.0 · ${sso.status_label}` : 'Not set up' }}
                    </p>
                    <p class="settings-row__hint">
                        Let members sign in with your organisation’s own identity provider instead of an email
                        address and password.
                    </p>
                </div>
                <MdsButton variant="secondary" icon-left="shield" @click="router.visit('/settings/sso')">
                    {{ sso.configured ? 'Manage single sign-on' : 'Set up single sign-on' }}
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
                <TwoFactorSetup
                    :enabled="twoFactor.enabled"
                    :confirmed="twoFactor.confirmed"
                    :needs-password-confirmation="twoFactor.needs_password_confirmation"
                />
            </section>
        </MdsCard>

        <!-- About (Increment I5, PRD Feature #10). Last, and OUTSIDE the can_manage block above: it is a
             support aid rather than a control, and the person who files a bug report is rarely the Owner. -->
        <AboutCard :about="appSettings.about" />
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
