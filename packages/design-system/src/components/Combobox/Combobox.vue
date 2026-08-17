<script setup lang="ts">
/**
 * The ARIA 1.2 combobox (Increment J4c, DSR §3.4.1 / §4.5).
 *
 * A text input that filters a listbox, where the highlighted option moves with `aria-activedescendant` and
 * DOM focus **never leaves the input**. Extracted from `CommandPalette.vue`, which had been the only
 * implementation of this pattern in the product and was a LOGGED DEVIATION for exactly that reason —
 * generalising a heterogeneous palette into a primitive before there was a second consumer would have been
 * inventing an API from one example, so the log said to wait for the increment that owns this component.
 * This is that increment, and the entry is deleted rather than amended, as its own disposition instructed.
 *
 * ⚠️ NO HTML TAG IS SPELLED WITH ANGLE BRACKETS ANYWHERE IN THIS COMMENT, AND THAT IS DELIBERATE. The
 * vue3-vite preset preserves comments for docgen, so the SFC parser tokenises comment bodies that Vitest,
 * vue-tsc and the app's own Vite build all skip — and the error names a line past the end of the file.
 *
 * ── THE MOVE BUYS SCRUTINY RATHER THAN REUSE, AND HERE THAT IS THE WHOLE POINT ─────────────────────────
 * An application-tree component gets no Storybook story and therefore **no `checkA11y` scan at all**. The
 * palette recorded that gap against itself, and it is the argument that moved `MdsMenu` in J4b and
 * `MdsTabs` in J4c1 — both of which turned up real defects the moment a scanner finally looked.
 *
 * ── OMITTED, NEVER DANGLING ────────────────────────────────────────────────────────────────────────────
 * `aria-controls` and `aria-activedescendant` are absent whenever the thing they would point at is absent.
 * An attribute naming an id that is not in the document is WORSE than no attribute: a screen reader
 * announces nothing and the reader has no way to tell why. ⚠️ axe does not resolve either id, so the
 * accessibility gate cannot see this — it is held in the unit suite instead, which is why those cases
 * exist at all.
 *
 * ── ESCAPE IS DELIBERATELY NOT BOUND, AND THAT IS A DECISION RATHER THAN AN OMISSION ────────────────────
 * The ARIA pattern would have Escape collapse the listbox first. This component's consumer sits inside
 * `MdsModal`, whose Escape is the dismissal, so a listbox that swallowed the first press would make
 * closing the dialog take two — a regression the reader would experience as the palette ignoring them.
 *
 * ⚠️ THAT MAKES THIS THE THIRD MEMBER OF A FAMILY THAT MUST NOT BE "ALIGNED": `MdsTooltip` listens on
 * `document` in the CAPTURE phase because it never holds focus; `MdsMenu` binds its own root because it
 * always does; `MdsCombobox` binds neither, because the surface that owns its Escape is the dialog around
 * it. Each is correct for its own shape, and picking the wrong one is invisible until something wraps a
 * dialog around the component. The day a consumer mounts this OUTSIDE a dialog, Escape becomes its
 * problem to add — with a `stopPropagation` argument written down, not copied from one of the other two.
 *
 * ── THE LISTBOX IS IN FLOW, NOT AN ANCHORED POPUP ──────────────────────────────────────────────────────
 * The one consumer renders it inside a dialog body, where flow layout is exactly right. An anchored
 * variant would need `MdsTooltip`'s teleport-plus-exemption construction and a placement module, and
 * building that now would ship API with no consumer — the mistake DSR §3.4a records against itself. It is
 * owed by its first real consumer, in that consumer's own PR, the way the `MdsIconButton` title
 * suppression prop is.
 */
import { computed, ref, useId } from 'vue';
import TextInput from '../TextInput/TextInput.vue';

export interface ComboboxOption {
    /** Identity for the list. Never rendered. */
    key: string;
    /**
     * The option's text. Rendered as-is unless the consumer takes the `option` slot — and it is what the
     * component falls back to, so a slotted consumer still owes a sensible label here.
     */
    label: string;
    /**
     * Optional grouping. CONSECUTIVE options sharing a value render inside one labelled group; options
     * with none render as direct children of the listbox.
     *
     * ⚠️ A flat list with a `group` field rather than a nested structure, because the flat index IS the
     * `aria-activedescendant` index. Nesting would put a second index in the component whose only job is
     * to agree with the first, and a relationship that can disagree with itself is the defect this whole
     * component is careful about.
     */
    group?: string;
}

