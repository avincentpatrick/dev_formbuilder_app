<script setup lang="ts">
/**
 * Whether single sign-on is on, and the honest statement of what "on" means in P1a (ADR-0016 §D14).
 *
 * ⚠️ THIS CONTROL IS ON THE UNGATED ROUTE, WHICH IS WHY IT IS ITS OWN CARD RATHER THAN A ROW IN THE POLICY
 * FORM. `PATCH /settings/sso/status` carries `can:tenant.settings.manage` alone, so a tenant downgraded off
 * Enterprise can still switch SSO OFF — the ADR-0012 §D9 escape hatch. Merging it into the policy card would
 * put it behind `can.configure` in the markup and undo that at the UI layer while the route stayed open.
 * Enabling is refused server-side instead: undo always, redo only on the plan.
 *
 * ⚠️ THE ACTIVE-STATE BANNER IS A STATEMENT OF WHAT IS TRUE RIGHT NOW, AND P1c HAD TO REWRITE IT.
 * P1a shipped it saying "signing in with SSO isn't switched on yet — everyone continues to sign in with
 * their email and password", which was exact then: an active connection published correct SP metadata and
 * accepted no logins, because `/sso/saml/login` and the ACS landed in P1b. **P1b landed, and the sentence
 * became false while continuing to render** — telling an admin their working sign-in does not work, which
 * is worse than saying nothing. It now describes the live behaviour and points at the sign-in URL on the
 * details card, which is still the only user-facing way in until the auth vertical adds a button.
 *
 * The rule the original was applying is right and survives: a wait, or a caveat, is stated in words rather
 * than implied by a disabled control (the `domains/Index.vue` rule). The lesson added on top is that a
 * sentence about what a NEIGHBOURING increment has not built yet has an expiry date, and nothing in the
 * build will notice when it passes.
 */
import { computed } from 'vue';
import { MdsBadge, MdsBanner, MdsButton, MdsCard, MdsIcon, MdsSwitch } from '@meridian/design-system';
import type { BadgeVariant } from '@meridian/design-system';
import type { SsoConnectionRow } from './types';

const props = defineProps<{
    connection: SsoConnectionRow;
    /** Owner/Admin alone — the status toggle and Remove survive a downgrade. */
    canManage: boolean;
    /** Owner/Admin AND the plan. Gates only the ENABLE direction; disabling stays available. */
    canConfigure: boolean;
    busy: boolean;
}>();

const emit = defineEmits<{ toggle: [enabled: boolean]; remove: [] }>();

const isOn = computed<boolean>(() => props.connection.status === 'active');

const badgeVariant = computed<BadgeVariant>(() => {
    switch (props.connection.status) {
        case 'active':
            return 'success';
        case 'disabled':
            return 'neutral';
        default:
            return 'info';
    }
});

/** Off→on needs the plan; on→off never does. Mirrors SsoConnectionService::changeStatus() exactly. */
const canToggle = computed<boolean>(() => (isOn.value ? props.canManage : props.canConfigure));
</script>

<template>
    <MdsCard class="settings-card">
        <template #header>
            <div class="settings-card__head">
                <MdsIcon name="shield" size="sm" aria-hidden="true" />
                <h2 class="settings-card__title">Single sign-on</h2>
                <MdsBadge :variant="badgeVariant" :label="connection.status_label" />
            </div>
        </template>

        <MdsBanner
            v-if="isOn"
            tone="info"
            icon="info"
            message="Members can sign in with your identity provider now, using the sign-in link below. Email and password still work, so nobody is locked out while you roll this out."
        />

        <div class="settings-row">
            <div class="settings-row__text">
                <p class="settings-row__label">{{ connection.protocol_label }} connection</p>
                <p class="settings-row__hint">
                    <template v-if="connection.status === 'draft'">
                        Your provider’s details are stored. Turn this on once you have added this workspace on
                        their side.
                    </template>
                    <template v-else-if="isOn">
                        Turning this off keeps everything you have configured — nothing is deleted.
                    </template>
                    <template v-else>
                        Switched off. Your provider’s details are kept, so turning it back on takes one click.
                    </template>
                </p>
            </div>
            <MdsSwitch
                :model-value="isOn"
                label="Single sign-on enabled"
                :disabled="!canToggle || busy"
                @update:model-value="emit('toggle', $event)"
            />
        </div>

        <div v-if="canManage" class="settings-row sso-status__danger">
            <div class="settings-row__text">
                <p class="settings-row__label">Remove this connection</p>
                <p class="settings-row__hint">
                    Deletes your provider’s details and this workspace’s trust in them. Members keep their
                    accounts and sign in with their email and password.
                </p>
            </div>
            <MdsButton variant="tertiary" icon-left="trash" :disabled="busy" @click="emit('remove')">
                Remove
            </MdsButton>
        </div>
    </MdsCard>
</template>

<style scoped>
/* Re-declared, not inherited — scoped CSS reaches a child SFC's root node only (the ModulesCard note). */
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
    gap: var(--mds-space-2) var(--mds-space-4);
}

.settings-row + .settings-row {
    margin-block-start: var(--mds-space-3);
    padding-block-start: var(--mds-space-3);
    border-block-start: 1px solid var(--mds-color-border-default);
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

/* The banner is a sibling of the rows, not inside one — it describes the card, not a control. */
.mds-banner + .settings-row {
    margin-block-start: var(--mds-space-4);
}

.sso-status__danger {
    margin-block-start: var(--mds-space-3);
}
</style>
