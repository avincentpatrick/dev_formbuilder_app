<script setup lang="ts">
/**
 * The resume welcome-back banner (Increment H10, UX §5.2). Shown once when a session was restored from a resume
 * link: it confirms the answers came back and says where the respondent is, plus an optional reconciliation
 * note when the device's local draft won a newest-wins tie. Page-local by design — the shared design system has
 * no banner primitive, so this mirrors SummaryBanner.vue's role/focus/border-accent pattern (DSR §1.2
 * governance: don't add an Mds component for a one-off).
 *
 * role="status" (not alert — nothing is wrong) + focus-on-mount so a keyboard/screen-reader user lands on the
 * explanation before the fields, coinciding with the aria-live step announcement.
 *
 * Increment H21b, Doc #27 §5.2 — the "You're 64% complete" line is GONE. `completeness_percent` is coverage of
 * the AUTHORED form and stays relevance-unaware by decision (a reviewer's inbox column has to be comparable
 * between two rows). Under branching that makes the respondent-facing sentence simply false: someone on a short
 * branch is at step 3 of 3 and 31% coverage. The number stays on the tenant surfaces; what a respondent needs
 * on resume is where they are, and §5.3's explanation when that is not where they left off.
 */
import { computed, onMounted, ref } from 'vue';
import type { StepResolution } from '../composables/useFormRuntime';

const props = defineProps<{
    /** How the stored resume cursor resolved (§5.3); null when the session carried no stored step. */
    resolution: StepResolution | null;
    /** The step actually landed on, named so a drifted resume is explained rather than silently different. */
    stepTitle: string | null;
    note: string | null;
}>();

const rootEl = ref<HTMLElement | null>(null);

const drifted = computed(() => props.resolution === 'nearest' || props.resolution === 'first-incomplete');

onMounted(() => rootEl.value?.focus());
</script>

<template>
    <div ref="rootEl" class="welcome-back" role="status" tabindex="-1">
        <p class="welcome-back__lead">
            <span class="welcome-back__title">Welcome back</span>
            — we’ve restored your saved answers.
            <template v-if="drifted">
                The step you were on no longer applies to your answers, so we’ve taken you
                <template v-if="props.stepTitle">to “{{ props.stepTitle }}”</template>
                <template v-else>to the next step that does</template>.
            </template>
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
