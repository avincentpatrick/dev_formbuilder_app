<script setup lang="ts">
// Design-system-styled password-confirmation (secure-area) page (Increment C1).
import { useForm } from '@inertiajs/vue3';
import { MdsAlert, MdsButton, MdsFormField, MdsPasswordInput } from '@meridian/design-system';
import AuthLayout from '@/Layouts/AuthLayout.vue';

// The write this gate just threw away, if it threw one away (M99, R-43bdc36b). Every route behind
// `step-up` is a non-GET, and `Redirector::guest` records `previous()` rather than the request for
// those — so the body is gone by the time anyone reaches this page. Saying nothing let a workspace
// Owner press Save, confirm a password, land back on the page they came from, and read their own
// unchanged data as though the change had been made.
//
// ⚠️ A PROP RATHER THAN A TOAST, because `AuthLayout` mounts no `MdsToastHost` — a toast raised for
// this page would render nothing at all.
defineProps<{ discardedWrite?: { method: string; url: string } | null }>();

const form = useForm({ password: '' });

function submit(): void {
  form.post('/user/confirm-password', { onFinish: () => form.reset() });
}
</script>

<template>
  <AuthLayout title="Confirm password">
    <!--
      `assertive` is correct here and is not the default: this announces an EVENT that just happened —
      a change was discarded — rather than a condition that was already true when the page loaded.
      DSR §3.7a draws exactly that line between Alert and Banner.
    -->
    <MdsAlert
      v-if="discardedWrite"
      tone="warning"
      assertive
      title="Your change was not saved"
      message="This area asks you to confirm your password first, so the change you submitted was
        discarded. Confirm below, then make it again."
    />

    <p class="auth-note">This is a secure area. Please confirm your password before continuing.</p>

    <form class="auth-form" @submit.prevent="submit">
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

      <MdsButton type="submit" :loading="form.processing">Confirm</MdsButton>
    </form>
  </AuthLayout>
</template>
