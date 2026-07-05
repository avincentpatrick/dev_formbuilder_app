<script setup lang="ts">
// Design-system-styled sign-in page (Increment C1). Posts to Fortify's session-auth route.
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsTextInput, MdsPasswordInput } from '@meridian/design-system';
import AuthLayout from '@/Layouts/AuthLayout.vue';

const form = useForm({ email: '', password: '', remember: false });

function submit(): void {
  form.post('/login');
}
</script>

<template>
  <AuthLayout title="Sign in">
    <form class="auth-form" @submit.prevent="submit">
      <MdsFormField
        label="Email"
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

      <label class="auth-remember">
        <input v-model="form.remember" type="checkbox" />
        <span>Remember me</span>
      </label>

      <MdsButton type="submit" :loading="form.processing">Sign in</MdsButton>
    </form>

    <nav class="auth-links">
      <a href="/forgot-password">Forgot your password?</a>
      <a href="/register">Create an account</a>
    </nav>
  </AuthLayout>
</template>
