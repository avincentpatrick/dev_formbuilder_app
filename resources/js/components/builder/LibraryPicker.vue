<script setup lang="ts">
/**
 * The builder's left-pane Library view (Increment G9b): the tenant's own + platform-seeded reusable
 * questions, "popular" first (as BuilderPresenter orders them). Clicking one inserts a materialized field
 * into the current draft — the server rebuilds the field from the item's stored shape. New items arrive via
 * the config panel's one-click "Save to library". Mirrors FieldPalette's structure so the two left-pane
 * views read as one system.
 */
import { computed, ref } from 'vue';
import { MdsBadge, MdsEmptyState } from '@meridian/design-system';
import type { LibraryItem } from './types';

const props = defineProps<{
    items: LibraryItem[];
    fieldTypeLabels: Record<string, string>;
    disabled?: boolean;
}>();
const emit = defineEmits<{ insert: [itemId: string] }>();

const filter = ref('');

const visible = computed<LibraryItem[]>(() => {
    const q = filter.value.trim().toLowerCase();
    if (!q) return props.items;
    return props.items.filter(
        (i) => i.name.toLowerCase().includes(q) || (i.category ?? '').toLowerCase().includes(q),
    );
});

function typeLabel(value: string): string {
    return props.fieldTypeLabels[value] ?? value;
}
</script>

<template>
    <div class="library">
        <h2 class="library__title">Question library</h2>
        <p class="library__hint">Insert a reusable question into your form.</p>

        <input
            v-model="filter"
            type="search"
            class="library__filter"
            placeholder="Search questions"
            aria-label="Search the question library"
        />

        <ul v-if="visible.length > 0" class="library__list">
            <li v-for="item in visible" :key="item.id">
                <button
                    type="button"
                    class="library__item"
                    :disabled="disabled"
                    :title="`Insert “${item.name}” into the form`"
                    @click="emit('insert', item.id)"
                >
                    <span class="library__item-main">
                        <span class="library__item-name">{{ item.name }}</span>
                        <span class="library__item-type">{{ typeLabel(item.field_type) }}</span>
                    </span>
                    <MdsBadge v-if="item.is_platform" label="Meridian" variant="info" />
                </button>
            </li>
        </ul>

        <MdsEmptyState
            v-else-if="items.length === 0"
            headline="No saved questions"
            description="Save a field from its config panel to reuse it across your forms."
        />
        <p v-else class="library__empty" role="status">No questions match “{{ filter }}”.</p>
    </div>
</template>

<style scoped>
.library {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    padding: var(--mds-space-4);
    height: 100%;
    overflow-y: auto;
}

.library__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    font-weight: var(--mds-type-heading-4-font-weight);
    color: var(--mds-color-text-heading);
}

.library__hint {
    margin: calc(-1 * var(--mds-space-2)) 0 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.library__filter {
    width: 100%;
    min-height: 34px;
    padding: 0 var(--mds-space-2);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-sm);
    background: var(--mds-color-bg-surface);
    color: var(--mds-color-text-body);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
}

.library__filter:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
}

.library__list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-0-5);
    margin: 0;
    padding: 0;
    list-style: none;
}

.library__item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-2);
    width: 100%;
    min-height: 44px;
    padding: var(--mds-space-1) var(--mds-space-2);
    border: 1px solid transparent;
    border-radius: var(--mds-radius-sm);
    background: transparent;
    color: var(--mds-color-text-body);
    font-family: var(--mds-font-family-body);
    text-align: left;
    cursor: pointer;
    transition:
        background-color var(--mds-duration-fast) var(--mds-ease-standard),
        border-color var(--mds-duration-fast) var(--mds-ease-standard);
}

.library__item:hover:not(:disabled) {
    background-color: var(--mds-color-bg-sunken);
    border-color: var(--mds-color-border-default);
}

.library__item:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
}

.library__item:disabled {
    color: var(--mds-color-text-disabled);
    cursor: not-allowed;
}

.library__item-main {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-0-5);
    min-width: 0;
}

.library__item-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: var(--mds-type-body-md-font-size);
}

.library__item-type {
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-caption-font-size);
}

.library__empty {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}
</style>
