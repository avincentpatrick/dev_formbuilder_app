<script setup lang="ts">
/**
 * A label edited where it is shown (M150, `R-34edf1f5`) — a question's row in Structure, and next a question in Preview
 * (`R-74c3cf35`), so it knows nothing about the store: it holds a draft and says how the edit ended.
 *
 * ── ONE ANSWER PER EDIT, NEVER ONE PER KEYSTROKE ───────────────────────────────────────────────────────────────────────
 * The settings pane writes its Label on every keystroke through the store's 600ms `touch()`, which suits a field that
 * stays open. Here that would save a half-typed label at any pause and leave an undo entry per pause, so the draft stays
 * local and the owner commits it once (`useBuilderStore.renameField`).
 *
 * `label` is the input's accessible name — the row shows no visible label beside it. Enter and blur commit; Escape
 * cancels. Each edit ends ONCE: Enter unmounts the input, and whether a browser then sends a blur for it is not something
 * to depend on. Enter while an input method is composing belongs to the IME, not to us.
 *
 * M176 (`R-923c5041`): a box that grows with a long question ("Label field is too small. It cannot accommodate long
 * questions"), wrapping instead of scrolling sideways. Still one line of text: Enter commits as before, and a pasted line
 * break becomes a space.
 */
import { onMounted, ref } from 'vue';
import { MdsTextarea } from '@meridian/design-system';
import { LABEL_MAX, oneLine } from './label-text';

const props = withDefaults(defineProps<{ value: string; label: string; maxlength?: number }>(), { maxlength: LABEL_MAX });
const emit = defineEmits<{ commit: [value: string, via: 'key' | 'blur']; cancel: [] }>();

const draft = ref(props.value);
const input = ref<{ $el: HTMLTextAreaElement } | null>(null);
let finished = false;

function commit(via: 'key' | 'blur'): void {
    if (finished) return;
    finished = true;
    emit('commit', draft.value, via);
}

function cancel(): void {
    if (finished) return;
    finished = true;
    emit('cancel');
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter') {
        if (event.isComposing) return;
        // A host may sit inside a <form> (Preview), whose implicit submission Enter would otherwise trigger.
        event.preventDefault();
        commit('key');
    } else if (event.key === 'Escape') {
        event.preventDefault();
        // The edit is what Escape closes here, not a drawer or a dialog around it.
        event.stopPropagation();
        cancel();
    }
}

onMounted(() => {
    const el = input.value?.$el;
    el?.focus();
    el?.select();
});

// The owner ends an edit itself when something takes over without moving focus — a drag's pointerdown is cancelled so
// it never blurs the input.
defineExpose({ commit });
</script>

<template>
    <MdsTextarea
        ref="input"
        :model-value="draft"
        class="inline-label-edit"
        autosize
        :aria-label="label"
        :maxlength="maxlength"
        @update:model-value="draft = oneLine($event)"
        @keydown="onKeydown"
        @blur="commit('blur')"
    />
</template>

<style scoped>
.inline-label-edit {
    flex: 1;
    min-width: 0;
}
</style>
