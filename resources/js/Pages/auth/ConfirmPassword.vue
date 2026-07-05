<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const form = useForm({ password: '' });

function submit(): void {
  form.post('/user/confirm-password', { onFinish: () => form.reset() });
}
</script>

<template>
  <main>
    <h1>Confirm password</h1>
    <p>This is a secure area. Please confirm your password before continuing.</p>
    <form @submit.prevent="submit">
      <p>
        <label for="password">Password</label>
        <input id="password" v-model="form.password" type="password" autocomplete="current-password" required autofocus />
        <span v-if="form.errors.password">{{ form.errors.password }}</span>
      </p>
      <button type="submit" :disabled="form.processing">Confirm</button>
    </form>
  </main>
</template>
