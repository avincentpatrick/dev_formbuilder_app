<script setup lang="ts">
/**
 * A form's presentation mode (`D35`, row `R-f1332829`) — PATCH /forms/{form}/page-mode, gated
 * `can:update,form` alone. There is no `feature:` gate because the entitlement catalog holds no key for
 * presentation mode; {@see \App\Http\Requests\Forms\UpdatePageModeRequest} states that in full.
 *
 * ⛔ THIS IS THE COLUMN'S FIRST WRITER OF ANY KIND. `forms.single_page_mode` shipped with the first forms
 * migration and was read by the guest runtime and by manual encoding all along, but nothing outside three
 * seeder lines and one test had ever written it — so both single-page branches were unreachable for a real
 * tenant. This control is what makes them reachable, which is a wider change than "the builder gained a
 * setting" and is recorded as such at the row.
 *
 * ── WHY `MdsSegmentedControl` HERE, WHEN THIS FILE'S HOST REFUSES IT ──────────────────────────────
 * {@link FormSettingsModal}'s docblock states that `MdsSegmentedControl` "CANNOT be used here", and that
 * refusal stands — but read its clauses: five segments do not fit a 520px dialog, and the control has no
 * vertical orientation. Both are properties of the RAIL, which is a five-entry vertical list. This is a
 * TWO-option horizontal choice in the panel body, which is the shape the control is for, and it is the
 * repo's radiogroup — the alternative is a lone checkbox that can only name one of the two modes and
 * leaves the default implied. The host's refusal is amended to name its own scope rather than weakened.
 *
 * ⚠️ AND IT CARRIES `D28`'s HOST GUARD, WHICH IS NOT OPTIONAL. The control is `inline-flex` with no wrap,
 * and the settings panel becomes a full-screen sheet below 480px where an author's text-size preference
 * can widen two segments past the shell. `min-width: 0` was measured inert on a host the control is already
 * the width of (`M109`) — what overflows is the control's own flex line, so the line is what wraps. Same
 * rule, same reason, as `PreviewStepStrip` and the two hosts before it.
 *
 * ⚠️ The visually-hidden-input idiom this control uses is guarded inside the design-system component, and
 * `clipped-node-containment.test.ts` scans each file's own source for that idiom — so it is named here and
 * deliberately never written out, which is the trap `PreviewRuntime`'s header records paying for.
 *
 * The optimistic write and its revert follow {@link SaveResumePanel}: a control that waits for a round trip
 * reads as broken, and reopening the modal re-seeds from the server so a reverted value cannot linger.
 */
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { MdsSegmentedControl } from '@meridian/design-system';

/** The two values the boolean expresses, as the control's string model (`D57`: two modes, not an enum). */
const STEPPED = 'stepped';
const ONE_PAGE = 'one-page';

const OPTIONS = [
    { value: STEPPED, label: 'Step by step' },
    { value: ONE_PAGE, label: 'One page' },
];

const props = defineProps<{
    /** Whether the SETTINGS MODAL is open — see `ConfirmationPanel`'s prop note for why, not the section. */
    open: boolean;
    formId: string;
    singlePageMode: boolean;
}>();

const mode = ref(props.singlePageMode ? ONE_PAGE : STEPPED);

// Re-seed on open like every other section, and for `SaveResumePanel`'s second reason too: if a write was
// refused and reverted, reopening the modal shows what the server actually holds.
watch(
    () => props.open,
    (open) => {
        if (!open) return;
        mode.value = props.singlePageMode ? ONE_PAGE : STEPPED;
    },
    { immediate: true },
);

function onChange(value: string): void {
    const previous = mode.value;
    mode.value = value;

    router.patch(
        `/forms/${props.formId}/page-mode`,
        // The payload key is the COLUMN name while everything around it is named for the setting: the column
        // name encodes one of the two values, so it reads wrong at the `false` end, but keeping it on the wire
        // is what lets the rule, the service and the column be read as one thing.
        { single_page_mode: value === ONE_PAGE },
        {
            preserveScroll: true,
            preserveState: true,
            // Revert the optimistic choice if the write is rejected — e.g. editing rights lost mid-session.
            onError: () => {
                mode.value = previous;
            },
        },
    );
}
</script>

<template>
    <div class="page-mode">
        <p class="page-mode__prose">
            Choose how a respondent moves through this form. Step by step shows one section at a time with
            Next and Back; one page shows every section in a single scroll with one Submit at the end.
        </p>

        <MdsSegmentedControl
            :model-value="mode"
            :options="OPTIONS"
            ariaLabel="How respondents move through this form"
            @update:model-value="onChange"
        />

        <p class="page-mode__note">
            Step by step is the default for a new form. This saves as soon as you change it — there is
            nothing to confirm.
        </p>
    </div>
</template>

<style scoped>
.page-mode__prose {
    margin: 0 0 var(--mds-space-4);
    color: var(--mds-color-text-body);
}

.page-mode__note {
    margin: var(--mds-space-4) 0 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

/**
 * `D28`'s host guard — see the header. The control is `inline-flex` with no wrap, and this panel becomes a
 * full-screen sheet below 480px. `.mds-segmented` is the child's ROOT element, so it carries this scope id
 * and no `:deep` is needed — which is exactly how `PreviewStepStrip` writes the same rule.
 */
.page-mode .mds-segmented {
    flex-wrap: wrap;
}
</style>
