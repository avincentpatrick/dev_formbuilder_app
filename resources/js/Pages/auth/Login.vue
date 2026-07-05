<script setup lang="ts">
// Unstyled Increment-B1 auth page. The design-system-styled version lands with the app shell in
// Increment C; this is a functional placeholder posting to Fortify's session-auth routes.
import { useForm } from '@inertiajs/vue3';

const form = useForm({ email: '', password: '', remember: false });

function submit(): void {
  form.post('/login');
}
</script>

<template>
  <main>
    <h1>Sign in</h1>
    <form @submit.prevent="submit">
      <p>
        <label for="email">Email</label>
        <input id="email" v-model="form.email" type="email" autocomplete="email" required />
        <span v-if="form.errors.email">{{ form.errors.email }}</span>
      </p>
      <p>
        <label for="password">Password</label>
        <input id="password" v-model="form.password" type="password" autocomplete="current-password" required />
        <span v-if="form.errors.password">{{ form.errors.password }}</span>
      </p>
      <p>
        <label><input v-model="form.remember" type="checkbox" /> Remember me</label>
      </p>
      <button type="submit" :disabled="form.processing">Sign in</button>
    </form>
    <p><a href="/forgot-password">Forgot your password?</a> · <a href="/register">Create an account</a></p>
  </main>
</template>
