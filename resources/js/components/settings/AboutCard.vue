<script setup lang="ts">
/**
 * Settings → About (Increment I5, PRD Feature #10) — what is deployed, for support and debugging.
 *
 * Rendered for EVERY member, not just Owner/Admin, and that is the point of the card: the person who
 * reports a problem is usually not an admin, and gating this would make "which version are you on?" the
 * one question they cannot answer.
 *
 * The values come from the SERVER ({@see \App\Support\Platform\BuildInfo}), not from the Vite
 * `__APP_VERSION__` define — that constant identifies the client BUNDLE (it is what gets stamped onto
 * `submissions.app_version`), which is a different fact from what is deployed and carries no timestamp.
 *
 * Absent values are rendered as an explicit phrase rather than as an empty row: a blank line beside
 * "Build date" reads as a broken panel, while "Development build" is true and legible.
 *
 * ⚠️ THE `.settings-*` RULES ARE RE-DECLARED AT THE BOTTOM — scoped CSS reaches a child SFC's root node only.
 */
import { computed } from 'vue';
import { MdsCard, MdsIcon } from '@meridian/design-system';

const props = defineProps<{
    about: { version: string; commit: string | null; built_at: string | null; environment: string };
}>();

const builtAt = computed(() => {
    if (props.about.built_at === null) return 'Development build';

    const parsed = new Date(props.about.built_at);

    return Number.isNaN(parsed.getTime())
        ? 'Development build'
        : parsed.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
});
</script>

<template>
    <MdsCard class="settings-card">
        <template #header>
            <div class="settings-card__head">
                <MdsIcon name="info" size="sm" aria-hidden="true" />
                <h2 class="settings-card__title">About</h2>
            </div>
        </template>

        <!-- A definition list, not a table: this is four label/value pairs, and a <table> would announce
             rows and columns that carry no meaning. -->
        <dl class="about__list">
            <div class="about__row">
                <dt class="about__term">Version</dt>
                <dd class="about__value">{{ about.version }}</dd>
            </div>
            <div v-if="about.commit" class="about__row">
                <dt class="about__term">Revision</dt>
                <dd class="about__value about__value--mono">{{ about.commit }}</dd>
            </div>
            <div class="about__row">
                <dt class="about__term">Build date</dt>
                <dd class="about__value">{{ builtAt }}</dd>
            </div>
            <div class="about__row">
                <dt class="about__term">Environment</dt>
                <dd class="about__value">{{ about.environment }}</dd>
            </div>
        </dl>

        <p class="about__hint">Quote the version and revision when you report a problem.</p>
    </MdsCard>
</template>

<style scoped>
.about__list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    margin: 0;
}

.about__row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2) var(--mds-space-4);
    justify-content: space-between;
}

.about__term {
    margin: 0;
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.about__value {
    margin: 0;
    color: var(--mds-color-text-secondary);
}

.about__value--mono {
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
}

.about__hint {
    margin: var(--mds-space-4) 0 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

/* Re-declared, not inherited — see the header note about scoped CSS and child SFCs. */
.settings-card {
    max-width: 640px;
}

.settings-card__head {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    color: var(--mds-color-text-secondary);
}

.settings-card__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}
</style>
