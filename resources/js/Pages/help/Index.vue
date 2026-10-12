<script setup lang="ts">
/**
 * The in-app user manual (M162, `R-f9211765`, `D114`) — how to create a form, from a blank page to an exported
 * response, for the people testing the app and everyone after them.
 *
 * One long page with a contents list rather than tabs or a page per chapter: a first-time reader scrolls and
 * searches it with the browser's own find, every chapter has an address (`/help#publish`) another page or a message
 * can link to, and it prints as one document. The text is `manual.ts`; this file only lays it out.
 *
 * Headings run h1 (the page header) → h2 (a chapter) → h3 (a section), with no level skipped, so a screen reader's
 * heading list is the contents list. The contents list itself is h2 "Contents" → h3 per part.
 *
 * M163 (`D115`) added a second part, "Builder reference" — every question type, its settings, and the builder's left
 * and right panels — after the user read the first and asked for exactly that. The contents list is grouped by part,
 * and each chapter card names its part above its title.
 */
import { Head } from '@inertiajs/vue3';
import { MdsCard } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import ManualBlock from '@/components/help/ManualBlock.vue';
import { MANUAL_PARTS, manual } from '@/components/help/manual';

const parts = MANUAL_PARTS.map((part) => ({ ...part, chapters: manual.filter((chapter) => chapter.part === part.key) }));
const partTitle = Object.fromEntries(MANUAL_PARTS.map((part) => [part.key, part.title]));
</script>

<template>
    <Head title="Help" />
    <div class="manual">
        <PageHeader title="Help" icon="help" />

        <p class="manual__intro">
            User manual for the form builder, in two parts. <em>Step by step</em> walks you through creating a form
            from start to finish; <em>Builder reference</em> explains every question type, its settings, and the
            builder's left and right panels. Every button this manual names is written in
            <strong class="manual__intro-ui">bold</strong>, exactly as it appears on your screen.
        </p>

        <nav class="manual__contents" aria-labelledby="manual-contents-heading">
            <h2 id="manual-contents-heading" class="manual__contents-title">Contents</h2>
            <div v-for="part in parts" :key="part.key" class="manual__contents-group">
                <h3 class="manual__contents-group-title">{{ part.title }}</h3>
                <ol class="manual__contents-list">
                    <li v-for="chapter in part.chapters" :key="chapter.id">
                        <a class="manual__contents-link" :href="`#${chapter.id}`">{{ chapter.title }}</a>
                        <span class="manual__contents-summary">{{ chapter.summary }}</span>
                    </li>
                </ol>
            </div>
        </nav>

        <section
            v-for="chapter in manual"
            :id="chapter.id"
            :key="chapter.id"
            class="manual__chapter"
            :aria-labelledby="`${chapter.id}-heading`"
        >
            <MdsCard>
                <p class="manual__chapter-part">{{ partTitle[chapter.part] }}</p>
                <h2 :id="`${chapter.id}-heading`" class="manual__chapter-title">{{ chapter.title }}</h2>
                <p class="manual__chapter-summary">{{ chapter.summary }}</p>
                <div v-for="section in chapter.sections" :id="section.id" :key="section.id" class="manual__section">
                    <h3 class="manual__section-title">{{ section.title }}</h3>
                    <ManualBlock v-for="(block, i) in section.blocks" :key="i" :block="block" />
                </div>
            </MdsCard>
        </section>
    </div>
</template>

<style scoped>
.manual {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-6);
}

.manual__intro {
    max-width: 72ch;
    margin: calc(var(--mds-space-2) * -1) 0 0;
    color: var(--mds-color-text-secondary);
}

.manual__intro-ui {
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.manual__contents {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    padding: var(--mds-space-4);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-lg);
    background-color: var(--mds-color-bg-surface);
}

.manual__contents-title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-4-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.manual__contents-group {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

.manual__contents-group-title,
.manual__chapter-part {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    font-weight: var(--mds-font-weight-semibold);
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--mds-color-text-secondary);
}

.manual__chapter-part {
    margin-bottom: var(--mds-space-1);
}

.manual__contents-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr));
    gap: var(--mds-space-3) var(--mds-space-6);
    margin: 0;
    padding-inline-start: var(--mds-space-6);
}

.manual__contents-list li {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    min-width: 0;
}

.manual__contents-link {
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-action-primary-fg);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.manual__contents-summary {
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

/* The contents links jump here; the margin keeps a chapter's heading clear of the top of the viewport. */
.manual__chapter,
.manual__section {
    scroll-margin-top: var(--mds-space-6);
}

.manual__chapter-title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.manual__chapter-summary {
    max-width: 72ch;
    margin: var(--mds-space-1) 0 0;
    color: var(--mds-color-text-secondary);
}

.manual__section {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    margin-top: var(--mds-space-6);
}

.manual__section-title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-4-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}
</style>
