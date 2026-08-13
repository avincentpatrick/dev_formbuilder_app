<script setup lang="ts">
// Design-system-styled sign-in page (Increment C1). Posts to Fortify's session-auth route.
import { useForm } from '@inertiajs/vue3';
import {
  MdsButton,
  MdsCheckbox,
  MdsFormField,
  MdsTextInput,
  MdsPasswordInput,
} from '@meridian/design-system';
import AuthLayout from '@/Layouts/AuthLayout.vue';

// I5 — whether /register is reachable from HERE, on THIS host. The server answers it from the same
// RegistrationGate the route middleware uses, precisely so the link and the route cannot disagree: a
// visible "Create an account" that leads to a 404 is the exact failure that sharing one gate prevents.
defineProps<{ canRegister: boolean }>();

const form = useForm({ email: '', password: '', remember: false });

function submit(): void {
  form.post('/login');
}
</script>

<template>
  <AuthLayout title="Sign in" variant="split">
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

      <!-- J3b: the shared control, not a raw checkbox under a page-owned class. `MdsCheckbox`
           carries the 44px touch target (§4.4) and the check GLYPH as the state signifier, which
           the 16px `accent-color` version had neither of. The accessible name is unchanged, which
           matters less here than for the two fields either side of it but is still the rule. -->
      <MdsCheckbox v-model="form.remember" label="Remember me" />

      <MdsButton type="submit" :loading="form.processing">Sign in</MdsButton>
    </form>

    <nav class="auth-links">
      <a href="/forgot-password">Forgot your password?</a>
      <a v-if="canRegister" href="/register">Create an account</a>
    </nav>
  </AuthLayout>
</template>
