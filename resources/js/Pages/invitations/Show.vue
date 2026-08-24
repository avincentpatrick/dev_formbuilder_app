<script setup lang="ts">
// Design-system-styled invitation accept/decline page (Increment C1).
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
  MdsBanner,
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
  isUnusedPlaceholder: boolean;
  signedInAs: string | null;
  token: string;
  passwordPolicy: PasswordRequirement[];
}>();

const form = useForm({ name: '', password: '' });

// Business-rule failures (e.g. an expired invite) come back as a shared `membership` session error,
// not a form-field error.
const page = usePage();
const membershipError = computed(() => page.props.errors?.membership);

// ⚠️ `isUnusedPlaceholder` ANSWERS "HAS THIS IDENTITY EVER BEEN USED", NEVER "ARE YOU THE INVITEE" — and
// the server enforces both. `InvitationController::accept()` additionally requires an established identity
// to be signed in AS THEMSELVES, so a visitor signed in as somebody else got an Accept button whose POST
// always 403s; since M9 the Decline button does the same. The page could not see that second condition
// until `signedInAs` was published, which is why it kept offering an act it could not deliver.
const wrongAccount = computed(
  () => props.signedInAs !== null && props.signedInAs !== props.email,
);

// An established identity who is NOT signed in is handed off to /login and returned here, so the button
// still works — it just does something other than what it says. Naming the hop is the whole fix.
const acceptLabel = computed(() =>
  !props.isUnusedPlaceholder && props.signedInAs === null
    ? 'Sign in to accept'
    : 'Accept invitation',
);

function accept(): void {
  form.post(`/invitations/${props.token}`, { onFinish: () => form.reset('password') });
}

function decline(): void {
  useForm({}).delete(`/invitations/${props.token}`);
}

function signOut(): void {
  useForm({}).post('/logout');
}
</script>

<template>
  <AuthLayout :title="`Join ${props.tenantName}`">
    <p v-if="props.email" class="auth-note">Invitation for {{ props.email }}</p>

    <!-- J3b: `MdsBanner` replaces the page-owned `.auth-alert--error`, whose ONLY consumer this was.
         `tone="danger"` rather than the old transparent-with-a-red-border treatment, and the icon is
         required by the component precisely so colour is never the only channel (WCAG 1.4.1). It stays
         `role="status"` — the component's deliberate choice — which is right here: this condition was
         already true when the page loaded rather than being an error the user just caused.

         ⚠️ M9: THIS BANNER RENDERED NOTHING AT ALL BETWEEN J3b AND HERE. `MdsBanner` was used without
         being imported, and `resources/js/app.ts` registers no components globally, so Vue resolved it to
         nothing. Measured rather than assumed: with the import deleted again, BOTH `vue-tsc --noEmit` and
         `vite build` still exit 0, so no gate in this repository could ever have seen it. Every other
         consumer in the tree imports it, which is why this was the only page it happened on. -->
    <MdsBanner v-if="membershipError" tone="danger" icon="alert" :message="String(membershipError)" />

    <template v-if="wrongAccount">
      <MdsBanner
        tone="warning"
        icon="user"
        :message="`You’re signed in as ${props.signedInAs}. Sign out and sign in as ${props.email} to accept or decline this invitation.`"
      />

      <!-- Not a dead end: `POST /logout` is a Fortify route in its own group, reachable from here for the
           same reason the two-factor interstitial can reach it. Without this the page states a condition
           the visitor has no way to resolve. -->
      <form class="auth-form" @submit.prevent="signOut">
        <MdsButton type="submit" variant="secondary">Sign out</MdsButton>
      </form>
    </template>

    <template v-else>
      <form class="auth-form" @submit.prevent="accept">
        <template v-if="props.isUnusedPlaceholder">
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

        <MdsButton type="submit" :loading="form.processing">{{ acceptLabel }}</MdsButton>
      </form>

      <MdsButton variant="tertiary" size="sm" @click="decline">Decline</MdsButton>
    </template>
  </AuthLayout>
</template>
