<script setup lang="ts">
/**
 * The builder's left pane: every one of the 31 field types as an add-button, grouped by category
 * (data straight from the FieldType enum via BuilderPresenter, so the palette never re-lists them).
 * Advanced types (geo / media capture / cascading / matrix) are shown fully but flagged — their rich
 * config editors follow the form engine (ADR-0004). Adding places the field in the current section.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { MdsIcon } from '@meridian/design-system';
import type { IconName } from '@meridian/design-system';
import type { PaletteGroup } from './types';

const props = defineProps<{ palette: PaletteGroup[]; disabled?: boolean }>();
const emit = defineEmits<{ add: [typeValue: string] }>();

/**
 * Keyboard access to the palette's own scroll region (WCAG 2.1.1 / axe `scrollable-region-focusable`).
 *
 * `.palette` is `overflow-y: auto` over 31 add-buttons, so at a short viewport it always scrolls. It
 * normally passes the rule *because of* those buttons — but `:disabled="saving"` makes every one of them
 * non-focusable while a debounced save is in flight, and a scroll region with no focusable content and no
 * tab stop of its own is unreachable by keyboard: the types past the fold simply cannot be got at. That is
 * a real defect, and it is also why `builder-axe` has been intermittently red on `main` (it fired on the
 * `e04e68b` run and again here) — the gate was catching a genuine race, not flaking.
 *
 * Measured rather than assumed, mirroring `DataTable.vue`: a palette that fits adds no tab stop, since
 * focusability is the remedy for content you would otherwise be unable to reach, not a decoration.
 * `role="group"` deliberately, NOT `region` — a landmark here would compete with the page's own.
 * Re-measures on resize (a viewport change or the personalization type scale can push a fitting palette
 * over) and whenever the type list changes. Guarded for SSR.
 */
const root = ref<HTMLElement | null>(null);
const scrollable = ref(false);

function measure(): void {
    const el = root.value;
    if (!el) {
        scrollable.value = false;

        return;
    }

    const overflowY = getComputedStyle(el).overflowY;
    const scrolls = overflowY === 'auto' || overflowY === 'scroll';

    scrollable.value = scrolls && el.scrollHeight > el.clientHeight + 1;
}

let observer: ResizeObserver | null = null;

onMounted(() => {
    measure();

    if (typeof ResizeObserver === 'undefined' || !root.value) return;

    observer = new ResizeObserver(() => measure());
    observer.observe(root.value);
});

onBeforeUnmount(() => {
    observer?.disconnect();
    observer = null;
});

watch(() => props.palette, () => queueMicrotask(measure), { deep: false });
</script>

<template>
    <div
        ref="root"
        class="palette"
        :tabindex="scrollable ? 0 : undefined"
        :role="scrollable ? 'group' : undefined"
        :aria-label="scrollable ? 'Add a field' : undefined"
    >
        <h2 class="palette__title">Add a field</h2>
        <div v-for="group in palette" :key="group.category" class="palette__group">
            <div class="palette__group-head">
                <MdsIcon :name="(group.icon as IconName)" size="sm" />
                <span>{{ group.label }}</span>
            </div>
            <ul class="palette__list">
                <li v-for="type in group.types" :key="type.value">
                    <button
                        type="button"
                        class="palette__item"
                        :disabled="disabled"
                        :title="type.advanced ? `${type.label} — baseline settings (advanced editor coming with the form engine)` : type.label"
                        @click="emit('add', type.value)"
                    >
                        <span class="palette__item-label">{{ type.label }}</span>
                        <span v-if="type.advanced" class="palette__adv">Adv</span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>

<style scoped>
.palette {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    padding: var(--mds-space-4);
    height: 100%;
    overflow-y: auto;
}

/* The scroll region only becomes focusable when it actually scrolls (see `measure()`), so this focus ring
   appears only on a real tab stop. */
.palette:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: -2px;
}

.palette__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    font-weight: var(--mds-type-heading-4-font-weight);
    color: var(--mds-color-text-heading);
}

.palette__group {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
}

.palette__group-head {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-1);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-caption-font-size);
    font-weight: var(--mds-font-weight-semibold);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.palette__list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-0-5);
    margin: 0;
    padding: 0;
    list-style: none;
}

.palette__item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-2);
    width: 100%;
    min-height: 34px;
    padding: 0 var(--mds-space-2);
    border: 1px solid transparent;
    border-radius: var(--mds-radius-sm);
    background: transparent;
    color: var(--mds-color-text-body);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    text-align: left;
    cursor: pointer;
    transition:
        background-color var(--mds-duration-fast) var(--mds-ease-standard),
        border-color var(--mds-duration-fast) var(--mds-ease-standard);
}

.palette__item:hover:not(:disabled) {
    background-color: var(--mds-color-bg-sunken);
    border-color: var(--mds-color-border-default);
}

.palette__item:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
}

.palette__item:disabled {
    color: var(--mds-color-text-disabled);
    cursor: not-allowed;
}

.palette__adv {
    flex-shrink: 0;
    padding: 0 var(--mds-space-1);
    border-radius: var(--mds-radius-sm);
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-caption-font-size);
    font-weight: var(--mds-font-weight-medium);
}
</style>
