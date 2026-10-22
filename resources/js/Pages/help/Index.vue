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
 *
 * ── M164 — THE CONTENTS STAY WITHIN REACH ─────────────────────────────────────────────────────────────────────────
 * With twenty-three chapters the user found getting back to the contents hard (*"is there another way that contents
 * are always seen? can we have the contents on the left side?"*). So:
 *   · from 1024px wide, the contents are a column on the left that stays on screen (`position: sticky` inside the
 *     shell's own scroll region, `.app-shell__content`) and scrolls on its own; the chapter being read is marked
 *     `aria-current="location"` and drawn as the current one;
 *   · below that — where the app sidebar is already icons only and a second column would squeeze the text — the list
 *     stays on top, a fixed "Contents" button jumps back to it, and each chapter ends with "Back to contents".
 * The current chapter is read from the page on every scroll (one read per frame): the last chapter whose top has
 * passed 40% of the screen's height — or the last chapter of all once it is wholly on screen, because a short closing
 * chapter at the foot of the page can never reach that line. An `IntersectionObserver` was the first draft and a real
 * browser proved it wrong for exactly that chapter. The shell scrolls `.app-shell__content`, not the window, so the
 * listener is a capturing one on `document`, which hears a scroll from any element.
 */
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { MdsCard, MdsIcon } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import ManualBlock from '@/components/help/ManualBlock.vue';
import { MANUAL_PARTS, manual } from '@/components/help/manual';

const parts = MANUAL_PARTS.map((part) => ({ ...part, chapters: manual.filter((chapter) => chapter.part === part.key) }));
const partTitle = Object.fromEntries(MANUAL_PARTS.map((part) => [part.key, part.title]));

const current = ref<string | null>(null);

/** The chapter being read, from where the chapters are on screen now. */
function readCurrent(): string | null {
    const line = window.innerHeight * 0.4;
    let found: string | null = null;
    for (const chapter of manual) {
        const box = document.getElementById(chapter.id)?.getBoundingClientRect();
        if (box && box.top <= line) found = chapter.id;
    }
    const last = manual[manual.length - 1];
    const lastBox = document.getElementById(last.id)?.getBoundingClientRect();
    if (lastBox && lastBox.top < window.innerHeight && lastBox.bottom <= window.innerHeight) found = last.id;
    return found;
}

let frame: number | null = null;
function onScroll(): void {
    if (frame !== null) return;
    frame = requestAnimationFrame(() => {
        frame = null;
        current.value = readCurrent();
    });
}

/**
 * The column is taller than a laptop screen and scrolls on its own, so the marked chapter can sit below its fold —
 * measured: at 1440×900 "Choice questions" was half hidden. Bring it to the middle of the column when it leaves the
 * column's view. Only the column's own `scrollTop` moves, never the page — `scrollIntoView` would scroll every
 * scrollable ancestor too, and yank the chapter the reader is reading. Below 1024px the list does not scroll, and
 * nothing happens.
 */
const contentsNav = ref<HTMLElement | null>(null);
watch(current, async (id) => {
    await nextTick();
    const nav = contentsNav.value;
    if (nav === null || id === null || nav.scrollHeight <= nav.clientHeight) return;
    const link = nav.querySelector<HTMLElement>(`a[href="#${id}"]`);
    if (link === null) return;
    const navBox = nav.getBoundingClientRect();
    const linkBox = link.getBoundingClientRect();
    if (linkBox.top >= navBox.top && linkBox.bottom <= navBox.bottom) return;
    nav.scrollTop += linkBox.top - navBox.top - (navBox.height - linkBox.height) / 2;
});

onMounted(() => {
    current.value = readCurrent();
    document.addEventListener('scroll', onScroll, { capture: true, passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
});

onBeforeUnmount(() => {
    document.removeEventListener('scroll', onScroll, { capture: true });
    window.removeEventListener('resize', onScroll);
    if (frame !== null) cancelAnimationFrame(frame);
});
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

        <div class="manual__layout">
            <nav id="manual-contents" ref="contentsNav" class="manual__contents" aria-labelledby="manual-contents-heading">
                <h2 id="manual-contents-heading" class="manual__contents-title">Contents</h2>
                <div v-for="part in parts" :key="part.key" class="manual__contents-group">
                    <h3 class="manual__contents-group-title">{{ part.title }}</h3>
                    <ol class="manual__contents-list">
                        <li v-for="chapter in part.chapters" :key="chapter.id">
                            <a
                                class="manual__contents-link"
                                :class="{ 'manual__contents-link--current': current === chapter.id }"
                                :href="`#${chapter.id}`"
                                :aria-current="current === chapter.id ? 'location' : undefined"
                            >{{ chapter.title }}</a>
                            <span class="manual__contents-summary">{{ chapter.summary }}</span>
                        </li>
                    </ol>
                </div>
            </nav>

            <div class="manual__chapters">
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
                        <p class="manual__back"><a class="manual__back-link" href="#manual-contents">Back to contents</a></p>
                    </MdsCard>
                </section>
            </div>
        </div>

        <a class="manual__to-contents" href="#manual-contents">
            <MdsIcon name="chevron-up" size="sm" />
            <span>Contents</span>
        </a>
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

/* Narrow first: the contents above the chapters. */
.manual__layout {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-6);
    min-width: 0;
}

.manual__chapters {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-6);
    min-width: 0;
}

.manual__contents {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    padding: var(--mds-space-4);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-lg);
    background-color: var(--mds-color-bg-surface);
    scroll-margin-top: var(--mds-space-6);
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
    letter-spacing: var(--mds-tracking-wide);
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

/* The chapter being read: heavier and un-underlined, plus a bar — never colour alone (WCAG 1.4.1). */
.manual__contents-link--current {
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
    text-decoration: none;
    box-shadow: inset 3px 0 0 var(--mds-color-action-primary-bg);
    padding-inline-start: var(--mds-space-2);
    margin-inline-start: calc(var(--mds-space-2) * -1);
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

.manual__back {
    margin: var(--mds-space-6) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.manual__back-link {
    color: var(--mds-color-action-primary-fg);
    text-decoration: underline;
    text-underline-offset: 2px;
}

/* The way back on a narrow screen: always in the same corner, above the page, below the shell's drawer. */
.manual__to-contents {
    position: fixed;
    right: var(--mds-space-4);
    bottom: var(--mds-space-4);
    z-index: 10;
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-1);
    padding: var(--mds-space-2) var(--mds-space-4);
    border-radius: var(--mds-radius-full);
    background-color: var(--mds-color-action-primary-bg);
    color: var(--mds-color-text-on-primary);
    font-weight: var(--mds-font-weight-semibold);
    text-decoration: none;
    box-shadow: var(--mds-shadow-3);
}

.manual__to-contents:focus-visible,
.manual__contents-link:focus-visible,
.manual__back-link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

/* ── From 1024px: the contents become a column on the left that stays on screen ─────────────────────────── */
@media (min-width: 64rem) {
    .manual__layout {
        display: grid;
        grid-template-columns: 15rem minmax(0, 1fr);
        align-items: start;
    }

    .manual__contents {
        position: sticky;
        top: var(--mds-space-4);
        max-height: calc(100vh - 7rem);
        overflow-y: auto;
    }

    .manual__contents-list {
        grid-template-columns: 1fr;
        gap: var(--mds-space-2);
        padding-inline-start: var(--mds-space-5);
    }

    /* The column is for finding a chapter, not reading about it: the summaries stay in the narrow list only. */
    .manual__contents-summary {
        display: none;
    }

    /* The column is always there, so neither way back is needed. */
    .manual__back,
    .manual__to-contents {
        display: none;
    }
}
</style>
