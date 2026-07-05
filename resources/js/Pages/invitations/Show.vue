<script setup lang="ts">
// Design-system-styled invitation accept/decline page (Increment C1).
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { MdsButton, MdsFormField, MdsTextInput, MdsPasswordInput } from '@meridian/design-system';
import AuthLayout from '@/Layouts/AuthLayout.vue';

const props = defineProps<{
  tenantName: string;
  email: string | null;
  needsRegistration: boolean;
  token: string;
}>();

const form = useForm({ name: '', password: '' });

// Business-rule failures (e.g. an expired invite) come back as a shared `membership` session error,
// not a form-field error.
const page = usePage();
const membershipError = computed(() => page.props.errors?.membership);

function accept(): void {
  form.post(`/invitations/${props.token}`, { onFinish: () => form.reset('password') });
}

function decline(): void {
  useForm({}).delete(`/invitations/${props.token}`);
}
</script>

<template>
  <AuthLayout :title="`Join ${props.tenantName}`">
    <p v-if="props.email" class="auth-note">Invitation for {{ props.email }}</p>

    <p v-if="membershipError" class="auth-alert auth-alert--error">{{ membershipError }}</p>

    <form class="auth-form" @submit.prevent="accept">
      <template v-if="props.needsRegistration">
        <MdsFormField label="Your name" :error="form.errors.name" v-slot="{ id, describedby, invalid }">
          <MdsTextInput
            :id="id"
            v-model="form.name"
            type="text"
            autocomplete="name"
            :describedby="describedby"
            :invalid="invalid"
          />
        </MdsFormField>

        <MdsFormField
          label="Choose a password"
          :error="form.errors.password"
          v-slot="{ id, describedby, invalid }"
        >
          <MdsPasswordInput
            :id="id"
            v-model="form.password"
            autocomplete="new-password"
            :describedby="describedby"
            :invalid="invalid"
          />
        </MdsFormField>
      </template>

      <MdsButton type="submit" :loading="form.processing">Accept invitation</MdsButton>
    </form>

    <MdsButton variant="tertiary" size="sm" @click="decline">Decline</MdsButton>
  </AuthLayout>
</template>
