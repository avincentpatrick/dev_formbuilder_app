<script setup lang="ts">
// Design-system-styled password-reset request page (Increment C1).
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsTextInput } from '@meridian/design-system';
import AuthLayout from '@/Layouts/AuthLayout.vue';

defineProps<{ status?: string }>();
const form = useForm({ email: '' });

function submit(): void {
  form.post('/forgot-password');
}
</script>

<template>
  <AuthLayout title="Reset your password">
    <p v-if="status" class="auth-alert">{{ status }}</p>

    <form class="auth-form" @submit.prevent="submit">
      <MdsFormField
        label="Email"
        help="We'll email you a link to reset your password."
        :error="form.errors.email"
        v-slot="{ id, describedby, invalid }"
      >
        <MdsTextInput
          :id="id"
          v-model="form.email"
          type="email"
          autocomplete="email"
          :describedby="describedby"
          :invalid="invalid"
        />
      </MdsFormField>

      <MdsButton type="submit" :loading="form.processing">Email password reset link</MdsButton>
    </form>

    <nav class="auth-links">
      <a href="/login">Back to sign in</a>
    </nav>
  </AuthLayout>
</template>
