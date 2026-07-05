<script setup lang="ts">
// Unstyled Increment-B2b invitation accept/decline page (styled in Increment C).
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
  tenantName: string;
  email: string | null;
  needsRegistration: boolean;
  token: string;
}>();

const form = useForm({ name: '', password: '' });

// Business-rule failures (e.g. an expired invite) come back as a shared `membership` session error,
// not a form-field error.
const page = usePage<{ errors: Record<string, string> }>();
const membershipError = computed(() => page.props.errors?.membership);

function accept(): void {
  form.post(`/invitations/${props.token}`, { onFinish: () => form.reset('password') });
}

function decline(): void {
  useForm({}).delete(`/invitations/${props.token}`);
}
</script>

<template>
  <main>
    <h1>Join {{ props.tenantName }}</h1>
    <p v-if="props.email">Invitation for {{ props.email }}</p>

    <form @submit.prevent="accept">
      <template v-if="props.needsRegistration">
        <p>
          <label for="name">Your name</label>
          <input id="name" v-model="form.name" type="text" autocomplete="name" required />
          <span v-if="form.errors.name">{{ form.errors.name }}</span>
        </p>
        <p>
          <label for="password">Choose a password</label>
          <input id="password" v-model="form.password" type="password" autocomplete="new-password" required />
          <span v-if="form.errors.password">{{ form.errors.password }}</span>
        </p>
      </template>
      <span v-if="membershipError">{{ membershipError }}</span>
      <button type="submit" :disabled="form.processing">Accept invitation</button>
    </form>

    <button type="button" @click="decline">Decline</button>
  </main>
</template>
