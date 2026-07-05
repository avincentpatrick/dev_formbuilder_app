<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

defineProps<{ status?: string }>();
const form = useForm({});

function resend(): void {
  form.post('/email/verification-notification');
}
</script>

<template>
  <main>
    <h1>Verify your email</h1>
    <p>Please confirm your email address by clicking the link we just sent you.</p>
    <p v-if="status === 'verification-link-sent'">A new verification link has been sent to your email address.</p>
    <form @submit.prevent="resend">
      <button type="submit" :disabled="form.processing">Resend verification email</button>
    </form>
    <form method="POST" action="/logout">
      <button type="submit">Log out</button>
    </form>
  </main>
</template>
