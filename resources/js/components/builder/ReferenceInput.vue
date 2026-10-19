<script setup lang="ts">
/**
 * A text box with the "Insert a question" picker (M178, `R-75719ac4`). The testers: *"direct reference the field (not
 * the entire field question but the field code should be enough)"*; the user: *"all the type of question must include
 * the referencing"*. Typing `${code}` by hand still works; this adds two ways not to:
 *
 *   - the "Insert a question" button opens a search over the spot's questions — `MdsCombobox`, grouped by section, each
 *     row its label and its code — and the chosen one is written at the caret;
 *   - typing `@` at the start of a word, or `${`, opens the same list under the box, narrowed as the author types; ↑/↓
 *     move, Enter picks the highlighted one, Escape closes it and leaves the text, Tab closes it and moves on.
 *
 * Either way the write is `insertAtCaret()` — `execCommand('insertText')`, so the box's own undo takes it back in one
 * step and a `v-model` hears it as typing. What a spot offers is decided by `referenceCandidates()`; an empty list
 * renders the bare box, so a caller with nothing to offer (the hub's settings page) gets no picker at all.
 *
 * ⛔ IN THE FLOW, NEVER FLOATING. Every spot sits in a scroll container (the settings panel, a modal body), where DSR §3.4b
 * forbids a menu; the list pushes what is below it down, as "Add a question here" does (`AddQuestionHere.vue`).
 *
 * ⚠️ The box keeps DOM focus throughout the `@` path (a combobox pattern on a text box: `aria-autocomplete="list"`, with
 * `aria-controls` / `aria-activedescendant` set only while the list exists), and options take `mousedown.prevent`, so a
 * pointer pick never blurs the box. Keys pressed while an IME is composing are left alone.
 */
import { computed, nextTick, ref, useId } from 'vue';
import { MdsButton, MdsCombobox, MdsTextInput, MdsTextarea, type ComboboxOption } from '@meridian/design-system';
import { insertAtCaret } from './insert-at-caret';
import { DATE_GROUP, findTrigger, matchCandidates, type ReferenceCandidate } from './reference-candidates';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        modelValue: string;
        candidates: ReferenceCandidate[];
        /** A one-line box (`MdsTextInput`) instead of a textarea. */
        singleLine?: boolean;
        disabled?: boolean;
    }>(),
    { singleLine: false, disabled: false },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const control = ref<{ field: HTMLTextAreaElement | HTMLInputElement | null } | null>(null);
const listId = useId();
const searchInputId = useId();

const trigger = ref<{ from: number; query: string } | null>(null);
const active = ref(0);
const searching = ref(false);
const search = ref('');
const notice = ref('');

const offers = computed(() => props.candidates.length > 0 && !props.disabled);
const matches = computed(() => (trigger.value === null ? [] : matchCandidates(props.candidates, trigger.value.query)));

/** Consecutive matches of one section, for the list's groups; `index` is the position `active` counts in. */
const runs = computed(() => {
    const out: { group: string; items: { candidate: ReferenceCandidate; index: number }[] }[] = [];
    matches.value.forEach((candidate, index) => {
        const last = out[out.length - 1];
        if (last !== undefined && last.group === candidate.group) last.items.push({ candidate, index });
        else out.push({ group: candidate.group, items: [{ candidate, index }] });
    });

    return out;
});

const optionId = (index: number): string => `${listId}-${index}`;

/** A row reads "Label · code", or the code alone for a question with no label; Today has no code to show. */
function rowText(candidate: ReferenceCandidate): string {
    return candidate.group === DATE_GROUP || candidate.label === candidate.key ? candidate.label : candidate.label;
}

const searchOptions = computed<ComboboxOption[]>(() =>
    matchCandidates(props.candidates, search.value).map((candidate) => ({ key: candidate.key, label: rowText(candidate), group: candidate.group })),
);

const status = computed(() => {
    if (notice.value !== '') return notice.value;
    if (trigger.value === null) return '';

    return matches.value.length === 1 ? '1 question matches' : `${matches.value.length} questions match`;
});

function box(): HTMLTextAreaElement | HTMLInputElement | null {
    return control.value?.field ?? null;
}

/** After every keystroke and click: does the text before the caret ask for a question? */
function readTrigger(): void {
    const element = box();
    if (element === null || !offers.value) {
        trigger.value = null;
        return;
    }

    const next = findTrigger(element.value.slice(0, element.selectionStart ?? element.value.length));
    if (next?.from !== trigger.value?.from) active.value = 0;
    trigger.value = next;
    notice.value = '';
}

function write(candidate: ReferenceCandidate, from?: number): void {
    const element = box();
    if (element === null) return;

    notice.value = insertAtCaret(element, candidate.insert, from) ? '' : 'There is no room left in this box for that question.';
}

function pick(candidate: ReferenceCandidate): void {
    const from = trigger.value?.from;
    trigger.value = null;
    write(candidate, from);
}

