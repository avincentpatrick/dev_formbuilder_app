<script setup lang="ts">
/**
 * One row of the structured condition editor — a single comparison, emptiness test, entry count or
 * membership check (Increment H21d2). Pure: props down, a fresh condition up, no store.
 *
 * A row's SHAPE is decided by its OPERATOR, not by a mode switch: "is blank" makes a `blank`, "includes"
 * makes a `selected`, a repeat-section subject makes a `count`, everything else makes a `compare`. An author
 * changing "is" to "is blank" is changing their mind about a condition, not choosing a different editor.
 *
 * Both sides of a comparison are the same control on purpose. The set this editor may author is exactly the
 * set `condition-describer.ts` can read (Doc #27 amendment D2), and that set does not constrain operand
 * order — `18 < ${age}` and `${end} > ${start}` are both readable, so both must load here without a rewrite.
 * A narrower editor would need a narrower classifier, and a second classifier is the thing D2 forbids.
 *
 * It lives in its own file rather than inside `ConditionRows.vue` because `vue-tsc` does not carry a
 * discriminated-union narrowing from a `v-if` on the element into the expressions inside it; a typed prop
 * does the narrowing at the boundary, where it holds.
 */
import { MdsIconButton, MdsNumberInput, MdsSelect, MdsTextInput } from '@meridian/design-system';
import { computed } from 'vue';

import type { Comparator, Condition, Operand } from './condition-model';
import type { ConditionCatalogue, ConditionFieldOption, EnumOption } from './types';

type Row = Exclude<Condition, { kind: 'group' }>;

/** The `count()` subject is a call, not an `Operand`, so the row model carries it as its own arm. */
type Subject = Operand | { kind: 'count'; section: string };

type RowOperator = Comparator | 'blank' | 'not_blank' | 'includes' | 'excludes';

