<script setup lang="ts">
/**
 * The structured condition editor — H21d2, the write half of Doc #27 §8 and the other half of H-map row 254.
 *
 * ── ONE VALUE, ONE CONTROL, TWO MODES ───────────────────────────────────────────────────────────────
 * §8 required this component either to REPLACE `ConfigPanel`'s free-text relevance input or to coexist with
 * it — and said that coexistence means answering which one wins when they disagree. It replaces it, and the
 * answer is that they cannot disagree: there is one stored value (`relevant_expression`, still a `text`
 * column) and exactly one editor on screen at a time. The mode is DERIVED from the value on every render and
 * never stored, which is `ValidationEditor.vue`'s precedent for the same problem one increment earlier.
 *
 *  - blank or describable  → the structured rows, with an "Edit as text" escape;
 *  - opaque or invalid     → the raw text alone, and the structured tree is not mounted at all.
 *
 * ── READ-ONLY MEANS THE ROWS, NOT THE AUTHOR ────────────────────────────────────────────────────────
 * §8's "a non-representable expression renders read-only and is never rewritten" constrains THIS editor. It
 * does not mean an author loses the text box they have today: taking away the only way to edit
 * `${age} + 1 > 18` would be a regression dressed as a safety measure. So an opaque condition keeps a fully
 * editable textarea, and what is guaranteed is that nothing here ever parses it, re-prints it, or touches a
 * byte of it.
 *
 * That guarantee is mechanical rather than careful: the draft is seeded from the prop and `update:expression`
 * is emitted ONLY from a user mutation — never from a watcher, never on mount — and `publish()` refuses to
 * emit a value equal to the one already stored. Opening a node cannot write to it.
 *
 * ── THE WRITE PATH IS THE EXISTING ONE ──────────────────────────────────────────────────────────────
 * There is no new endpoint. `ConfigPanel` feeds this straight into `setField`/`setSection` → `store.touch()`
 * → the same debounced `PATCH /forms/{form}/fields/{field}` every other config control uses, which is what
 * inherits `builderClient`'s typed 409 rather than re-implementing it. §8 named a new write endpoint as the
 * obvious place to lose that contract; the cheapest way not to lose it is not to add one.
 */
import { MdsButton, MdsTextarea } from '@meridian/design-system';
import { computed, ref, watch } from 'vue';

import ConditionRows from './ConditionRows.vue';
import { describe, type LabelLookup } from './condition-describer';
import {
    isComplete,
    normalize,
    parseExpression,
    serialize,
    toCondition,
    type Condition,
    type SerializeRefusal,
} from './condition-model';
import type { ConditionCatalogue } from './types';

type Group = Extract<Condition, { kind: 'group' }>;

