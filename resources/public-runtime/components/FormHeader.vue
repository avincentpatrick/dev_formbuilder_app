<script setup lang="ts">
/**
 * The form's banner: title (h1), the language switcher (UX §6, hidden for single-locale forms), the form's reference
 * files (M132), and the ambient autosave indicator (UX §5.1).
 */
import LanguageSwitcher from './LanguageSwitcher.vue';
import ReferenceFileList from './ReferenceFileList.vue';
import SavedIndicator from './SavedIndicator.vue';
import type { GuestReferenceFile } from '../lib/types';

defineProps<{
    title: string;
    description: string | null;
    saving: boolean;
    savedAt: string | null;
    /** M132 (`R-bf49e4c1`) — the files the version shows, under the description on every page. */
    referenceFiles?: GuestReferenceFile[];
    shareToken?: () => string;
}>();
</script>

<template>
    <header class="form-header">
        <div class="form-header__row">
            <h1 class="form-header__title">{{ title }}</h1>
            <LanguageSwitcher />
        </div>
        <p v-if="description" class="form-header__desc">{{ description }}</p>
        <ReferenceFileList v-if="referenceFiles && referenceFiles.length > 0 && shareToken" :files="referenceFiles" :share-token="shareToken" />
        <SavedIndicator :saving="saving" :saved-at="savedAt" />
    </header>
</template>

<style scoped>
.form-header {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    padding-bottom: var(--mds-space-4);
    border-bottom: 1px solid var(--mds-color-border-default);
}

.form-header__row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--mds-space-4);
    flex-wrap: wrap;
}

.form-header__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-1-font-size);
    line-height: var(--mds-type-heading-1-line-height);
    font-weight: var(--mds-type-heading-1-font-weight);
    letter-spacing: var(--mds-type-heading-1-letter-spacing);
    color: var(--mds-color-text-heading);
}

.form-header__desc {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-secondary);
}
</style>
