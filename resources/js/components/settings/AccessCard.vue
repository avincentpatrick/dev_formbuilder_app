<script setup lang="ts">
/**
 * Settings → Access (Increment I5, PRD Feature #10) — who may join this workspace, and (I8a, PRD Feature
 * #14) what they must have in place to stay in it.
 *
 * Autosave-on-change, matching the Appearance and Notifications cards above it rather than the
 * Profile/Drafts save-button pattern: one switch behind a Save button is a button nobody presses.
 *
 * The hint names the actual signup URL. "People can sign up" is not a complete sentence for an admin
 * deciding whether to allow it — where they sign up is the part that decides the answer.
 *
 * ⚠️ EACH SWITCH PATCHES ONLY ITS OWN KEY. `UpdateAccessSettingsRequest` marks both fields `sometimes`
 * precisely so these two independent decisions can share a panel without one write clobbering the other;
 * sending the whole form object here would defeat that from the client side and make the `sometimes`
 * look like dead defensiveness to the next reader.
 *
 * ⚠️ THE WARNING UNDER THE 2FA SWITCH IS NOT A NICETY. Enforcement applies to the person who turned it
 * on, on their very next request. An unenrolled Owner who flips it is redirected to the enrollment
 * interstitial immediately — recoverable (the interstitial IS the enrollment page) but alarming if it
 * arrives unannounced, and "I locked myself out" is the support ticket it would otherwise generate.
 *
 * ⚠️ THE `.settings-*` RULES ARE RE-DECLARED AT THE BOTTOM. Vue applies a parent's scope id to a child
 * component's ROOT node only, so `Settings/Index.vue`'s `<style scoped>` reaches `.settings-card` and
 * nothing inside it. Every card component on this page carries this block.
 */
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsCard, MdsIcon, MdsSwitch } from '@meridian/design-system';

const props = defineProps<{
    access: {
        invite_only: boolean;
        require_two_factor: boolean;
        signup_host: string;
        actor_enrolled: boolean;
    };
}>();

const inviteOnly = ref(props.access.invite_only);
const requireTwoFactor = ref(props.access.require_two_factor);

watch(
    () => props.access.invite_only,
    (next) => {
        inviteOnly.value = next;
    },
);

watch(
    () => props.access.require_two_factor,
    (next) => {
        requireTwoFactor.value = next;
    },
);

const form = useForm({ invite_only: props.access.invite_only });

function setInviteOnly(value: boolean): void {
    inviteOnly.value = value;
    form.invite_only = value;
    form.patch('/settings/access', {
        preserveScroll: true,
        preserveState: true,
        // A rejected write must not leave the switch showing a state the server does not hold.
        onError: () => {
            inviteOnly.value = props.access.invite_only;
        },
    });
}

// Its OWN form, so the payload carries `require_two_factor` and nothing else — see the partial-write
// note in the header. Sharing `form` above would send both keys on every flip of either switch.
const twoFactorForm = useForm({ require_two_factor: props.access.require_two_factor });

function setRequireTwoFactor(value: boolean): void {
    requireTwoFactor.value = value;
    twoFactorForm.require_two_factor = value;
    twoFactorForm.patch('/settings/access', {
        preserveScroll: true,
        preserveState: true,
        onError: () => {
            requireTwoFactor.value = props.access.require_two_factor;
        },
    });
}
</script>

<template>
    <MdsCard class="settings-card">
        <template #header>
            <div class="settings-card__head">
                <MdsIcon name="users" size="sm" aria-hidden="true" />
                <h2 class="settings-card__title">Access</h2>
            </div>
        </template>

        <div class="settings-row">
            <div class="settings-row__text">
                <p class="settings-row__label">Invitation only</p>
                <p class="settings-row__hint">
                    When this is on, people join only through an invitation you send. Turn it off to let
                    anyone create an account at
                    <span class="access__host">{{ access.signup_host }}</span> and join as a Viewer — you can
                    change their role afterwards on the Members page.
                </p>
            </div>
            <MdsSwitch
                :model-value="inviteOnly"
                label="Invitation only"
                @update:model-value="setInviteOnly"
            />
        </div>

        <div class="settings-row">
            <div class="settings-row__text">
                <p class="settings-row__label">Require two-factor authentication</p>
                <p class="settings-row__hint">
                    Every member must finish two-factor enrolment before they can use this workspace.
                    Anyone who hasn't is sent to the setup page until they do.
                </p>
                <p v-if="requireTwoFactor && !access.actor_enrolled" class="access__warn" role="status">
                    You haven't enrolled yet, so this applies to you too — you'll be taken to the setup
                    page on your next page load.
                </p>
            </div>
            <MdsSwitch
                :model-value="requireTwoFactor"
                label="Require two-factor authentication"
                @update:model-value="setRequireTwoFactor"
            />
        </div>

        <div class="settings-form__foot">
            <span
                v-if="form.recentlySuccessful || twoFactorForm.recentlySuccessful"
                class="settings-form__saved"
                role="status"
                >Saved</span
            >
        </div>
    </MdsCard>
</template>

<style scoped>
.access__host {
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-heading);
}

.access__warn {
    margin: var(--mds-space-2) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-status-warning-fg);
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
    margin-block-start: var(--mds-space-4);
    min-block-size: 1.5rem;
}

.settings-form__saved {
    color: var(--mds-color-status-success-fg);
    font-size: var(--mds-type-body-sm-font-size);
}
</style>
