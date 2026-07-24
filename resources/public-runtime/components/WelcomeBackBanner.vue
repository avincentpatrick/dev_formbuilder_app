<script setup lang="ts">
/**
 * The resume welcome-back banner (Increment H10, UX §5.2). Shown once when a session was restored from a resume
 * link: it confirms the answers came back and reports progress ("you're 64% complete"), plus an optional
 * reconciliation note when the device's local draft won a newest-wins tie. Page-local by design — the shared
 * design system has no banner primitive, so this mirrors SummaryBanner.vue's role/focus/border-accent pattern
 * (DSR §1.2 governance: don't add an Mds component for a one-off).
 *
 * role="status" (not alert — nothing is wrong) + focus-on-mount so a keyboard/screen-reader user lands on the
 * explanation before the fields, coinciding with the aria-live step announcement.
 */
import { onMounted, ref } from 'vue';

const props = defineProps<{ completeness: number | null; note: string | null }>();

const rootEl = ref<HTMLElement | null>(null);

onMounted(() => rootEl.value?.focus());
</script>

<template>
    <div ref="rootEl" class="welcome-back" role="status" tabindex="-1">
        <p class="welcome-back__lead">
            <span class="welcome-back__title">Welcome back</span>
            — we’ve restored your saved answers.
            <template v-if="props.completeness !== null"> You’re {{ props.completeness }}% complete.</template>
        </p>
        <p v-if="props.note" class="welcome-back__note">{{ props.note }}</p>
    </div>
</template>

<style scoped>
.welcome-back {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    padding: var(--mds-space-4);
    border: 1px solid var(--mds-color-action-primary-bg);
    border-left-width: 4px;
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

.welcome-back:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.welcome-back__lead {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

.welcome-back__title {
    font-weight: var(--mds-font-weight-semibold);
}

.welcome-back__note {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size, var(--mds-type-caption-font-size));
    color: var(--mds-color-text-secondary);
}
</style>
