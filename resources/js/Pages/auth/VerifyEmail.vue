<script setup lang="ts">
// Design-system-styled email-verification page (Increment C1).
import { useForm } from '@inertiajs/vue3';
import { MdsButton } from '@meridian/design-system';
import AuthLayout from '@/Layouts/AuthLayout.vue';

defineProps<{ status?: string }>();
const form = useForm({});

function resend(): void {
  form.post('/email/verification-notification');
}
</script>

<template>
  <AuthLayout title="Verify your email">
    <p class="auth-note">
      Please confirm your email address by clicking the link we just sent you.
    </p>

    <p v-if="status === 'verification-link-sent'" class="auth-alert">
      A new verification link has been sent to your email address.
    </p>

    <form class="auth-form" @submit.prevent="resend">
      <MdsButton type="submit" :loading="form.processing">Resend verification email</MdsButton>
    </form>

    <form method="POST" action="/logout">
      <MdsButton type="submit" variant="tertiary" size="sm">Log out</MdsButton>
    </form>
  </AuthLayout>
</template>
