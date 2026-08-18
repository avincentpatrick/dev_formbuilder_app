<script setup lang="ts">
/**
 * The 2FA enrollment interstitial (Increment I8a, PRD Feature #14) — where EnforceTenantTwoFactor sends a
 * member of a workspace whose Owner has turned on "Require two-factor authentication".
 *
 * AuthLayout, not the app shell, and that is the substantive choice on this page. The person is signed in,
 * so the shell would render — with a nav they cannot use, a bell polling for notifications they cannot
 * open, and every link bouncing straight back here. A gate that looks like the app but behaves like a wall
 * reads as a bug; a gate that looks like a gate explains itself. It also means no I4 notification poll
 * runs here, which is the correct answer for a page nobody should linger on.
 *
 * The enrollment control is the SHARED TwoFactorSetup component — the same one in Settings → Security and
 * on the super-admin landing — so there is one enrollment UI in the product rather than three that drift.
 * The controller redirects to /dashboard the moment `two_factor_confirmed_at` lands, so the success state
 * is a navigation rather than a message.
 */
import { Link } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import TwoFactorSetup from '@/components/settings/TwoFactorSetup.vue';

defineProps<{ enabled: boolean; confirmed: boolean; needsPasswordConfirmation: boolean }>();
</script>

<template>
    <AuthLayout title="Two-factor authentication required">
        <p class="auth-note">
            This workspace requires every member to use two-factor authentication. Set it up now to
            continue.
        </p>

        <TwoFactorSetup
            :enabled="enabled"
            :confirmed="confirmed"
            :needs-password-confirmation="needsPasswordConfirmation"
        />

        <!-- The second door. "Enroll or leave" needs both, and POST /logout is a Fortify route outside
             this gate, so it stays reachable. Without it the only way out of this page is to clear
             cookies. -->
        <p class="tfa-required__out">
            Not your workspace?
            <Link href="/logout" method="post" as="button" type="button" class="tfa-required__signout">
                Sign out
            </Link>
        </p>
    </AuthLayout>
</template>

<style scoped>
.tfa-required__out {
    margin: var(--mds-space-6) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.tfa-required__signout {
    padding: 0;
    background: none;
    border: 0;
    font: inherit;
    color: var(--mds-color-action-primary-fg);
    text-decoration: underline;
    cursor: pointer;
}

.tfa-required__signout:hover {
    text-decoration: none;
}
</style>