const props = defineProps<{
    row: Row;
    catalogue: ConditionCatalogue;
    /** "1", "2.3" — renders "Condition 2.3 operator" so every control has a unique accessible name. */
    ordinal: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{ 'update:row': [value: Row]; remove: [] }>();

/**
 * A row unpacked into every part any of its shapes could need. Derived fresh from the condition on each
 * render rather than held as local state: the group above is the single source of truth, and a parallel
 * model would be a second place a half-edited row could live.
 */
interface RowParts {
    subject: Subject;
    op: RowOperator;
    right: Operand;
    n: number;
    value: string;
}

const TEXT: Operand = { kind: 'text', value: '' };

const parts = computed<RowParts>(() => {
    const row = props.row;

    switch (row.kind) {
        case 'compare':
            return { subject: row.left, op: row.op, right: row.right, n: Number.NaN, value: '' };
        case 'blank':
            return { subject: row.subject, op: row.op === 'eq' ? 'blank' : 'not_blank', right: { ...TEXT }, n: Number.NaN, value: '' };
        case 'count':
            return { subject: { kind: 'count', section: row.section }, op: row.op, right: { ...TEXT }, n: row.n, value: '' };
        case 'selected':
            return {
                subject: { kind: 'field', key: row.field },
                op: row.negated ? 'excludes' : 'includes',
                right: { ...TEXT },
                n: Number.NaN,
                value: row.value,
            };
    }
});

function rowFrom(next: RowParts): Row {
    if (next.op === 'blank' || next.op === 'not_blank') {
        // A count subject has no emptiness test — `count()` always yields a number — so it degrades to the
        // nearest representable thing rather than producing a condition no parser would accept.
        const subject: Operand = next.subject.kind === 'count' ? { kind: 'field', key: next.subject.section } : next.subject;

        return { kind: 'blank', op: next.op === 'blank' ? 'eq' : 'neq', subject };
    }

    if (next.op === 'includes' || next.op === 'excludes') {
        // `selected()`'s first argument must be a bare reference. The parser allows any Value-kind
        // expression there; the describable set does not, and this editor stays inside that set.
        const key = next.subject.kind === 'field' ? next.subject.key : next.subject.kind === 'count' ? next.subject.section : '';

        return { kind: 'selected', field: key, value: next.value, negated: next.op === 'excludes' };
    }

    if (next.subject.kind === 'count') {
        return { kind: 'count', op: next.op, section: next.subject.section, n: next.n };
    }

    return { kind: 'compare', op: next.op, left: next.subject, right: next.right };
}

function patch(change: Partial<RowParts>): void {
    emit('update:row', rowFrom({ ...parts.value, ...change }));
}

// ── the controls ────────────────────────────────────────────────────────────────────────────────────

const LITERAL = 'literal';

const subjectGroups = computed(() => {
    const groups = [
        { label: 'Questions', options: props.catalogue.fields.map((f) => ({ value: `field:${f.key}`, label: f.label })) },
    ];

    if (props.catalogue.repeatables.length > 0) {
        groups.push({
            label: 'Repeat sections',
            options: props.catalogue.repeatables.map((s) => ({ value: `count:${s.key}`, label: `Entries in ${s.label}` })),
        });
    }

    groups.push({ label: 'Other', options: [{ value: LITERAL, label: 'A fixed value' }] });

    return groups;
});

/** The right-hand side of a comparison is a question or a fixed value — never a count, per `countClause`. */
const objectGroups = computed(() => [
    { label: 'Other', options: [{ value: LITERAL, label: 'A fixed value' }] },
    { label: 'Questions', options: props.catalogue.fields.map((f) => ({ value: `field:${f.key}`, label: f.label })) },
]);

const subjectValue = computed<string>(() => {
    const subject = parts.value.subject;
    if (subject.kind === 'count') return `count:${subject.section}`;

    return subject.kind === 'field' ? `field:${subject.key}` : LITERAL;
});

function setSubject(value: string): void {
    const previous = parts.value.subject;

    if (value.startsWith('field:')) {
        patch({ subject: { kind: 'field', key: value.slice(6) } });

        return;
    }
    if (value.startsWith('count:')) {
        patch({ subject: { kind: 'count', section: value.slice(6) } });

        return;
    }

    // Switching to a fixed value keeps whatever literal was already there, so flipping between a question
    // and a value does not silently discard what the author typed.
    patch({ subject: previous.kind === 'field' || previous.kind === 'count' ? { ...TEXT } : previous });
}

function setObject(value: string): void {
    const previous = parts.value.right;
    const next: Operand = value.startsWith('field:')
        ? { kind: 'field', key: value.slice(6) }
        : previous.kind === 'field'
          ? { ...TEXT }
          : previous;

    patch({ right: retype(next, parts.value) });
}

const COMPARATOR_LABELS: Record<Comparator, string> = {
    eq: 'is',
    neq: 'is not',
    gt: 'is more than',
    lt: 'is less than',
    gte: 'is at least',
    lte: 'is at most',
};

/** The same six against a count, where "is more than 0" reads as nonsense beside a repeat section. */
const COUNT_LABELS: Record<Comparator, string> = {
    eq: 'has exactly',
    neq: 'does not have exactly',
    gt: 'has more than',
    lt: 'has fewer than',
    gte: 'has at least',
    lte: 'has at most',
};

type OperatorOption = EnumOption & { disabled?: boolean };

const operatorOptions = computed<OperatorOption[]>(() => {
    const counting = parts.value.subject.kind === 'count';
    const labels = counting ? COUNT_LABELS : COMPARATOR_LABELS;
    const options: OperatorOption[] = (Object.keys(labels) as Comparator[]).map((op) => ({ value: op, label: labels[op] }));

    if (counting) return options;

    options.push({ value: 'blank', label: 'is blank' }, { value: 'not_blank', label: 'is not blank' });

    // `selected()` needs a bare reference, so a fixed-value subject cannot carry it. Disabled rather than
    // absent: an option that vanishes when a neighbouring control changes is harder to understand than one
    // that is visibly unavailable.
    const literalSubject = parts.value.subject.kind !== 'field';
    options.push(
        { value: 'includes', label: 'includes', disabled: literalSubject },
        { value: 'excludes', label: 'does not include', disabled: literalSubject },
    );

    return options;
});

function setOperator(value: string): void {
    const op = value as RowOperator;

    patch({ op, right: retype(parts.value.right, { ...parts.value, op }) });
}

function fieldFor(key: string): ConditionFieldOption | undefined {
    return props.catalogue.fields.find((f) => f.key === key);
}

/**
 * Whether a NEW fixed value should be a number literal. Ordering is numeric-only in both engines (Doc #27
 * amendment A1), so an ordering operator always wants a number; equality follows the subject field's own
 * type, because `${code} = '007'` and `${code} = 7` are genuinely different questions (Eq rule 4 vs rule 5).
 *
 * A LOADED operand never passes through here — its kind is preserved exactly as parsed.
 */
function retype(operand: Operand, next: RowParts): Operand {
    if (operand.kind === 'field') return operand;

    const wantsNumber =
        next.op === 'gt' ||
        next.op === 'lt' ||
        next.op === 'gte' ||
        next.op === 'lte' ||
        (next.subject.kind === 'field' && (fieldFor(next.subject.key)?.numeric ?? false));

    if (wantsNumber && operand.kind === 'text') {
        return { kind: 'number', value: operand.value === '' ? Number.NaN : Number(operand.value) };
    }
    if (!wantsNumber && operand.kind === 'number') {
        return { kind: 'text', value: Number.isFinite(operand.value) ? String(operand.value) : '' };
    }

    return operand;
}

/** The choices of the field a `selected()` row names, when it has any. */
const choices = computed<EnumOption[]>(() => {
    const subject = parts.value.subject;

    return subject.kind === 'field' ? (fieldFor(subject.key)?.options ?? []) : [];
});

const subjectNumber = computed<number | null>(() => {
    const subject = parts.value.subject;

    return subject.kind === 'number' && Number.isFinite(subject.value) ? subject.value : null;
});

const subjectText = computed<string>(() => (parts.value.subject.kind === 'text' ? parts.value.subject.value : ''));

const objectNumber = computed<number | null>(() => {
    const right = parts.value.right;

    return right.kind === 'number' && Number.isFinite(right.value) ? right.value : null;
});

function name(suffix: string): string {
    return `Condition ${props.ordinal} ${suffix}`;
}
</script>

<template>
    <div class="cond-row">
        <MdsSelect
            :model-value="subjectValue"
            :groups="subjectGroups"
            :disabled="disabled"
            :aria-label="name('subject')"
            @update:model-value="setSubject"
        />

        <MdsNumberInput
            v-if="parts.subject.kind === 'number'"
            :model-value="subjectNumber"
            :disabled="disabled"
            :aria-label="name('subject value')"
            @update:model-value="patch({ subject: { kind: 'number', value: $event ?? Number.NaN } })"
        />
        <MdsTextInput
            v-else-if="parts.subject.kind === 'text'"
            :model-value="subjectText"
            :disabled="disabled"
            :aria-label="name('subject value')"
            @update:model-value="patch({ subject: { kind: 'text', value: $event } })"
        />

        <MdsSelect
            :model-value="parts.op"
            :options="operatorOptions"
            :disabled="disabled"
            :aria-label="name('operator')"
            @update:model-value="setOperator"
        />

        <template v-if="row.kind === 'compare'">
            <MdsSelect
                :model-value="parts.right.kind === 'field' ? `field:${parts.right.key}` : LITERAL"
                :groups="objectGroups"
                :disabled="disabled"
                :aria-label="name('compared with')"
                @update:model-value="setObject"
            />
            <MdsNumberInput
                v-if="parts.right.kind === 'number'"
                :model-value="objectNumber"
                :disabled="disabled"
                :aria-label="name('value')"
                @update:model-value="patch({ right: { kind: 'number', value: $event ?? Number.NaN } })"
            />
            <MdsTextInput
                v-else-if="parts.right.kind === 'text'"
                :model-value="parts.right.value"
                :disabled="disabled"
                :aria-label="name('value')"
                @update:model-value="patch({ right: { kind: 'text', value: $event } })"
            />
        </template>

        <MdsNumberInput
            v-else-if="row.kind === 'count'"
            :model-value="Number.isFinite(row.n) ? row.n : null"
            :min="0"
            :disabled="disabled"
            :aria-label="name('entry count')"
            @update:model-value="patch({ n: $event ?? Number.NaN })"
        />

        <template v-else-if="row.kind === 'selected'">
            <MdsSelect
                v-if="choices.length > 0"
                :model-value="row.value"
                :options="choices"
                placeholder="Choose an option"
                :disabled="disabled"
                :aria-label="name('option')"
                @update:model-value="patch({ value: $event })"
            />
            <MdsTextInput
                v-else
                :model-value="row.value"
                :disabled="disabled"
                :aria-label="name('option')"
                @update:model-value="patch({ value: $event })"
            />
        </template>

        <MdsIconButton
            icon="trash"
            :label="`Remove condition ${ordinal}`"
            variant="danger"
            size="sm"
            :disabled="disabled"
            @click="emit('remove')"
        />
    </div>
</template>

<style scoped>
/* One control per line by default. The config pane is full width below the builder's 1024px pane
   linearization, and a non-wrapping row of five controls in a shared primitive is what has reddened the
   responsive a11y gate three times (H12b, H14, H15b). `min-width: 0` on every box is the same discipline
   `CascadingEditor` uses. */
.cond-row {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
    min-width: 0;
}

.cond-row > * {
    min-width: 0;
}

@media (min-width: 640px) {
    .cond-row {
        flex-direction: row;
        flex-wrap: wrap;
        align-items: center;
    }
}
</style>