function onKeydown(event: KeyboardEvent): void {
    if (trigger.value === null || event.isComposing) return;

    const count = matches.value.length;

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        if (count === 0) return;
        event.preventDefault();
        active.value = (active.value + (event.key === 'ArrowDown' ? 1 : count - 1)) % count;
    } else if (event.key === 'Enter') {
        const chosen = matches.value[active.value];
        if (chosen === undefined) return;
        event.preventDefault();
        pick(chosen);
    } else if (event.key === 'Escape') {
        // Stopped here so a surrounding modal stays open: this Escape belongs to the list.
        event.preventDefault();
        event.stopPropagation();
        trigger.value = null;
    } else if (event.key === 'Tab') {
        trigger.value = null;
    }
}

function openSearch(): void {
    search.value = '';
    notice.value = '';
    searching.value = true;
    void nextTick(() => document.getElementById(searchInputId)?.focus());
}

function closeSearch(): void {
    searching.value = false;
    box()?.focus();
}

function onSearchSelect(option: ComboboxOption): void {
    const chosen = props.candidates.find((candidate) => candidate.key === option.key);
    searching.value = false;
    if (chosen !== undefined) write(chosen);
}
</script>

<template>
    <div class="reference-input">
        <component
            :is="singleLine ? MdsTextInput : MdsTextarea"
            ref="control"
            v-bind="$attrs"
            :model-value="modelValue"
            :disabled="disabled"
            :aria-autocomplete="offers ? 'list' : undefined"
            :aria-controls="trigger !== null ? listId : undefined"
            :aria-activedescendant="trigger !== null && matches.length > 0 ? optionId(active) : undefined"
            @update:model-value="emit('update:modelValue', $event)"
            @input="readTrigger"
            @click="readTrigger"
            @keydown="onKeydown"
            @blur="trigger = null"
        />

        <div v-if="trigger !== null" :id="listId" class="reference-input__list" role="listbox" aria-label="Questions">
            <div v-for="run in runs" :key="run.group" class="reference-input__group" role="group" :aria-label="run.group">
                <p class="reference-input__group-heading" aria-hidden="true">{{ run.group }}</p>
                <div
                    v-for="entry in run.items"
                    :id="optionId(entry.index)"
                    :key="entry.candidate.key"
                    class="reference-input__option"
                    :class="{ 'is-active': entry.index === active }"
                    role="option"
                    :aria-selected="entry.index === active"
                    @mousedown.prevent="pick(entry.candidate)"
                >
                    {{ rowText(entry.candidate) }}
                </div>
            </div>
            <p v-if="matches.length === 0" class="reference-input__empty">No question matches “{{ trigger.query }}”.</p>
        </div>

        <p class="reference-input__status" role="status">{{ status }}</p>

        <div v-if="offers" class="reference-input__tools">
            <template v-if="!searching">
                <MdsButton variant="tertiary" size="sm" icon-left="plus" @click="openSearch">Insert a question</MdsButton>
                <span class="reference-input__hint">or type @ in the box</span>
            </template>
            <div v-else class="reference-input__search" @keydown.esc.prevent.stop="closeSearch">
                <MdsCombobox
                    v-model="search"
                    :options="searchOptions"
                    :input-id="searchInputId"
                    label="Find a question"
                    listbox-label="Questions"
                    placeholder="Type a question or its code"
                    :status="searchOptions.length === 1 ? '1 question' : `${searchOptions.length} questions`"
                    @select="onSearchSelect"
                >
                    <template #empty>
                        <p class="reference-input__empty">No question matches “{{ search }}”.</p>
                    </template>
                </MdsCombobox>
                <div>
                    <MdsButton variant="tertiary" size="sm" @click="closeSearch">Cancel</MdsButton>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* `position: relative` is load-bearing: the visually hidden status line below needs a positioned ancestor here, or it
   escapes every scroll container on the way up (`clipped-node-containment.test.ts`). */
.reference-input {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    min-width: 0;
}

.reference-input__list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    max-height: 16rem;
    overflow-y: auto;
    padding: var(--mds-space-1);
    border: 1px solid var(--mds-color-input-border);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-input-bg);
}

.reference-input__group-heading {
    margin: 0;
    padding: var(--mds-space-1) var(--mds-space-2);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.reference-input__option {
    padding: var(--mds-space-2) var(--mds-space-3);
    border-radius: var(--mds-radius-sm);
    cursor: pointer;
}

.reference-input__option.is-active {
    background-color: var(--mds-color-action-primary-tint);
    color: var(--mds-color-text-heading);
}

.reference-input__empty,
.reference-input__hint {
    margin: 0;
    padding: var(--mds-space-1) var(--mds-space-2);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.reference-input__tools {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
}

.reference-input__search {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    width: 100%;
}

.reference-input__status {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    clip-path: inset(50%);
    white-space: nowrap;
}
</style>
