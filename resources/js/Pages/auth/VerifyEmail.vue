<script setup lang="ts">
// Design-system-styled email-verification page (Increment C1; the correction form is J3a).
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsBanner, MdsButton, MdsFormField, MdsTextInput } from '@meridian/design-system';
import AuthLayout from '@/Layouts/AuthLayout.vue';

const props = defineProps<{
  status?: string;
  /** The signed-in user's current values, so the correction form can seed itself. */
  name?: string;
  email?: string;
}>();

const resendForm = useForm({});

function resend(): void {
  resendForm.post('/email/verification-notification');
}

/**
 * ⚠️ THIS FORM IS THE ONLY WAY OUT OF A LOCKOUT J3a WOULD OTHERWISE CREATE, AND IT IS NOT A CONVENIENCE.
 *
 * `UpdateUserProfileInformation` nulls `email_verified_at` whenever the address changes — correct, since a
 * new address is unproven — and J3a mounts `verified` on the authenticated tenant group. So a member who
 * mistypes their own email in Settings is bounced to THIS page on their next page load, and `/settings`,
 * the only other surface with an email field, is now behind the gate they just fell behind. The remaining
 * action would be "resend", which resends to the typo forever. There is no support path and no self-serve
 * path: the account is simply gone.
 *
 * `PUT /user/profile-information` is a Fortify route carrying only `auth`, so it is still reachable from
 * here. `name` is submitted UNCHANGED because Fortify's action validates and rewrites both fields together;
 * omitting it would fail validation, and sending a blank would erase the person's name as the price of
 * fixing a typo.
 */
const correctForm = useForm({
  name: props.name ?? '',
  email: props.email ?? '',
});

const correcting = ref(false);

function correct(): void {
  // The `updateProfileInformation` error bag is Fortify's, named by its own `validateWithBag()` call.
  correctForm.put('/user/profile-information', { errorBag: 'updateProfileInformation', preserveScroll: true });
}
</script>

<template>
  <AuthLayout title="Verify your email">
    <p class="auth-note">
      Please confirm your email address by clicking the link we just sent
      <template v-if="props.email">to <strong>{{ props.email }}</strong></template>
      <template v-else>you</template>.
    </p>

    <MdsBanner
      v-if="props.status === 'verification-link-sent'"
      icon="mail"
      message="A new verification link has been sent to your email address."
    />

    <form class="auth-form" @submit.prevent="resend">
      <MdsButton type="submit" :loading="resendForm.processing">Resend verification email</MdsButton>
    </form>

    <!-- Progressive disclosure (DSR §5): the common case is "I'll go click the link", so the correction
         path is one click away rather than a second form competing with the primary action. -->
    <nav v-if="!correcting" class="auth-links">
      <a href="#" role="button" @click.prevent="correcting = true">Wrong email address?</a>
    </nav>

    <form v-else class="auth-form" @submit.prevent="correct">
      <p class="auth-note">
        Enter the correct address and we'll send a new link there instead.
      </p>

      <MdsFormField
        label="Email"
        required
        :error="correctForm.errors.email"
        v-slot="{ id, describedby, invalid }"
      >
        <MdsTextInput
          :id="id"
          v-model="correctForm.email"
          type="email"
          autocomplete="email"
          :describedby="describedby"
          :invalid="invalid"
        />
      </MdsFormField>

      <MdsButton type="submit" :loading="correctForm.processing">Update and resend</MdsButton>
    </form>

    <form method="POST" action="/logout">
      <MdsButton type="submit" variant="tertiary" size="sm">Log out</MdsButton>
    </form>
  </AuthLayout>
</template>
