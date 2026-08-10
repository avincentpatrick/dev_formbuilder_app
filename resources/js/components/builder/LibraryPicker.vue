<script setup lang="ts">
/**
 * The builder's left-pane Library view (Increment G9b): the tenant's own + platform-seeded reusable
 * questions, "popular" first (as BuilderPresenter orders them). Clicking one inserts a materialized field
 * into the current draft — the server rebuilds the field from the item's stored shape. New items arrive via
 * the config panel's one-click "Save to library". Mirrors FieldPalette's structure so the two left-pane
 * views read as one system.
 *
 * ── ⚠️ THE FILTER IS `MdsTextInput type="search"` AS OF J1e, AND IT USED NOT TO BE ──────────────────────
 * This component predates DSR §3.2's Search row and rendered a RAW `<input type="search">` with its own
 * geometry (34px min-height, `--mds-radius-sm`, `--mds-space-2` padding, `body-md` type). §3.2 note 4 named
 * it as the one search field in the product the design system did not describe, and filed the migration for
 * the increment that touched the list-page keyword filters. This is that increment.
 *
 * Two consequences worth stating rather than rediscovering. The box grows 34 → 40px, which is `MdsInput`'s
 * `min-height` and is the reason `builder-axe.spec.ts` and `field-library-axe.spec.ts` are re-run for this
 * change — the builder's left pane is the app's tightest vertical budget. And the `aria-label` survives the
 * swap only because `MdsTextInput`'s root element IS the `<input>` under Vue's default `inheritAttrs`, so
 * the attribute falls through; if that component ever gains a wrapper element, this label silently moves
 * onto a `<div>` and the field loses its accessible name.
 *
 * It stays `aria-label`-only rather than gaining an `MdsFormField`: the pane already carries an `<h2>` and a
 * one-line hint directly above, so a third "Search" caption would be noise in 240px of horizontal space.
 * That is §4.2's visible-label preference yielding to its own "wherever there is room" clause, recorded
 * because the neighbouring six list pages just went the other way.
 */
import { computed, ref } from 'vue';
import { MdsBadge, MdsEmptyState, MdsTextInput } from '@meridian/design-system';
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

        <MdsTextInput
            v-model="filter"
            type="search"
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

/* `.library__filter`'s hand-rolled box and focus ring are gone (J1e) — MdsTextInput owns both now. */

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