const props = withDefaults(
    defineProps<{
        /** The query text. */
        modelValue: string;
        options: ComboboxOption[];
        /** The input's label. Rendered visually hidden — see the stylesheet. */
        label: string;
        /** The listbox's accessible name, e.g. "Search results". */
        listboxLabel: string;
        placeholder?: string;
        /**
         * What the polite live region announces. The CONSUMER owns this wording: it knows whether a
         * synthetic row should be counted, and DSR §3.4.1 makes zero-result copy a single string with a
         * single code path, which a component computing its own count would quietly branch.
         */
        status?: string;
        /** Forwarded to the input, so a consumer can point the modal's initial focus at it. */
        inputId?: string;
    }>(),
    { placeholder: undefined, status: '', inputId: undefined },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
    /** An option was activated, by Enter or by pointer. */
    select: [option: ComboboxOption];
    /** Enter with no option to activate. The consumer decides what a bare query means. */
    submit: [query: string];
}>();

const generatedId = useId();
const inputId = computed(() => props.inputId ?? `${generatedId}-input`);
const listboxId = `${generatedId}-listbox`;
const optionIdPrefix = `${generatedId}-option`;

const activeIndex = ref(0);

const hasOptions = computed(() => props.options.length > 0);

/** Absent, never dangling — see the docblock. */
const activeDescendantId = computed(() =>
    hasOptions.value && props.options[activeIndex.value] !== undefined
        ? `${optionIdPrefix}-${activeIndex.value}`
        : undefined,
);

const controlsId = computed(() => (hasOptions.value ? listboxId : undefined));

/**
 * The flat list segmented into runs of the same `group`, so the template can emit one group wrapper per
 * run while every option keeps its ORIGINAL index. That index is what the id, the active descendant and
 * the selection all use, so it must survive the grouping rather than be recomputed from it.
 */
const runs = computed(() => {
    const out: { group: string | undefined; items: { option: ComboboxOption; index: number }[] }[] = [];

    props.options.forEach((option, index) => {
        const tail = out[out.length - 1];

        if (tail !== undefined && tail.group === option.group) tail.items.push({ option, index });
        else out.push({ group: option.group, items: [{ option, index }] });
    });

    return out;
});

/**
 * Reset the highlight whenever the list changes identity. Without it a shrinking list leaves the index
 * past the end, `aria-activedescendant` goes absent (correctly), and Enter silently does nothing — the
 * palette solved this by resetting on every fetch, which is the consumer doing the component's job.
 */
function clampActive(): void {
    if (activeIndex.value > props.options.length - 1) activeIndex.value = 0;
}

function move(delta: number): void {
    if (!hasOptions.value) return;

    const count = props.options.length;
    activeIndex.value = (activeIndex.value + delta + count) % count;
}

function activate(): void {
    clampActive();
    const option = props.options[activeIndex.value];

    if (option === undefined) emit('submit', props.modelValue);
    else emit('select', option);
}

function onKeydown(event: KeyboardEvent): void {
    // ⚠️ Escape is NOT handled here. See the docblock — the surface that owns it is the dialog around this
    // component, and swallowing the first press would make closing that dialog take two.
    switch (event.key) {
        case 'ArrowDown':
            // preventDefault, or the caret jumps to the end of the input while the highlight moves.
            event.preventDefault();
            move(1);
            break;
        case 'ArrowUp':
            event.preventDefault();
            move(-1);
            break;
        case 'Home':
            event.preventDefault();
            activeIndex.value = 0;
            break;
        case 'End':
            event.preventDefault();
            activeIndex.value = Math.max(0, props.options.length - 1);
            break;
        case 'Enter':
            event.preventDefault();
            activate();
            break;
        default:
            break;
    }
}

function onInput(event: Event): void {
    activeIndex.value = 0;
    emit('update:modelValue', (event.target as HTMLInputElement).value);
}

function onOptionClick(index: number): void {
    activeIndex.value = index;
    activate();
}

/** Exposed so a consumer can re-highlight after replacing the list asynchronously. */
defineExpose({ resetActive: () => (activeIndex.value = 0) });
</script>

