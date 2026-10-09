<script setup lang="ts">
/**
 * The pages chosen on the scans page, listed before "Read this scan" (M154, `R-c84e4f12`). Before this a native
 * file input showed only "2 files", so a wrong photo was first seen on the review screen — after it had been
 * sent and read.
 *
 * Presentational: the page owns the files, their order and their previews (object URLs it revokes), and this
 * component only shows them and asks for a removal by id. A preview is decorative (`alt=""`) because the file's
 * name sits beside it.
 *
 * The order is shown and needs no control: since M152 the reader places each page by the first question it
 * holds, so pages can be chosen in any order — the hint says so rather than inviting a reorder.
 */
import { MdsIconButton } from '@meridian/design-system';

export interface ChosenPageView {
    id: number;
    name: string;
    size: number;
    /** An object URL for a photo, or null for a file with no preview (a PDF). */
    previewUrl: string | null;
}

withDefaults(defineProps<{ pages: ChosenPageView[]; disabled?: boolean }>(), { disabled: false });

const emit = defineEmits<{ remove: [id: number] }>();

function sizeLabel(bytes: number): string {
    return bytes < 1_000_000 ? `${Math.max(1, Math.round(bytes / 1000))} KB` : `${(bytes / 1_000_000).toFixed(1)} MB`;
}

/** What stands in for a preview: the file's extension, which for an accepted file without one is PDF. */
function kindLabel(name: string): string {
    const dot = name.lastIndexOf('.');
    return dot > 0 ? name.slice(dot + 1, dot + 5).toUpperCase() : 'FILE';
}
</script>

<template>
    <div v-if="pages.length > 0" class="chosen">
        <p id="chosen-pages-title" class="chosen__title">
            {{ pages.length }} {{ pages.length === 1 ? 'page' : 'pages' }} chosen
        </p>
        <ol class="chosen__list" aria-labelledby="chosen-pages-title">
            <li v-for="(page, index) in pages" :key="page.id" class="chosen__item">
                <img v-if="page.previewUrl" class="chosen__thumb" :src="page.previewUrl" alt="" />
                <span v-else class="chosen__thumb chosen__thumb--file" aria-hidden="true">{{ kindLabel(page.name) }}</span>
                <span class="chosen__meta">
                    <span class="chosen__name">{{ page.name }}</span>
                    <span class="chosen__size">Page {{ index + 1 }} · {{ sizeLabel(page.size) }}</span>
                </span>
                <MdsIconButton
                    icon="trash"
                    size="sm"
                    variant="danger"
                    :label="`Remove ${page.name}`"
                    :disabled="disabled"
                    @click="emit('remove', page.id)"
                />
            </li>
        </ol>
        <p class="chosen__hint">Any order is fine — the pages are put in printed order when they are read.</p>
    </div>
</template>

<style scoped>
.chosen {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

.chosen__title {
    margin: 0;
    font-weight: var(--mds-type-label-font-weight);
}

.chosen__list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

.chosen__item {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
    padding: var(--mds-space-2);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background: var(--mds-color-bg-surface);
}

/* The `MediaInput.vue` thumbnail: a fixed square, cropped, so a portrait photo and a landscape one line up. */
.chosen__thumb {
    flex: 0 0 auto;
    display: block;
    width: 56px;
    height: 56px;
    object-fit: cover;
    border-radius: var(--mds-radius-sm);
}

.chosen__thumb--file {
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-type-label-font-weight);
}

.chosen__meta {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-width: 0;
}

.chosen__name {
    overflow-wrap: anywhere;
}

.chosen__size,
.chosen__hint {
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.chosen__hint {
    margin: 0;
}
</style>
