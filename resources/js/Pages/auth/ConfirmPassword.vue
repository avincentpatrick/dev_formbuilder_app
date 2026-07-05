<script setup lang="ts">
// Design-system-styled password-confirmation (secure-area) page (Increment C1).
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsPasswordInput } from '@meridian/design-system';
import AuthLayout from '@/Layouts/AuthLayout.vue';

const form = useForm({ password: '' });

function submit(): void {
  form.post('/user/confirm-password', { onFinish: () => form.reset() });
}
</script>

<template>
  <AuthLayout title="Confirm password">
    <p class="auth-note">This is a secure area. Please confirm your password before continuing.</p>

    <form class="auth-form" @submit.prevent="submit">
      <MdsFormField
        label="Password"
        :error="form.errors.password"
        v-slot="{ id, describedby, invalid }"
      >
        <MdsPasswordInput
          :id="id"
          v-model="form.password"
          autocomplete="current-password"
          :describedby="describedby"
          :invalid="invalid"
        />
      </MdsFormField>

      <MdsButton type="submit" :loading="form.processing">Confirm</MdsButton>
    </form>
  </AuthLayout>
</template>
