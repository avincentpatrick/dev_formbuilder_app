<script setup lang="ts">
/**
 * Quick Light/Dark/Match-System toggle in the top nav (exceptions-log #3 — a beyond-spec addition;
 * canonical home is Settings → Appearance). Shares the theme logic with the Settings panel.
 */
import { MdsSegmentedControl, type IconName } from '@meridian/design-system';
import type { ThemeMode } from '@/types/inertia';
import { useThemePreference } from '@/composables/useTheme';

const { mode, setMode } = useThemePreference();

const options: { value: ThemeMode; label: string; icon: IconName }[] = [
    { value: 'light', label: 'Light', icon: 'sun' },
    { value: 'dark', label: 'Dark', icon: 'moon' },
    { value: 'system', label: 'System', icon: 'monitor' },
];
</script>

<template>
    <MdsSegmentedControl
        class="theme-quick"
        :model-value="mode"
        :options="options"
        ariaLabel="Theme"
        compact
        @update:model-value="(v: string) => setMode(v as ThemeMode)"
    />
</template>

<style scoped>
/*
 * ── THREE STATES, AND EVERY THRESHOLD IS MEASURED RATHER THAN CHOSEN (J8) ──────────────────────────────
 * `MdsSegmentedControl` is `inline-flex` with no wrap and no overflow handling (exceptions-log #13 cost 5),
 * and it is the ONLY child of `.topnav__right` that declares `min-width: 0`. So when the bar runs out of
 * room this control absorbs the ENTIRE squeeze: the fieldset collapses while its content does not reflow,
 * and the labels paint straight out of the box and across the Feedback trigger beside them.
 * `.app-shell { overflow-x: clip }` then swallows the evidence — the document never widens, so every
 * `scrollWidth` gate in the suite stays green over an unreadable bar.
 *
 * Measured on the running dashboard. Spill of the content past the fieldset's own right edge, in px:
 *
 *                    960px   900px   860px   834px   800px   760px   700px   601px
 *   BEFORE, labelled
 *     standard           -       -       -     8.5    27.6    50.1    83.9   139.5
 *     large              -     3.5    25.7    40.1    58.9    81.1   114.3   169.1
 *     extra_large        -    31.0    52.7    66.8    85.2   106.9   139.4   193.1
 *   AFTER, icon-only (content 139px instead of 214–236px)
 *     standard           -       -       -       -       -     7.3    38.3    89.4
 *     large              -       -       -       -     8.0    28.0    58.0   107.6
 *     extra_large        -       -    -0.4    12.3    28.8    48.2    77.4   125.5
 *
 * ⚠️ IT IS NOT AN `extra_large` DEFECT, WHICH IS WHAT THE ROW THAT SCHEDULED THIS SAID. It bites at the
 * DEFAULT type scale from 834px down — and 834 is one of the three e2e viewport projects, so it has been
 * on screen in every tablet run this suite has ever made, unseen for the reason above.
 *
 * ⚠️ AND COLLAPSING TO ICONS IS NOT ENOUGH ON ITS OWN — the second table is why this control also has to
 * disappear rather than merely shrink. Icon-only content is 139px and the box keeps being squeezed below
 * it, so the spill returns at 760 / 800 / 834. There is no third thing to give up: `.fb`, the bell and the
 * account menu all keep their automatic minimum size, and the only remaining source of width is the search
 * field — which is NOT available, because global search is a standing product principle and this control
 * is a beyond-spec convenience whose canonical home is Settings → Appearance. The convenience yields.
 * (Wrapping is not on the table either: `.topnav` is a fixed 64px with `flex-shrink: 0`, so a wrapped
 * control trades a horizontal defect for a vertical one.)
 *
 * ⚠️ VISUALLY hidden, NEVER removed. `MdsIcon` is `aria-hidden` unless given a `label` and this control
 * passes none, so this span is the ONLY source of each radio's accessible name — `display: none` would
 * leave three unnamed radios. The glyph and the filled chip remain the WCAG 1.4.1 non-colour signifiers
 * without the word, which is why the collapse is legitimate here at all.
 *
 * `html[data-font-size]` is precisely "an enlarged scale": `FontSizeScale::attributeValue()` emits NO
 * attribute for Standard, so the bare-attribute selector matches `large` and `extra_large` and nothing
 * else. Do not rewrite it as an `:is()` list of the two values — it would drift the day a fourth step is
 * added, and the enum is the contract.
 */

/* ── 2. ICON-ONLY ─────────────────────────────────────────────────────────────────────────────────────
   The enlarged scales lose their labels first: measured, they still fit at 960 and spill at 900. */
@media (max-width: 959px) {
    html[data-font-size] .theme-quick :deep(.mds-segmented__seg > span) {
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

    /* §4.4's 44×44, the way `.topnav__search-compact` already does it in this same bar. The glyph stays
       16px; only the hit area grows. */
    html[data-font-size] .theme-quick :deep(.mds-segmented__seg) {
        min-width: 44px;
        min-height: 44px;
    }
}

/* The default scale holds its labels ~100px longer — it fits at 860 and spills at 834. */
@media (max-width: 859px) {
    .theme-quick :deep(.mds-segmented__seg > span) {
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

    .theme-quick :deep(.mds-segmented__seg) {
        min-width: 44px;
        min-height: 44px;
    }
}

/* ── 3. HIDDEN — Settings → Appearance still covers it here, which is this control's own argument ──────
   Both thresholds sit a viewport-step ABOVE the width where icon-only was measured to start spilling
   (760 for the default scale, 834 for `large`, 860 for `extra_large`), so neither is a boundary case.
   ⚠️ These SUPERSEDE the previous `max-width: 600px` rule rather than joining it — that rule is gone
   because it is now unreachable, not because the small-screen reasoning changed. */
@media (max-width: 899px) {
    html[data-font-size] .theme-quick {
        display: none;
    }
}

@media (max-width: 799px) {
    .theme-quick {
        display: none;
    }
}
</style>
