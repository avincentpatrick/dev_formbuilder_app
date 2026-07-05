<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{ email: string; token: string }>();
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });

function submit(): void {
  form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
  <main>
    <h1>Choose a new password</h1>
    <form @submit.prevent="submit">
      <p>
        <label for="email">Email</label>
        <input id="email" v-model="form.email" type="email" autocomplete="email" required />
        <span v-if="form.errors.email">{{ form.errors.email }}</span>
      </p>
      <p>
        <label for="password">New password</label>
        <input id="password" v-model="form.password" type="password" autocomplete="new-password" required />
        <span v-if="form.errors.password">{{ form.errors.password }}</span>
      </p>
      <p>
        <label for="password_confirmation">Confirm new password</label>
        <input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" required />
      </p>
      <button type="submit" :disabled="form.processing">Reset password</button>
    </form>
  </main>
</template>
