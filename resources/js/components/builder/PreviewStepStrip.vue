<script setup lang="ts">
/**
 * The builder preview's section strip (Increment M119, `R-ae391298`).
 *
 * ⛔ PROP-DRIVEN, WITH NO ACCESS TO THE RUNTIME, AND THAT IS THE POINT RATHER THAN A STYLE.
 * The preview's whole design is two channels: a LIVE render model drives every visible string, and the
 * FROZEN engine is asked only about relevance and step membership. A strip that injected the runtime could
 * reach `runtime.visibleSteps[].title` — resolved once off the frozen snapshot — and would then show a
 * section's old name for up to 300ms after a rename, directly beside the heading showing the new one. Taking
 * its labels as a prop makes that mistake unavailable, and makes this component testable without an engine.
 *
 * ⛔ A RADIOGROUP, NEVER A TABLIST, AND NEITHER HALF OF THAT IS NEGOTIABLE. Thirteen Playwright locators walk
 * `[role="tab"]` on the builder and four of them CLICK every match, so a second tablist would have its tabs
 * clicked mid-scan; `builder-axe.spec.ts` asserts the page holds exactly one. `MdsSegmentedControl` is a
 * native `<fieldset>` of radio inputs, so it carries radiogroup semantics WITH the arrow-key roving those
 * semantics promise, and writes no role attribute of its own. Hand-rolling `role="radiogroup"` would claim
 * the contract without implementing it, which `FormSettingsModal` refused for the same reason in `M117`.
 *
 * ⚠️ IT DEGRADES TO A SELECT RATHER THAN SCROLLING, above {@link PREVIEW_STRIP_MAX_SEGMENTS}. A segmented
 * control is `inline-flex` and never shrinks a segment below its longest word, so a twenty-section form
 * renders a bar no width can hold; a horizontally scrolling one would also be invisible to every overflow
 * gate in this repository, because `.builder__pane` CLIPS rather than scrolls.
 */
import { computed } from 'vue';
import { MdsSegmentedControl, MdsSelect } from '@meridian/design-system';
import { PREVIEW_STRIP_MAX_SEGMENTS, type PreviewStripOption } from './preview-model';

const props = defineProps<{
    /** One entry per visible step, already index-prefixed and resolved in the live model. */
    options: PreviewStripOption[];
    /** The step currently on screen, or `null` before the engine has settled on one. */
    currentKey: string | null;
}>();

const emit = defineEmits<{ go: [key: string] }>();

const asSegments = computed(() => props.options.length <= PREVIEW_STRIP_MAX_SEGMENTS);

/**
 * The empty string stands in for "no step yet".
 *
 * `MdsSegmentedControl` requires a `string`, and passing `null` through would select nothing while also
 * failing the prop type. An empty value matches no option, which renders as no segment checked — the honest
 * rendering of a preview whose engine has not produced a step.
 */
const selected = computed(() => props.currentKey ?? '');

function onChange(value: string): void {
    if (value !== '' && value !== props.currentKey) {
        emit('go', value);
    }
}
</script>

<template>
    <div class="preview__strip" data-preview-strip>
        <MdsSegmentedControl
            v-if="asSegments"
            :model-value="selected"
            :options="options"
            ariaLabel="Jump to section"
            compact
            @update:model-value="onChange"
        />
        <MdsSelect
            v-else
            :model-value="selected"
            :options="options"
            aria-label="Jump to section"
            @update:model-value="onChange"
        />
    </div>
</template>

<style scoped>
/*
 * ⚠️ A BLOCK ONLY FOR CONSISTENCY, AND THE STRONGER CLAIM THIS COMMENT ONCE MADE WAS FALSE.
 * It said a `display: flex` wrapper would let the fieldset's own `min-width: 0` absorb the overflow,
 * leaving `scrollWidth - clientWidth` reading zero while the labels spilled. MEASURED AT 375px WITH
 * `extra_large` AND OpenDyslexic, ON FORCED CONTENT LONG ENOUGH TO ACTUALLY OVERFLOW: the flex form
 * reports a spill of 123px and the block form 126px. Both see it. The claim was inferred from a green
 * gate and the gate was green for an entirely different reason — no seeded fixture produces a strip
 * wide enough to overflow at all, which is what `personalization-axe.spec.ts` now records and what a
 * filed row asks for a fixture to fix.
 *
 * So: a block because the three hosts on this page that already carry an element-level overflow read
 * are blocks, and one less shape to reason about is worth more than a preference. The guard below is
 * what actually prevents the spill, in either form.
 */
.preview__strip {
    padding-bottom: var(--mds-space-2);
}

/*
 * ⛔ `D28`'s HOST GUARD, AND IT IS THE ONLY THING STANDING BETWEEN A LONG SECTION NAME AND A CLIPPED
 * CONTROL. `MdsSegmentedControl` is an `inline-flex` with no wrap whose segments never shrink below
 * their longest word, and `D28` chose to guard at the host rather than change the shared component.
 * Section names are author-controlled and unbounded, so this host is strictly more exposed than the
 * two that already carry this rule — `Builder.vue`'s centre control and `ConfigPanel.vue`'s groups.
 *
 * ⚠️ `min-width: 0; max-width: 100%` DOES NOT WORK HERE and was measured not to: `M109` recorded that
 * it is inert on a host the control is already exactly the width of. What overflows is the control's
 * own flex LINE, so wrapping that line is the fix. `.builder__pane` CLIPS rather than scrolls, so
 * without this the spill is invisible to every document-level gate in the repository.
 */
.preview__strip .mds-segmented {
    flex-wrap: wrap;
}
</style>
