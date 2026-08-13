<script setup lang="ts">
// Design-system-styled registration page (Increment C1).
import { useForm } from '@inertiajs/vue3';
import {
  MdsButton,
  MdsFormField,
  MdsTextInput,
  MdsPasswordInput,
  MdsPasswordStrength,
  describedByWithStrength,
  type PasswordRequirement,
} from '@meridian/design-system';
import AuthLayout from '@/Layouts/AuthLayout.vue';

// The SERVER's rule list, published by App\Support\Auth\PasswordPolicy. Nothing about the policy is
// restated here, so the checklist and the validator that judges this password cannot disagree.
defineProps<{ passwordPolicy: PasswordRequirement[] }>();

const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });

function submit(): void {
  form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
  <AuthLayout title="Create your account" variant="split">
    <form class="auth-form" @submit.prevent="submit">
      <MdsFormField label="Name" :error="form.errors.name" v-slot="{ id, describedby, invalid }">
        <MdsTextInput
          :id="id"
          v-model="form.name"
          type="text"
          autocomplete="name"
          :describedby="describedby"
          :invalid="invalid"
        />
      </MdsFormField>

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
        label="Password"
        :error="form.errors.password"
        v-slot="{ id, describedby, invalid }"
      >
        <MdsPasswordInput
          :id="id"
          v-model="form.password"
          autocomplete="new-password"
          :describedby="describedByWithStrength(id, describedby)"
          :invalid="invalid"
        />
        <MdsPasswordStrength
          :input-id="id"
          :password="form.password"
          :requirements="passwordPolicy"
        />
      </MdsFormField>

      <MdsFormField label="Confirm password" v-slot="{ id, describedby }">
        <MdsPasswordInput
          :id="id"
          v-model="form.password_confirmation"
          autocomplete="new-password"
          :describedby="describedby"
        />
      </MdsFormField>

      <MdsButton type="submit" :loading="form.processing">Create account</MdsButton>
    </form>

    <nav class="auth-links">
      <a href="/login">Already have an account? Sign in</a>
    </nav>
  </AuthLayout>
</template>
