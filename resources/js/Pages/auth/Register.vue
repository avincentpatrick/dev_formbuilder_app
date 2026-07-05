<script setup lang="ts">
// Unstyled Increment-B1 auth page (styled in Increment C).
import { useForm } from '@inertiajs/vue3';

const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });

function submit(): void {
  form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
  <main>
    <h1>Create your account</h1>
    <form @submit.prevent="submit">
      <p>
        <label for="name">Name</label>
        <input id="name" v-model="form.name" type="text" autocomplete="name" required />
        <span v-if="form.errors.name">{{ form.errors.name }}</span>
      </p>
      <p>
        <label for="email">Email</label>
        <input id="email" v-model="form.email" type="email" autocomplete="email" required />
        <span v-if="form.errors.email">{{ form.errors.email }}</span>
      </p>
      <p>
        <label for="password">Password</label>
        <input id="password" v-model="form.password" type="password" autocomplete="new-password" required />
        <span v-if="form.errors.password">{{ form.errors.password }}</span>
      </p>
      <p>
        <label for="password_confirmation">Confirm password</label>
        <input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" required />
      </p>
      <button type="submit" :disabled="form.processing">Register</button>
    </form>
    <p><a href="/login">Already have an account? Sign in</a></p>
  </main>
</template>
