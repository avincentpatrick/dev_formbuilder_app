<script setup lang="ts">
/**
 * "Continue with Google" (Increment J3c2 — ADR-0019).
 *
 * ⚠️ THE LABEL IS "Continue with Google" AND MAY NEVER BECOME "Sign in with Google".
 * `tests/e2e/global-setup.ts` signs in TWICE before any spec in the suite runs, locating the submit
 * control with `getByRole('button', { name: 'Sign in' })` — NOT exact, so it matches on substring. A
 * second control whose accessible name merely CONTAINS "Sign in" is a strict-mode violation in global
 * setup, which is not a handful of failures but every spec in the suite with none executed.
 * `AuthLayout.vue`'s header records the same constraint for the marketing panel. "Continue with" is also
 * the honest verb: the same button registers and signs in, and the person cannot know in advance which.
 *
 * ⚠️ `as="a"`, NOT A BUTTON, which `MdsButton` was explicitly built for: *"an action that NAVIGATES —
 * especially to another origin, like an OAuth consent screen — has to be a link"*. It renders a real
 * anchor, so middle-click and open-in-new-tab work, and its ARIA role is `link` — which means it could
 * not collide with the `getByRole('button', …)` matcher above even if the label were wrong.
 *
 * ⚠️ NO CLIENT-SIDE `disabled` STATE AND NO `router` CALL. The server decides whether this renders at
 * all (`canUseGoogle`, from `GoogleSignInGate` — the same object the redirect route asks), so there is
 * no in-between state to express. A plain `href` also means the flow survives a hydration failure,
 * which matters on the one page a person reaches when nothing else is working.
 *
 * This component owns its own styles because `Login.vue` and `Register.vue` deliberately have NONE —
 * every class they use belongs to `AuthLayout.vue`'s unscoped shell block, and that file is not edited
 * by this increment (its lack of `overflow-x: clip` is load-bearing for the e2e overflow assertion).
 */
import { MdsButton } from '@meridian/design-system';
import GoogleMark from './GoogleMark.vue';
</script>

<template>
  <div class="auth-social">
    <!-- `aria-hidden` on the rule: it is a visual separator, and a screen reader announcing "or"
         between two controls it already reads in order adds nothing. -->
    <p class="auth-social__divider" aria-hidden="true"><span>or</span></p>

    <MdsButton as="a" href="/auth/google/redirect" variant="secondary">
      <GoogleMark />
      Continue with Google
    </MdsButton>
  </div>
</template>

<style scoped>
.auth-social {
  display: flex;
  flex-direction: column;
  gap: var(--mds-space-4);
}

/* A centred label sitting on a rule, drawn with two flex-filled borders rather than a background
   gradient so it inherits the theme's border token in both modes with no second definition. */
.auth-social__divider {
  display: flex;
  align-items: center;
  gap: var(--mds-space-3);
  margin: 0;
  color: var(--mds-color-text-secondary);
  font-size: var(--mds-type-body-md-font-size);
  line-height: var(--mds-type-body-md-line-height);
}

.auth-social__divider::before,
.auth-social__divider::after {
  content: '';
  flex: 1 1 0;
  border-top: 1px solid var(--mds-color-border-default);
}
</style>
