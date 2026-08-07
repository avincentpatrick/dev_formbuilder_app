<script setup lang="ts">
/**
 * Super-admin mandatory-MFA enrolment landing (RBAC §9). The `superadmin.mfa` middleware redirects here
 * until two-factor is confirmed; this page sits outside that gate. Styled with the shared design system
 * and the reusable TwoFactorSetup component (also used in tenant Settings → Security).
 */
import { MdsCard } from '@meridian/design-system';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TwoFactorSetup from '@/components/settings/TwoFactorSetup.vue';

defineProps<{ enabled: boolean; confirmed: boolean; needsPasswordConfirmation: boolean }>();
</script>

<template>
    <AdminLayout title="Two-factor authentication">
        <MdsCard>
            <p class="admin-tfa__intro">
                Platform administrators must enable two-factor authentication before using the admin
                console.
            </p>
            <TwoFactorSetup
                :enabled="enabled"
                :confirmed="confirmed"
                :needs-password-confirmation="needsPasswordConfirmation"
            />
        </MdsCard>
    </AdminLayout>
</template>

<style scoped>
.admin-tfa__intro {
    margin: 0 0 var(--mds-space-5);
    font-size: var(--mds-type-body-lg-font-size);
    color: var(--mds-color-text-body);
}
</style>
