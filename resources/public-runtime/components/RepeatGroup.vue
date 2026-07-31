<script setup lang="ts">
/**
 * A repeatable section rendered as an add/remove-instance loop (Increment G2) — the visible counterpart to the
 * G1 pipeline. Each instance is a real `<fieldset>`/`<legend>` holding its member fields (via `InstanceField`),
 * with its own Remove control; an Add control appends a fresh instance (disabled at `max_instances`). All
 * per-instance relevance/required/constraint and the min/max count check come from the engine through the
 * store — this component only manages the add/remove interaction, the min/max affordances, focus movement, and
 * the aria-live announcements (UX §10.1).
 */
import { computed, nextTick, ref } from 'vue';
import { MdsButton } from '@meridian/design-system';
import InstanceField from './InstanceField.vue';
import { useAnnouncer, useRuntime } from '../composables/context';
import type { RenderSection } from '../lib/types';

const props = defineProps<{ section: RenderSection }>();
const runtime = useRuntime();
const announcer = useAnnouncer();

const rootEl = ref<HTMLElement | null>(null);
const addButton = ref<InstanceType<typeof MdsButton> | null>(null);

const sectionKey = computed(() => props.section.key);
// Increment H6b — piped through the store's seam, and flat-scoped for the same positional reason
// `SectionView` is: a section's own label precedes its members, so its holes can only name flat fields.
// It feeds the "Add X" button, each instance legend and the add/remove announcements, so a raw `${key}`
// leaking here would reach a screen reader as well as the screen.
const label = computed(() => runtime.sectionTitleFor(props.section));
const members = computed(() => runtime.membersOf(sectionKey.value));
const uids = computed(() => runtime.instanceUidsFor(sectionKey.value));
const count = computed(() => runtime.instanceCount(sectionKey.value));
const canAdd = computed(() => runtime.canAddInstance(sectionKey.value));
const min = computed(() => runtime.minInstances(sectionKey.value));
const max = computed(() => runtime.maxInstances(sectionKey.value));
const countError = computed(() => runtime.sectionCountError(sectionKey.value));

// A concise bounds hint under the section heading (only when a bound actually constrains the group).
const boundsHint = computed<string | null>(() => {
    const lo = min.value;
    const hi = max.value;
    if (lo > 0 && hi !== null) {
        return `Add ${lo} to ${hi}.`;
    }
    if (lo > 0) {
        return `Add at least ${lo}.`;
    }
    if (hi !== null) {
        return `Add up to ${hi}.`;
    }
    return null;
});

function instanceLegend(index: number): string {
    return `${label.value} ${index + 1}`;
}

function firstFocusable(container: HTMLElement | null): HTMLElement | null {
    return container?.querySelector<HTMLElement>('input, select, textarea, button') ?? null;
}

async function onAdd(): Promise<void> {
    const index = runtime.addInstance(sectionKey.value);
    if (index < 0) {
        return;
    }
    announcer.announce(`Added ${label.value} ${index + 1}.`);
    await nextTick();
    const added = rootEl.value?.querySelector<HTMLElement>(`[data-instance-index="${index}"]`) ?? null;
    const target = firstFocusable(added) ?? added?.querySelector<HTMLElement>('[data-instance-heading]') ?? null;
    target?.focus();
}

async function onRemove(index: number): Promise<void> {
    runtime.removeInstance(sectionKey.value, index);
    announcer.announce(`Removed ${label.value} ${index + 1}.`);
    await nextTick();
    // Focus the nearest stable control: the previous instance's Remove button, else the Add button.
    const remaining = rootEl.value?.querySelectorAll<HTMLElement>('[data-instance-remove]') ?? [];
    const fallbackIndex = Math.min(index, remaining.length - 1);
    if (fallbackIndex >= 0 && remaining[fallbackIndex]) {
        remaining[fallbackIndex].focus();
    } else {
        addButton.value?.$el?.focus?.();
    }
}
</script>

<template>
    <div ref="rootEl" class="repeat-group" :data-repeat-group="sectionKey">
        <p v-if="boundsHint" class="repeat-group__bounds">{{ boundsHint }}</p>

        <p v-if="count === 0" class="repeat-group__empty">Nothing added yet.</p>

        <ol v-else class="repeat-group__list">
            <li v-for="(uid, index) in uids" :key="uid">
                <fieldset class="repeat-instance" data-repeat-instance :data-instance-index="index">
                    <legend class="repeat-instance__legend" tabindex="-1" data-instance-heading>
                        {{ instanceLegend(index) }}
                    </legend>
                    <div class="repeat-instance__fields">
                        <InstanceField
                            v-for="field in members"
                            :key="field.key"
                            :section-key="sectionKey"
                            :index="index"
                            :field="field"
                        />
                    </div>
                    <div class="repeat-instance__actions">
                        <MdsButton
                            type="button"
                            variant="tertiary"
                            size="sm"
                            icon-left="trash"
                            data-instance-remove
                            :aria-label="`Remove ${instanceLegend(index)}`"
                            @click="onRemove(index)"
                        >
                            Remove
                        </MdsButton>
                    </div>
                </fieldset>
            </li>
        </ol>

        <p v-if="countError" class="repeat-group__error" role="alert">
            <svg class="repeat-group__error-icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                <path
                    fill="currentColor"
                    d="M8 1a7 7 0 100 14A7 7 0 008 1zm-.9 3.6h1.8l-.2 5h-1.4l-.2-5zM8 12.4a1 1 0 110-2 1 1 0 010 2z"
                />
            </svg>
            <span>{{ countError }}</span>
        </p>

        <div class="repeat-group__add">
            <MdsButton
                ref="addButton"
                type="button"
                variant="secondary"
                icon-left="plus"
                :disabled="!canAdd"
                @click="onAdd"
            >
                Add {{ label }}
            </MdsButton>
            <span v-if="!canAdd" class="repeat-group__max-note">Maximum of {{ max }} reached.</span>
        </div>
    </div>
</template>

<style scoped>
.repeat-group {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.repeat-group__bounds {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.repeat-group__empty {
    margin: 0;
    padding: var(--mds-space-3) var(--mds-space-4);
    border: 1px dashed var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.repeat-group__list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.repeat-instance {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    margin: 0;
    padding: var(--mds-space-4);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
    min-width: 0;
}

.repeat-instance__legend {
    padding: 0 var(--mds-space-1);
    margin-left: calc(-1 * var(--mds-space-1));
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.repeat-instance__legend:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
}

.repeat-instance__fields {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.repeat-instance__actions {
    display: flex;
    justify-content: flex-end;
}

.repeat-group__error {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-1);
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-danger-text);
}

.repeat-group__error-icon {
    flex-shrink: 0;
    width: 1em;
    height: 1em;
    margin-top: 0.15em;
}

.repeat-group__add {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
    flex-wrap: wrap;
}

.repeat-group__max-note {
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}
</style>
