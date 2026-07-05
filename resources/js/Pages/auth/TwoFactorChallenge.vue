<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

const recovery = ref(false);
const form = useForm({ code: '', recovery_code: '' });

function submit(): void {
  form.post('/two-factor-challenge');
}
</script>

<template>
  <main>
    <h1>Two-factor authentication</h1>
    <form @submit.prevent="submit">
      <template v-if="!recovery">
        <p>
          <label for="code">Authentication code</label>
          <input id="code" v-model="form.code" type="text" inputmode="numeric" autocomplete="one-time-code" autofocus />
          <span v-if="form.errors.code">{{ form.errors.code }}</span>
        </p>
      </template>
      <template v-else>
        <p>
          <label for="recovery_code">Recovery code</label>
          <input id="recovery_code" v-model="form.recovery_code" type="text" autocomplete="one-time-code" />
          <span v-if="form.errors.recovery_code">{{ form.errors.recovery_code }}</span>
        </p>
      </template>
      <button type="submit" :disabled="form.processing">Verify</button>
    </form>
    <button type="button" @click="recovery = !recovery">
      {{ recovery ? 'Use an authentication code' : 'Use a recovery code' }}
    </button>
  </main>
</template>
