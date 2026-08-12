<script setup lang="ts">
/**
 * Slim centered auth-card layout for pre-auth guest surfaces (sign in, register, password
 * reset, 2FA challenge, invitation accept). A documented member of the Public/Guest Runtime
 * Shell family — NOT a third top-level shell (design-system-reference.md §3.0; see
 * docs/ux/exceptions-log.md #1). Composes design-system tokens only.
 */
import { Head } from '@inertiajs/vue3';

defineProps<{ title: string }>();
</script>

<template>
    <Head :title="title" />
    <div class="auth">
        <main class="auth__card">
            <p class="auth__brand">Meridian</p>
            <h1 class="auth__title">{{ title }}</h1>
            <div class="auth__body">
                <slot />
            </div>
        </main>
    </div>
</template>

<style scoped>
.auth {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    min-height: 100dvh;
    padding: var(--mds-space-6);
    background-color: var(--mds-color-bg-canvas);
}

.auth__card {
    width: 100%;
    max-width: 400px;
    padding: var(--mds-space-6);
    background-color: var(--mds-color-bg-surface);
    border: 1px solid var(--mds-color-border-default);
    /* JR2: the page-level card tier (DSR §2.6). This card is the entire visual weight of the sign-in
       screen and it is the FIRST surface a new user sees, so it is the last one that should still be
       speaking the old language. `.auth-alert` below stays at `md` — it sits inside this card. */
    border-radius: var(--mds-radius-xl);
    box-shadow: var(--mds-shadow-2);
}

.auth__brand {
    margin: 0 0 var(--mds-space-2);
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    font-weight: var(--mds-font-weight-bold);
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--mds-color-action-primary-fg);
}

.auth__title {
    margin: 0 0 var(--mds-space-6);
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-2-font-size);
    line-height: var(--mds-type-heading-2-line-height);
    font-weight: var(--mds-font-weight-semibold);
    text-transform: uppercase;
    letter-spacing: 0.02em;
    color: var(--mds-color-text-heading);
}

/* Spaces the top-level blocks a page drops into the slot (form, links, notices).
   Flex `gap` reaches slotted children directly — no :slotted() needed. */
.auth__body {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}
</style>

<!-- Guest-shell layout helpers, owned here (not per page) so every auth screen is consistent —
     these belong to the auth shell, not to any one page. Token-only; text links become a shared
     design-system component in C2, at which point .auth-links is replaced. -->
<style>
.auth-form {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.auth-note {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
}

.auth-links {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-4);
    font-size: var(--mds-type-body-md-font-size);
}

.auth-links a {
    color: var(--mds-color-action-primary-fg);
    font-weight: var(--mds-font-weight-medium);
    text-decoration: none;
}

.auth-links a:hover {
    text-decoration: underline;
}

.auth-remember {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    font-size: var(--mds-type-body-md-font-size);
    color: var(--mds-color-text-body);
    cursor: pointer;
}

.auth-remember input {
    width: 16px;
    height: 16px;
    accent-color: var(--mds-color-action-primary-bg);
}

.auth-alert {
    margin: 0;
    padding: var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
}

.auth-alert--error {
    background-color: transparent;
    border: 1px solid var(--mds-color-action-danger-bg);
    color: var(--mds-color-danger-text);
}
</style>