<template>
    <div class="mds-combobox">
        <label class="mds-combobox__label" :for="inputId">{{ label }}</label>

        <!--
            ⚠️ `MdsTextInput`, NOT A BARE INPUT, AND THE FIRST DRAFT GOT THIS WRONG IN A WAY ONLY THE VISUAL
            SWEEP COULD SEE. It rendered a bare input carrying `class="mds-input"`, on the assumption that
            the shared geometry is a global class. It is not: `.mds-input` is declared inside
            `TextInput.vue`'s SCOPED style block and exists nowhere else, so the box arrived with no border,
            no radius, no min-height and no fill — a native input dropped into a dialog. Every ARIA
            assertion passed, happy-dom computes no layout, and axe has no rule for "unstyled", so the whole
            gate stack was green over it.

            The component's root IS the input under Vue's default `inheritAttrs`, so `role`, the four ARIA
            attributes and both handlers land on the real control exactly as they would have on a bare one.
            The explicit role overrides the implicit `searchbox` that `type="search"` would otherwise give
            it — DSR §3.2's note 1 records that override and names this component as where it first bit.
        -->
        <TextInput
            :id="inputId"
            :model-value="modelValue"
            type="search"
            role="combobox"
            autocomplete="off"
            :placeholder="placeholder"
            aria-autocomplete="list"
            :aria-expanded="hasOptions"
            :aria-controls="controlsId"
            :aria-activedescendant="activeDescendantId"
            @input="onInput"
            @keydown="onKeydown"
        />

        <div v-if="hasOptions" :id="listboxId" class="mds-combobox__list" role="listbox" :aria-label="listboxLabel">
            <template v-for="(run, runIndex) in runs" :key="run.group ?? `ungrouped-${runIndex}`">
                <!--
                    A run with no group name renders its options as DIRECT children of the listbox. A
                    role=group demands an accessible name, and inventing one for a synthetic trailing row
                    would put a heading in the reader's ear that is not on the screen.

                    Both branches render the SAME option markup, which is duplication that earns its keep:
                    the alternative is one wrapper element that is sometimes a group and sometimes not, and
                    a listbox whose children are conditionally-roled divs is precisely the shape
                    aria-required-children exists to reject.
                -->
                <div v-if="run.group !== undefined" class="mds-combobox__group" role="group" :aria-label="run.group">
                    <p class="mds-combobox__group-heading" aria-hidden="true">{{ run.group }}</p>
                    <!--
                        role=option on a div, never a button: a button inside a listbox trips axe's
                        nested-interactive AND breaks aria-activedescendant. Rows carry no tabindex by
                        design — DOM focus never leaves the input.
                    -->
                    <div
                        v-for="entry in run.items"
                        :id="`${optionIdPrefix}-${entry.index}`"
                        :key="entry.option.key"
                        class="mds-combobox__option"
                        :class="{ 'is-active': entry.index === activeIndex }"
                        role="option"
                        :aria-selected="entry.index === activeIndex"
                        @click="onOptionClick(entry.index)"
                    >
                        <slot name="option" :option="entry.option" :active="entry.index === activeIndex">
                            {{ entry.option.label }}
                        </slot>
                    </div>
                </div>

                <template v-else>
                    <div
                        v-for="entry in run.items"
                        :id="`${optionIdPrefix}-${entry.index}`"
                        :key="entry.option.key"
                        class="mds-combobox__option"
                        :class="{ 'is-active': entry.index === activeIndex }"
                        role="option"
                        :aria-selected="entry.index === activeIndex"
                        @click="onOptionClick(entry.index)"
                    >
                        <slot name="option" :option="entry.option" :active="entry.index === activeIndex">
                            {{ entry.option.label }}
                        </slot>
                    </div>
                </template>
            </template>
        </div>

        <slot v-else name="empty" />

        <!--
            The polite live region, visually hidden. Rendered VISIBLY it failed axe's colour-contrast check
            intermittently — a transient "Searching…" state measured mid-transition — and a flaky gate is
            worse than no gate. It duplicates what the list already shows, so hiding it costs a sighted
            reader nothing.

            ⚠️ It is INSIDE this component, which puts it inside whatever dialog wraps it. `inert` removes
            everything outside an open modal from the accessibility tree, so a region in the page shell
            would stop announcing entirely and nothing replays on close.
        -->
        <p class="mds-combobox__label" role="status">{{ status }}</p>
    </div>
</template>

<style scoped>
/*
 * ⚠️ `position: relative` IS LOAD-BEARING, NOT DECORATIVE — the same one line `MdsSegmentedControl`,
 * `MdsDataTable` and `MdsBarChart` each carry, and for the same measured reason. Both `.mds-combobox__label`
 * rules below are the absolutely-positioned clip-rect visually-hidden pattern. Without a positioned ancestor
 * HERE their containing block resolves outside this component, so no scroll container in between can clip
 * them: a 1px hidden node then sits at document coordinates and extends the DOCUMENT's scrollable box.
 *
 * `clipped-node-containment.test.ts` asserts the design system holds ZERO instances of that shape, and this
 * component is why `CommandPalette.vue` could leave the app-tree exception list — the clipping moved here,
 * so the guard has to be satisfied here.
 */
.mds-combobox {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    min-width: 0;
}

.mds-combobox__label {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    clip-path: inset(50%);
    white-space: nowrap;
}

.mds-combobox__list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    max-height: 22rem;
    overflow-y: auto;
}

.mds-combobox__group-heading {
    margin: 0;
    padding: var(--mds-space-1) var(--mds-space-2);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.mds-combobox__option {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: var(--mds-space-2) var(--mds-space-3);
    border-radius: var(--mds-radius-sm);
    cursor: pointer;
}

/* The same token the sidebar uses for its current-section row, so "the highlighted thing" looks the same in
   both places. ⚠️ An invented name resolves to nothing and the highlight silently disappears — check the
   token list rather than guessing, which is what `token-references.test.ts` is the gate for. */
.mds-combobox__option.is-active {
    background-color: var(--mds-color-action-primary-tint);
    color: var(--mds-color-text-heading);
}
</style>
