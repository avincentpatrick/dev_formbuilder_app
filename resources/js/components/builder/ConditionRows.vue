<script setup lang="ts">
/**
 * One `and`/`or` group of the structured condition editor, and — recursively — every group inside it
 * (Increment H21d2). Pure: props down, a fresh group up on every change, no store, no persistence.
 *
 * Nesting is unbounded here BY DESIGN. The describable set is "`and`/`or` chains of the readable shapes at
 * any depth", and this editor may author exactly that set; capping the UI at some depth would make
 * "editable" narrower than "describable" and reintroduce the second predicate Doc #27 amendment D2 spent an
 * increment avoiding. Absurd depth is refused where it is actually a limit — by the engine's own parse-depth
 * budget, through `serialize()`'s self-check — rather than guessed at here.
 */
import { MdsButton, MdsSelect } from '@meridian/design-system';

import ConditionRow from './ConditionRow.vue';
import type { Condition } from './condition-model';
import type { ConditionCatalogue, EnumOption } from './types';

type Group = Extract<Condition, { kind: 'group' }>;

const props = defineProps<{
    group: Group;
    catalogue: ConditionCatalogue;
    /** "" at the root, "2." inside the second child — so labels read "Condition 2.1 operator". */
    path: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{ 'update:group': [value: Group] }>();

function replace(index: number, next: Condition): void {
    emit('update:group', { ...props.group, children: props.group.children.map((child, i) => (i === index ? next : child)) });
}

function remove(index: number): void {
    emit('update:group', { ...props.group, children: props.group.children.filter((_, i) => i !== index) });
}

function addCondition(): void {
    const first = props.catalogue.fields[0];

    emit('update:group', {
        ...props.group,
        children: [
            ...props.group.children,
            // Seeded with the first question and an EMPTY value, which makes the row incomplete
            // (`isComplete()` refuses an empty operand) — so adding a row writes nothing until the author
            // has actually said something. A default that happened to be complete would write a condition
            // they never chose.
            { kind: 'compare', op: 'eq', left: { kind: 'field', key: first?.key ?? '' }, right: { kind: 'text', value: '' } },
        ],
    });
}

function addGroup(): void {
    // The opposite operator, because a group carrying the SAME one as its parent says nothing the flat form
    // does not — `normalize()` would flatten it straight back out.
    emit('update:group', {
        ...props.group,
        children: [...props.group.children, { kind: 'group', op: props.group.op === 'and' ? 'or' : 'and', children: [] }],
    });
}

const matchOptions: EnumOption[] = [
    { value: 'and', label: 'All of' },
    { value: 'or', label: 'Any of' },
];
</script>

<template>
    <div class="cond-group">
        <div class="cond-group__match">
            <MdsSelect
                class="cond-group__match-select"
                :model-value="group.op"
                :options="matchOptions"
                :disabled="disabled"
                :aria-label="`Group ${path || '1.'} match`"
                @update:model-value="emit('update:group', { ...group, op: $event === 'or' ? 'or' : 'and' })"
            />
            <span class="cond-group__match-hint">of these must be true</span>
        </div>

        <ul class="cond-group__list">
            <li v-for="(child, i) in group.children" :key="i" class="cond-group__item">
                <ConditionRows
                    v-if="child.kind === 'group'"
                    :group="child"
                    :catalogue="catalogue"
                    :path="`${path}${i + 1}.`"
                    :disabled="disabled"
                    @update:group="replace(i, $event)"
                />
                <ConditionRow
                    v-else
                    :row="child"
                    :catalogue="catalogue"
                    :ordinal="`${path}${i + 1}`"
                    :disabled="disabled"
                    @update:row="replace(i, $event)"
                    @remove="remove(i)"
                />
            </li>
        </ul>

        <p v-if="group.children.length === 0" class="cond-group__empty">Nothing here yet — this group is ignored.</p>

        <div class="cond-group__actions">
            <MdsButton variant="tertiary" size="sm" icon-left="plus" :disabled="disabled" @click="addCondition">
                Add condition
            </MdsButton>
            <MdsButton variant="tertiary" size="sm" icon-left="plus" :disabled="disabled" @click="addGroup">Add group</MdsButton>
        </div>
    </div>
</template>

<style scoped>
.cond-group {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    min-width: 0;
}

.cond-group__match {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
    min-width: 0;
}

.cond-group__match-select {
    max-width: 140px;
}

.cond-group__match-hint {
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.cond-group__list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    min-width: 0;
}

.cond-group__item {
    min-width: 0;
}

/* A nested group is carried by a left rule rather than by padding that compounds — the LogicRail idiom.
   Four levels of compounding padding is the whole width at 375px. */
.cond-group__item > .cond-group {
    padding-left: var(--mds-space-3);
    border-left: 2px solid var(--mds-color-border-default);
}

.cond-group__empty {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    font-style: italic;
}

.cond-group__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
}
</style>
