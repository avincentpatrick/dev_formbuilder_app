<script setup lang="ts">
/**
 * The keyword control inside a {@link FilterBar} (Increment J1e, DSR §3.2).
 *
 * A labelled `MdsTextInput type="search"` that emits `submit` when the user commits a query — Enter, or
 * blurring after a change. Six list pages use it, and each turns that one event into an Inertia visit.
 *
 * ⚠️ NO HTML TAG IS SPELLED WITH ANGLE BRACKETS ANYWHERE IN THIS COMMENT — see `FilterBar.vue`'s docblock
 * for the Storybook-only build failure that convention avoids.
 *
 * ── ⚠️ IT NEVER TAKES A `disabled` PROP, AND THE OMISSION IS THE DESIGN ────────────────────────────────
 * Every other control in a filter bar binds `:disabled="busy"` while a round-trip is in flight, which is
 * right for a one-shot `MdsSelect`. It is wrong here: disabling a focused text input BLURS it, so a user
 * who keeps typing loses the caret and the rest of their word goes nowhere. There is no prop to pass, so no
 * page can reintroduce it by copying its neighbour — and the spec asserts the rendered input carries no
 * `disabled` attribute.
 *
 * ── ⚠️ WHY BOTH HANDLERS, AND WHY THEY NEED A LATCH ────────────────────────────────────────────────────
 * Committing a text input fires the native `change` event, and browsers fire it on Enter as well as on
 * blur — so `@change` alone would very nearly be enough. "Very nearly" is not a good enough contract for
 * the primary interaction of a search box, so Enter is bound explicitly too. The cost is that a changed
 * value plus Enter fires BOTH, and unguarded that is two full Inertia visits for one keystroke, the second
 * racing the first with `replace: true` on both.
 *
 * `committed` is that guard, and it deliberately does NOT test against `applied` directly: the two handlers
 * both fire long before the server answers, so `applied` is still the OLD value for both of them and would
 * catch nothing. The latch moves synchronously; `applied` is what re-seeds it once the answer lands.
 *
 * ── ⚠️ AND THE RE-SEED IS WHAT MAKES "CLEAR, THEN RETYPE THE SAME THING" WORK ──────────────────────────
 * Without the watcher, a private latch remembers `clinic` across a "Clear filters" that reset the list to
 * everything — so typing `clinic` again and pressing Enter would be silently ignored, with the box reading
 * `clinic` over an unfiltered list. Watching `applied` (what the SERVER last ran) is what keeps the latch
 * describing the world rather than describing this component's history.
 *
 * ── The locator ────────────────────────────────────────────────────────────────────────────────────────
 * `type="search"`'s implicit role is `searchbox`, NOT `textbox` (DSR §3.2 note 1). Tests reach this with
 * `getByRole('searchbox', { name: … })`.
 */
import { ref, watch } from 'vue';
import FormField from '../FormField/FormField.vue';
import TextInput from '../TextInput/TextInput.vue';

const props = withDefaults(
    defineProps<{
        modelValue?: string;
        /** What the SERVER last filtered by. Re-seeds the latch; never read for display. */
        applied?: string;
        /** Overridden per page so a screen reader hears "Search forms", not six identical "Search"es. */
        label?: string;
        placeholder?: string;
        inputId?: string;
    }>(),
    { modelValue: '', applied: '', label: 'Search' },
);

const emit = defineEmits<{ 'update:modelValue': [value: string]; submit: [] }>();

const committed = ref(props.applied);

watch(
    () => props.applied,
    (value) => {
        // ⚠️ THE BOX ADOPTS WHAT THE SERVER ACTUALLY RAN, WHICH IS NOT ALWAYS WHAT WAS TYPED. Every
        // presenter echoes `SearchTerms::raw()` — the CLAMPED, trimmed query — precisely so a 300-character
        // paste re-renders as the 200 characters that ran; without this line that echo has no reader and
        // the box sits there disagreeing with the list beneath it, re-submitting on every Enter because its
        // value never matches what came back.
        //
        // Guarded on `modelValue === committed`, i.e. "the user has not typed since they hit Enter". They
        // are perfectly entitled to keep typing while the request is in flight — that is the whole reason
        // this input is never disabled — and overwriting them mid-word would be a worse bug than the one
        // being fixed.
        if (props.modelValue === committed.value && props.modelValue !== value) {
            emit('update:modelValue', value);
        }

        committed.value = value;
    },
);

function commit(): void {
    if (props.modelValue === committed.value) return;
    committed.value = props.modelValue;
    emit('submit');
}
</script>

<template>
    <FormField :label="label" :input-id="inputId" v-slot="{ id, describedby }">
        <TextInput
            :id="id"
            type="search"
            :model-value="modelValue"
            :placeholder="placeholder"
            :describedby="describedby"
            @update:model-value="emit('update:modelValue', $event)"
            @keyup.enter="commit"
            @change="commit"
        />
    </FormField>
</template>
