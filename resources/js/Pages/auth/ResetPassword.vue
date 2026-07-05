<script setup lang="ts">
// Design-system-styled new-password page (Increment C1).
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsTextInput, MdsPasswordInput } from '@meridian/design-system';
import AuthLayout from '@/Layouts/AuthLayout.vue';

const props = defineProps<{ email: string; token: string }>();
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });

function submit(): void {
  form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
  <AuthLayout title="Choose a new password">
    <form class="auth-form" @submit.prevent="submit">
      <MdsFormField label="Email" :error="form.errors.email" v-slot="{ id, describedby, invalid }">
        <MdsTextInput
          :id="id"
          v-model="form.email"
          type="email"
          autocomplete="email"
          :describedby="describedby"
          :invalid="invalid"
        />
      </MdsFormField>

      <MdsFormField
        label="New password"
        :error="form.errors.password"
        v-slot="{ id, describedby, invalid }"
      >
        <MdsPasswordInput
          :id="id"
          v-model="form.password"
          autocomplete="new-password"
          :describedby="describedby"
          :invalid="invalid"
        />
      </MdsFormField>

      <MdsFormField label="Confirm new password" v-slot="{ id, describedby }">
        <MdsPasswordInput
          :id="id"
          v-model="form.password_confirmation"
          autocomplete="new-password"
          :describedby="describedby"
        />
      </MdsFormField>

      <MdsButton type="submit" :loading="form.processing">Reset password</MdsButton>
    </form>
  </AuthLayout>
</template>
