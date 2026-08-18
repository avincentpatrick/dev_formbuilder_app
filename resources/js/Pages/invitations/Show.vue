<script setup lang="ts">
// Design-system-styled invitation accept/decline page (Increment C1).
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
  MdsButton,
  MdsFormField,
  MdsTextInput,
  MdsPasswordInput,
  MdsPasswordStrength,
  describedByWithStrength,
  type PasswordRequirement,
} from '@meridian/design-system';
import AuthLayout from '@/Layouts/AuthLayout.vue';

const props = defineProps<{
  tenantName: string;
  email: string | null;
  needsRegistration: boolean;
  token: string;
  passwordPolicy: PasswordRequirement[];
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

    <!-- J3b: `MdsBanner` replaces the page-owned `.auth-alert--error`, whose ONLY consumer this was.
         `tone="danger"` rather than the old transparent-with-a-red-border treatment, and the icon is
         required by the component precisely so colour is never the only channel (WCAG 1.4.1). It stays
         `role="status"` — the component's deliberate choice — which is right here: this condition was
         already true when the page loaded rather than being an error the user just caused. -->
    <MdsBanner v-if="membershipError" tone="danger" icon="alert" :message="String(membershipError)" />

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
            :describedby="describedByWithStrength(id, describedby)"
            :invalid="invalid"
          />
          <MdsPasswordStrength
            :input-id="id"
            :password="form.password"
            :requirements="props.passwordPolicy"
          />
        </MdsFormField>
      </template>

      <MdsButton type="submit" :loading="form.processing">Accept invitation</MdsButton>
    </form>

    <MdsButton variant="tertiary" size="sm" @click="decline">Decline</MdsButton>
  </AuthLayout>
</template>
