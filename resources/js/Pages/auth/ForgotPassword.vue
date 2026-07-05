<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

defineProps<{ status?: string }>();
const form = useForm({ email: '' });

function submit(): void {
  form.post('/forgot-password');
}
</script>

<template>
  <main>
    <h1>Reset your password</h1>
    <p v-if="status">{{ status }}</p>
    <form @submit.prevent="submit">
      <p>
        <label for="email">Email</label>
        <input id="email" v-model="form.email" type="email" autocomplete="email" required />
        <span v-if="form.errors.email">{{ form.errors.email }}</span>
      </p>
      <button type="submit" :disabled="form.processing">Email password reset link</button>
    </form>
    <p><a href="/login">Back to sign in</a></p>
  </main>
</template>
