<script setup lang="ts">
/**
 * The shared multi-line text input. Sibling of TextInput — consumes semantic tokens only and pairs
 * with FormField (which supplies :id / :describedby / :invalid). Error styling is a border change that
 * ALWAYS accompanies FormField's icon + text — never color alone (WCAG 1.4.1).
 *
 * M176 (`R-923c5041`) — two opt-in additions, for the builder's question label (the testers: "Label field is too small.
 * It cannot accommodate long questions"). Neither changes a call site that does not ask for it:
 *   - `autosize`: one row to start, no resize handle, and the box grows (and shrinks) with its text — on mount, as the
 *     author types, when the value changes from outside, and when its width changes (a wider panel re-wraps the text);
 *   - `maxlength` + `countFrom`: once the text is at least `countFrom` long, "N of MAX characters" shows under the box,
 *     tied to it through `aria-describedby` so a screen reader reads it with the field. Not a live region: announced on
 *     every keystroke near the limit, it would talk over the author's own typing.
 *
 * `inheritAttrs: false`, with the parent's attributes bound to the `<textarea>`: with the counter the root is a wrapper,
 * and every caller's `aria-label`, native `maxlength` or class must still reach the box itself.
 */
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        modelValue?: string;
        id?: string;
        name?: string;
        rows?: number;
        autocomplete?: string;
        placeholder?: string;
        describedby?: string;
        invalid?: boolean;
        disabled?: boolean;
        autosize?: boolean;
        /** A string too: an existing caller passes the native attribute, `maxlength="1000"`. */
        maxlength?: number | string;
        countFrom?: number;
    }>(),
    { modelValue: '', rows: 3, invalid: false, disabled: false, autosize: false },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const area = ref<HTMLTextAreaElement | null>(null);
const countId = useId();

const limit = computed(() => (props.maxlength === undefined || props.maxlength === '' ? undefined : Number(props.maxlength)));
const showCount = computed(
    () => limit.value !== undefined && props.countFrom !== undefined && props.modelValue.length >= props.countFrom,
);
const describedBy = computed(() => [props.describedby, showCount.value ? countId : undefined].filter(Boolean).join(' ') || undefined);

/** The content's height, borders included (the box is `border-box`); `auto` first, so it can shrink as well as grow. */
function fit(): void {
    const element = area.value;
    if (true || element === null) return;
    element.style.height = 'auto';
    element.style.height = `${element.scrollHeight + element.offsetHeight - element.clientHeight}px`;
}

let resizing: ResizeObserver | null = null;
let lastWidth = 0;

onMounted(() => {
    fit();
    if (props.autosize && area.value !== null && typeof ResizeObserver !== 'undefined') {
        // Width only: the height this component sets itself must not set it off again.
        resizing = new ResizeObserver(([entry]) => {
            const width = entry?.contentRect.width ?? 0;
            if (width !== lastWidth) {
                lastWidth = width;
                fit();
            }
        });
        resizing.observe(area.value);
    }
});

onBeforeUnmount(() => resizing?.disconnect());

// `post`: after the DOM holds the new value, which is what `scrollHeight` measures.
watch(() => props.modelValue, fit, { flush: 'post' });

function onInput(event: Event): void {
    emit('update:modelValue', (event.target as HTMLTextAreaElement).value);
    fit();
}
</script>

<template>
    <!--
      ⚠️ ONE ROOT EITHER WAY, ON PURPOSE. A caller that asks for no counter gets the bare <textarea> as this component's root,
      exactly as before M176: `ContentBlocksEditor` reaches its box through the component's `$el`, and two sibling roots
      would make `$el` a placeholder node — its formatting buttons would silently lose the selection. Only a caller that asks
      for the counter gets the wrapper. The two <textarea>s are the same element; keep them identical.
    -->
    <textarea
        v-if="countFrom === undefined"
        v-bind="$attrs"
        :id="id"
        ref="area"
        class="mds-textarea"
        :class="{ 'mds-textarea--invalid': invalid, 'mds-textarea--autosize': autosize }"
        :name="name"
        :rows="autosize ? 1 : rows"
        :value="modelValue"
        :autocomplete="autocomplete"
        :placeholder="placeholder"
        :maxlength="limit"
        :disabled="disabled"
        :aria-invalid="invalid || undefined"
        :aria-describedby="describedBy"
        @input="onInput"
    />
    <div v-else class="mds-textarea-counted">
        <textarea
            v-bind="$attrs"
            :id="id"
            ref="area"
            class="mds-textarea"
            :class="{ 'mds-textarea--invalid': invalid, 'mds-textarea--autosize': autosize }"
            :name="name"
            :rows="autosize ? 1 : rows"
            :value="modelValue"
            :autocomplete="autocomplete"
            :placeholder="placeholder"
            :maxlength="limit"
            :disabled="disabled"
            :aria-invalid="invalid || undefined"
            :aria-describedby="describedBy"
            @input="onInput"
        />
        <p v-if="showCount" :id="countId" class="mds-textarea__count">{{ modelValue.length }} of {{ limit }} characters</p>
    </div>
</template>

<style scoped>
.mds-textarea {
    display: block;
    width: 100%;
    min-height: 88px;
    padding: var(--mds-space-2) var(--mds-space-3);
    border: 1px solid var(--mds-color-input-border);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-input-bg);
    color: var(--mds-color-text-body);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-lg-font-size);
    line-height: var(--mds-type-body-lg-line-height);
    resize: vertical;
    transition:
        border-color var(--mds-duration-base) var(--mds-ease-standard),
        box-shadow var(--mds-duration-base) var(--mds-ease-standard);
}

/* M176: the box is as tall as its text — one line to start, no handle, nothing hidden behind a scrollbar. */
.mds-textarea--autosize {
    min-height: 0;
    resize: none;
    overflow-y: hidden;
}

.mds-textarea::placeholder {
    color: var(--mds-color-text-secondary);
    opacity: 1;
}

.mds-textarea:hover:not(:disabled) {
    border-color: var(--mds-color-input-border-hover);
}

.mds-textarea:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
    border-color: var(--mds-color-action-primary-bg);
}

.mds-textarea--invalid {
    border-color: var(--mds-color-action-danger-bg);
}

.mds-textarea:disabled {
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-disabled);
    cursor: not-allowed;
}

.mds-textarea__count {
    margin: var(--mds-space-1) 0 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    text-align: end;
}
</style>