const props = defineProps<{
    expression: string | null;
    catalogue: ConditionCatalogue;
    /** "Show this question only when…" / "Show this section only when…" — the fieldset's own name. */
    legend: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{ 'update:expression': [value: string | null] }>();

const EMPTY: Group = { kind: 'group', op: 'and', children: [] };

/**
 * The draft always presents as a GROUP so the recursive rows render one shape. `normalize()` unwraps a group
 * of one on the way out, so a single condition round-trips as itself rather than acquiring a wrapper.
 */
function seed(expression: string | null): Group {
    if (expression === null || expression.trim() === '') return { ...EMPTY };

    try {
        const condition = toCondition(parseExpression(expression));
        if (condition === null) return { ...EMPTY };

        return condition.kind === 'group' ? condition : { kind: 'group', op: 'and', children: [condition] };
    } catch {
        return { ...EMPTY };
    }
}

const draft = ref<Group>(seed(props.expression));
const refusal = ref<SerializeRefusal | null>(null);
const asText = ref(false);

/** What this component last wrote, so its own echo through the prop does not re-seed a live edit. */
let lastEmitted: string | null | undefined;

watch(
    () => props.expression,
    (next) => {
        if (next === lastEmitted) return;

        // An outside change — undo/redo, or a conflict resolved in `ConflictDialog`. Adopt it wholesale.
        draft.value = seed(next);
        refusal.value = null;
    },
);

/**
 * The same key→label map the rail builds, so the sentence under the rows and the sentence on the card are
 * the same sentence. Without it this component would read "age is more than 18" where the rail reads "Your
 * age is more than 18" — one reading rendered two ways, which is exactly the divergence the shared
 * classifier exists to prevent.
 */
const labels = computed<LabelLookup>(() => {
    const lookup: LabelLookup = {};
    // Sections first, then fields, matching `logic-rail.ts`'s `labelLookup()`: keys are not unique across
    // the two tables, and the two must resolve a collision the same way or they disagree about one word.
    props.catalogue.repeatables.forEach((s) => (lookup[s.key] = s.label));
    props.catalogue.fields.forEach((f) => (lookup[f.key] = f.label));

    return lookup;
});

const reading = computed(() => describe(props.expression ?? null, labels.value));
const representable = computed(() => reading.value.status === 'blank' || reading.value.status === 'described');
const structured = computed(() => representable.value && !asText.value);
const incomplete = computed(() => {
    const normalized = normalize(draft.value);

    return normalized !== null && !isComplete(normalized);
});

function publish(value: string | null): void {
    // Idempotence, and the whole of the never-rewritten guarantee at the seam: a value identical to the one
    // already stored is not a change, and emitting it would bump the row's version for nothing.
    if (value === (props.expression ?? null)) return;

    lastEmitted = value;
    emit('update:expression', value);
}

function onRowsChange(next: Group): void {
    draft.value = next;

    const normalized = normalize(next);
    if (normalized === null) {
        // The author emptied the editor. `null`, not `''` — `describe()` and `hasCondition()` both read the
        // empty string as no condition, but the column is nullable and null is what "no condition" is.
        refusal.value = null;
        publish(null);

        return;
    }

    if (!isComplete(normalized)) {
        // A row the author is halfway through. Held on screen, deliberately not written: a half-built
        // condition has no honest text form, and writing a guess at one is the failure §8 calls silent.
        refusal.value = null;

        return;
    }

    const result = serialize(normalized);
    if (result.text === undefined) {
        refusal.value = result.error;

        return;
    }

    refusal.value = null;
    publish(result.text);
}

function onTextChange(value: string): void {
    // The raw arm writes what was typed, verbatim and unparsed. Nothing here canonicalizes.
    publish(value.trim() === '' ? null : value);
}

function toText(): void {
    asText.value = true;
}

function toRows(): void {
    // Re-seed from whatever the author left in the textarea, so the rows show their text and not a stale tree.
    draft.value = seed(props.expression);
    refusal.value = null;
    asText.value = false;
}

const refusalMessage = computed<string | null>(() => {
    const error = refusal.value;
    if (error === null) return null;

    switch (error.reason) {
        case 'quotes':
            return 'A value can’t contain both a straight apostrophe and a double quote — conditions have no way to escape either. Use one or the other.';
        case 'number':
            return 'That number is too large, too small or too precise to write into a condition.';
        case 'key':
            return `“${error.key}” isn’t a usable question key, so this condition can’t be saved.`;
        case 'unparseable':
            return 'This condition is too long or too deeply nested to save. Try splitting it across a section and its questions.';
        case 'empty':
            return null;
    }
});

const note = computed<string | null>(() => {
    if (reading.value.status === 'opaque') {
        return 'This condition uses something the builder can’t show as rows — arithmetic, a nested function, or a negated group. It is shown exactly as you wrote it and is never rewritten.';
    }
    if (reading.value.status === 'invalid') {
        return `This condition doesn’t parse, so publishing will refuse it: ${reading.value.reason}`;
    }

    return null;
});
</script>

<template>
    <fieldset class="cond" :disabled="disabled">
        <legend class="cond__legend">{{ legend }}</legend>

        <template v-if="structured">
            <ConditionRows :group="draft" :catalogue="catalogue" path="" :disabled="disabled" @update:group="onRowsChange" />

            <p v-if="refusalMessage" class="cond__error" role="alert">{{ refusalMessage }}</p>
            <p v-else-if="incomplete" class="cond__pending">
                Finish every condition before this is saved — a question, a check and a value.
            </p>
            <p v-else-if="reading.status === 'described'" class="cond__reading">{{ reading.prose }}</p>
            <p v-else class="cond__reading">Always shown — no conditions yet.</p>

            <div class="cond__actions">
                <MdsButton variant="tertiary" size="sm" icon-left="edit" @click="toText">Edit as text</MdsButton>
            </div>
        </template>

        <template v-else>
            <p v-if="note" class="cond__note" :class="{ 'cond__note--invalid': reading.status === 'invalid' }">{{ note }}</p>

            <MdsTextarea
                :model-value="expression ?? ''"
                :rows="2"
                placeholder="e.g. ${age} > 18"
                aria-label="Condition expression"
                @update:model-value="onTextChange"
            />
            <p class="cond__help">
                Supports ${question} references, and/or/not, = != &gt; &lt; &gt;= &lt;=, arithmetic, and
                selected()/count(). Checked when you publish.
            </p>

            <div v-if="representable" class="cond__actions">
                <MdsButton variant="tertiary" size="sm" icon-left="filter" @click="toRows">Use the condition builder</MdsButton>
            </div>
        </template>
    </fieldset>
</template>

<style scoped>
.cond {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    border: 0;
    min-width: 0;
}

.cond__legend {
    padding: 0;
    margin-bottom: var(--mds-space-1);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-medium);
    /* `text-body`, matching `MdsFormField`'s own label — the legend IS this control group's label, and a
       different colour would make it read as a different kind of thing. There is no `text-primary` token. */
    color: var(--mds-color-text-body);
}

.cond__reading,
.cond__pending,
.cond__help {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.cond__reading {
    font-style: italic;
}

/* `status-danger-fg` and NOT `danger-text`: the latter is `danger-300` in dark, which is 4.22:1 on
   `bg-surface` at this size — the real contrast violation the dark builder-axe scan caught in H21d1. */
.cond__error,
.cond__note--invalid {
    color: var(--mds-color-status-danger-fg);
}

.cond__error,
.cond__note {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
}

.cond__note {
    color: var(--mds-color-text-secondary);
}

.cond__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
}
</style>
