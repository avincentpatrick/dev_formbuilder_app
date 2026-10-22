<script setup lang="ts">
/**
 * One release's notes — M181 (`R-c7247dbf`). Shared by the What's new page and the prompt, so the two cannot describe
 * a release two ways: its title, its version and the day it went live, then each change with a New / Improved / Fixed
 * badge (the one map, `statusVariant()`, DSR §3.8) and its words.
 *
 * ⚠️ PLAIN TEXT, NEVER `v-html`. A note is written in a JSON file by hand; a tag in it is shown as typed
 * (`raw-html-sink-census.test.ts` allows one sink in the whole app, and this is not it).
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { MdsBadge, statusVariant } from '@meridian/design-system';
import type { WhatsNewRelease } from '@/types/inertia';

const props = withDefaults(defineProps<{ release: WhatsNewRelease; headingLevel?: 2 | 3 }>(), { headingLevel: 2 });

const headingTag = computed(() => `h${props.headingLevel}`);

// The day it went live, in the reader's own locale and zone — a date, not a time: nobody acts on the minute.
const wentLive = computed(() =>
    new Intl.DateTimeFormat(undefined, { dateStyle: 'long' }).format(new Date(props.release.released_at)),
);
</script>

<template>
    <section class="release">
        <component :is="headingTag" class="release__title">{{ release.title }}</component>
        <p class="release__meta">
            Version {{ release.version }} · <time :datetime="release.released_at">{{ wentLive }}</time>
        </p>
        <ul class="release__items" role="list">
            <li v-for="(item, index) in release.items" :key="index" class="release__item">
                <MdsBadge :variant="statusVariant(item.kind).variant" :label="statusVariant(item.kind).label" />
                <span class="release__text">
                    <Link v-if="item.href" :href="item.href" class="release__link">{{ item.text }}</Link>
                    <template v-else>{{ item.text }}</template>
                </span>
            </li>
        </ul>
    </section>
</template>

<style scoped>
.release {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

.release__title {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.release__meta {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

/* A reading measure for the words (M173's rule: text keeps one, left-aligned; the page around it fills the width). */
.release__items {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    max-inline-size: 80ch;
    margin: var(--mds-space-2) 0 0;
    padding: 0;
    list-style: none;
}

.release__item {
    display: flex;
    align-items: baseline;
    gap: var(--mds-space-3);
}

/* The badges line up in one column, so the words start at one edge whatever the kind is called. */
.release__item > :first-child {
    flex: none;
    min-inline-size: 6.5rem;
    justify-content: center;
}

.release__text {
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

.release__link {
    color: var(--mds-color-action-primary-fg);
}

.release__link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}
</style>
