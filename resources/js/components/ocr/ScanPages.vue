<script setup lang="ts">
/**
 * The scanned pages beside the review form (M129 — single-form OCR groundwork 2, `docs/ocr-pipeline-design.md`
 * §4: "the original scanned image (zoomable) on one side").
 *
 * ⚠️ EACH FRAME IS A FOCUSABLE, LABELLED REGION. Zoomed in, a frame scrolls, and a scrolling region that a
 * keyboard cannot reach is an axe failure (`scrollable-region-focusable`) as well as a reviewer who cannot pan
 * across the page they are checking. `tabindex="0"` plus a name is what fixes both.
 *
 * ⚠️ A PDF IS A LINK, NOT A FRAME. Tenant pages forbid framing (`X-Frame-Options: DENY`), the file route sends a
 * PDF as a download, and nothing here renders PDF pages — so a PDF scan offers its file. Showing PDF pages
 * inline is a filed row.
 *
 * M153: the same pages on a saved response (`Pages/submissions/Show.vue`), where the heading reads "Scanned pages"
 * — on the review screen the paper is the thing being read, on the response it is one record among several.
 */
import { computed, ref } from 'vue';
import { MdsButton } from '@meridian/design-system';
import type { ScanPage } from './scan-review';

const props = withDefaults(defineProps<{ pages: ScanPage[]; title?: string }>(), { title: 'The paper' });

/** Zoom steps: 1 fits the page to the panel's width. */
const ZOOM_STEPS = [1, 1.5, 2, 3] as const;
const zoomIndex = ref(0);
const zoom = computed(() => ZOOM_STEPS[zoomIndex.value]);
const zoomLabel = computed(() => `${Math.round(zoom.value * 100)}%`);

function zoomIn(): void {
    zoomIndex.value = Math.min(zoomIndex.value + 1, ZOOM_STEPS.length - 1);
}

function zoomOut(): void {
    zoomIndex.value = Math.max(zoomIndex.value - 1, 0);
}

function isImage(page: ScanPage): boolean {
    return page.mime.startsWith('image/');
}

const hasImage = computed(() => props.pages.some(isImage));
</script>

<template>
    <section class="scan-pages" aria-labelledby="scan-pages-title">
        <div class="scan-pages__head">
            <h2 id="scan-pages-title" class="scan-pages__title">{{ title }}</h2>
            <div v-if="hasImage" class="scan-pages__zoom" role="group" aria-label="Zoom">
                <MdsButton size="sm" variant="secondary" :disabled="zoomIndex === 0" @click="zoomOut">Zoom out</MdsButton>
                <span class="scan-pages__zoom-level" aria-live="polite">{{ zoomLabel }}</span>
                <MdsButton size="sm" variant="secondary" :disabled="zoomIndex === ZOOM_STEPS.length - 1" @click="zoomIn">
                    Zoom in
                </MdsButton>
            </div>
        </div>

        <ol class="scan-pages__list">
            <li v-for="page in pages" :key="page.number" class="scan-pages__item">
                <template v-if="isImage(page)">
                    <div
                        v-if="page.servable"
                        class="scan-pages__frame"
                        role="region"
                        tabindex="0"
                        :aria-label="`Page ${page.number} of the scan`"
                        :data-page="page.number"
                    >
                        <img
                            class="scan-pages__image"
                            :src="page.url"
                            :alt="`Scanned page ${page.number}`"
                            :style="{ width: `${zoom * 100}%` }"
                        />
                    </div>
                    <p v-else class="scan-pages__pending">
                        Page {{ page.number }} is being checked for viruses. It will appear here shortly.
                    </p>
                </template>
                <p v-else class="scan-pages__file">
                    <a :href="page.url" download>Download the scanned PDF</a>
                    to compare it with the answers.
                </p>
            </li>
        </ol>
    </section>
</template>

<style scoped>
.scan-pages {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.scan-pages__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-2);
}

.scan-pages__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
}

.scan-pages__zoom {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
}

.scan-pages__zoom-level {
    min-width: 3.5em;
    text-align: center;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.scan-pages__list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    margin: 0;
    padding: 0;
    list-style: none;
}

.scan-pages__frame {
    max-height: 70vh;
    overflow: auto;
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background: var(--mds-color-bg-surface);
}

.scan-pages__frame:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.scan-pages__image {
    display: block;
    max-width: none;
    height: auto;
}

.scan-pages__pending,
.scan-pages__file {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.scan-pages__file a {
    color: var(--mds-color-action-primary-fg);
}
</style>
