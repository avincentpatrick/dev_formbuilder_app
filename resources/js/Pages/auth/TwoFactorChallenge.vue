<script setup lang="ts">
// Design-system-styled two-factor challenge page (Increment C1).
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsTextInput } from '@meridian/design-system';
import AuthLayout from '@/Layouts/AuthLayout.vue';

const recovery = ref(false);
const form = useForm({ code: '', recovery_code: '' });

function submit(): void {
  form.post('/two-factor-challenge');
}
</script>

<template>
  <AuthLayout title="Two-factor authentication">
    <form class="auth-form" @submit.prevent="submit">
      <MdsFormField
        v-if="!recovery"
        label="Authentication code"
        help="Enter the 6-digit code from your authenticator app."
        :error="form.errors.code"
        v-slot="{ id, describedby, invalid }"
      >
        <MdsTextInput
          :id="id"
          v-model="form.code"
          type="text"
          inputmode="numeric"
          autocomplete="one-time-code"
          :describedby="describedby"
          :invalid="invalid"
        />
      </MdsFormField>

      <MdsFormField
        v-else
        label="Recovery code"
        help="Enter one of your saved recovery codes."
        :error="form.errors.recovery_code"
        v-slot="{ id, describedby, invalid }"
      >
        <MdsTextInput
          :id="id"
          v-model="form.recovery_code"
          type="text"
          autocomplete="one-time-code"
          :describedby="describedby"
          :invalid="invalid"
        />
      </MdsFormField>

      <MdsButton type="submit" :loading="form.processing">Verify</MdsButton>
    </form>

    <MdsButton variant="tertiary" size="sm" @click="recovery = !recovery">
      {{ recovery ? 'Use an authentication code' : 'Use a recovery code' }}
    </MdsButton>
  </AuthLayout>
</template>
