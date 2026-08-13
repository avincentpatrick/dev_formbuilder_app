<script setup lang="ts">
/**
 * Single-select segmented control. Built on native radio inputs inside a <fieldset>, so it gets
 * the ARIA radiogroup semantics and arrow-key roving selection for free. The visible glyph + label
 * are the non-color signifiers of the selected segment (never color alone — WCAG 1.4.1).
 */
import { useId } from 'vue';
import Icon from '../Icon/Icon.vue';
import type { IconName } from '../Icon/icons';

interface SegmentOption {
    value: string;
    label: string;
    icon?: IconName;
}

const props = withDefaults(
    defineProps<{
        modelValue: string;
        options: SegmentOption[];
        ariaLabel: string;
        name?: string;
        compact?: boolean;
    }>(),
    { compact: false },
);

defineEmits<{ 'update:modelValue': [value: string] }>();

const groupName = props.name ?? useId();
</script>

<template>
    <fieldset class="mds-segmented" :class="{ 'mds-segmented--compact': compact }">
        <legend class="mds-segmented__legend">{{ ariaLabel }}</legend>
        <label
            v-for="opt in options"
            :key="opt.value"
            class="mds-segmented__seg"
            :class="{ 'is-selected': opt.value === modelValue }"
        >
            <input
                class="mds-segmented__input"
                type="radio"
                :name="groupName"
                :value="opt.value"
                :checked="opt.value === modelValue"
                @change="$emit('update:modelValue', opt.value)"
            />
            <Icon v-if="opt.icon" :name="opt.icon" size="sm" />
            <span>{{ opt.label }}</span>
        </label>
    </fieldset>
</template>

<style scoped>
/* ⚠️ `position: relative` IS LOAD-BEARING, NOT DECORATIVE — the same defect `DataTable.vue` documents,
   on the other axis. Both `.mds-segmented__legend` and `.mds-segmented__input` below are the
   `position: absolute` + `clip: rect(0 0 0 0)` visually-hidden pattern. Without a positioned ancestor
   HERE their containing block resolves outside the fieldset, so no scroll container between this control
   and the document can clip them: a 1px hidden node then sits at document coordinates and extends the
   DOCUMENT's scrollable box, even though the control itself is correctly contained.

   Measured in JR5 by the builder sweep: at a 375px viewport with the config pane selected, the group's
   hidden `<legend>` landed 73px past the bottom of a workspace that is `height: 100%` all the way up,
   and the whole page gained 73px of real VERTICAL scroll behind a screen that owns its own scrolling.
   G11 found the identical thing horizontally on `MdsDataTable`; the fix there was this one line and the
   note that it is "a latent bug in this component". It was latent in this one too — every consumer had
   it, and it only became visible where an ancestor finally tried to clip.

   ⚠️ NO GATE IN THIS REPO CAN SEE IT. The e2e overflow assertion reads `documentElement.scrollWidth`,
   which `.app-shell { overflow-x: clip }` pins flat, and nothing measures scrollHeight at all; happy-dom
   lays nothing out; axe has no rule for it. Hence the source-text assertion in SegmentedControl.test.ts. */
.mds-segmented {
    position: relative;
    display: inline-flex;
    min-width: 0;
    gap: var(--mds-space-0-5);
    margin: 0;
    padding: var(--mds-space-0-5);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

/* The group label is announced but not shown (each control provides visual context). */
.mds-segmented__legend {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}

.mds-segmented__seg {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--mds-space-1);
    min-height: 32px;
    padding: 0 var(--mds-space-3);
    border-radius: var(--mds-radius-sm);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-secondary);
    cursor: pointer;
    transition:
        background-color var(--mds-duration-fast) var(--mds-ease-standard),
        color var(--mds-duration-fast) var(--mds-ease-standard);
}

.mds-segmented--compact .mds-segmented__seg {
    padding: 0 var(--mds-space-2);
}

.mds-segmented__seg:hover {
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-body);
}

/* Selected = a solid filled chip (the verified action-primary + on-primary pairing), so contrast
   holds in both themes; the fill + label + glyph are the non-color signifiers. */
.mds-segmented__seg.is-selected {
    background-color: var(--mds-color-action-primary-bg);
    color: var(--mds-color-text-on-primary);
    font-weight: var(--mds-font-weight-semibold);
}

/* Visually-hidden but still focusable — arrow keys move between radios natively. */
.mds-segmented__input {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}

.mds-segmented__seg:has(.mds-segmented__input:focus-visible) {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
}
</style>
