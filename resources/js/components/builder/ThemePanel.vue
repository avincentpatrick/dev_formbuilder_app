<script setup lang="ts">
/**
 * A form's preset theme (M131, `R-6017d6d8`; `D65` = A, `D81`) — PATCH /forms/{form}/theme, gated
 * `can:update,form` alone: presets are on every plan, and the workspace's own brand colour stays Starter+.
 *
 * The presets are TRANSMITTED (`FormThemePreset::catalogue()`), colours included, so this file holds no hex of
 * its own to drift from the server's pinned literals. Each one is contrast-checked on the server in both themes
 * (`FormThemePresetTest`); the swatches here only show the author what a respondent will see.
 *
 * ── A RADIO GROUP IN A FIELDSET, NOT A SEGMENTED CONTROL ───────────────────────────────────────────
 * Six choices, each with a description and two swatches, do not fit `MdsSegmentedControl`'s one line; and a
 * choice of one-of-N with a label each is what a fieldset of `MdsRadio`s is for. One `name` per form, so a hub
 * page and a builder modal could never share a group by accident.
 *
 * The swatches are `aria-hidden`: they repeat, in colour, what the label and description already say in words.
 * Their grounds are FIXED — the real light and dark surfaces — because they show what a respondent sees in each
 * theme, which is a different question from what this author's own theme is (`BrandingCard`'s rule).
 *
 * The optimistic write and its revert follow `PageModePanel`: a control that waits for a round trip reads as
 * broken, and reopening re-seeds from the server so a refused choice cannot linger.
 */
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { MdsRadio } from '@meridian/design-system';
import type { ThemePresetOption } from '@/components/forms/types';

/** The workspace brand: the absence of a preset, which `MdsRadio`'s string model spells as ''. */
const WORKSPACE = '';

const props = defineProps<{
    /** Whether the settings are open — see `ConfirmationPanel`'s prop note for why, not the section. */
    open: boolean;
    formId: string;
    preset: string | null;
    presets: ThemePresetOption[];
}>();

const selected = ref(props.preset ?? WORKSPACE);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        selected.value = props.preset ?? WORKSPACE;
    },
    { immediate: true },
);

function choose(value: string): void {
    if (value === selected.value) return;

    const previous = selected.value;
    selected.value = value;

    router.patch(
        `/forms/${props.formId}/theme`,
        { preset: value === WORKSPACE ? null : value },
        {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                selected.value = previous;
            },
        },
    );
}

const groupName = `form-theme-${props.formId}`;
</script>

<template>
    <div class="theme-panel">
        <p class="theme-panel__prose">
            Choose how this form looks to respondents. Each theme sets the colour of buttons, links and focus rings,
            and some also change the type or the corners. Every theme is checked for contrast in light and dark.
        </p>

        <fieldset class="theme-panel__group">
            <legend class="theme-panel__legend">Theme</legend>

            <ul class="theme-panel__options" role="list">
                <li class="theme-panel__option" :class="{ 'theme-panel__option--on': selected === WORKSPACE }">
                    <MdsRadio
                        :id="`${groupName}-workspace`"
                        :model-value="selected"
                        :value="WORKSPACE"
                        label="Workspace brand"
                        :name="groupName"
                        :describedby="`${groupName}-workspace-desc`"
                        @update:model-value="choose"
                    />
                    <p :id="`${groupName}-workspace-desc`" class="theme-panel__desc">
                        Your workspace's brand colour, or the product's own when none is set. The default.
                    </p>
                </li>

                <li
                    v-for="option in presets"
                    :key="option.value"
                    class="theme-panel__option"
                    :class="{ 'theme-panel__option--on': selected === option.value }"
                    :data-theme-option="option.value"
                >
                    <MdsRadio
                        :id="`${groupName}-${option.value}`"
                        :model-value="selected"
                        :value="option.value"
                        :label="option.label"
                        :name="groupName"
                        :describedby="`${groupName}-${option.value}-desc`"
                        @update:model-value="choose"
                    />
                    <p :id="`${groupName}-${option.value}-desc`" class="theme-panel__desc">
                        {{ option.description }} {{ option.font }}, {{ option.radius.toLowerCase() }}.
                    </p>
                    <div class="theme-panel__swatches" aria-hidden="true">
                        <span
                            v-for="mode in (['light', 'dark'] as const)"
                            :key="mode"
                            class="theme-panel__swatch"
                            :class="`theme-panel__swatch--${mode}`"
                        >
                            <span class="theme-panel__fake-button" :style="{ background: option.tokens[mode].bg }">Submit</span>
                            <span class="theme-panel__fake-link" :style="{ color: option.tokens[mode].fg }">Link</span>
                            <span class="theme-panel__fake-tint" :style="{ background: option.tokens[mode].tint }">Selected</span>
                        </span>
                    </div>
                </li>
            </ul>
        </fieldset>

        <p class="theme-panel__note">
            This saves as soon as you choose. Respondents see it the next time they open the form.
        </p>
    </div>
</template>

<style scoped>
.theme-panel__prose {
    margin: 0 0 var(--mds-space-4);
    color: var(--mds-color-text-body);
}

.theme-panel__group {
    margin: 0;
    padding: 0;
    border: 0;
    min-inline-size: 0;
}

.theme-panel__legend {
    margin-bottom: var(--mds-space-3);
    padding: 0;
    color: var(--mds-color-text-heading);
    font-weight: var(--mds-font-weight-semibold);
}

.theme-panel__options {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

/* The selected option carries a heavier border as well as the radio's dot — never colour alone (WCAG 1.4.1). */
.theme-panel__option {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    min-inline-size: 0;
}

.theme-panel__option--on {
    border: 2px solid var(--mds-color-action-primary-fg);
    padding: calc(var(--mds-space-3) - 1px);
}

.theme-panel__desc {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.theme-panel__swatches {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
}

.theme-panel__swatch {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
    padding: var(--mds-space-2);
    border-radius: var(--mds-radius-sm);
    min-inline-size: 0;
}

/* The real light and dark `--mds-color-bg-surface` and ink — see the header for why these are fixed. */
.theme-panel__swatch--light {
    background: #ffffff;
    color: #121a2a;
    border: 1px solid var(--mds-color-border-default);
}

.theme-panel__swatch--dark {
    background: #1a2130;
    color: #eff4fd;
}

.theme-panel__fake-button {
    padding: var(--mds-space-1) var(--mds-space-2);
    border-radius: var(--mds-radius-sm);
    color: #ffffff;
    font-size: var(--mds-type-caption-font-size);
    font-weight: var(--mds-font-weight-semibold);
}

.theme-panel__fake-link {
    font-size: var(--mds-type-caption-font-size);
    text-decoration: underline;
}

.theme-panel__fake-tint {
    padding: var(--mds-space-1) var(--mds-space-2);
    border-radius: var(--mds-radius-sm);
    font-size: var(--mds-type-caption-font-size);
}

.theme-panel__note {
    margin: var(--mds-space-4) 0 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}
</style>
