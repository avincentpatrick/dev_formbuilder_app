<script setup lang="ts">
// Unstyled Increment-B2c mandatory-MFA enrollment landing (styled in Increment C). Super-admins are
// redirected here by the `superadmin.mfa` middleware until they confirm two-factor auth. It drives
// Fortify's own global 2FA endpoints (registered on the central domain, outside the /admin group).
import { useForm } from '@inertiajs/vue3';

// Enable 2FA (Fortify generates the secret + recovery codes). Fortify's `confirmPassword` feature may
// interpose a password-confirmation step; that is handled by Fortify's own flow.
const enableForm = useForm({});
function enable(): void {
  enableForm.post('/user/two-factor-authentication');
}

// Confirm enrollment with a TOTP code from the authenticator app.
const confirmForm = useForm({ code: '' });
function confirm(): void {
  confirmForm.post('/user/confirmed-two-factor-authentication', {
    onSuccess: () => confirmForm.reset('code'),
  });
}
</script>

<template>
  <main>
    <h1>Two-factor authentication required</h1>
    <p>
      Platform administrators must enable two-factor authentication before using the admin console.
    </p>

    <form @submit.prevent="enable">
      <button type="submit" :disabled="enableForm.processing">Enable two-factor authentication</button>
    </form>

    <p>
      After enabling, scan the QR code shown at
      <code>/user/two-factor-qr-code</code> with your authenticator app, then confirm with a code:
    </p>

    <form @submit.prevent="confirm">
      <label for="code">Authentication code</label>
      <input id="code" v-model="confirmForm.code" type="text" inputmode="numeric" autocomplete="one-time-code" required />
      <span v-if="confirmForm.errors.code">{{ confirmForm.errors.code }}</span>
      <button type="submit" :disabled="confirmForm.processing">Confirm</button>
    </form>
  </main>
</template>
